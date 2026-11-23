<?php
/**
 * NexRaiz — Passerelle de consultation/téléchargement sécurisée pour les
 * livrables du Pack Classic. Vérifie toujours côté serveur que
 * l'utilisateur est le propriétaire du dossier (ou un membre autorisé de
 * l'équipe) ET que le statut du livrable autorise sa consultation — un
 * livrable "en_preparation" n'est jamais accessible au client, quel que
 * soit l'identifiant transmis dans l'URL.
 *
 * mode=apercu : affichage intégré (Content-Disposition inline), pour une
 * prévisualisation PDF dans le navigateur. mode=telecharger (défaut) :
 * téléchargement forcé. Chaque consultation réelle par le client (jamais
 * par l'équipe) incrémente le compteur de consultations.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/livrables-classic.php';

$utilisateur = require_login('mon-espace.php');
$livrableId = (int) ($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'telecharger') === 'apercu' ? 'apercu' : 'telecharger';

$stmt = db()->prepare(
    'SELECT l.*, ds.user_id, ds.pack_id, pk.slug AS pack_slug
     FROM livrables l
     JOIN dossiers ds ON ds.id = l.dossier_id
     JOIN packs pk ON pk.id = ds.pack_id
     WHERE l.id = ?'
);
$stmt->execute([$livrableId]);
$livrable = $stmt->fetch();

if (!$livrable || $livrable['pack_slug'] !== 'classic') {
    http_response_code(404);
    exit('Livrable introuvable.');
}

$estProprietaire = (int) $livrable['user_id'] === (int) $utilisateur['id'];
$estEquipe = in_array($utilisateur['role'], ['consultant', 'admin', 'super_admin'], true);

if ($estProprietaire && ($livrable['statut'] !== 'disponible' || (int) $livrable['est_version_courante'] !== 1)) {
    // Le client ne peut jamais consulter un livrable non publié, même le
    // sien — "en préparation" reste invisible tant que le consultant ne
    // l'a pas explicitement envoyé. Il ne peut pas non plus accéder à une
    // ancienne version remplacée (même identifiant connu par un lien ou un
    // historique de navigateur) : seule la version courante lui est
    // montrée. L'équipe, elle, peut consulter l'historique complet.
    http_response_code(403);
    exit('Ce livrable n\'est pas (ou plus) disponible.');
}
if (!$estProprietaire && !$estEquipe) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

if ($estProprietaire) {
    classic_enregistrer_consultation_livrable($livrableId);
}

if (str_starts_with($livrable['chemin'], 'prive:')) {
    $cheminAbsolu = classic_livrable_chemin_absolu($livrable['chemin']);
    $racineReelle = realpath(classic_livrables_stockage_prive_racine());
    $cheminReel = realpath($cheminAbsolu);
    if ($cheminReel === false || $racineReelle === false || !str_starts_with($cheminReel, $racineReelle)) {
        http_response_code(404);
        exit('Fichier introuvable.');
    }

    $extension = strtolower(pathinfo($livrable['nom_original'], PATHINFO_EXTENSION));
    $typesMime = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    $disposition = $mode === 'apercu' ? 'inline' : 'attachment';

    header('Content-Type: ' . ($typesMime[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($cheminReel));
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $livrable['nom_original']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($cheminReel);
    exit;
}

if (str_starts_with($livrable['chemin'], 'correction-document.php')) {
    // Marqueur interne (livrable "CV/lettre corrigé" pas encore converti en
    // vrai PDF) : ne devrait jamais être atteignable ici puisqu'un livrable
    // "disponible" a toujours déjà été converti par classic_publier_livrable()
    // — garde de sécurité si cet invariant venait à être rompu.
    http_response_code(404);
    exit('Fichier introuvable.');
}

// Chemin public historique (uploads/...) : redirection telle quelle, pas de
// contrôle d'accès supplémentaire possible sans déplacer le fichier.
header('Location: ' . $livrable['chemin']);
exit;
