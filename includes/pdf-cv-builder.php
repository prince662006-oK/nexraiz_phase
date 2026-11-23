<?php
/**
 * NexRaiz — Génération du PDF du CV Builder structuré (Pack Classic).
 * Parallèle à includes/pdf-cv.php (qui génère à partir du texte libre de
 * `corrections`) : celui-ci construit le PDF à partir des champs typés de
 * `classic_cv_builder` + `classic_cv_builder_experiences` +
 * `classic_cv_builder_formations`, avec le modèle (avec/sans photo) et la
 * couleur d'accent choisis par le consultant. Publié via le même mécanisme
 * de livrable que les deux autres outils (voir includes/livrables-classic.php),
 * pour que « CV corrigé » reste une seule source de vérité quel que soit
 * l'outil utilisé en dernier.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/livrables-classic.php';
require_once __DIR__ . '/cv-builder-classic.php';
require_once __DIR__ . '/documents-classic.php';

/**
 * @return array{chemin:string,nom:string,taille_ko:int}|null null si aucun
 * modèle n'a encore été choisi pour ce dossier (rien à générer).
 */
function generer_pdf_cv_structure(int $dossierId, string $prenom, string $nom): ?array
{
    $cv = classic_cv_builder_recuperer($dossierId);
    if (!$cv || !$cv['layout']) {
        return null;
    }
    $experiences = classic_cv_builder_experiences($dossierId);
    $formations = classic_cv_builder_formations($dossierId);

    $photoDataUri = null;
    if ($cv['layout'] === 'avec_photo' && $cv['photo_document_id']) {
        $stmt = db()->prepare('SELECT chemin FROM documents WHERE id = ? AND dossier_id = ?');
        $stmt->execute([$cv['photo_document_id'], $dossierId]);
        $cheminPhoto = $stmt->fetchColumn();
        if ($cheminPhoto) {
            $cheminAbsolu = classic_chemin_absolu_fichier($cheminPhoto);
            if (is_file($cheminAbsolu)) {
                $extension = strtolower(pathinfo($cheminAbsolu, PATHINFO_EXTENSION));
                $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
                $photoDataUri = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($cheminAbsolu));
            }
        }
    }

    $html = construire_html_cv_structure($cv, $experiences, $formations, $prenom . ' ' . $nom, $photoDataUri);

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isPhpEnabled'    => true, // uniquement pour le compteur de pages, voir includes/pdf-cv.php pour la même justification.
        'defaultFont'     => 'DejaVu Sans',
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfBrut = $dompdf->output();

    $dossierUpload = classic_livrables_stockage_prive_racine() . '/' . $dossierId;
    if (!is_dir($dossierUpload)) {
        mkdir($dossierUpload, 0755, true);
    }
    $nomFichier = bin2hex(random_bytes(16)) . '.pdf';
    file_put_contents($dossierUpload . '/' . $nomFichier, $pdfBrut);

    return [
        'chemin'    => 'prive:' . $dossierId . '/' . $nomFichier,
        'nom'       => 'CV corrigé — ' . $prenom . ' ' . $nom . '.pdf',
        'taille_ko' => (int) round(strlen($pdfBrut) / 1024),
    ];
}

