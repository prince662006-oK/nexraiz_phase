<?php
/**
 * NexRaiz — Génération d'un PDF mis en forme (CV ou lettre de motivation
 * corrigés), Pack Classic. Appelée au moment où le consultant envoie le
 * livrable au candidat (voir consultant-corrections-classic.php, action
 * "envoyer_livrable") : prend la dernière version de `corrections` et
 * produit un vrai fichier PDF téléchargeable, avec une mise en page
 * professionnelle — pas juste le texte brut affiché sur une page web.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/livrables-classic.php';

/**
 * Construit le PDF pour le dossier/type donné à partir de la dernière
 * correction enregistrée, l'enregistre hors du répertoire public (voir
 * classic_livrables_stockage_prive_racine()) et renvoie ses infos pour
 * mettre à jour la ligne `livrables`.
 *
 * @return array{chemin:string,nom:string,taille_ko:int}|null null si
 * aucune correction n'existe pour ce type (rien à générer).
 */
function generer_pdf_correction(int $dossierId, string $typeDocument, string $prenom, string $nom): ?array
{
    $stmt = db()->prepare(
        'SELECT contenu_corrige, created_at FROM corrections
         WHERE dossier_id = ? AND document_type = ? ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$dossierId, $typeDocument]);
    $correction = $stmt->fetch();
    if (!$correction || trim((string) $correction['contenu_corrige']) === '') {
        return null;
    }

    $titreDocument = $typeDocument === 'lettre' ? 'Lettre de motivation' : 'CV';
    $html = construire_html_correction($titreDocument, $prenom . ' ' . $nom, $correction['contenu_corrige']);

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isPhpEnabled'    => true, // nécessaire pour le compteur de pages ({PAGE_NUM}/{PAGE_COUNT}) en pied de page ; le seul <script type="text/php"> du document est écrit ci-dessous, jamais dérivé du contenu du candidat (toujours échappé via htmlspecialchars).
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
    $cheminAbsolu = $dossierUpload . '/' . $nomFichier;
    file_put_contents($cheminAbsolu, $pdfBrut);

    $nomAffiche = ($typeDocument === 'lettre' ? 'Lettre de motivation' : 'CV') . ' corrigé(e) — ' . $prenom . ' ' . $nom . '.pdf';

    return [
        'chemin'    => 'prive:' . $dossierId . '/' . $nomFichier,
        'nom'       => $nomAffiche,
        'taille_ko' => (int) round(strlen($pdfBrut) / 1024),
    ];
}

/**
 * Convertit les marqueurs **gras** et *italique* en balises <strong>/<em> —
 * appliqué UNIQUEMENT à du texte déjà échappé par htmlspecialchars() : les
 * délimiteurs reconnus sont des astérisques littéraux, jamais du HTML
 * fourni par l'utilisateur ou l'IA (aucun risque d'injection).
 */
function classic_appliquer_emphases_echappees(string $texteEchappe): string
{
    $texte = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $texteEchappe);
    return preg_replace('/\*(.+?)\*/su', '<em>$1</em>', $texte);
}

/**
 * Génère le HTML du document. Heuristique simple pour la mise en forme :
 * une ligne courte (< 42 caractères) entièrement en majuscules est traitée
 * comme un titre de section (CONTACT, LANGUES, COMPÉTENCES...), le reste
 * comme du texte courant — sans essayer de "comprendre" la structure du
 * CV, ce qui resterait fragile sur un texte libre. Les marqueurs **gras**
 * et *italique* saisis par le consultant dans l'éditeur sont honorés.
 */
function construire_html_correction(string $titreDocument, string $nomCandidat, string $contenu): string
{
    $lignes = preg_split('/\r\n|\r|\n/', trim($contenu));
    $blocsHtml = '';
    $enListe = false;

    foreach ($lignes as $ligne) {
        $ligneTrim = trim($ligne);
        if ($ligneTrim === '') {
            if ($enListe) { $blocsHtml .= '</ul>'; $enListe = false; }
            continue;
        }

        // Le test de titre de section porte sur le texte sans les
        // marqueurs d'emphase (un titre en gras doit rester reconnu).
        $ligneSansMarqueurs = str_replace('*', '', $ligneTrim);
        $estTitreSection = mb_strlen($ligneSansMarqueurs) <= 42
            && mb_strtoupper($ligneSansMarqueurs, 'UTF-8') === $ligneSansMarqueurs
            && preg_match('/\p{L}/u', $ligneSansMarqueurs);

        if ($estTitreSection) {
            if ($enListe) { $blocsHtml .= '</ul>'; $enListe = false; }
            $blocsHtml .= '<h2>' . classic_appliquer_emphases_echappees(htmlspecialchars($ligneTrim)) . '</h2>';
            continue;
        }

        if (preg_match('/^[-•*]\s+/u', $ligneTrim)) {
            if (!$enListe) { $blocsHtml .= '<ul>'; $enListe = true; }
            $blocsHtml .= '<li>' . classic_appliquer_emphases_echappees(htmlspecialchars(preg_replace('/^[-•*]\s+/u', '', $ligneTrim))) . '</li>';
            continue;
        }

        if ($enListe) { $blocsHtml .= '</ul>'; $enListe = false; }
        $blocsHtml .= '<p>' . classic_appliquer_emphases_echappees(htmlspecialchars($ligneTrim)) . '</p>';
    }
    if ($enListe) { $blocsHtml .= '</ul>'; }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 36px 40px 54px; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #1a2233; margin: 0; }
  h2 { font-size: 12px; letter-spacing: 1px; color: #0B192C; border-bottom: 1.5px solid #d9b45c; padding-bottom: 4px; margin: 18px 0 8px; }
  h2:first-child { margin-top: 0; }
  p { font-size: 11px; line-height: 1.55; margin: 0 0 6px; }
  ul { margin: 0 0 8px; padding-left: 16px; }
  li { font-size: 11px; line-height: 1.5; margin-bottom: 3px; }
  .sticker-nexraiz {
    position: fixed;
    bottom: 14px;
    right: 40px;
    padding: 4px 12px;
    background: #ffffff;
    border: 1px solid #b4c5ff;
    border-radius: 14px;
    color: #0051d5;
    font-size: 9px;
    font-weight: bold;
  }
  .sticker-nexraiz .point { color: #0051d5; }
</style>
</head>
<body>
  {$blocsHtml}
  <div class="sticker-nexraiz"><span class="point">●</span> Optimisé par NexRaiz</div>
  <script type="text/php">
    if (isset(\$pdf)) {
        \$pdf->page_text(40, 812, "{PAGE_NUM} / {PAGE_COUNT}", null, 9, array(0.54, 0.58, 0.64));
    }
  </script>
</body>
</html>
HTML;
}
