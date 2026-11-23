<?php
/**
 * NexRaiz — Formations & certifications (consultant), partagé par tous les
 * packs (voir formations.php, qui est l'unique page de ce module). Couvre
 * le catalogue de formations (table existante `formations`), les leçons
 * internes optionnelles, l'inscription/progression, et les certifications
 * professionnelles externes déclarées par le consultant.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // log_activite() si besoin
require_once __DIR__ . '/notifications.php';

/**
 * Racine de stockage privé des justificatifs de certification — même
 * principe que includes/documents.php, hors du répertoire servi par Apache.
 */
function formations_stockage_prive_racine(): string
{
    return nx_racine_stockage_prive() . '/nexraiz_private_storage/certifications';
}

/**
 * Leçons d'une formation, dans l'ordre. Tableau vide si aucun contenu
 * pédagogique interne n'a encore été ajouté (cas de toutes les formations
 * existantes aujourd'hui) — ne jamais en simuler.
 */
function formation_lecons(int $formationId): array
{
    $stmt = db()->prepare('SELECT * FROM formation_lecons WHERE formation_id = ? ORDER BY ordre ASC, id ASC');
    $stmt->execute([$formationId]);
    return $stmt->fetchAll();
}

/**
 * Inscription du consultant à une formation, ou null s'il ne l'a pas
 * commencée (une formation sans inscription est "à commencer").
 */