function construire_html_cv_structure(array $cv, array $experiences, array $formations, string $nomCandidat, ?string $photoDataUri): string
{
    $hex = cv_builder_palette_couleurs()[$cv['couleur_accent']]['hex'] ?? '#13203F';
    $avecPhoto = $cv['layout'] === 'avec_photo';

    $blocExperiences = '';
    foreach ($experiences as $e) {
        $periode = htmlspecialchars(($e['date_debut'] ? date('m/Y', strtotime($e['date_debut'])) : '') . ' — ' . ($e['poste_actuel'] ? 'Présent' : ($e['date_fin'] ? date('m/Y', strtotime($e['date_fin'])) : '')));
        $blocExperiences .= '<div class="entree">'
            . '<div class="entree-titre"><span>' . htmlspecialchars($e['poste']) . '</span><span class="entree-periode">' . $periode . '</span></div>'
            . '<p class="entree-sous-titre">' . htmlspecialchars(trim(($e['entreprise'] ?? '') . ($e['lieu'] ? ' — ' . $e['lieu'] : ''))) . '</p>'
            . ($e['description'] ? '<p class="entree-description">' . nl2br(htmlspecialchars($e['description'])) . '</p>' : '')
            . '</div>';
    }

    $blocFormations = '';
    foreach ($formations as $f) {
        $periode = htmlspecialchars(($f['date_debut'] ? date('m/Y', strtotime($f['date_debut'])) : '') . ' — ' . ($f['date_fin'] ? date('m/Y', strtotime($f['date_fin'])) : ''));
        $blocFormations .= '<div class="entree">'
            . '<div class="entree-titre"><span>' . htmlspecialchars($f['diplome']) . '</span><span class="entree-periode">' . $periode . '</span></div>'
            . '<p class="entree-sous-titre">' . htmlspecialchars(trim(($f['etablissement'] ?? '') . ($f['lieu'] ? ' — ' . $f['lieu'] : ''))) . '</p>'
            . ($f['description'] ? '<p class="entree-description">' . nl2br(htmlspecialchars($f['description'])) . '</p>' : '')
            . '</div>';
    }

    $chipsCompetences = '';
    foreach ($cv['competences'] as $c) { $chipsCompetences .= '<span class="chip">' . htmlspecialchars($c) . '</span>'; }
    $chipsLangues = '';
    foreach ($cv['langues'] as $l) { $chipsLangues .= '<span class="chip chip-accent">' . htmlspecialchars($l) . '</span>'; }

    $entete = '<div class="entete">'
        . ($avecPhoto && $photoDataUri ? '<img class="photo" src="' . htmlspecialchars($photoDataUri) . '" alt="">' : '')
        . '<div class="entete-texte"><h1>' . htmlspecialchars($nomCandidat) . '</h1>'
        . ($cv['titre_poste'] ? '<p class="poste">' . htmlspecialchars($cv['titre_poste']) . '</p>' : '')
        . '<p class="coordonnees">' . htmlspecialchars(implode(' · ', array_filter([$cv['ville'] ?? '', $cv['telephone_affiche'] ?? '', $cv['email_affiche'] ?? '']))) . '</p>'
        . '</div></div>';

    $profil = $cv['resume_profil'] ? '<div class="section"><h2>Profil</h2><p>' . nl2br(htmlspecialchars($cv['resume_profil'])) . '</p></div>' : '';

    // Teinte claire de la couleur d'accent pour le fond des puces "langues",
    // calculée en RGBA plutôt qu'avec color-mix() (non supporté par le
    // moteur CSS de Dompdf).
    [$rr, $gg, $bb] = sscanf($hex, '#%02x%02x%02x') ?: [19, 32, 63];
    $hexClair = "rgba({$rr},{$gg},{$bb},0.12)";

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 32px 40px 50px; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #1a2233; margin: 0; font-size: 11px; }
  .entete { display: flex; align-items: center; gap: 16px; border-bottom: 2px solid {$hex}; padding-bottom: 12px; margin-bottom: 14px; }
  .photo { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 2px solid {$hex}; }
  .entete h1 { margin: 0; font-size: 22px; color: {$hex}; }
  .poste { margin: 2px 0; font-size: 13px; color: #444653; font-weight: bold; }
  .coordonnees { margin: 2px 0 0; font-size: 10px; color: #667085; }
  .section { margin-bottom: 12px; }
  h2 { font-size: 11px; letter-spacing: 1px; text-transform: uppercase; color: {$hex}; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin: 0 0 8px; }
  .entree { margin-bottom: 8px; }
  .entree-titre { display: flex; justify-content: space-between; font-weight: bold; font-size: 12px; }
  .entree-periode { color: #667085; font-weight: normal; font-size: 10px; }
  .entree-sous-titre { margin: 1px 0 3px; color: #444653; font-size: 10.5px; }
  .entree-description { margin: 0; line-height: 1.5; }
  .chip { display: inline-block; background: #f1f5f9; color: #1a2233; padding: 2px 8px; border-radius: 10px; font-size: 9.5px; margin: 0 4px 4px 0; }
  .chip-accent { background: {$hexClair}; color: {$hex}; }
  .sticker-nexraiz { position: fixed; bottom: 12px; right: 40px; padding: 3px 10px; background: #ffffff; border: 1px solid {$hex}; border-radius: 12px; color: {$hex}; font-size: 9px; font-weight: bold; }
</style>
</head>
<body>
  {$entete}
  {$profil}
  <div class="section"><h2>Expériences professionnelles</h2>{$blocExperiences}</div>
  <div class="section"><h2>Formation</h2>{$blocFormations}</div>
  <div class="section"><h2>Compétences</h2>{$chipsCompetences}</div>
  <div class="section"><h2>Langues</h2>{$chipsLangues}</div>
  <div class="sticker-nexraiz">● Optimisé par NexRaiz</div>
</body>
</html>
HTML;
}
