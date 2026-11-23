<?php
/**
 * NexRaiz — Passerelle de téléchargement sécurisée pour les documents
 * déposés via nx_enregistrer_document_client() (Pack Assistance, Pack
 * Booster, Pack Wellbeing — tout pack utilisant le dépôt générique
 * includes/documents.php, par opposition au dépôt dédié du Pack Classic qui
 * a sa propre passerelle classic-document-telecharger.php).
 *
 * Vérifie toujours, côté serveur, que l'utilisateur connecté (client
 * propriétaire du dossier, ou consultant/admin) est autorisé avant de
 * servir le fichier — jamais une confiance aveugle dans l'identifiant
 * transmis dans l'URL.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';

$utilisateur = require_login('mon-espace.php');
$documentId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT d.*, ds.user_id, pk.slug AS pack_slug
     FROM documents d
     JOIN dossiers ds ON ds.id = d.dossier_id
     JOIN packs pk ON pk.id = ds.pack_id
     WHERE d.id = ?'
);
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
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
    $cheminAbsolu = nx_document_client_chemin_absolu($document['chemin'], $document['pack_slug']);
    $racineReelle = realpath(nx_documents_stockage_prive_racine($document['pack_slug']));
    $cheminReel = realpath($cheminAbsolu);
    // Le chemin résolu doit rester strictement sous la racine de stockage —
    // défense en profondeur même si $chemin provient de la base, jamais de
    // l'URL directement.
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

// Chemin public historique (uploads/...) : dépôt antérieur à la migration
// vers le stockage privé, non déplacé rétroactivement — limite connue.
header('Location: ' . $document['chemin']);
exit;
