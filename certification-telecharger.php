<?php
/**
 * NexRaiz — Passerelle de téléchargement sécurisée pour les justificatifs
 * de certification externe (certifications_externes.justificatif_chemin).
 * Vérifie toujours que l'utilisateur connecté est le propriétaire de la
 * certification ou un administrateur avant de servir le fichier.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/formations.php';

$utilisateur = require_login('mon-espace.php');
$certificationId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM certifications_externes WHERE id = ?');
$stmt->execute([$certificationId]);
$certification = $stmt->fetch();

if (!$certification || !$certification['justificatif_chemin']) {
    http_response_code(404);
    exit('Justificatif introuvable.');
}

$estProprietaire = (int) $certification['user_id'] === (int) $utilisateur['id'];
$estAdmin = in_array($utilisateur['role'], ['admin', 'super_admin'], true);
if (!$estProprietaire && !$estAdmin) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

$chemin = $certification['justificatif_chemin'];
if (!str_starts_with($chemin, 'prive:')) {
    http_response_code(404);
    exit('Justificatif introuvable.');
}

$cheminAbsolu = formations_stockage_prive_racine() . '/' . substr($chemin, strlen('prive:'));
$racineReelle = realpath(formations_stockage_prive_racine());
$cheminReel = realpath($cheminAbsolu);
if ($cheminReel === false || $racineReelle === false || !str_starts_with($cheminReel, $racineReelle)) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$extension = strtolower(pathinfo($certification['justificatif_nom'] ?? '', PATHINFO_EXTENSION));
$typesMime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

header('Content-Type: ' . ($typesMime[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($cheminReel));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $certification['justificatif_nom'] ?? 'justificatif') . '"');
header('X-Content-Type-Options: nosniff');
readfile($cheminReel);
exit;
