<?php
/**
 * NexRaiz — Passerelle de téléchargement sécurisée pour les fichiers de
 * ressources (ressources.fichier_chemin). Toute requête vérifie la session
 * ET le rôle avant de servir le fichier — une ressource archivée n'est
 * accessible qu'aux administrateurs, jamais par simple connaissance de
 * l'identifiant.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ressources.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$estAdmin = in_array($consultant['role'], ['admin', 'super_admin'], true);
$ressourceId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM ressources WHERE id = ?');
$stmt->execute([$ressourceId]);
$ressource = $stmt->fetch();

if (!$ressource || !$ressource['fichier_chemin']) {
    http_response_code(404);
    exit('Fichier introuvable.');
}
if ($ressource['statut'] !== 'publie' && !$estAdmin) {
    http_response_code(403);
    exit('Accès non autorisé.');
}
if (!str_starts_with($ressource['fichier_chemin'], 'prive:')) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$cheminAbsolu = ressource_chemin_absolu($ressource['fichier_chemin']);
$racineReelle = realpath(ressources_stockage_prive_racine());
$cheminReel = realpath($cheminAbsolu);
if ($cheminReel === false || $racineReelle === false || !str_starts_with($cheminReel, $racineReelle)) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$typesMime = [
    'pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
];
$extension = $ressource['fichier_extension'] ?? strtolower(pathinfo($ressource['fichier_nom_original'] ?? '', PATHINFO_EXTENSION));

header('Content-Type: ' . ($typesMime[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($cheminReel));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $ressource['fichier_nom_original'] ?? 'ressource') . '"');
header('X-Content-Type-Options: nosniff');
readfile($cheminReel);
exit;
