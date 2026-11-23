<?php
/**
 * NexRaiz — Livrables du Pack Classic : versionnement, stockage protégé,
 * suivi réel des consultations. Complète includes/livrables.php (qui reste
 * le point de publication unique, réutilisé ici sans modification) sans le
 * remplacer — les autres packs (Booster, Assistance, Wellbeing) continuent
 * d'utiliser leur stockage public existant, inchangé.
 *
 * Versionnement : un "livrable logique" est identifié par (dossier_id,
 * titre). Tant qu'il n'a jamais été publié (statut en_preparation), les
 * mises à jour écrasent la même ligne — rien n'a encore été vu par le
 * client. Dès qu'il est "disponible", toute mise à jour crée une NOUVELLE
 * ligne (version + 1), marque l'ancienne comme non courante, et republie —
 * jamais d'écrasement silencieux d'une version déjà remise au client.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // log_activite()
require_once __DIR__ . '/livrables.php'; // marquer_livrable_disponible() — point de publication unique, réutilisé
require_once __DIR__ . '/documents.php'; // nx_icone_fichier()

function classic_livrables_stockage_prive_racine(): string
{
    return dirname(__DIR__, 3) . '/nexraiz_private_storage/classic/livrables';
}

function classic_livrable_categories(): array
{
    return [
        'rapport'              => 'Rapport d\'accompagnement',
        'compte_rendu'         => 'Compte rendu partageable',
        'document_professionnel' => 'Document professionnel',
        'plan_action'          => "Plan d'action",
        'autre'                => 'Autre',
    ];
}

/**
 * Catégorie déterminable automatiquement uniquement pour les livrables
 * générés par le système lui-même (titre connu et stable) — jamais une
 * supposition sur un titre libre saisi par un consultant.
 */
function classic_livrable_categorie_auto(string $titre): ?string
{
    if (in_array($titre, ['CV corrigé', 'Lettre de motivation corrigée'], true)) {
        return 'document_professionnel';
    }
    if (str_starts_with($titre, 'Compte rendu')) {
        return 'compte_rendu';
    }
    return null;
}

/**
 * Dépose un fichier de livrable envoyé par le consultant (champ
 * $champFichier), valide son contenu réel (pas seulement son extension), et
 * le stocke hors du répertoire servi par le serveur web.
 *
 * @return array{chemin:string,nom_original:string,taille_ko:int}|string Le
 * tableau de métadonnées en cas de succès, sinon un message d'erreur.
 */
