<?php
/**
 * NexRaiz — Prévisualisation à la demande d'une correction CV/lettre, Pack
 * Classic : génère un PDF à partir du texte actuellement dans l'éditeur
 * (pas encore enregistré), pour que le consultant voie le rendu final
 * avant de sauvegarder. N'écrit jamais rien en base ni sur disque — le PDF
 * est streamé directement, jamais stocké (pas de livrable créé/modifié).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pdf-cv.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}
require_csrf();

$dossierId = (int) ($_POST['dossier_id'] ?? 0);
$typeDocument = $_POST['document_type'] ?? '';
$contenuCorrige = trim($_POST['contenu_corrige'] ?? '');

if (!in_array($typeDocument, ['cv', 'lettre'], true)) {
    http_response_code(400);
    exit('Type de document invalide.');
}
if ($contenuCorrige === '') {
    http_response_code(400);
    exit('Rien à prévisualiser : la version corrigée est vide.');
}

$packId = get_pack_id('classic');
$stmt = db()->prepare('SELECT ds.id, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?');
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    http_response_code(404);
    exit('Dossier introuvable.');
}

$titreDocument = $typeDocument === 'lettre' ? 'Lettre de motivation' : 'CV';
$html = construire_html_correction($titreDocument, $dossier['prenom'] . ' ' . $dossier['nom'], $contenuCorrige);

$dompdf = new \Dompdf\Dompdf([
    'isRemoteEnabled' => false,
    'isPhpEnabled'    => true,
    'defaultFont'     => 'DejaVu Sans',
]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="apercu.pdf"');
echo $dompdf->output();
