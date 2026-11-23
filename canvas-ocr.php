<?php
/**
 * Endpoint JSON (AJAX) : OCR d'une page de document scanné/aplati pour
 * l'éditeur visuel "Canva" (Pack Classic). Le navigateur a déjà rendu la
 * page en image via PDF.js (aucune rasterisation PDF côté serveur
 * nécessaire, et aucune bibliothèque de ce type n'est installée) ; cet
 * endpoint lance Tesseract sur cette image et renvoie les lignes de texte
 * détectées avec leurs coordonnées et un score de confiance, pour les
 * présenter comme des blocs modifiables — jamais appliqué automatiquement,
 * le consultant reste décisionnaire sur chaque zone.
 *
 * Aucun fichier n'est conservé après traitement (image temporaire et sortie
 * Tesseract supprimées, succès ou échec).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ocr.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

require_csrf();

$dossierId = (int) ($_POST['dossier_id'] ?? 0);
$imageBase64 = $_POST['image_base64'] ?? '';

$packId = get_pack_id('classic');
$stmt = db()->prepare('SELECT id FROM dossiers WHERE id = ? AND pack_id = ?');
$stmt->execute([$dossierId, $packId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Dossier introuvable.']);
    exit;
}

if (!ocr_disponible()) {
    http_response_code(503);
    echo json_encode(['error' => "La reconnaissance optique (OCR) n'est pas disponible sur ce serveur. Ce document devra être corrigé manuellement, sans détection automatique des zones."]);
    exit;
}

// Accepte "data:image/png;base64,...." ou le base64 nu.
if (str_contains($imageBase64, ',')) {
    $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
}
$imageBrute = base64_decode($imageBase64, true);
if ($imageBrute === false || substr($imageBrute, 0, 8) !== "\x89PNG\x0d\x0a\x1a\x0a") {
    http_response_code(400);
    echo json_encode(['error' => 'Image invalide (PNG attendu).']);
    exit;
}
$tailleMaxOctets = 12 * 1024 * 1024;
if (strlen($imageBrute) > $tailleMaxOctets) {
    http_response_code(400);
    echo json_encode(['error' => 'Image trop volumineuse pour l\'OCR (12 Mo maximum).']);
    exit;
}

$dossierTemp = sys_get_temp_dir() . '/nexraiz_ocr';
if (!is_dir($dossierTemp)) {
    mkdir($dossierTemp, 0700, true);
}
$cheminImage = $dossierTemp . '/' . bin2hex(random_bytes(16)) . '.png';
file_put_contents($cheminImage, $imageBrute);

try {
    $mots = ocr_image_vers_mots($cheminImage);
    $lignes = ocr_mots_vers_lignes($mots);
    echo json_encode(['ok' => true, 'lignes' => $lignes]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    // Ne jamais laisser l'image temporaire sur le disque, succès ou échec.
    @unlink($cheminImage);
}
