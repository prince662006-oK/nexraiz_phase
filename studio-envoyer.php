<?php
/**
 * Endpoint JSON (AJAX) : "Transmettre au candidat" en un clic depuis le
 * studio de correction (Pack Classic). Enchaîne exactement les mêmes
 * étapes que le flux existant de consultant-corrections-classic.php
 * (action "enregistrer_correction" puis "envoyer_livrable") pour ne pas
 * dupliquer de logique de publication : enregistre la version courante,
 * crée le livrable au premier envoi, génère le PDF mis en forme, puis le
 * rend disponible au candidat.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/livrables-classic.php';
require_once __DIR__ . '/includes/pdf-cv.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

require_csrf();

$dossierId = (int) ($_POST['dossier_id'] ?? 0);
$typeDocument = $_POST['document_type'] ?? '';
$contenuInitial = trim($_POST['contenu_initial'] ?? '');
$contenuCorrige = trim($_POST['contenu_corrige'] ?? '');

if (!in_array($typeDocument, ['cv', 'lettre'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de document invalide.']);
    exit;
}
if ($contenuCorrige === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Le document est vide.']);
    exit;
}

$packId = get_pack_id('classic');
$stmt = db()->prepare(
    'SELECT ds.id, ds.user_id, u.prenom, u.nom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    http_response_code(404);
    echo json_encode(['error' => 'Dossier introuvable.']);
    exit;
}

// La checklist qualité n'est pas gérée depuis le Studio : on reporte celle
// de la version précédente plutôt que de la remettre à zéro (voir la même
// règle dans consultant-corrections-classic.php).
$stmt = db()->prepare('SELECT checklist_json FROM corrections WHERE dossier_id = ? AND document_type = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$dossierId, $typeDocument]);
$checklistJson = $stmt->fetchColumn() ?: null;

$stmt = db()->prepare('INSERT INTO corrections (dossier_id, consultant_id, document_type, contenu_initial, contenu_corrige, checklist_json) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$dossierId, $consultant['id'], $typeDocument, $contenuInitial, $contenuCorrige, $checklistJson]);
log_activite($dossierId, 'Correction enregistrée (studio) : ' . $typeDocument);

$titreLivrable = $typeDocument === 'cv' ? 'CV corrigé' : 'Lettre de motivation corrigée';
$stmt = db()->prepare('SELECT id, statut FROM livrables WHERE dossier_id = ? AND titre = ? AND est_version_courante = 1 ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId, $titreLivrable]);
$livrable = $stmt->fetch();

$pdfGenere = generer_pdf_correction($dossierId, $typeDocument, $dossier['prenom'], $dossier['nom']);
if (!$pdfGenere) {
    http_response_code(400);
    echo json_encode(['error' => 'Aucun contenu à publier.']);
    exit;
}
$nouveauFichier = ['chemin' => $pdfGenere['chemin'], 'nom_original' => $pdfGenere['nom'], 'taille_ko' => $pdfGenere['taille_ko']];

if ($livrable) {
    classic_publier_livrable((int) $livrable['id'], $dossierId, $nouveauFichier);
} else {
    $livrableId = classic_creer_ou_maj_brouillon_livrable($dossierId, $titreLivrable, $nouveauFichier['nom_original'], $nouveauFichier['chemin'], $nouveauFichier['taille_ko'], classic_livrable_categorie_auto($titreLivrable));
    classic_publier_livrable($livrableId, $dossierId);
}

echo json_encode(['ok' => true]);
