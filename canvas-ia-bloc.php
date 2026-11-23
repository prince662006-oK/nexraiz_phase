<?php
/**
 * Endpoint JSON (AJAX) : propose une amélioration IA (Prince AI / Groq)
 * d'un seul bloc de texte sélectionné dans l'éditeur visuel "Canva"
 * (Pack Classic). Ne modifie jamais rien côté serveur : la proposition est
 * renvoyée au navigateur, qui l'affiche pour acceptation, modification ou
 * refus par le consultant avant toute application au brouillon.
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
$texte = trim($_POST['texte'] ?? '');
$instruction = trim($_POST['instruction'] ?? '');

$packId = get_pack_id('classic');
$stmt = db()->prepare('SELECT id FROM dossiers WHERE id = ? AND pack_id = ?');
$stmt->execute([$dossierId, $packId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Dossier introuvable.']);
    exit;
}

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$contexte = json_decode((string) $stmt->fetchColumn(), true) ?: [];

try {
    $propose = groq_ameliorer_bloc($texte, $contexte, $instruction);
    echo json_encode(['texte' => $propose]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
