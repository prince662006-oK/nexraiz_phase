<?php
/**
 * NexRaiz — Poste de travail du consultant sur un dossier, Pack Assistance.
 * Centre de prise en charge : situation du client, préparation des
 * échanges, points de vigilance, plan d'action, orientation externe,
 * documents, messagerie intégrée, notes privées, chronologie, et actions
 * de clôture (diagnostic, compte rendu, plan envoyé).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';
require_once __DIR__ . '/includes/message-attachments.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);
$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom, u.email, u.telephone
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();

if (!$dossier) {
    // Pas de dossier précisé (ou introuvable) : on ouvre le plus récent du pack.
    $stmt = db()->prepare('SELECT id FROM dossiers WHERE pack_id = ? ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$packId]);
    $premierId = $stmt->fetchColumn();
    if ($premierId) {
        header('Location: consultant-dossier.php?dossier=' . $premierId);
    } else {
        header('Location: consultant-diagnostic.php');
    }
    exit;
}

// Le premier consultant qui ouvre un dossier non assigné en devient responsable.
if ($dossier['consultant_id'] === null) {
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND consultant_id IS NULL');
    $stmt->execute([$consultantId, $dossierId]);
    $dossier['consultant_id'] = $consultantId;
}

// Propriétaire réel du dossier, récupéré depuis la base (jamais supposé
// être la personne connectée — c'était un bug : l'en-tête affichait
// systématiquement $consultant, même quand un admin ouvrait le dossier
// d'un autre consultant).
$proprietaireNom = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    if ($p = $stmt->fetch()) {
        $proprietaireNom = $p['prenom'] . ' ' . $p['nom'];
    }
}
$estProprietaire = $dossier['consultant_id'] === null || (int) $dossier['consultant_id'] === $consultantId;
$peutModifier = $voitTout || $estProprietaire;

$succes = '';
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if (!$peutModifier) {
        $erreur = "Ce dossier est pris en charge par " . ($proprietaireNom ?? 'un autre consultant') . " — lecture seule pour vous.";
    } elseif ($action === 'changer_statut') {
        $nouveauStatut = $_POST['statut'] ?? '';
        $stmt = db()->prepare('SELECT diagnostic_valide_le FROM qualifications WHERE dossier_id = ?');
        $stmt->execute([$dossierId]);
        $diagnosticValideLe = $stmt->fetchColumn();
        if ($nouveauStatut === 'cloture' && !$diagnosticValideLe) {
            $erreur = "Impossible de clôturer : le diagnostic n'a pas encore été validé.";
        } elseif (in_array($nouveauStatut, ['en_cours', 'en_attente', 'cloture'], true)) {
            db()->prepare('UPDATE dossiers SET statut = ? WHERE id = ?')->execute([$nouveauStatut, $dossierId]);
            log_activite($dossierId, 'Statut du dossier changé : ' . $nouveauStatut);
            notify((int) $dossier['user_id'], 'statut_dossier', 'Votre dossier a été mis à jour', 'Nouveau statut : ' . $nouveauStatut, 'tableau-bord.php');
            $dossier['statut'] = $nouveauStatut;
            $succes = 'Statut mis à jour.';
        }
    } elseif ($action === 'editer_fiche') {
        $stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
        $stmt->execute([$dossierId]);
        $donneesExistantes = json_decode((string) $stmt->fetchColumn(), true) ?: [];

        $personnesTexte = trim($_POST['personnes_impliquees'] ?? '');
        $personnes = [];
        foreach (preg_split('/\r?\n/', $personnesTexte) as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '') continue;
            $parts = explode('—', $ligne, 2);
            $nomPersonne = trim($parts[0]);
            if ($nomPersonne === '') continue; // ligne mal formée (ex. juste "—") : ignorée sans erreur
            $personnes[] = ['nom' => $nomPersonne, 'role' => trim($parts[1] ?? '')];
        }
        $donneesExistantes['personnes_impliquees'] = $personnes;

        $stmt = db()->prepare(
            'INSERT INTO qualifications (dossier_id, situation_type, urgence, resume, donnees_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE situation_type = VALUES(situation_type), urgence = VALUES(urgence),
                 resume = VALUES(resume), donnees_json = VALUES(donnees_json)'
        );
        $stmt->execute([
            $dossierId,
            trim($_POST['situation_type'] ?? ''),
            trim($_POST['urgence'] ?? ''),
            trim($_POST['resume'] ?? ''),
            json_encode($donneesExistantes, JSON_UNESCAPED_UNICODE),
        ]);
        log_activite($dossierId, 'Fiche dossier mise à jour');
        $succes = 'Fiche dossier mise à jour.';
    } elseif ($action === 'valider_diagnostic') {
        $stmt = db()->prepare('SELECT 1 FROM qualifications WHERE dossier_id = ?');
        $stmt->execute([$dossierId]);
        if (!$stmt->fetchColumn()) {
            $erreur = "Impossible de valider : aucune qualification n'a encore été renseignée pour ce dossier.";
        } else {
            db()->prepare('UPDATE qualifications SET diagnostic_valide_le = NOW() WHERE dossier_id = ?')->execute([$dossierId]);
            log_activite($dossierId, 'Diagnostic validé par le consultant');
            $succes = 'Diagnostic validé.';
        }
    } elseif ($action === 'ajouter_vigilance') {
        $categories = [
            'stress'    => ['icone' => 'local_fire_department', 'couleur' => 'bg-red-100 text-red-700'],
            'conflit'   => ['icone' => 'groups', 'couleur' => 'bg-orange-100 text-orange-700'],
            'procedure' => ['icone' => 'gavel', 'couleur' => 'bg-purple-100 text-purple-700'],
            'urgence'   => ['icone' => 'schedule', 'couleur' => 'bg-blue-100 text-blue-700'],
            'orientation' => ['icone' => 'explore', 'couleur' => 'bg-surface-container-high text-secondary'],
        ];
        $cat = $categories[$_POST['categorie_vigilance'] ?? ''] ?? $categories['urgence'];
        $titre = trim($_POST['titre_vigilance'] ?? '');
        $texte = trim($_POST['texte_vigilance'] ?? '');
        if ($titre !== '') {
            $stmt = db()->prepare('INSERT INTO dossier_points_vigilance (dossier_id, titre, texte, icone, couleur) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$dossierId, $titre, $texte, $cat['icone'], $cat['couleur']]);
            log_activite($dossierId, 'Point de vigilance ajouté : ' . $titre);
        }
    } elseif ($action === 'traiter_vigilance') {
        $vigilanceId = (int) ($_POST['vigilance_id'] ?? 0);
        $stmt = db()->prepare('SELECT titre FROM dossier_points_vigilance WHERE id = ? AND dossier_id = ?');
        $stmt->execute([$vigilanceId, $dossierId]);
        $titreVigilance = $stmt->fetchColumn();
        if ($titreVigilance) {
            // On ne supprime jamais silencieusement une alerte : elle reste
            // tracée dans l'historique, seul son statut "traité" change.
            db()->prepare('UPDATE dossier_points_vigilance SET traite = 1 WHERE id = ?')->execute([$vigilanceId]);
            log_activite($dossierId, 'Point de vigilance traité : ' . $titreVigilance);
        }
    } elseif ($action === 'ajouter_action') {
        $titre = trim($_POST['titre_action'] ?? '');
        if ($titre !== '') {
            $priorite = in_array($_POST['priorite_action'] ?? '', ['normale', 'haute'], true) ? $_POST['priorite_action'] : 'normale';
            $stmt = db()->prepare('INSERT INTO actions_plan (dossier_id, titre, description, proprietaire, priorite, echeance, statut) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $dossierId, $titre, trim($_POST['description_action'] ?? '') ?: null,
                trim($_POST['proprietaire_action'] ?? '') ?: 'Consultant', $priorite,
                $_POST['echeance_action'] ?: null, 'a_faire',
            ]);
            log_activite($dossierId, 'Action ajoutée au plan : ' . $titre);
            if (trim($_POST['proprietaire_action'] ?? '') !== '' && stripos(trim($_POST['proprietaire_action']), 'client') !== false) {
                notify((int) $dossier['user_id'], 'action_plan', 'Nouvelle action à réaliser', $titre, 'tableau-bord.php');
            }
        }
    } elseif ($action === 'changer_statut_action') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $nouveauStatutAction = $_POST['nouveau_statut'] ?? '';
        if (in_array($nouveauStatutAction, ['a_faire', 'en_cours', 'termine', 'a_venir'], true)) {
            $stmt = db()->prepare('UPDATE actions_plan SET statut = ? WHERE id = ? AND dossier_id = ?');
            $stmt->execute([$nouveauStatutAction, $actionId, $dossierId]);
        }
    } elseif ($action === 'toggle_orientation') {
        $type = $_POST['type_orientation'] ?? '';
        if (in_array($type, ['avocat', 'medecin_travail', 'psychologue', 'expert_rh'], true)) {
            $stmt = db()->prepare('SELECT recommande FROM dossier_orientations WHERE dossier_id = ? AND type = ?');
            $stmt->execute([$dossierId, $type]);
            $actuel = $stmt->fetchColumn();
            $nouveau = $actuel === false ? 1 : (1 - (int) $actuel);
            $stmt = db()->prepare(
                'INSERT INTO dossier_orientations (dossier_id, type, recommande) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE recommande = VALUES(recommande)'
            );
            $stmt->execute([$dossierId, $type, $nouveau]);
            if ($nouveau) {
                log_activite($dossierId, 'Orientation recommandée : ' . $type);
            }
        }
    } elseif ($action === 'ajouter_document') {
        if (!empty($_FILES['fichier_document']['name'])) {
            $categorie = trim($_POST['categorie_document'] ?? '');
            $uploadsDir = __DIR__ . '/uploads/assistance/' . $dossierId;
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
            $nomFichier = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['fichier_document']['name']));
            $cheminAbsolu = $uploadsDir . '/' . $nomFichier;
            if (move_uploaded_file($_FILES['fichier_document']['tmp_name'], $cheminAbsolu)) {
                $cheminRelatif = 'uploads/assistance/' . $dossierId . '/' . $nomFichier;
                $tailleKo = (int) round($_FILES['fichier_document']['size'] / 1024);
                $stmt = db()->prepare('INSERT INTO documents (dossier_id, nom_original, categorie, chemin, taille_ko) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$dossierId, $_FILES['fichier_document']['name'], $categorie ?: null, $cheminRelatif, $tailleKo]);
                log_activite($dossierId, 'Document ajouté' . ($categorie ? ' : ' . $categorie : ''));
            } else {
                $erreur = "Impossible d'enregistrer le fichier.";
            }
        }
    } elseif ($action === 'enregistrer_notes') {
        db()->prepare('UPDATE dossiers SET notes_consultant = ? WHERE id = ?')->execute([trim($_POST['notes_consultant'] ?? ''), $dossierId]);
        log_activite($dossierId, 'Notes de session enregistrées');
        $succes = 'Notes enregistrées.';
    } elseif ($action === 'generer_compte_rendu') {
        $stmt = db()->prepare('SELECT diagnostic_valide_le FROM qualifications WHERE dossier_id = ?');
        $stmt->execute([$dossierId]);
        if (!$stmt->fetchColumn()) {
            $erreur = 'Le diagnostic doit être validé avant de générer le compte rendu.';
        } else {
            // Génère un rapport toujours à jour (reconstruit depuis les données
            // du dossier à chaque consultation, voir compte-rendu.php) : le
            // clic est lui-même l'action délibérée d'envoi, donc "disponible"
            // directement — pas de brouillon intermédiaire à confirmer.
            $titre = 'Compte rendu — ' . date('d/m/Y');
            $stmt = db()->prepare('INSERT INTO livrables (dossier_id, titre, nom_original, chemin, taille_ko, statut) VALUES (?, ?, ?, ?, 0, "disponible")');
            $stmt->execute([$dossierId, $titre, $titre, 'compte-rendu.php?dossier=' . $dossierId]);
            log_activite($dossierId, 'Compte rendu généré et ajouté aux livrables du client');
            notify((int) $dossier['user_id'], 'livrable', 'Nouveau livrable disponible', $titre, 'tableau-bord.php');
            $succes = 'Compte rendu généré et transmis au client.';
        }
    } elseif ($action === 'envoyer_plan') {
        $stmt = db()->prepare('SELECT titre, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC');
        $stmt->execute([$dossierId]);
        $lignes = array_map(fn ($a) => '- ' . $a['titre'] . ($a['echeance'] ? ' (échéance : ' . date('d/m/Y', strtotime($a['echeance'])) . ')' : ''), $stmt->fetchAll());
        $corps = "Voici votre plan d'action personnalisé :\n\n" . ($lignes ? implode("\n", $lignes) : 'Aucune action pour le moment.');
        $stmt = db()->prepare(
            'INSERT INTO messages (dossier_id, expediteur_id, expediteur_type, corps, lu_par_client, lu_par_consultant)
             VALUES (?, ?, ?, ?, 0, 1)'
        );
        $stmt->execute([$dossierId, $consultantId, 'consultant', $corps]);
        log_activite($dossierId, "Plan d'action envoyé au client");
        $succes = 'Plan envoyé au client.';
    } elseif ($action === 'envoyer_message') {
        $corps = trim($_POST['corps_message'] ?? '');
        try {
            $pieceJointe = traiter_piece_jointe_message('piece_jointe_message');
        } catch (RuntimeException $e) {
            $erreur = $e->getMessage();
            $pieceJointe = null;
        }
        if (!$erreur) {
            if ($corps === '' && !$pieceJointe) {
                $erreur = 'Merci de saisir un message ou de joindre un fichier.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO messages (dossier_id, expediteur_id, expediteur_type, corps, piece_jointe_nom, piece_jointe_chemin, piece_jointe_taille, lu_par_client, lu_par_consultant)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)'
                );
                $stmt->execute([$dossierId, $consultantId, 'consultant', $corps, $pieceJointe['nom'] ?? null, $pieceJointe['chemin'] ?? null, $pieceJointe['taille'] ?? null]);
                log_activite($dossierId, 'Message envoyé au client');
            }
        }
    }

    header('Location: consultant-dossier.php?dossier=' . $dossierId . '&succes=' . urlencode($succes) . '&erreur=' . urlencode($erreur) . '#' . ($_POST['ancre'] ?? ''));
    exit;
}

if (isset($_GET['succes']) && $_GET['succes'] !== '') $succes = $_GET['succes'];
if (isset($_GET['erreur']) && $_GET['erreur'] !== '') $erreur = $_GET['erreur'];

$stmt = db()->prepare('SELECT donnees_json, situation_type, urgence, resume, documents_disponibles, diagnostic_valide_le FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = $stmt->fetch() ?: [];
$donneesJson = json_decode((string) ($qualification['donnees_json'] ?? ''), true) ?: [];
$personnesImpliquees = $donneesJson['personnes_impliquees'] ?? [];
$urgenceInfo = assistance_urgence_info($qualification['urgence'] ?? null);
$diagnosticValide = !empty($qualification['diagnostic_valide_le']);

$stmt = db()->prepare('SELECT texte, created_at FROM activites WHERE dossier_id = ? ORDER BY id ASC');
$stmt->execute([$dossierId]);
$chronologieComplete = $stmt->fetchAll();
$dernierEvenement = $chronologieComplete ? end($chronologieComplete) : null;

$stmt = db()->prepare('SELECT id, titre, texte, icone, couleur, traite FROM dossier_points_vigilance WHERE dossier_id = ? ORDER BY traite ASC, id DESC');
$stmt->execute([$dossierId]);
$pointsVigilance = $stmt->fetchAll();
$nbVigilanceOuvertes = count(array_filter($pointsVigilance, fn ($pv) => !$pv['traite']));

$stmt = db()->prepare('SELECT id, titre, description, proprietaire, priorite, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC, id ASC');
$stmt->execute([$dossierId]);
$planAction = $stmt->fetchAll();
$actionStatutLabels = ['a_faire' => ['À faire', 'bg-surface-container-high text-secondary'], 'en_cours' => ['En cours', 'bg-blue-100 text-blue-700'], 'termine' => ['Terminé', 'bg-green-100 text-green-700'], 'a_venir' => ['À venir', 'bg-surface-container-low text-secondary']];
$actionsOuvertes = array_filter($planAction, fn ($a) => $a['statut'] !== 'termine');
$actionsEnRetard = array_filter($actionsOuvertes, fn ($a) => $a['echeance'] && strtotime($a['echeance']) < strtotime('today'));

$stmt = db()->prepare('SELECT type, recommande FROM dossier_orientations WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$orientationsBrutes = [];
foreach ($stmt->fetchAll() as $o) { $orientationsBrutes[$o['type']] = (bool) $o['recommande']; }
$orientationsDef = [
    'avocat' => ['icone' => 'balance', 'titre' => 'Avocat', 'texte' => 'Droit du travail'],
    'medecin_travail' => ['icone' => 'medical_services', 'titre' => 'Médecin du travail', 'texte' => 'Évaluation santé'],
    'psychologue' => ['icone' => 'psychology', 'titre' => 'Psychologue', 'texte' => 'Soutien psychologique'],
    'expert_rh' => ['icone' => 'person', 'titre' => 'Expert RH', 'texte' => 'Conseil carrière'],
];

$documentsAttendusDecodes = json_decode((string) ($qualification['documents_disponibles'] ?? ''), true);
$documentsAttendus = is_array($documentsAttendusDecodes) ? $documentsAttendusDecodes : [];
$stmt = db()->prepare('SELECT nom_original, categorie, chemin, uploaded_at FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$dossierId]);
$documentsRecus = $stmt->fetchAll();
$documentsParCategorie = [];
foreach ($documentsRecus as $doc) {
    if ($doc['categorie']) $documentsParCategorie[$doc['categorie']][] = $doc;
}
$documentsManquants = array_values(array_filter($documentsAttendus, fn ($c) => empty($documentsParCategorie[$c])));

$stmt = db()->prepare("SELECT titre, echeance FROM actions_plan WHERE dossier_id = ? AND statut != 'termine' ORDER BY echeance ASC LIMIT 4");
$stmt->execute([$dossierId]);
$prochainesEtapes = $stmt->fetchAll();

// --- Messagerie intégrée : les 5 derniers messages, réutilise exactement
// la même table et les mêmes règles que consultant-messages-assistance.php
// (pas de second système de messagerie). ---
db()->prepare("UPDATE messages SET lu_par_consultant = 1 WHERE dossier_id = ? AND expediteur_type = 'client'")->execute([$dossierId]);
$stmt = db()->prepare('SELECT expediteur_type, corps, piece_jointe_nom, piece_jointe_chemin, created_at FROM messages WHERE dossier_id = ? ORDER BY id DESC LIMIT 5');
$stmt->execute([$dossierId]);
$derniersMessages = array_reverse($stmt->fetchAll());
$stmt = db()->prepare('SELECT COUNT(*) FROM messages WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$nbMessagesTotal = (int) $stmt->fetchColumn();

function assistance_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

// Conseils d'échange : contenu de préparation, adapté selon le type de
// situation renseigné par le client (pas de fabrication par dossier — un
// jeu de recommandations générique par type de situation, réutilisable).
// Ce sont des suggestions de posture, jamais un avis juridique ou médical.
$conseilsParSituation = [
    "Conflit avec l'employeur" => [
        'messages' => ["Vous n'êtes pas seul(e)", 'Vous avez des droits', 'Nous avançons étape par étape', 'Nous préparons une stratégie adaptée'],
        'posture' => ['Écoute active', 'Empathie et neutralité', 'Clarté et pédagogie', 'Rassurer sans minimiser'],
        'eviter' => ['Promettre un résultat', 'Juger les décisions passées', 'Prendre parti', 'Minimiser les faits'],
    ],
    'Conflit avec un collègue' => [
        'messages' => ["Vous n'êtes pas seul(e)", 'Chaque situation est différente', 'Nous clarifions les faits ensemble'],
        'posture' => ['Écoute active', 'Neutralité vis-à-vis des deux parties', 'Clarté sur les options possibles'],
        'eviter' => ['Prendre parti', 'Minimiser le vécu', 'Promettre une médiation garantie'],
    ],
    'Harcèlement présumé' => [
        'messages' => ["Vous n'êtes pas seul(e)", 'Ce que vous décrivez est pris au sérieux', 'Vos droits seront expliqués clairement'],
        'posture' => ['Écoute active et bienveillante', 'Neutralité totale', 'Rigueur dans la collecte des faits'],
        'eviter' => ['Minimiser ou relativiser', 'Porter un jugement', 'Promettre une issue précise'],
    ],
    'Discrimination alléguée' => [
        'messages' => ['Ce que vous rapportez est pris au sérieux', 'Nous allons structurer les faits', 'Vos droits seront expliqués clairement'],
        'posture' => ['Neutralité totale', 'Rigueur factuelle', 'Écoute sans jugement'],
        'eviter' => ['Qualifier juridiquement à la place d\'un avocat', 'Minimiser', 'Promettre une issue précise'],
    ],
    'Licenciement contesté' => [
        'messages' => ['Nous allons clarifier la procédure', 'Vos droits seront vérifiés point par point', 'Nous avançons étape par étape'],
        'posture' => ['Clarté et pédagogie', 'Rigueur factuelle', 'Empathie'],
        'eviter' => ['Promettre une réintégration', 'Juger l\'employeur', 'Improviser sur le droit du travail'],
    ],
    'Rupture de contrat' => [
        'messages' => ['Nous clarifions vos options', 'Chaque cas a ses spécificités', 'Nous avançons étape par étape'],
        'posture' => ['Clarté et pédagogie', 'Rigueur factuelle'],
        'eviter' => ['Promettre une issue précise', 'Improviser sur le droit du travail'],
    ],
    'Problème de rémunération' => [
        'messages' => ['Nous allons vérifier les éléments avec vous', 'Vos droits seront expliqués clairement'],
        'posture' => ['Rigueur factuelle', 'Clarté et pédagogie'],
        'eviter' => ['Avancer un montant garanti', 'Juger l\'employeur sans les faits'],
    ],
    'Épuisement professionnel déclaré' => [
        'messages' => ["Vous n'êtes pas seul(e)", 'Votre état est pris au sérieux', 'Nous pouvons vous orienter vers un professionnel de santé'],
        'posture' => ['Écoute bienveillante', 'Rassurer sans minimiser', 'Orientation si besoin'],
        'eviter' => ['Poser un diagnostic médical', 'Minimiser la fatigue exprimée', 'Promettre une guérison'],
    ],
];
$conseils = $conseilsParSituation[$qualification['situation_type'] ?? ''] ?? [
    'messages' => ["Vous n'êtes pas seul(e)", 'Vous avez des droits', 'Nous avançons étape par étape', 'Nous préparons une stratégie adaptée'],
    'posture' => ['Écoute active', 'Empathie et neutralité', 'Clarté et pédagogie', 'Rassurer sans minimiser'],
    'eviter' => ['Promettre un résultat', 'Juger les décisions passées', 'Prendre parti', 'Minimiser les faits'],
];

$statutDossierLabels = ['en_cours' => ['En cours', 'bg-blue-100 text-blue-700', 'bg-blue-600'], 'en_attente' => ['En attente', 'bg-orange-100 text-orange-700', 'bg-orange-500'], 'cloture' => ['Clôturé', 'bg-green-100 text-green-700', 'bg-green-600']];
[$statutLabel, $statutClass, $statutDot] = $statutDossierLabels[$dossier['statut']] ?? ['—', 'bg-surface-container-high text-secondary', 'bg-secondary'];

// --- Résumé opérationnel : répond à 4 questions à partir des vraies données. ---
if (!empty($documentsManquants)) {
    $prochaineAction = 'Relancer le client pour : ' . implode(', ', $documentsManquants);
} elseif (!$diagnosticValide) {
    $prochaineAction = 'Valider le diagnostic';
} elseif (!$planAction) {
    $prochaineAction = "Construire le plan d'action";
} elseif ($actionsEnRetard) {
    $prochaineAction = count($actionsEnRetard) . ' action(s) en retard à traiter';
} elseif ($actionsOuvertes) {
    $prochaineAction = 'Suivre les actions en cours';
} else {
    $prochaineAction = 'Générer le compte rendu si le dossier est prêt';
}
$blocage = null;
if ($nbMessagesTotal && !$diagnosticValide && $documentsManquants) {
    $blocage = 'Documents manquants : ' . implode(', ', $documentsManquants);
} elseif ($actionsEnRetard) {
    $blocage = count($actionsEnRetard) . ' action(s) en retard';
} elseif ($nbVigilanceOuvertes > 0) {
    $blocage = $nbVigilanceOuvertes . ' point(s) de vigilance non traité(s)';
}

render_head('Espace NexRaiz — Suivi, plan d\'action et orientation', 'Structurer les prochaines étapes du dossier client.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dossier_sidebar($packId, $consultant, 'suivi'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center gap-space-md px-gutter-mobile lg:px-gutter-desktop">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <div class="flex items-center gap-space-md ml-auto shrink-0">
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
          <div class="hidden sm:flex flex-col leading-tight">
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary">Consultant</span>
          </div>
          <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors ml-space-xs" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
        </div>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="decouverte.php" class="hover:text-primary transition-colors">Pack Assistance</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <a href="consultant-dossiers-assistance.php" class="hover:text-primary transition-colors">Mes dossiers</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span>Dossier #<?php echo htmlspecialchars($dossier['numero']); ?></span>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Suivi et plan d'action</span>
      </nav>

      <?php if (!$peutModifier): ?>
      <div class="flex items-start gap-space-xs bg-orange-50 border border-orange-200 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-orange-600 mt-0.5">lock</span>
        <p class="font-body-sm text-body-sm text-on-surface">Ce dossier est pris en charge par <strong><?php echo htmlspecialchars($proprietaireNom ?? 'un autre consultant'); ?></strong>. Vous le consultez en lecture seule.</p>
      </div>
      <?php endif; ?>

      <?php if ($succes): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
      </div>
      <?php endif; ?>
      <?php if ($erreur): ?>
      <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($erreur); ?></p>
      </div>
      <?php endif; ?>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Espace NexRaiz : Suivi, plan d'action et orientation</h1>
          <p class="font-body-sm text-body-sm text-secondary">Structurer les prochaines étapes du dossier client</p>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-wrap items-center gap-space-lg">
        <div class="flex items-center gap-space-xs">
          <div class="w-11 h-11 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-lg text-label-lg font-bold"><?php echo strtoupper(mb_substr($dossier['prenom'], 0, 1) . mb_substr($dossier['nom'], 0, 1)); ?></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($dossier['email']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($dossier['telephone'] ?? '—'); ?></p></div>
        </div>
        <div><p class="font-body-sm text-[11px] text-secondary">Dossier</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($dossier['numero']); ?></p></div>
        <div><p class="font-body-sm text-[11px] text-secondary">Ouvert le</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y', strtotime($dossier['created_at']))); ?></p></div>
        <div><p class="font-body-sm text-[11px] text-secondary">Consultant</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($proprietaireNom ?? 'Non assigné'); ?></p></div>
        <?php if ($peutModifier): ?>
        <form method="post" class="ml-auto">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="changer_statut">
          <input type="hidden" name="ancre" value="">
          <select name="statut" onchange="this.form.submit()" class="flex items-center gap-1 px-space-sm py-1 rounded-full <?php echo $statutClass; ?> font-label-md text-label-md font-bold border-0 focus:outline-none">
            <option value="en_cours" <?php echo $dossier['statut'] === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
            <option value="en_attente" <?php echo $dossier['statut'] === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
            <option value="cloture" <?php echo $dossier['statut'] === 'cloture' ? 'selected' : ''; ?> <?php echo !$diagnosticValide ? 'disabled title="Diagnostic non validé"' : ''; ?>>Clôturé<?php echo !$diagnosticValide ? ' (diagnostic requis)' : ''; ?></option>
          </select>
        </form>
        <?php else: ?>
        <span class="ml-auto px-space-sm py-1 rounded-full <?php echo $statutClass; ?> font-label-md text-label-md font-bold"><?php echo htmlspecialchars($statutLabel); ?></span>
        <?php endif; ?>
      </div>

      <!-- Résumé opérationnel -->
      <div class="bg-primary-container/5 border border-primary-container/20 rounded-xl p-space-md grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <div class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Situation</span>
          <span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface font-semibold"><span class="w-2 h-2 rounded-full <?php echo $urgenceInfo['dot']; ?>"></span><?php echo htmlspecialchars($qualification['situation_type'] ?? 'Non renseignée'); ?></span>
        </div>
        <div class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Où en est le dossier</span>
          <span class="font-body-sm text-body-sm text-on-surface"><?php echo $diagnosticValide ? 'Diagnostic validé' : 'Diagnostic non validé'; ?><?php echo $dernierEvenement ? ' · ' . htmlspecialchars(assistance_il_y_a($dernierEvenement['created_at'])) : ''; ?></span>
        </div>
        <div class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Prochaine action</span>
          <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($prochaineAction); ?></span>
        </div>
        <div class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Blocage</span>
          <span class="font-body-sm text-body-sm <?php echo $blocage ? 'text-error font-semibold' : 'text-on-surface'; ?>"><?php echo $blocage ? htmlspecialchars($blocage) : 'Aucun blocage identifié'; ?></span>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">

        <div class="flex flex-col gap-space-sm">
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="fiche">
            <div class="flex items-center justify-between">
              <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Fiche dossier client</h2>
              <?php if ($peutModifier): ?>
              <button type="button" class="edit-toggle h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors" data-target="edit-fiche">Éditer</button>
              <?php endif; ?>
            </div>
            <div class="flex items-center justify-between"><span class="font-body-sm text-body-sm text-secondary">Situation concernée</span><span class="px-space-xs py-0.5 rounded-full bg-orange-100 text-orange-700 font-label-md text-[11px] font-bold"><?php echo htmlspecialchars($qualification['situation_type'] ?? 'Non renseignée'); ?></span></div>
            <div class="flex items-center justify-between"><span class="font-body-sm text-body-sm text-secondary">Niveau d'urgence <span class="text-[10px]">(déclaré)</span></span><span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface font-semibold"><span class="w-2 h-2 rounded-full <?php echo $urgenceInfo['dot']; ?>"></span><?php echo htmlspecialchars($urgenceInfo['label']); ?></span></div>
            <div class="flex flex-col gap-space-2xs">
              <span class="font-body-sm text-body-sm text-secondary">Personnes impliquées</span>
              <?php if (!$personnesImpliquees): ?>
              <span class="font-body-sm text-[12px] text-secondary">Aucune personne renseignée.</span>
              <?php endif; ?>
              <?php foreach ($personnesImpliquees as $p): ?>
              <span class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">person</span><strong><?php echo htmlspecialchars($p['nom']); ?></strong><?php if (!empty($p['role'])): ?><span class="text-secondary">— <?php echo htmlspecialchars($p['role']); ?></span><?php endif; ?></span>
              <?php endforeach; ?>
            </div>
            <div class="flex flex-col gap-space-2xs pt-space-xs border-t border-outline-variant/60">
              <span class="font-body-sm text-body-sm text-secondary">Résumé transmis par le client</span>
              <p class="font-body-sm text-body-sm text-on-surface leading-relaxed"><?php echo $qualification['resume'] ? nl2br(htmlspecialchars($qualification['resume'])) : 'Aucun résumé transmis par le client pour le moment.'; ?></p>
            </div>

            <?php if ($peutModifier): ?>
            <form method="post" id="edit-fiche" class="hidden flex-col gap-space-sm bg-surface-container-low rounded-xl p-space-sm">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="editer_fiche">
              <input type="hidden" name="ancre" value="fiche">
              <label class="flex flex-col gap-space-2xs">
                <span class="font-label-md text-[12px] text-on-surface font-semibold">Situation concernée</span>
                <input type="text" name="situation_type" list="situationsConnues" value="<?php echo htmlspecialchars($qualification['situation_type'] ?? ''); ?>" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <datalist id="situationsConnues">
                  <?php foreach (array_keys($conseilsParSituation) as $situationConnue): ?>
                  <option value="<?php echo htmlspecialchars($situationConnue); ?>"></option>
                  <?php endforeach; ?>
                </datalist>
              </label>
              <label class="flex flex-col gap-space-2xs">
                <span class="font-label-md text-[12px] text-on-surface font-semibold">Niveau d'urgence (déclaré)</span>
                <select name="urgence" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                  <?php foreach (['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique'] as $val => $label): ?>
                  <option value="<?php echo $val; ?>" <?php echo ($qualification['urgence'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="flex flex-col gap-space-2xs">
                <span class="font-label-md text-[12px] text-on-surface font-semibold">Personnes impliquées <span class="font-normal text-secondary">(une par ligne, format "Nom — Rôle")</span></span>
                <textarea name="personnes_impliquees" rows="3" class="w-full p-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars(implode("\n", array_map(fn ($p) => $p['nom'] . ' — ' . $p['role'], $personnesImpliquees))); ?></textarea>
              </label>
              <label class="flex flex-col gap-space-2xs">
                <span class="font-label-md text-[12px] text-on-surface font-semibold">Résumé (notes du consultant — la version initiale du client reste dans l'historique)</span>
                <textarea name="resume" rows="4" class="w-full p-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars($qualification['resume'] ?? ''); ?></textarea>
              </label>
              <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-label-md flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">save</span>Enregistrer</button>
            </form>
            <?php endif; ?>

            <div class="flex flex-col gap-space-sm pt-space-xs border-t border-outline-variant/60">
              <span class="font-body-sm text-body-sm text-secondary">Chronologie des événements</span>
              <?php if (!$chronologieComplete): ?>
              <p class="font-body-sm text-[12px] text-secondary">Aucun événement enregistré pour le moment.</p>
              <?php else: $chronologieApercu = array_slice($chronologieComplete, -5); ?>
              <div class="flex flex-col gap-space-sm relative pl-space-md" id="chrono-apercu">
                <div class="absolute left-[5px] top-1 bottom-1 w-0.5 bg-outline-variant"></div>
                <?php foreach ($chronologieApercu as $c): ?>
                <div class="relative">
                  <span class="absolute -left-space-md top-1 w-2.5 h-2.5 rounded-full bg-primary-container"></span>
                  <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y', strtotime($c['created_at']))); ?></p>
                  <p class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($c['texte']); ?></p>
                </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($chronologieComplete) > count($chronologieApercu)): ?>
              <div class="hidden flex-col gap-space-sm relative pl-space-md" id="chrono-complete">
                <div class="absolute left-[5px] top-1 bottom-1 w-0.5 bg-outline-variant"></div>
                <?php foreach ($chronologieComplete as $c): ?>
                <div class="relative">
                  <span class="absolute -left-space-md top-1 w-2.5 h-2.5 rounded-full bg-primary-container"></span>
                  <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y', strtotime($c['created_at']))); ?></p>
                  <p class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($c['texte']); ?></p>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="history-toggle h-9 rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors" data-show="chrono-complete" data-hide="chrono-apercu">Voir toute la chronologie (<?php echo count($chronologieComplete); ?>)</button>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <div class="flex items-center justify-between">
              <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Préparation des échanges</h2>
              <span class="font-body-sm text-[10px] text-secondary">Suggestions, pas un avis juridique ou médical</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
              <div class="flex flex-col gap-space-2xs">
                <p class="font-label-md text-label-md text-on-surface font-bold">Messages clés</p>
                <?php foreach ($conseils['messages'] as $m): ?>
                <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-green-600 mt-0.5">check</span><?php echo htmlspecialchars($m); ?></span>
                <?php endforeach; ?>
              </div>
              <div class="flex flex-col gap-space-2xs">
                <p class="font-label-md text-label-md text-on-surface font-bold">Posture recommandée</p>
                <?php foreach ($conseils['posture'] as $p): ?>
                <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-primary mt-0.5">check_circle</span><?php echo htmlspecialchars($p); ?></span>
                <?php endforeach; ?>
              </div>
              <div class="flex flex-col gap-space-2xs">
                <p class="font-label-md text-label-md text-on-surface font-bold">Points à éviter</p>
                <?php foreach ($conseils['eviter'] as $e): ?>
                <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-error mt-0.5">close</span><?php echo htmlspecialchars($e); ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Échanges avec le client (messagerie intégrée) -->
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="messages">
            <div class="flex items-center justify-between">
              <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Échanges avec le client</h2>
              <a href="consultant-messages-assistance.php?dossier=<?php echo $dossierId; ?>" class="font-label-md text-[11px] text-primary flex items-center gap-1">Conversation complète (<?php echo $nbMessagesTotal; ?>) <span class="material-symbols-outlined text-[13px]">arrow_forward</span></a>
            </div>
            <?php if (!$derniersMessages): ?>
            <p class="font-body-sm text-body-sm text-secondary">Aucun message pour le moment.</p>
            <?php endif; ?>
            <div class="flex flex-col gap-space-xs max-h-72 overflow-y-auto">
              <?php foreach ($derniersMessages as $m): ?>
              <div class="flex flex-col gap-0.5 p-space-xs rounded-lg <?php echo $m['expediteur_type'] === 'client' ? 'bg-surface-container-low self-start' : 'bg-primary-container/10 self-end'; ?> max-w-[85%]">
                <span class="font-label-md text-[10px] text-secondary font-bold"><?php echo $m['expediteur_type'] === 'client' ? htmlspecialchars($dossier['prenom']) : 'Vous'; ?> · <?php echo htmlspecialchars(assistance_il_y_a($m['created_at'])); ?></span>
                <?php if ($m['corps']): ?><p class="font-body-sm text-[12px] text-on-surface"><?php echo nl2br(htmlspecialchars($m['corps'])); ?></p><?php endif; ?>
                <?php if ($m['piece_jointe_nom']): ?><a href="<?php echo htmlspecialchars($m['piece_jointe_chemin']); ?>" target="_blank" rel="noopener" class="font-label-md text-[11px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">attach_file</span><?php echo htmlspecialchars($m['piece_jointe_nom']); ?></a><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if ($peutModifier): ?>
            <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="envoyer_message">
              <input type="hidden" name="ancre" value="messages">
              <textarea name="corps_message" rows="2" placeholder="Répondre au client..." class="w-full p-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary resize-none"></textarea>
              <div class="flex items-center gap-space-xs">
                <input type="file" name="piece_jointe_message" class="flex-1 font-body-sm text-[11px] text-secondary">
                <button type="submit" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1 hover:bg-primary transition-colors shrink-0"><span class="material-symbols-outlined text-[16px]">send</span>Envoyer</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="flex flex-col gap-space-sm">
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="vigilance">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Points de vigilance<?php echo $nbVigilanceOuvertes > 0 ? ' (' . $nbVigilanceOuvertes . ' ouverts)' : ''; ?></h2>
            <?php if (!$pointsVigilance): ?>
            <p class="font-body-sm text-body-sm text-secondary">Aucun point de vigilance identifié pour le moment.</p>
            <?php endif; ?>
            <?php foreach ($pointsVigilance as $pv): ?>
            <div class="flex items-start gap-space-xs <?php echo $pv['traite'] ? 'opacity-50' : ''; ?>">
              <div class="w-8 h-8 rounded-lg <?php echo $pv['couleur']; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]"><?php echo $pv['icone']; ?></span></div>
              <div class="flex-1"><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($pv['titre']); ?><?php echo $pv['traite'] ? ' <span class="font-body-sm text-[10px] text-green-700">· traité</span>' : ''; ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($pv['texte']); ?></p></div>
              <?php if ($peutModifier && !$pv['traite']): ?>
              <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="traiter_vigilance">
                <input type="hidden" name="ancre" value="vigilance">
                <input type="hidden" name="vigilance_id" value="<?php echo $pv['id']; ?>">
                <button type="submit" class="h-7 px-space-xs rounded-lg border border-outline-variant font-label-md text-[10px] text-on-surface hover:bg-surface-container-low transition-colors shrink-0">Marquer traité</button>
              </form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($peutModifier): ?>
            <form method="post" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_vigilance">
              <input type="hidden" name="ancre" value="vigilance">
              <select name="categorie_vigilance" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <option value="stress">Stress / impact santé</option>
                <option value="conflit">Conflit relationnel</option>
                <option value="procedure">Procédure sensible</option>
                <option value="urgence">Urgence perçue</option>
                <option value="orientation">Besoin d'orientation</option>
              </select>
              <input type="text" name="titre_vigilance" placeholder="Titre (ex : Stress élevé)" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <input type="text" name="texte_vigilance" placeholder="Précision" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <button type="submit" class="self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[16px]">add</span>Ajouter</button>
            </form>
            <?php endif; ?>
          </div>

          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="plan">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Plan d'action personnalisé</h2>
            <?php if (!$planAction): ?>
            <p class="font-body-sm text-body-sm text-secondary">Aucune action pour le moment.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full min-w-[460px] border-collapse">
                <thead>
                  <tr class="border-b border-outline-variant/60">
                    <th class="text-left font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold py-space-2xs pr-space-xs">Action</th>
                    <th class="text-left font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold py-space-2xs pr-space-xs">Propriétaire</th>
                    <th class="text-left font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold py-space-2xs pr-space-xs">Échéance</th>
                    <th class="text-left font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold py-space-2xs">Statut</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($planAction as $a): [$label, $class] = $actionStatutLabels[$a['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; $enRetard = $a['echeance'] && $a['statut'] !== 'termine' && strtotime($a['echeance']) < strtotime('today'); ?>
                  <tr class="border-b border-outline-variant/40">
                    <td class="py-space-xs pr-space-xs font-body-sm text-[12px] text-on-surface">
                      <span class="flex items-center gap-1"><?php echo htmlspecialchars($a['titre']); ?><?php if ($a['priorite'] === 'haute'): ?><span class="px-1 rounded-full bg-error-container text-error font-label-md text-[9px] font-bold">Prioritaire</span><?php endif; ?></span>
                      <?php if (!empty($a['description'])): ?><span class="block font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($a['description']); ?></span><?php endif; ?>
                    </td>
                    <td class="py-space-xs pr-space-xs font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($a['proprietaire'] ?? '—'); ?></td>
                    <td class="py-space-xs pr-space-xs font-body-sm text-[12px] <?php echo $enRetard ? 'text-error font-bold' : 'text-secondary'; ?>"><?php echo $a['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : '—'; ?></td>
                    <td class="py-space-xs">
                      <?php if ($peutModifier): ?>
                      <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="changer_statut_action">
                        <input type="hidden" name="ancre" value="plan">
                        <input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                        <select name="nouveau_statut" onchange="this.form.submit()" class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold border-0 <?php echo $class; ?>">
                          <?php foreach ($actionStatutLabels as $val => [$lbl, ]): ?>
                          <option value="<?php echo $val; ?>" <?php echo $a['statut'] === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                          <?php endforeach; ?>
                        </select>
                      </form>
                      <?php else: ?>
                      <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $class; ?>"><?php echo $label; ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
            <?php if ($peutModifier): ?>
            <form method="post" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_action">
              <input type="hidden" name="ancre" value="plan">
              <input type="text" name="titre_action" placeholder="Intitulé de l'action..." class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <input type="text" name="description_action" placeholder="Description (optionnel)" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <div class="flex flex-col sm:flex-row gap-space-xs">
                <input type="text" name="proprietaire_action" placeholder="Propriétaire (Consultant/Client)" class="flex-1 h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <input type="date" name="echeance_action" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <select name="priorite_action" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                  <option value="normale">Priorité normale</option>
                  <option value="haute">Priorité haute</option>
                </select>
                <button type="submit" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center justify-center gap-1 shrink-0"><span class="material-symbols-outlined text-[14px]">add</span>Ajouter</button>
              </div>
            </form>
            <?php endif; ?>
          </div>

          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <div class="flex items-center justify-between">
              <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Orientation externe</h2>
              <span class="px-space-xs py-0.5 rounded-full bg-primary-container/10 text-primary font-label-md text-[10px] font-bold">Recommandation, pas une prise de RDV confirmée</span>
            </div>
            <div class="grid grid-cols-2 gap-space-sm">
              <?php foreach ($orientationsDef as $type => $o): $actif = $orientationsBrutes[$type] ?? false; ?>
              <form method="post" class="flex flex-col items-center text-center gap-space-2xs p-space-sm rounded-xl border border-outline-variant/60">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="toggle_orientation">
                <input type="hidden" name="ancre" value="">
                <input type="hidden" name="type_orientation" value="<?php echo $type; ?>">
                <span class="material-symbols-outlined text-[22px] text-primary"><?php echo $o['icone']; ?></span>
                <p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($o['titre']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($o['texte']); ?></p>
                <button type="submit" <?php echo $peutModifier ? '' : 'disabled'; ?> class="relative w-10 h-6 rounded-full transition-colors <?php echo $actif ? 'bg-primary-container' : 'bg-outline-variant'; ?> <?php echo $peutModifier ? '' : 'opacity-60 cursor-not-allowed'; ?>">
                  <span class="absolute top-0.5 <?php echo $actif ? 'left-[18px]' : 'left-0.5'; ?> w-5 h-5 rounded-full bg-white shadow transition-all"></span>
                </button>
                <span class="font-body-sm text-[10px] text-secondary"><?php echo $actif ? 'Recommandée' : 'Recommander'; ?></span>
              </form>
              <?php endforeach; ?>
            </div>
            <p class="flex items-center gap-1 font-body-sm text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px]">info</span>Les recommandations seront incluses dans le plan transmis au client.</p>
          </div>
        </div>

        <div class="flex flex-col gap-space-sm">
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="documents">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Analyse des documents</h2>
            <?php if (!$documentsAttendus): ?>
            <p class="font-body-sm text-body-sm text-secondary">Le client n'a indiqué aucun document disponible.</p>
            <?php endif; ?>
            <?php foreach ($documentsAttendus as $categorie): $recu = !empty($documentsParCategorie[$categorie]); ?>
            <div class="flex items-center justify-between">
              <span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($categorie); ?></span>
              <span class="flex items-center gap-space-sm">
                <span class="flex items-center gap-1 font-label-md text-[11px] font-semibold <?php echo $recu ? 'text-green-600' : 'text-error'; ?>"><?php echo $recu ? 'Reçu' : 'Non reçu'; ?> <span class="material-symbols-outlined text-[14px]"><?php echo $recu ? 'check_circle' : 'cancel'; ?></span></span>
                <?php if ($recu): ?>
                <a href="<?php echo htmlspecialchars($documentsParCategorie[$categorie][0]['chemin']); ?>" target="_blank" rel="noopener" class="h-7 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface hover:bg-surface-container-low transition-colors">Voir</a>
                <?php endif; ?>
              </span>
            </div>
            <?php endforeach; ?>
            <?php if ($peutModifier): ?>
            <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_document">
              <input type="hidden" name="ancre" value="documents">
              <select name="categorie_document" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <?php foreach (($documentsAttendus ?: ['Contrat de travail', 'Emails', 'Échanges RH', 'Captures (messages, outils internes)', 'Témoignages', 'Autres documents']) as $categorie): ?>
                <option value="<?php echo htmlspecialchars($categorie); ?>"><?php echo htmlspecialchars($categorie); ?></option>
                <?php endforeach; ?>
              </select>
              <input type="file" name="fichier_document" class="font-body-sm text-[12px] text-secondary">
              <button type="submit" class="self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[16px]">upload</span>Ajouter un document</button>
            </form>
            <?php endif; ?>
          </div>

          <?php if ($peutModifier): ?>
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Notes de session <span class="font-body-sm text-[10px] text-secondary font-normal">(privées — jamais visibles par le client)</span></h2>
            <form method="post" id="notesForm" class="flex flex-col gap-space-xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="enregistrer_notes">
              <input type="hidden" name="ancre" value="">
              <textarea name="notes_consultant" rows="4" placeholder="Vos observations sur ce dossier..." class="w-full font-body-sm text-[12px] text-on-surface border border-outline-variant rounded-lg p-space-xs focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars($dossier['notes_consultant'] ?? ''); ?></textarea>
              <button type="submit" class="self-start h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface hover:bg-surface-container-low transition-colors">Enregistrer</button>
            </form>
            <p class="font-body-sm text-[10px] text-secondary">Dernière mise à jour : <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($dossier['updated_at']))); ?></p>
          </div>
          <?php endif; ?>

          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Prochaines étapes</h2>
            <?php if (!$prochainesEtapes): ?>
            <p class="font-body-sm text-body-sm text-secondary">Aucune échéance à venir.</p>
            <?php endif; ?>
            <?php foreach ($prochainesEtapes as $e): ?>
            <div class="flex items-center justify-between">
              <span class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-primary">check</span><?php echo htmlspecialchars($e['titre']); ?></span>
              <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo $e['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($e['echeance']))) : '—'; ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($documentsManquants): ?>
            <div class="flex items-start gap-1 pt-space-xs border-t border-outline-variant/40"><span class="material-symbols-outlined text-[14px] text-orange-600 mt-0.5">description</span><span class="font-body-sm text-[12px] text-on-surface">Documents manquants : <?php echo htmlspecialchars(implode(', ', $documentsManquants)); ?></span></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <?php if ($peutModifier): ?>
        <form method="post" onsubmit="return confirm('Valider le diagnostic de ce dossier ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="valider_diagnostic"><input type="hidden" name="ancre" value="fiche">
          <button type="submit" class="w-full h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[18px]"><?php echo $diagnosticValide ? 'task_alt' : 'radio_button_unchecked'; ?></span><?php echo $diagnosticValide ? 'Diagnostic validé' : 'Valider le diagnostic'; ?></button>
        </form>
        <div class="flex flex-col gap-1">
          <form method="post" onsubmit="return confirm('Générer et transmettre le compte rendu au client ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generer_compte_rendu"><input type="hidden" name="ancre" value="">
            <button type="submit" <?php echo $diagnosticValide ? '' : 'disabled title="Validez le diagnostic avant de générer le compte rendu"'; ?> class="w-full h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity <?php echo $diagnosticValide ? '' : 'opacity-50 cursor-not-allowed'; ?>"><span class="material-symbols-outlined text-[18px]">description</span>Générer le compte rendu</button>
          </form>
          <a href="compte-rendu.php?dossier=<?php echo $dossierId; ?>" target="_blank" rel="noopener" class="self-center font-label-md text-[11px] text-primary">Prévisualiser</a>
          <a href="consultant-compte-rendu-form.php?dossier=<?php echo $dossierId; ?>" class="self-center font-label-md text-[11px] text-secondary hover:text-primary">+ Compte rendu structuré</a>
        </div>
        <form method="post" onsubmit="return confirm('Envoyer le plan d\'action (<?php echo count($planAction); ?> action(s)) au client ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="envoyer_plan"><input type="hidden" name="ancre" value="plan">
          <button type="submit" class="w-full h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[18px]">mail</span>Envoyer le plan au client</button>
        </form>
        <button type="submit" form="notesForm" class="h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[18px]">save</span>Enregistrer les notes</button>
        <?php endif; ?>
      </div>

    </main>

    <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
      <div class="flex flex-col gap-space-md">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-space-md">
          <div class="col-span-2 md:col-span-1 flex flex-col gap-space-xs">
            <span class="flex items-center gap-1.5"><span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span></span>
            <p class="font-body-sm text-[11px] text-inverse-on-surface/70">Accompagner, sécuriser, faire grandir.</p>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <h4 class="font-label-md text-[11px] uppercase tracking-wider text-inverse-primary font-bold">À propos</h4>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="#">Qui sommes-nous ?</a>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <h4 class="font-label-md text-[11px] uppercase tracking-wider text-inverse-primary font-bold">CGU</h4>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="#">Conditions générales d'utilisation</a>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <h4 class="font-label-md text-[11px] uppercase tracking-wider text-inverse-primary font-bold">Confidentialité</h4>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="#">Politique de confidentialité</a>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <h4 class="font-label-md text-[11px] uppercase tracking-wider text-inverse-primary font-bold">Contact</h4>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="contact.php">Nous contacter</a>
          </div>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-xs pt-space-sm border-t border-white/10">
          <span class="font-body-sm text-[11px] text-inverse-on-surface/60">© <?php echo date('Y'); ?> NexRaiz – Tous droits réservés</span>
        </div>
      </div>
    </footer>
  </div>
</div>
<script>
(function() {
  document.querySelectorAll('.edit-toggle, .history-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var showId = btn.getAttribute('data-target') || btn.getAttribute('data-show');
      var hideId = btn.getAttribute('data-hide');
      if (showId) {
        var el = document.getElementById(showId);
        if (el) { el.classList.toggle('hidden'); el.classList.toggle('flex'); }
      }
      if (hideId) {
        var elHide = document.getElementById(hideId);
        if (elHide) { elHide.classList.add('hidden'); }
      }
      if (btn.classList.contains('history-toggle')) { btn.classList.add('hidden'); }
    });
  });
})();
</script>
</body>
</html>
