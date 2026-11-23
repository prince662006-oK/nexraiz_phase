<?php
/**
 * Endpoint JSON (AJAX) : liste de suggestions IA prêtes à appliquer (studio
 * de correction, Pack Classic). Chaque suggestion propose un remplacement
 * pour un passage précis ; "Appliquer" côté client ne fait qu'un
 * remplacement local dans l'éditeur, jamais une réécriture automatique.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

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
$contenu = trim($_POST['contenu'] ?? '');

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

try {
    $suggestions = groq_suggestions_document($typeDocument, $contenu);
    echo json_encode(['suggestions' => $suggestions]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
