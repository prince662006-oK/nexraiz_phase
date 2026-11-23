<?php
/**
 * Endpoint JSON (AJAX) : propose une amélioration IA (Groq) du contenu d'un
 * CV ou d'une lettre de motivation, Pack Classic. Ne modifie jamais la base
 * de données — le texte renvoyé remplit simplement le champ "version
 * corrigée" dans le navigateur du consultant, qui doit ensuite relire et
 * cliquer sur "Enregistrer cette version" pour l'appliquer réellement.
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
$tonalite = trim($_POST['tonalite'] ?? '');

if (!in_array($typeDocument, ['cv', 'lettre', 'linkedin'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de document invalide.']);
    exit;
}

$packId = get_pack_id('classic');
$stmt = db()->prepare('SELECT id, user_id FROM dossiers WHERE id = ? AND pack_id = ?');
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
    $texteAmeliore = groq_ameliorer_document($typeDocument, $contenu, $contexte, $tonalite);
    echo json_encode(['texte' => $texteAmeliore]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
