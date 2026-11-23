<?php
/**
 * NexRaiz — Passerelle de téléchargement sécurisée pour les documents du
 * Pack Classic. Vérifie toujours, côté serveur, que l'utilisateur connecté
 * (client propriétaire du dossier, ou consultant/admin) est autorisé avant
 * de servir le fichier — jamais une confiance aveugle dans l'identifiant
 * transmis dans l'URL.
 *
 * Gère deux cas de stockage : "prive:..." (nouveaux dépôts, hors webroot,
 * lus et streamés par ce script) et l'ancien chemin public "uploads/..."
 * (dépôts antérieurs à ce changement, non migrés) — redirigé tel quel,
 * limite connue et signalée dans la livraison.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents-classic.php';

$utilisateur = require_login('mon-espace.php');
$documentId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT d.*, ds.user_id, ds.pack_id, pk.slug AS pack_slug
     FROM documents d
     JOIN dossiers ds ON ds.id = d.dossier_id
     JOIN packs pk ON pk.id = ds.pack_id
     WHERE d.id = ?'
);
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document || $document['pack_slug'] !== 'classic') {
    http_response_code(404);
    exit('Document introuvable.');
}

$estProprietaire = (int) $document['user_id'] === (int) $utilisateur['id'];
$estEquipe = in_array($utilisateur['role'], ['consultant', 'admin', 'super_admin'], true);
if (!$estProprietaire && !$estEquipe) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

if (str_starts_with($document['chemin'], 'prive:')) {
    $cheminRelatif = substr($document['chemin'], strlen('prive:'));
    $cheminAbsolu = classic_stockage_prive_racine() . '/' . $cheminRelatif;
    $racineReelle = realpath(classic_stockage_prive_racine());
    $cheminReel = realpath($cheminAbsolu);
    // Le chemin résolu doit rester strictement sous la racine de stockage —
    // empêche toute évasion de répertoire même si $cheminRelatif était
    // altéré (il provient de la base, jamais de l'URL, mais on vérifie
    // quand même par défense en profondeur).
    if ($cheminReel === false || $racineReelle === false || !str_starts_with($cheminReel, $racineReelle)) {
        http_response_code(404);
        exit('Fichier introuvable.');
    }

    $extension = strtolower(pathinfo($document['nom_original'], PATHINFO_EXTENSION));
    $typesMime = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    header('Content-Type: ' . ($typesMime[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($cheminReel));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $document['nom_original']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($cheminReel);
    exit;
}

// Chemin public historique (uploads/...) : pas de contrôle d'accès
// supplémentaire possible sans déplacer le fichier — limite connue.
header('Location: ' . $document['chemin']);
exit;
