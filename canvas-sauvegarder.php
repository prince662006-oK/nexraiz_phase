<?php
/**
 * Endpoint JSON (AJAX) : enregistre le brouillon de l'éditeur visuel
 * "Canva" (Pack Classic) — la liste des blocs de texte modifiés par le
 * consultant sur le document original. Repasse toujours l'édition en
 * statut "brouillon" : voir includes/canvas-editions.php.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/canvas-editions.php';

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
$editsBrut = $_POST['edits'] ?? '';

if (!in_array($typeDocument, ['cv', 'lettre'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de document invalide.']);
    exit;
}

$packId = get_pack_id('classic');
$stmt = db()->prepare('SELECT id FROM dossiers WHERE id = ? AND pack_id = ?');
$stmt->execute([$dossierId, $packId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Dossier introuvable.']);
    exit;
}

// Le document source n'est jamais pris tel quel depuis le navigateur (le
// client envoyait jusqu'ici l'URL de la passerelle sécurisée, qui ne
// correspondait plus au format autrefois attendu ici — toute sauvegarde
// échouait silencieusement). On redérive la vraie référence côté serveur,
// exactement comme consultant-canvas-editeur.php : seul le document
// réellement associé à ce dossier/type peut être enregistré comme source.
$stmt = db()->prepare('SELECT id, chemin FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$dossierId]);
$documentSource = null;
foreach ($stmt->fetchAll() as $d) {
    if (strpos($d['chemin'], '/' . $typeDocument . '/') !== false) {
        $documentSource = $d;
        break;
    }
}
if (!$documentSource) {
    http_response_code(404);
    echo json_encode(['error' => 'Document source introuvable pour ce dossier.']);
    exit;
}
$sourceChemin = 'classic-document-telecharger.php?id=' . $documentSource['id'];

$edits = json_decode($editsBrut, true);
if (!is_array($edits)) {
    http_response_code(400);
    echo json_encode(['error' => 'Données de brouillon invalides.']);
    exit;
}

canvas_edition_sauvegarder($dossierId, $typeDocument, $sourceChemin, $edits, (int) $consultant['id']);
echo json_encode(['ok' => true, 'enregistre_le' => date('H:i')]);
