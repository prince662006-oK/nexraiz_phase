<?php
/**
 * Endpoint JSON (AJAX) : assistance IA pour le formulaire de compte rendu
 * structuré (Pack Assistance). Ne modifie jamais rien en base — le texte ou
 * les actions renvoyés restent à relire et à insérer manuellement côté
 * client (voir consultant-compte-rendu-form.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ai.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

require_csrf();

$dossierId = (int) ($_POST['dossier_id'] ?? 0);
$mode = $_POST['mode'] ?? '';
$contenu = trim($_POST['contenu'] ?? '');

if (!in_array($mode, ['reformulation', 'actions-suggerees'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Mode invalide.']);
    exit;
}

$packId = get_pack_id('assistance');
$condVisibilite = $voitTout ? '' : ' AND (consultant_id = ? OR consultant_id IS NULL)';
$paramVisibilite = $voitTout ? [] : [$consultantId];
$stmt = db()->prepare("SELECT id FROM dossiers WHERE id = ? AND pack_id = ?{$condVisibilite}");
$stmt->execute(array_merge([$dossierId, $packId], $paramVisibilite));
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Dossier introuvable ou non accessible.']);
    exit;
}

try {
    $resultat = groq_assister_compte_rendu($mode, $contenu);
    echo json_encode($mode === 'reformulation' ? ['texte' => $resultat] : ['actions' => $resultat]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
