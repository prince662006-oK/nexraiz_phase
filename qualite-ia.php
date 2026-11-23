<?php
/**
 * Endpoint JSON (AJAX) : score de qualité indicatif (studio de correction,
 * Pack Classic) — purement informatif, n'influence aucune décision
 * automatique.
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
    $qualite = groq_evaluer_qualite($typeDocument, $contenu);
    echo json_encode($qualite);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
