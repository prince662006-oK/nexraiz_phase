<?php
/**
 * NexRaiz — Export PDF d'un compte rendu structuré (Pack Assistance).
 * N'inclut JAMAIS `observations_internes` : seules les sections
 * partageables définies par le spec (objet, contexte, objectifs, résumé
 * des échanges, faits rapportés, décisions, actions, prochaine étape)
 * figurent dans le document remis au client.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/comptes-rendus.php';

/**
 * @return array{chemin:string,nom:string,taille_ko:int}
 */
function generer_pdf_compte_rendu(array $compteRendu, string $prenomClient, string $nomClient): array
{
    $html = construire_html_compte_rendu($compteRendu, $prenomClient . ' ' . $nomClient);

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isPhpEnabled'    => true,
        'defaultFont'     => 'DejaVu Sans',
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfBrut = $dompdf->output();

    $dossierId = (int) $compteRendu['dossier_id'];
    $dossierUpload = __DIR__ . '/../uploads/assistance/' . $dossierId . '/comptes-rendus';
    if (!is_dir($dossierUpload)) {
        mkdir($dossierUpload, 0755, true);
    }
    $nomFichier = 'compte-rendu_' . $compteRendu['reference'] . '_' . time() . '.pdf';
    $cheminAbsolu = $dossierUpload . '/' . $nomFichier;
    file_put_contents($cheminAbsolu, $pdfBrut);

    return [
        'chemin'    => 'uploads/assistance/' . $dossierId . '/comptes-rendus/' . $nomFichier,
        'nom'       => 'Compte rendu ' . $compteRendu['reference'] . ' — ' . $prenomClient . ' ' . $nomClient . '.pdf',
        'taille_ko' => (int) round(strlen($pdfBrut) / 1024),
    ];
}

function construire_html_compte_rendu(array $cr, string $nomClient): string
{
    $typesLabels = cr_types_labels();
    $typeLabel = $typesLabels[$cr['type_intervention']] ?? $cr['type_intervention'];
    $dateFormatee = date('d/m/Y', strtotime($cr['date_intervention']));

    $section = static function (string $titre, ?string $contenu) {
        $contenu = trim((string) $contenu);
        if ($contenu === '') {
            return '';
        }
        $paragraphes = array_map(
            fn ($p) => '<p>' . nl2br(htmlspecialchars($p)) . '</p>',
            preg_split('/\n{2,}/', $contenu)
        );
        return '<h2>' . htmlspecialchars($titre) . '</h2>' . implode('', $paragraphes);
    };

    $actions = json_decode((string) $cr['actions_proposees_json'], true) ?: [];
    $actionsHtml = '';
    if ($actions) {
        $lignes = '';
        foreach ($actions as $action) {
            $echeance = !empty($action['echeance']) ? date('d/m/Y', strtotime($action['echeance'])) : '—';
            $lignes .= '<tr>'
                . '<td>' . htmlspecialchars($action['description'] ?? '') . '</td>'
                . '<td>' . htmlspecialchars($action['responsable'] ?? '') . '</td>'
                . '<td>' . htmlspecialchars($echeance) . '</td>'
                . '</tr>';
        }
        $actionsHtml = '<h2>Actions à réaliser</h2><table class="actions"><thead><tr><th>Action</th><th>Responsable</th><th>Échéance</th></tr></thead><tbody>' . $lignes . '</tbody></table>';
    }

    $corps = $section('Objet', $cr['objet'])
        . $section('Contexte', $cr['contexte'])
        . $section('Objectifs', $cr['objectifs'])
        . $section('Résumé des échanges', $cr['resume_echanges'])
        . $section('Faits rapportés', $cr['faits_rapportes'])
        . $section('Décisions', $cr['decisions'])
        . $actionsHtml
        . $section('Prochaine étape', $cr['prochaine_etape']);

    $participants = htmlspecialchars((string) ($cr['participants'] ?? ''));

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 36px 40px 54px; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #1a2233; margin: 0; }
  .entete { margin-bottom: 18px; }
  .entete .reference { font-size: 10px; color: #6b7280; letter-spacing: 1px; }
  .entete h1 { font-size: 16px; color: #0B192C; margin: 4px 0 10px; }
  .meta { font-size: 10px; color: #4b5563; margin-bottom: 4px; }
  h2 { font-size: 12px; letter-spacing: 1px; color: #0B192C; border-bottom: 1.5px solid #d9b45c; padding-bottom: 4px; margin: 18px 0 8px; }
  p { font-size: 11px; line-height: 1.55; margin: 0 0 6px; }
  table.actions { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  table.actions th, table.actions td { border: 1px solid #d1d5db; padding: 5px 7px; font-size: 10px; text-align: left; }
  table.actions th { background: #f3f4f6; }
  .sticker-nexraiz {
    position: fixed; bottom: 14px; right: 40px; padding: 4px 12px;
    background: #ffffff; border: 1px solid #b4c5ff; border-radius: 14px;
    color: #0051d5; font-size: 9px; font-weight: bold;
  }
</style>
</head>
<body>
  <div class="entete">
    <div class="reference">RÉFÉRENCE {$cr['reference']}</div>
    <h1>Compte rendu — {$typeLabel}</h1>
    <div class="meta">Client : {$nomClient}</div>
    <div class="meta">Date de l'intervention : {$dateFormatee}</div>
    <div class="meta">Participants : {$participants}</div>
  </div>
  {$corps}
  <div class="sticker-nexraiz"><span>●</span> NexRaiz — Pack Assistance</div>
  <script type="text/php">
    if (isset(\$pdf)) {
        \$pdf->page_text(40, 812, "{PAGE_NUM} / {PAGE_COUNT}", null, 9, array(0.54, 0.58, 0.64));
    }
  </script>
</body>
</html>
HTML;
}