function classic_deposer_fichier_livrable(int $dossierId, string $champFichier = 'fichier_livrable'): array|string
{
    if (!isset($_FILES[$champFichier]) || $_FILES[$champFichier]['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Merci de sélectionner un fichier.';
    }
    if ($_FILES[$champFichier]['error'] !== UPLOAD_ERR_OK) {
        return "Erreur lors de l'envoi du fichier.";
    }
    $tailleMaxOctets = 15 * 1024 * 1024;
    if ($_FILES[$champFichier]['size'] > $tailleMaxOctets) {
        return 'Le fichier dépasse la taille maximale de 15 Mo.';
    }

    $extension = strtolower(pathinfo($_FILES[$champFichier]['name'], PATHINFO_EXTENSION));
    $mimesAutorises = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];
    if (!isset($mimesAutorises[$extension])) {
        return 'Format non accepté (PDF, DOC, DOCX, JPG ou PNG uniquement).';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReel = finfo_file($finfo, $_FILES[$champFichier]['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeReel, $mimesAutorises[$extension], true)) {
        return "Le contenu du fichier ne correspond pas à son extension ({$extension}).";
    }

    $dossierStockage = classic_livrables_stockage_prive_racine() . '/' . $dossierId;
    if (!is_dir($dossierStockage) && !mkdir($dossierStockage, 0750, true) && !is_dir($dossierStockage)) {
        return "Impossible de préparer l'espace de stockage.";
    }
    $nomStockage = bin2hex(random_bytes(16)) . '.' . $extension;
    $cheminAbsolu = $dossierStockage . '/' . $nomStockage;
    if (!move_uploaded_file($_FILES[$champFichier]['tmp_name'], $cheminAbsolu)) {
        return "Erreur lors de l'enregistrement du fichier.";
    }

    return [
        'chemin'       => 'prive:' . $dossierId . '/' . $nomStockage,
        'nom_original' => basename($_FILES[$champFichier]['name']),
        'taille_ko'    => (int) round($_FILES[$champFichier]['size'] / 1024),
    ];
}

/** Chemin réel sur disque pour un "chemin" de livrable, public (legacy) ou privé. */
function classic_livrable_chemin_absolu(string $chemin): string
{
    if (str_starts_with($chemin, 'prive:')) {
        return classic_livrables_stockage_prive_racine() . '/' . substr($chemin, strlen('prive:'));
    }
    return dirname(__DIR__) . '/' . $chemin;
}

/**
 * Crée un livrable en brouillon (jamais vu par le client). Si un brouillon
 * existe déjà pour ce (dossier_id, titre), le remplace en place — rien n'a
 * encore été publié, pas de versionnement nécessaire à ce stade.
 *
 * @return int L'identifiant du livrable (nouveau ou réutilisé).
 */
function classic_creer_ou_maj_brouillon_livrable(
    int $dossierId,
    string $titre,
    string $nomOriginal,
    string $chemin,
    int $tailleKo,
    ?string $categorie = null,
    ?string $description = null,
    ?string $prochaineEtape = null
): int {
    $stmt = db()->prepare("SELECT id FROM livrables WHERE dossier_id = ? AND titre = ? AND est_version_courante = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$dossierId, $titre]);
    $existant = $stmt->fetch();

    if ($existant && classic_livrable_est_en_preparation((int) $existant['id'])) {
        db()->prepare('UPDATE livrables SET nom_original = ?, chemin = ?, taille_ko = ?, categorie = ?, description = ?, prochaine_etape = ? WHERE id = ?')
            ->execute([$nomOriginal, $chemin, $tailleKo, $categorie, $description, $prochaineEtape, $existant['id']]);
        return (int) $existant['id'];
    }

    $stmt = db()->prepare(
        'INSERT INTO livrables (dossier_id, titre, nom_original, chemin, taille_ko, statut, categorie, description, prochaine_etape)
         VALUES (?, ?, ?, ?, ?, "en_preparation", ?, ?, ?)'
    );
    $stmt->execute([$dossierId, $titre, $nomOriginal, $chemin, $tailleKo, $categorie, $description, $prochaineEtape]);
    // lastInsertId() est scopé à la connexion, pas à la table : il faut le
    // lire tout de suite, avant tout autre INSERT (log_activite() écrit
    // dans `activites`, ce qui aurait sinon renvoyé son propre id).
    $nouvelId = (int) db()->lastInsertId();
    log_activite($dossierId, 'Livrable déposé (en préparation) : ' . $titre);
    return $nouvelId;
}

function classic_livrable_est_en_preparation(int $livrableId): bool
{
    $stmt = db()->prepare('SELECT statut FROM livrables WHERE id = ?');
    $stmt->execute([$livrableId]);
    return $stmt->fetchColumn() === 'en_preparation';
}

/**
 * Publie un livrable. S'il n'a jamais été publié, le rend simplement
 * disponible (premier envoi, pas de nouvelle version). S'il était déjà
 * disponible et qu'un nouveau fichier est fourni, crée une NOUVELLE version
 * plutôt que d'écraser la précédente, et republie cette nouvelle version —
 * le candidat voit la mise à jour sans notification "nouveau livrable"
 * dupliquée (marquer_livrable_disponible() ne notifie qu'au vrai
 * changement de statut en_preparation -> disponible).
 *
 * @param array{chemin:string,nom_original:string,taille_ko:int}|null $nouveauFichier
 * @return int L'identifiant du livrable actif après publication (inchangé,
 * ou celui de la nouvelle version créée).
 */
function classic_publier_livrable(int $livrableId, int $dossierId, ?array $nouveauFichier = null): int
{
    $stmt = db()->prepare('SELECT * FROM livrables WHERE id = ? AND dossier_id = ?');
    $stmt->execute([$livrableId, $dossierId]);
    $livrable = $stmt->fetch();
    if (!$livrable) {
        return 0;
    }

    if ($livrable['statut'] === 'disponible' && $nouveauFichier !== null) {
        db()->prepare('UPDATE livrables SET est_version_courante = 0 WHERE id = ?')->execute([$livrableId]);
        $stmt = db()->prepare(
            'INSERT INTO livrables (dossier_id, titre, nom_original, chemin, taille_ko, statut, categorie, description, prochaine_etape, version, est_version_courante)
             VALUES (?, ?, ?, ?, ?, "en_preparation", ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $dossierId, $livrable['titre'], $nouveauFichier['nom_original'], $nouveauFichier['chemin'], $nouveauFichier['taille_ko'],
            $livrable['categorie'], $livrable['description'], $livrable['prochaine_etape'], (int) $livrable['version'] + 1,
        ]);
        $nouvelId = (int) db()->lastInsertId();
        marquer_livrable_disponible($nouvelId, $dossierId);
        log_activite($dossierId, 'Nouvelle version publiée : ' . $livrable['titre'] . ' (v' . ((int) $livrable['version'] + 1) . ')');
        return $nouvelId;
    }

    if ($nouveauFichier !== null) {
        db()->prepare('UPDATE livrables SET nom_original = ?, chemin = ?, taille_ko = ? WHERE id = ?')
            ->execute([$nouveauFichier['nom_original'], $nouveauFichier['chemin'], $nouveauFichier['taille_ko'], $livrableId]);
    }
    marquer_livrable_disponible($livrableId, $dossierId);
    return $livrableId;
}

/**
 * Livrables courants d'un dossier (une ligne par livrable logique, jamais
 * les versions remplacées). $statuts restreint les statuts retournés — la
 * page client ne doit jamais recevoir "en_preparation".
 *
 * @param array<int,string> $statuts
 */
function classic_livrables_du_dossier(int $dossierId, array $statuts = ['en_preparation', 'disponible']): array
{
    $placeholders = implode(',', array_fill(0, count($statuts), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM livrables WHERE dossier_id = ? AND est_version_courante = 1 AND statut IN ($placeholders) ORDER BY created_at DESC"
    );
    $stmt->execute(array_merge([$dossierId], $statuts));
    return $stmt->fetchAll();
}

/** Date de première publication d'un livrable logique (version 1), si elle existe. */
function classic_livrable_date_premiere_publication(int $dossierId, string $titre): ?string
{
    $stmt = db()->prepare("SELECT envoye_le FROM livrables WHERE dossier_id = ? AND titre = ? AND version = 1");
    $stmt->execute([$dossierId, $titre]);
    $date = $stmt->fetchColumn();
    return $date ?: null;
}

/** Enregistre une consultation réelle (jamais simulée) par le client. */
function classic_enregistrer_consultation_livrable(int $livrableId): void
{
    db()->prepare('UPDATE livrables SET consultations_count = consultations_count + 1, derniere_consultation_le = NOW() WHERE id = ?')
        ->execute([$livrableId]);
}

function classic_icone_livrable(string $nomOriginal): array
{
    return nx_icone_fichier($nomOriginal);
}
