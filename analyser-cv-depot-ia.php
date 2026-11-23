<?php
/**
 * Endpoint JSON (AJAX) : analyse IA informative d'un document CV déjà
 * déposé (module "Déposer des documents", Pack Classic). N'écrit jamais
 * rien en base — résultat affiché au consultant pour information, jamais
 * appliqué automatiquement aux réponses du candidat.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents-classic.php';
require_once __DIR__ . '/includes/extraction.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

require_csrf();

$documentId = (int) ($_POST['document_id'] ?? 0);

$packId = get_pack_id('classic');
$stmt = db()->prepare(
    'SELECT d.id, d.chemin, d.nom_original, d.categorie FROM documents d
     JOIN dossiers ds ON ds.id = d.dossier_id
     WHERE d.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$documentId, $packId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    echo json_encode(['error' => 'Document introuvable.']);
    exit;
}
// Catégorie réelle si renseignée (dépôts récents), sinon déduite du chemin
// (dépôts antérieurs à l'ajout de la colonne categorie) — même repli que
// includes/consultant-classic.php pour rester cohérent avec le reste du
// Pack Classic.
$estUnCv = $document['categorie'] === 'cv' || (!$document['categorie'] && strpos($document['chemin'], '/cv/') !== false);
if (!$estUnCv) {
    http_response_code(400);
    echo json_encode(['error' => "L'analyse n'est disponible que pour le document CV."]);
    exit;
}

$cheminAbsolu = classic_chemin_absolu_fichier($document['chemin']);
$extension = pathinfo($document['nom_original'], PATHINFO_EXTENSION);
$texte = extraire_texte_document($cheminAbsolu, $extension);
if ($texte === null || trim($texte) === '') {
    http_response_code(422);
    echo json_encode(['error' => "Impossible d'extraire le texte de ce fichier (format non pris en charge pour l'extraction automatique, ou document scanné sans texte)."]);
    exit;
}

try {
    $resultat = groq_extraire_infos_cv($texte);
    echo json_encode($resultat);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
