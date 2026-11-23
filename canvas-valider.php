<?php
/**
 * Endpoint JSON (AJAX) : publication du document final de l'éditeur visuel
 * "Canva" (Pack Classic). Contrairement à l'ancienne version (PDF déjà
 * construit côté navigateur avec pdf-lib, qui ne faisait que dessiner un
 * correctif par-dessus le texte d'origine), cet endpoint reçoit la liste
 * structurée des blocs modifiés et appelle le pont Python/PyMuPDF
 * (includes/pdf-editeur-python.php) qui supprime réellement le texte
 * d'origine du flux du PDF avant d'insérer le nouveau — vérifié par
 * extraction de texte après traitement, voir la suite de tests associée.
 * Le document final est ensuite publié comme livrable candidat via le
 * mécanisme existant (includes/livrables-classic.php) — jamais de nouveau
 * canal de publication.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/livrables-classic.php';
require_once __DIR__ . '/includes/canvas-editions.php';
require_once __DIR__ . '/includes/pdf-editeur-python.php';

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
$editsBrut = $_POST['edits'] ?? '';

if (!in_array($typeDocument, ['cv', 'lettre'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de document invalide.']);
    exit;
}

$packId = get_pack_id('classic');
$stmt = db()->prepare(
    'SELECT ds.id, ds.user_id, u.prenom, u.nom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    http_response_code(404);
    echo json_encode(['error' => 'Dossier introuvable.']);
    exit;
}

// Document source redérivé côté serveur, jamais fourni par le navigateur —
// même règle que canvas-sauvegarder.php.
$stmt = db()->prepare('SELECT id, chemin, nom_original FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$dossierId]);
$documentSource = null;
foreach ($stmt->fetchAll() as $d) {
    if (strpos($d['chemin'], '/' . $typeDocument . '/') !== false) {
        $documentSource = $d;
        break;
    }
}
if (!$documentSource || strtolower(pathinfo($documentSource['nom_original'], PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(404);
    echo json_encode(['error' => 'Document source introuvable ou non PDF.']);
    exit;
}
require_once __DIR__ . '/includes/documents-classic.php';
$cheminSourceAbsolu = classic_chemin_absolu_fichier($documentSource['chemin']);
if (!is_file($cheminSourceAbsolu)) {
    http_response_code(404);
    echo json_encode(['error' => 'Fichier source introuvable sur le serveur.']);
    exit;
}

$editsByPage = json_decode($editsBrut, true);
if (!is_array($editsByPage)) {
    http_response_code(400);
    echo json_encode(['error' => 'Données de modification invalides.']);
    exit;
}

// Aplatit la structure {page: {runKey: {...}}} en une liste de modifications
// pour le script Python — les rectangles sont déjà en points PDF, origine en
// haut à gauche (voir rectCssDepuisSpan() côté navigateur), exactement la
// convention utilisée par PyMuPDF : aucune conversion nécessaire.
$editsPourPython = [];
foreach ($editsByPage as $numeroPage => $blocs) {
    if (!is_array($blocs)) {
        continue;
    }
    foreach ($blocs as $bloc) {
        if (!is_array($bloc) || empty($bloc['rect']) || !isset($bloc['texte'])) {
            continue;
        }
        $rect = $bloc['rect'];
        $couleurFond = sscanf((string) ($bloc['couleurFond'] ?? ''), 'rgb(%d,%d,%d)');
        $couleurTexte = sscanf((string) ($bloc['couleurTexte'] ?? ''), 'rgb(%d,%d,%d)');
        $editsPourPython[] = [
            'page' => (int) $numeroPage,
            'x0' => (float) ($rect['left'] ?? 0),
            'y0' => (float) ($rect['top'] ?? 0),
            'x1' => (float) ($rect['left'] ?? 0) + (float) ($rect['width'] ?? 0),
            'y1' => (float) ($rect['top'] ?? 0) + (float) ($rect['height'] ?? 0),
            'texte' => (string) $bloc['texte'],
            'taille_police' => (float) ($bloc['taillePolice'] ?? $rect['fontSize'] ?? 11),
            'couleur_texte' => $couleurTexte && count(array_filter($couleurTexte, fn ($v) => $v !== null)) === 3 ? $couleurTexte : [17, 17, 17],
            'couleur_fond' => $couleurFond && count(array_filter($couleurFond, fn ($v) => $v !== null)) === 3 ? $couleurFond : [255, 255, 255],
            'gras' => !empty($bloc['gras']),
            'italique' => !empty($bloc['italique']),
            'souligne' => !empty($bloc['souligne']),
            'police' => in_array($bloc['police'] ?? '', ['helvetica', 'times', 'courier'], true) ? $bloc['police'] : 'helvetica',
        ];
    }
}

if (!$editsPourPython) {
    http_response_code(400);
    echo json_encode(['error' => 'Aucune modification à publier.']);
    exit;
}

try {
    $resultat = pdf_editeur_python_appliquer($cheminSourceAbsolu, $editsPourPython, ['texte' => 'TRAITE ET VALIDE PAR NEXRAIZ']);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$pdfBrut = file_get_contents($resultat['chemin']);
@unlink($resultat['chemin']);
if ($pdfBrut === false || substr($pdfBrut, 0, 4) !== '%PDF') {
    http_response_code(500);
    echo json_encode(['error' => 'Le document édité est invalide.']);
    exit;
}

$dossierUpload = classic_livrables_stockage_prive_racine() . '/' . $dossierId;
if (!is_dir($dossierUpload)) {
    mkdir($dossierUpload, 0755, true);
}
$nomFichier = bin2hex(random_bytes(16)) . '.pdf';
$cheminAbsoluStocke = $dossierUpload . '/' . $nomFichier;
file_put_contents($cheminAbsoluStocke, $pdfBrut);
$cheminStocke = 'prive:' . $dossierId . '/' . $nomFichier;

$titreLivrable = $typeDocument === 'cv' ? 'CV corrigé' : 'Lettre de motivation corrigée';
$nomAffiche = $titreLivrable . ' — ' . $dossier['prenom'] . ' ' . $dossier['nom'] . '.pdf';
$tailleKo = (int) round(strlen($pdfBrut) / 1024);
$nouveauFichier = ['chemin' => $cheminStocke, 'nom_original' => $nomAffiche, 'taille_ko' => $tailleKo];

$stmt = db()->prepare('SELECT id, statut FROM livrables WHERE dossier_id = ? AND titre = ? AND est_version_courante = 1 ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId, $titreLivrable]);
$livrableExistant = $stmt->fetch();

if ($livrableExistant) {
    classic_publier_livrable((int) $livrableExistant['id'], $dossierId, $nouveauFichier);
} else {
    $nouvelId = classic_creer_ou_maj_brouillon_livrable($dossierId, $titreLivrable, $nomAffiche, $cheminStocke, $tailleKo, classic_livrable_categorie_auto($titreLivrable));
    classic_publier_livrable($nouvelId, $dossierId);
}

canvas_edition_valider($dossierId, $typeDocument, $cheminStocke);

echo json_encode(['ok' => true, 'chemin' => $cheminStocke, 'avertissements' => $resultat['avertissements']]);