function formation_inscription(int $formationId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM formation_inscriptions WHERE formation_id = ? AND user_id = ?');
    $stmt->execute([$formationId, $userId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Progression réelle d'une inscription : pourcentage de leçons terminées
 * parmi les leçons existantes. Retourne null si la formation n'a aucune
 * leçon interne — dans ce cas la progression ne peut pas être calculée de
 * façon fiable, seul un statut binaire (en_cours/terminé, déclaré par le
 * consultant) a un sens, voir formation_statut_affichage().
 */
function formation_progression(int $formationId, int $inscriptionId): ?int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM formation_lecons WHERE formation_id = ?');
    $stmt->execute([$formationId]);
    $total = (int) $stmt->fetchColumn();
    if ($total === 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM formation_lecons_terminees WHERE inscription_id = ?');
    $stmt->execute([$inscriptionId]);
    $faites = (int) $stmt->fetchColumn();
    return (int) round($faites / $total * 100);
}

/**
 * Démarre (ou retrouve) l'inscription d'un consultant à une formation.
 * Idempotent : rejouer l'action ne crée jamais de doublon (clé unique
 * formation_id+user_id).
 */
function formation_demarrer(int $formationId, int $userId): array
{
    $existante = formation_inscription($formationId, $userId);
    if ($existante) {
        return $existante;
    }
    db()->prepare('INSERT INTO formation_inscriptions (formation_id, user_id) VALUES (?, ?)')->execute([$formationId, $userId]);
    return formation_inscription($formationId, $userId);
}

/**
 * Marque une leçon comme terminée pour une inscription donnée, recalcule la
 * progression et clôt automatiquement l'inscription si toutes les leçons
 * sont faites. Vérifie que la leçon appartient bien à la formation de
 * l'inscription (jamais une confiance aveugle dans l'identifiant transmis).
 */
function formation_marquer_lecon_terminee(int $inscriptionId, int $leconId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT fi.id, fi.formation_id FROM formation_inscriptions fi
         JOIN formation_lecons fl ON fl.formation_id = fi.formation_id
         WHERE fi.id = ? AND fi.user_id = ? AND fl.id = ?'
    );
    $stmt->execute([$inscriptionId, $userId, $leconId]);
    $info = $stmt->fetch();
    if (!$info) {
        return false;
    }

    db()->prepare('INSERT IGNORE INTO formation_lecons_terminees (inscription_id, lecon_id) VALUES (?, ?)')->execute([$inscriptionId, $leconId]);

    $progression = formation_progression((int) $info['formation_id'], $inscriptionId);
    if ($progression === 100) {
        db()->prepare("UPDATE formation_inscriptions SET statut = 'termine', termine_le = COALESCE(termine_le, NOW()) WHERE id = ?")->execute([$inscriptionId]);
    }

    return true;
}

/**
 * Marque une inscription comme terminée par déclaration du consultant —
 * uniquement pour les formations sans leçon interne (sinon la progression
 * par leçon fait foi, voir formation_marquer_lecon_terminee()).
 */
function formation_declarer_terminee(int $inscriptionId, int $userId): bool
{
    // Réservé aux formations SANS leçon interne (sinon c'est la progression
    // par leçon qui détermine la fin, voir formation_marquer_lecon_terminee()).
    $stmt = db()->prepare(
        "UPDATE formation_inscriptions SET statut = 'termine', termine_le = NOW()
         WHERE id = ? AND user_id = ?
           AND formation_id NOT IN (SELECT DISTINCT formation_id FROM formation_lecons)"
    );
    $stmt->execute([$inscriptionId, $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Enregistre une certification externe déposée par le consultant. Toujours
 * "en_attente" à la création — jamais validée automatiquement. Retourne une
 * chaîne vide en cas de succès, sinon un message d'erreur.
 */
function certification_enregistrer(int $userId, array $champs): string
{
    $nom = trim($champs['nom'] ?? '');
    $dateObtention = trim($champs['date_obtention'] ?? '');
    if ($nom === '') {
        return 'Le nom de la certification est obligatoire.';
    }
    $dateObtentionTs = strtotime($dateObtention);
    if ($dateObtentionTs === false) {
        return "Merci d'indiquer une date d'obtention valide.";
    }
    if ($dateObtentionTs > time()) {
        return "La date d'obtention ne peut pas être dans le futur.";
    }
    $dateExpiration = trim($champs['date_expiration'] ?? '');
    $dateExpirationSql = null;
    if ($dateExpiration !== '') {
        $tsExp = strtotime($dateExpiration);
        if ($tsExp === false) {
            return "La date d'expiration n'est pas valide.";
        }
        $dateExpirationSql = date('Y-m-d', $tsExp);
    }
    $lienVerification = trim($champs['lien_verification'] ?? '');
    if ($lienVerification !== '' && !filter_var($lienVerification, FILTER_VALIDATE_URL)) {
        return 'Le lien de vérification doit être une URL valide.';
    }

    $justificatifChemin = null;
    $justificatifNom = null;
    if (isset($_FILES['justificatif']) && $_FILES['justificatif']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['justificatif']['error'] !== UPLOAD_ERR_OK) {
            return "Erreur lors de l'envoi du justificatif.";
        }
        $tailleMaxOctets = 10 * 1024 * 1024;
        if ($_FILES['justificatif']['size'] > $tailleMaxOctets) {
            return 'Le justificatif dépasse la taille maximale de 10 Mo.';
        }
        $extension = strtolower(pathinfo($_FILES['justificatif']['name'], PATHINFO_EXTENSION));
        $mimesAutorises = [
            'pdf'  => ['application/pdf'],
            'png'  => ['image/png'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
        ];
        if (!isset($mimesAutorises[$extension])) {
            return 'Format de justificatif non accepté (PDF, JPG ou PNG uniquement).';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeReel = finfo_file($finfo, $_FILES['justificatif']['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mimeReel, $mimesAutorises[$extension], true)) {
            return "Le contenu du justificatif ne correspond pas à son extension ({$extension}).";
        }

        $dossierStockage = formations_stockage_prive_racine() . '/' . $userId;
        if (!is_dir($dossierStockage) && !mkdir($dossierStockage, 0750, true) && !is_dir($dossierStockage)) {
            return "Impossible de préparer l'espace de stockage.";
        }
        $nomStockage = bin2hex(random_bytes(16)) . '.' . $extension;
        $cheminAbsolu = $dossierStockage . '/' . $nomStockage;
        if (!move_uploaded_file($_FILES['justificatif']['tmp_name'], $cheminAbsolu)) {
            return "Erreur lors de l'enregistrement du justificatif.";
        }
        $justificatifChemin = 'prive:' . $userId . '/' . $nomStockage;
        $justificatifNom = basename($_FILES['justificatif']['name']);
    }

    $stmt = db()->prepare(
        'INSERT INTO certifications_externes (user_id, nom, organisme, date_obtention, date_expiration, identifiant, lien_verification, justificatif_chemin, justificatif_nom)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $nom,
        trim($champs['organisme'] ?? '') ?: null,
        date('Y-m-d', $dateObtentionTs),
        $dateExpirationSql,
        trim($champs['identifiant'] ?? '') ?: null,
        $lienVerification ?: null,
        $justificatifChemin,
        $justificatifNom,
    ]);

    return '';
}

/**
 * Valide ou refuse une certification externe — réservé à l'appelant (le
 * contrôle de rôle est fait dans formations.php, jamais ici). Un motif est
 * obligatoire en cas de refus.
 */
function certification_statuer(int $certificationId, bool $validee, string $motif, int $adminId): bool
{
    if (!$validee && trim($motif) === '') {
        return false;
    }
    $stmt = db()->prepare(
        "UPDATE certifications_externes
         SET statut = ?, motif_refus = ?, valide_par = ?, valide_le = NOW()
         WHERE id = ? AND statut = 'en_attente'"
    );
    $stmt->execute([$validee ? 'validee' : 'refusee', $validee ? null : trim($motif), $adminId, $certificationId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $stmt = db()->prepare('SELECT user_id, nom FROM certifications_externes WHERE id = ?');
    $stmt->execute([$certificationId]);
    $info = $stmt->fetch();
    if ($info) {
        notify(
            (int) $info['user_id'],
            'certification',
            $validee ? 'Certification validée' : 'Certification refusée',
            $info['nom'] . ($validee ? ' a été validée.' : ' a été refusée : ' . trim($motif)),
            'formations.php'
        );
    }
    return true;
}
