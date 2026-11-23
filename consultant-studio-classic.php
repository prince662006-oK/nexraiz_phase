<?php
/**
 * NexRaiz — Studio de correction (Pack Classic) : document original en
 * lecture seule à gauche, éditeur enrichi au centre (reprend exactement
 * les mêmes données que consultant-corrections-classic.php — table
 * `corrections` —, juste une présentation différente), assistant Prince AI
 * à droite (suggestions prêtes à appliquer + score de qualité indicatif).
 * Aucune nouvelle donnée : la publication au candidat passe par
 * studio-envoyer.php, qui réutilise le même mécanisme livrables que le
 * reste du Pack Classic.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$dossierId = (int) ($_GET['dossier'] ?? 0);
$documentType = $_GET['type'] ?? 'cv';
if (!in_array($documentType, ['cv', 'lettre'], true)) {
    $documentType = 'cv';
}

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    header('Location: consultant-analyse-classic.php');
    exit;
}

if ($dossier['consultant_id'] === null) {
    db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ?')->execute([$consultant['id'], $dossierId]);
}

$stmt = db()->prepare('SELECT id, nom_original, chemin FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$dossierId]);
$documentsBruts = $stmt->fetchAll();
$documentOriginal = null;
foreach ($documentsBruts as $d) {
    if (strpos($d['chemin'], '/' . $documentType . '/') !== false) {
        $documentOriginal = $d;
        break;
    }
}
$originalEstPdf = $documentOriginal && strtolower(pathinfo($documentOriginal['nom_original'], PATHINFO_EXTENSION)) === 'pdf';

$stmt = db()->prepare('SELECT document_type, contenu_initial, contenu_corrige, created_at FROM corrections WHERE dossier_id = ? AND document_type = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$dossierId, $documentType]);
$derniereCorrection = $stmt->fetch();

$contenuInitial = $derniereCorrection['contenu_initial'] ?? '';
if ($contenuInitial === '' && $documentOriginal) {
    $texteExtrait = extraire_texte_document(__DIR__ . '/' . $documentOriginal['chemin'], pathinfo($documentOriginal['nom_original'], PATHINFO_EXTENSION));
    if ($texteExtrait !== null) {
        $contenuInitial = $texteExtrait;
    }
}
// '??' seul ne suffit pas : une correction déjà enregistrée mais jamais
// retouchée a 'contenu_corrige' = '' (chaîne vide), pas NULL — sans ce test
// explicite, le studio s'ouvrait totalement vide au lieu de retomber sur le
// texte original extrait du document.
$contenuCorrige = !empty($derniereCorrection['contenu_corrige']) ? $derniereCorrection['contenu_corrige'] : $contenuInitial;

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = json_decode((string) $stmt->fetchColumn(), true) ?: [];

$libelleType = $documentType === 'lettre' ? 'Lettre de motivation' : 'CV';

// Historique réel des versions enregistrées (table `corrections`), pour le
// bouton "Historique" / "Versions" — jamais de données fictives.
$stmt = db()->prepare('SELECT contenu_corrige, created_at FROM corrections WHERE dossier_id = ? AND document_type = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$dossierId, $documentType]);
$historiqueVersions = $stmt->fetchAll();

/**
 * Conversion texte brut -> blocs HTML éditables (même heuristique que
 * includes/pdf-cv.php: ligne courte en majuscules = titre de section,
 * ligne commençant par -/•/* = puce, sinon paragraphe), pour amorcer
 * l'éditeur enrichi avec une mise en forme basique au lieu d'un bloc de
 * texte brut.
 */
function studio_texte_vers_html(string $texte): string
{
    $lignes = preg_split('/\r\n|\r|\n/', trim($texte));
    $html = '';
    $enListe = false;
    foreach ($lignes as $ligne) {
        $ligneTrim = trim($ligne);
        if ($ligneTrim === '') {
            if ($enListe) { $html .= '</ul>'; $enListe = false; }
            continue;
        }
        $estTitre = mb_strlen($ligneTrim) <= 42 && mb_strtoupper($ligneTrim, 'UTF-8') === $ligneTrim && preg_match('/\p{L}/u', $ligneTrim);
        if ($estTitre) {
            if ($enListe) { $html .= '</ul>'; $enListe = false; }
            $html .= '<h2>' . htmlspecialchars($ligneTrim) . '</h2>';
            continue;
        }
        if (preg_match('/^[-•*]\s+/u', $ligneTrim)) {
            if (!$enListe) { $html .= '<ul>'; $enListe = true; }
            $html .= '<li>' . htmlspecialchars(preg_replace('/^[-•*]\s+/u', '', $ligneTrim)) . '</li>';
            continue;
        }
        if ($enListe) { $html .= '</ul>'; $enListe = false; }
        $html .= '<p>' . htmlspecialchars($ligneTrim) . '</p>';
    }
    if ($enListe) { $html .= '</ul>'; }
    return $html !== '' ? $html : '<p><br></p>';
}

$historiqueVersionsJs = array_map(function (array $v): array {
    return [
        'date' => date('d/m/Y à H:i', strtotime($v['created_at'])),
        'html' => studio_texte_vers_html($v['contenu_corrige']),
    ];
}, $historiqueVersions);

render_head('Studio de correction — ' . $libelleType . ' — NexRaiz', 'Studio de correction enrichi, Pack Classic.');
?>
<style>
  body{overflow:hidden;}
  .studio-editor{outline:none;}
  .studio-editor h2{display:inline-block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;color:#0051d5;background:#eff4ff;padding:3px 10px;border-radius:999px;margin:20px 0 10px;}
  .studio-editor h2:first-child{margin-top:0;}
  .studio-editor p{margin:0 0 12px;line-height:1.65;}
  .studio-editor ul{margin:0 0 12px;padding-left:20px;}
  .studio-editor li{margin-bottom:5px;line-height:1.55;}
  .studio-diff-ajout{background:rgba(16,185,129,.15);color:#047857;font-weight:600;border-radius:3px;padding:0 2px;}
  .studio-diff-suppr{background:rgba(239,68,68,.12);color:#b91c1c;text-decoration:line-through;border-radius:3px;padding:0 2px;}
  .studio-chip-active{background:#0B192C !important;color:#fff !important;}
  .studio-quality-ring{transform:rotate(-90deg);}
</style>

<div class="flex flex-col h-screen">

  <header class="h-14 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-space-md gap-space-sm">
    <div class="flex items-center gap-space-sm min-w-0">
      <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>&onglet=<?php echo $documentType; ?>" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors shrink-0" title="Retour au dossier"><span class="material-symbols-outlined text-[20px]">arrow_back</span></a>
      <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-label-md text-label-md font-bold shrink-0"><?php echo htmlspecialchars(mb_substr($dossier['prenom'], 0, 1) . mb_substr($dossier['nom'], 0, 1)); ?></div>
      <div class="min-w-0">
        <div class="flex items-center gap-space-xs">
          <span class="font-headline-sm text-headline-sm text-on-surface font-bold truncate"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></span>
          <span class="px-space-xs py-0.5 rounded-full bg-surface-container-low text-secondary font-label-md text-[11px] font-semibold flex items-center gap-1 shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse"></span>En cours de correction</span>
        </div>
        <p class="font-body-sm text-[11px] text-secondary truncate">Candidature <?php echo htmlspecialchars($qualification['poste_vise'] ?? 'non renseignée'); ?></p>
      </div>
      <div class="h-6 w-px bg-outline-variant/60 mx-1 hidden md:block"></div>
      <nav class="hidden md:flex items-center gap-space-2xs">
        <a href="?dossier=<?php echo $dossierId; ?>&type=cv" class="px-space-sm h-8 flex items-center rounded-lg font-label-md text-[12px] <?php echo $documentType === 'cv' ? 'bg-surface-container-high text-on-surface font-bold shadow-sm' : 'text-secondary hover:bg-surface-container-low'; ?> transition-colors">CV</a>
        <a href="?dossier=<?php echo $dossierId; ?>&type=lettre" class="px-space-sm h-8 flex items-center rounded-lg font-label-md text-[12px] <?php echo $documentType === 'lettre' ? 'bg-surface-container-high text-on-surface font-bold shadow-sm' : 'text-secondary hover:bg-surface-container-low'; ?> transition-colors">Lettre de motivation</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <span id="statutAutosave" class="font-body-sm text-[11px] text-secondary hidden xl:inline">—</span>
      <div class="hidden md:flex items-center bg-surface-container-low p-1 rounded-lg">
        <button type="button" id="btnModeManuel" class="studio-mode-btn px-space-sm py-1 rounded font-label-md text-[11px] text-secondary transition-colors">Mode manuel</button>
        <button type="button" id="btnModeIA" class="studio-mode-btn px-space-sm py-1 rounded bg-surface-container-lowest text-on-surface font-label-md text-[11px] font-semibold shadow-sm flex items-center gap-1 transition-colors"><span class="material-symbols-outlined text-secondary text-[14px]">auto_awesome</span>Mode assisté par IA</button>
      </div>
      <a href="consultant-canvas-editeur.php?dossier=<?php echo $dossierId; ?>&type=<?php echo $documentType; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors hidden xl:flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">dashboard_customize</span>Éditeur visuel</a>
      <button type="button" id="btnTransmettre" class="h-9 px-space-md rounded-lg bg-[#0B192C] text-white font-label-md text-[12px] font-bold flex items-center gap-1 hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[16px]">send</span>Transmettre au candidat</button>
      <a href="logout.php" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="shrink-0 bg-surface-container-lowest shadow-[0_1px_3px_rgba(11,28,48,0.05)] px-space-md py-space-xs flex flex-wrap items-center justify-between gap-space-sm">
    <div class="flex items-center gap-space-xs font-label-md text-[11px] text-secondary">
      <span class="material-symbols-outlined text-[16px]">folder_open</span>
      <span>Dossiers</span>
      <span class="text-outline-variant">/</span>
      <span class="text-on-surface font-semibold">Pack Classic</span>
      <span class="text-outline-variant">/</span>
      <span class="px-space-xs py-0.5 rounded bg-surface-container-low text-secondary font-mono text-[10px] font-semibold"><?php echo htmlspecialchars($dossier['numero']); ?></span>
      <span class="h-3 w-px bg-outline-variant/60 mx-1"></span>
      <span class="w-1.5 h-1.5 rounded-full bg-[#10b981] inline-block"></span>
      <span class="text-on-surface font-medium">Session synchronisée</span>
    </div>
    <div class="flex items-center gap-space-xs">
      <div class="hidden lg:flex items-center gap-space-2xs bg-surface-container-low p-0.5 rounded-lg">
        <button type="button" id="btnVueTrois" class="studio-vue-btn px-space-sm py-1 rounded bg-surface-container-lowest text-on-surface font-label-md text-[11px] font-semibold shadow-sm flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">view_column</span>3 Volets</button>
        <button type="button" id="btnVueFocus" class="studio-vue-btn px-space-sm py-1 rounded text-secondary font-label-md text-[11px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">vertical_split</span>Focus éditeur</button>
      </div>
      <div class="relative">
        <button type="button" id="btnHistorique" class="h-8 px-space-sm rounded-lg bg-surface-container-low text-on-surface hover:bg-surface-container-high font-label-md text-[11px] flex items-center gap-1 transition-colors"><span class="material-symbols-outlined text-secondary text-[16px]">history</span>Versions <strong class="text-secondary">(<?php echo count($historiqueVersions); ?>)</strong></button>
        <div id="menuHistorique" class="hidden absolute right-0 top-full mt-1 w-80 max-h-96 overflow-y-auto bg-surface-container-lowest rounded-xl shadow-[0_8px_24px_rgba(11,28,48,0.12)] p-1 z-50"></div>
      </div>
      <div class="relative">
        <button type="button" id="btnExporter" class="h-8 px-space-sm rounded-lg bg-surface-container-low text-on-surface hover:bg-surface-container-high font-label-md text-[11px] flex items-center gap-1 transition-colors"><span class="material-symbols-outlined text-[16px]">download_for_offline</span>Exporter<span class="material-symbols-outlined text-[14px] text-secondary">expand_more</span></button>
        <div id="menuExporter" class="hidden absolute right-0 top-full mt-1 w-56 bg-surface-container-lowest rounded-xl shadow-[0_8px_24px_rgba(11,28,48,0.12)] p-1 z-50">
          <button type="button" id="btnApercuPdf" class="w-full text-left flex items-center gap-2 px-3 py-2 text-on-surface hover:bg-surface-container-low rounded-lg font-label-md text-[12px]"><span class="material-symbols-outlined text-error text-[18px]">picture_as_pdf</span>Aperçu PDF (consultant)</button>
        </div>
      </div>
      <span class="flex items-center gap-1 text-[#10b981] font-semibold font-label-md text-[11px]"><span class="material-symbols-outlined text-[14px]">cloud_done</span><span id="statutAutosaveStrip">Enregistré automatiquement</span></span>
    </div>
  </div>

  <div class="flex-1 min-h-0 p-space-md flex gap-space-md">

    <!-- Colonne 1 : document original (lecture seule) -->
    <section id="colonnePdf" class="w-[30%] shrink-0 flex flex-col bg-surface-container-lowest rounded-xl shadow-[0_1px_4px_rgba(11,28,48,0.04)] overflow-hidden transition-all duration-300">
      <div class="px-space-sm py-space-xs bg-surface-container-low flex items-center justify-between gap-space-xs">
        <div class="flex items-center gap-space-xs min-w-0">
          <span class="material-symbols-outlined text-error text-[20px] shrink-0">picture_as_pdf</span>
          <div class="min-w-0">
            <p class="font-label-md text-label-md font-semibold text-on-surface truncate"><?php echo $documentOriginal ? htmlspecialchars($documentOriginal['nom_original']) : 'Aucun document'; ?></p>
            <p class="font-body-sm text-[10px] text-secondary">Document original source</p>
          </div>
        </div>
        <?php if ($originalEstPdf): ?>
        <div class="flex items-center gap-1 shrink-0">
          <button type="button" id="btnZoomOutPdf" class="w-7 h-7 rounded flex items-center justify-center text-secondary hover:bg-surface-container-lowest transition-colors"><span class="material-symbols-outlined text-[16px]">zoom_out</span></button>
          <span id="pdfZoomLabel" class="font-label-md text-[11px] text-on-surface w-9 text-center">100%</span>
          <button type="button" id="btnZoomInPdf" class="w-7 h-7 rounded flex items-center justify-center text-secondary hover:bg-surface-container-lowest transition-colors"><span class="material-symbols-outlined text-[16px]">zoom_in</span></button>
        </div>
        <?php endif; ?>
      </div>
      <div class="px-space-sm py-1 bg-surface-container-lowest flex items-center justify-between font-label-md text-[10px] text-secondary border-b border-outline-variant/40">
        <span class="flex items-center gap-1 font-medium text-secondary"><span class="material-symbols-outlined text-[13px]">lock</span>Lecture seule · Verrouillé</span>
        <span id="pdfPageLabel" class="font-semibold text-on-surface"><?php echo $originalEstPdf ? 'Page 1/1' : ''; ?></span>
      </div>
      <?php if ($originalEstPdf): ?>
      <div class="flex-1 flex gap-1 overflow-hidden bg-surface-container-low p-1.5">
        <div id="pdfThumbs" class="w-14 shrink-0 flex flex-col gap-1.5 overflow-y-auto pr-0.5"></div>
        <div id="pdfStage" class="flex-1 overflow-auto bg-surface-container-lowest rounded-lg flex items-start justify-center p-2"></div>
      </div>
      <?php elseif ($documentOriginal): ?>
      <div class="flex-1 flex items-center justify-center p-space-md">
        <a href="<?php echo htmlspecialchars($documentOriginal['chemin']); ?>" target="_blank" rel="noopener" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir « <?php echo htmlspecialchars($documentOriginal['nom_original']); ?> »</a>
      </div>
      <?php else: ?>
      <div class="flex-1 flex items-center justify-center p-space-md text-center">
        <p class="font-body-sm text-body-sm text-secondary">Aucun document « <?php echo htmlspecialchars($documentType); ?> » déposé pour ce dossier.</p>
      </div>
      <?php endif; ?>
    </section>

    <!-- Colonne 2 : éditeur enrichi -->
    <section id="colonneEditeur" class="flex-1 min-w-0 flex flex-col bg-surface-container-lowest rounded-xl shadow-[0_2px_8px_rgba(11,28,48,0.06)] overflow-hidden">
      <div class="px-space-sm py-space-xs bg-surface-container-low flex flex-wrap items-center justify-between gap-space-sm">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-primary text-[20px]">edit_document</span>
          <div>
            <h1 class="font-headline-sm text-headline-sm font-bold text-on-surface leading-tight">Version corrigée</h1>
            <p class="font-body-sm text-[10px] text-secondary">Éditeur enrichi NexRaiz</p>
          </div>
        </div>
        <label class="flex items-center gap-2 cursor-pointer select-none bg-surface-container-lowest px-space-sm py-1 rounded-lg shadow-sm">
          <span class="font-label-md text-[11px] font-semibold text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[15px] text-secondary">difference</span>Afficher le diff</span>
          <input type="checkbox" id="chkDiff" class="sr-only peer">
          <div class="w-8 h-4 bg-surface-container-highest peer-checked:bg-secondary rounded-full relative transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:after:translate-x-full"></div>
        </label>
      </div>
      <div class="px-space-sm py-1 bg-surface-bright flex flex-wrap items-center justify-between gap-space-xs text-secondary border-b border-outline-variant/40">
        <div class="flex flex-wrap items-center gap-1">
          <select class="studio-fmt-select h-7 px-1.5 rounded bg-surface-container-low text-on-surface font-label-md text-[11px] font-medium border-0 focus:outline-none focus:ring-1 focus:ring-primary" data-cmd="formatBlock" title="Style du paragraphe">
            <option value="P">Texte</option>
            <option value="H1">Titre 1</option>
            <option value="H2">Titre 2</option>
            <option value="H3">Titre 3</option>
          </select>
          <select class="studio-fmt-select h-7 px-1.5 rounded bg-surface-container-low text-on-surface font-label-md text-[11px] font-medium border-0 focus:outline-none focus:ring-1 focus:ring-primary" data-cmd="fontName" title="Police">
            <option value="Arial, sans-serif">Arial</option>
            <option value="'Times New Roman', serif">Times New Roman</option>
            <option value="Georgia, serif">Georgia</option>
            <option value="Calibri, 'Segoe UI', sans-serif">Calibri</option>
            <option value="Verdana, sans-serif">Verdana</option>
          </select>
          <select class="studio-fmt-select h-7 px-1.5 rounded bg-surface-container-low text-on-surface font-label-md text-[11px] font-medium border-0 focus:outline-none focus:ring-1 focus:ring-primary" data-cmd="fontSize" title="Taille du texte">
            <option value="2">Petit</option>
            <option value="3" selected>Normal</option>
            <option value="4">Moyen</option>
            <option value="5">Grand</option>
            <option value="6">Très grand</option>
          </select>
          <div class="h-4 w-px bg-outline-variant/60 mx-1"></div>
          <div class="flex items-center gap-0.5">
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="bold" title="Gras"><span class="material-symbols-outlined text-[16px]">format_bold</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="italic" title="Italique"><span class="material-symbols-outlined text-[16px]">format_italic</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="underline" title="Souligné"><span class="material-symbols-outlined text-[16px]">format_underlined</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="strikeThrough" title="Barré"><span class="material-symbols-outlined text-[16px]">format_strikethrough</span></button>
          </div>
          <div class="h-4 w-px bg-outline-variant/60 mx-1"></div>
          <input type="color" class="studio-fmt-color w-6 h-6 rounded cursor-pointer border border-outline-variant/60 p-0 bg-transparent" data-cmd="foreColor" value="#0b1c30" title="Couleur du texte">
          <div class="h-4 w-px bg-outline-variant/60 mx-1"></div>
          <div class="flex items-center gap-0.5">
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="justifyLeft" title="Aligner à gauche"><span class="material-symbols-outlined text-[16px]">format_align_left</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="justifyCenter" title="Centrer"><span class="material-symbols-outlined text-[16px]">format_align_center</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="justifyRight" title="Aligner à droite"><span class="material-symbols-outlined text-[16px]">format_align_right</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="justifyFull" title="Justifier"><span class="material-symbols-outlined text-[16px]">format_align_justify</span></button>
          </div>
          <div class="h-4 w-px bg-outline-variant/60 mx-1"></div>
          <div class="flex items-center gap-0.5">
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="insertUnorderedList" title="Liste à puces"><span class="material-symbols-outlined text-[16px]">format_list_bulleted</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="insertOrderedList" title="Liste numérotée"><span class="material-symbols-outlined text-[16px]">format_list_numbered</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="outdent" title="Diminuer le retrait"><span class="material-symbols-outlined text-[16px]">format_indent_decrease</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="indent" title="Augmenter le retrait"><span class="material-symbols-outlined text-[16px]">format_indent_increase</span></button>
          </div>
          <div class="h-4 w-px bg-outline-variant/60 mx-1"></div>
          <div class="flex items-center gap-0.5">
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="undo" title="Annuler"><span class="material-symbols-outlined text-[16px]">undo</span></button>
            <button type="button" class="studio-fmt p-1 rounded hover:bg-surface-container-low hover:text-on-surface" data-cmd="redo" title="Rétablir"><span class="material-symbols-outlined text-[16px]">redo</span></button>
          </div>
        </div>
        <div id="diffLegende" class="hidden items-center gap-space-sm text-[10px] font-label-md">
          <span class="flex items-center gap-1 text-[#b91c1c]"><span class="w-2 h-2 rounded-full bg-[#ef4444]"></span>Supprimé</span>
          <span class="flex items-center gap-1 text-[#047857]"><span class="w-2 h-2 rounded-full bg-[#10b981]"></span>Ajouté</span>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto p-space-lg bg-surface-bright">
        <div class="max-w-3xl mx-auto">
          <div id="editeurEnrichi" class="studio-editor bg-surface-container-lowest rounded-xl shadow-sm p-space-lg font-body-md text-body-md text-on-surface" contenteditable="true"><?php echo studio_texte_vers_html($contenuCorrige); ?></div>
          <div id="vueDiff" class="hidden studio-editor bg-surface-container-lowest rounded-xl shadow-sm p-space-lg font-body-md text-body-md text-on-surface"></div>
        </div>
      </div>

      <div class="px-space-sm py-1 bg-surface-container-low flex items-center justify-between font-label-md text-[10px] text-secondary">
        <div class="flex items-center gap-space-sm">
          <span id="compteMots">0 mots</span>
          <span>·</span>
          <span id="compteCaracteres">0 caractères</span>
        </div>
        <span id="indicateurSauvegarde" class="flex items-center gap-1 text-secondary"><span class="material-symbols-outlined text-[14px]">cloud_done</span>Enregistré automatiquement</span>
      </div>
    </section>

    <!-- Colonne 3 : Assistant Prince AI -->
    <aside id="colonneIA" class="w-96 shrink-0 flex flex-col bg-surface-container-lowest rounded-xl shadow-[0_2px_8px_rgba(11,28,48,0.06)] overflow-y-auto transition-all duration-300">
      <div class="px-space-sm py-space-xs bg-surface-container-low shrink-0">
        <span class="font-label-md text-label-md font-bold text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">auto_awesome</span>Assistant Prince AI</span>
      </div>

      <div class="p-space-md border-b border-outline-variant/40 shrink-0" style="background:linear-gradient(135deg,#f8f9ff,#ffffff 60%,#eff4ff)">
        <div class="flex items-center justify-between mb-space-xs">
          <div>
            <span class="font-label-md text-[10px] font-bold uppercase tracking-wider text-secondary">Indice qualité global</span>
            <h3 id="qualiteTitre" class="font-headline-sm text-headline-sm font-bold text-on-surface">—</h3>
          </div>
          <div class="relative w-14 h-14 flex items-center justify-center shrink-0">
            <svg class="w-full h-full studio-quality-ring" viewbox="0 0 36 36">
              <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e4e7ee" stroke-width="3.5"></path>
              <path id="qualiteAnneau" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#0051d5" stroke-dasharray="0, 100" stroke-linecap="round" stroke-width="3.5"></path>
            </svg>
            <div class="absolute flex flex-col items-center">
              <span id="qualiteScore" class="font-headline-sm text-headline-sm font-black text-on-surface leading-none">–</span>
              <span class="text-[8px] font-bold text-secondary">/100</span>
            </div>
          </div>
        </div>
        <div id="qualiteDetail" class="space-y-1.5 text-[12px] hidden">
          <div><div class="flex justify-between text-on-surface font-medium mb-0.5"><span>Orthographe & grammaire</span><span id="qualiteOrtho" class="font-bold">–</span></div><div class="w-full h-1.5 bg-surface-container-low rounded-full overflow-hidden"><div id="barreOrtho" class="h-full bg-[#10b981] rounded-full" style="width:0%"></div></div></div>
          <div><div class="flex justify-between text-on-surface font-medium mb-0.5"><span>Style, tonalité & verbes d'action</span><span id="qualiteStyle" class="font-bold">–</span></div><div class="w-full h-1.5 bg-surface-container-low rounded-full overflow-hidden"><div id="barreStyle" class="h-full bg-[#f59e0b] rounded-full" style="width:0%"></div></div></div>
          <div><div class="flex justify-between text-on-surface font-medium mb-0.5"><span>Impact RH & métriques chiffrées</span><span id="qualiteImpact" class="font-bold">–</span></div><div class="w-full h-1.5 bg-surface-container-low rounded-full overflow-hidden"><div id="barreImpact" class="h-full bg-secondary rounded-full" style="width:0%"></div></div></div>
        </div>
        <p id="qualiteErreur" class="font-body-sm text-[11px] text-secondary mt-space-xs hidden"></p>
      </div>

      <div class="px-space-md pt-space-sm flex items-center gap-1 overflow-x-auto shrink-0">
        <button type="button" class="studio-chip px-space-sm py-1 rounded-full bg-primary-container text-white font-label-md text-[11px] font-semibold shrink-0 studio-chip-active" data-filtre="all">Tous <span class="studio-chip-count"></span></button>
        <button type="button" class="studio-chip px-space-sm py-1 rounded-full bg-surface-container-low text-secondary font-label-md text-[11px] font-medium shrink-0" data-filtre="ortho">Orthographe <span class="studio-chip-count"></span></button>
        <button type="button" class="studio-chip px-space-sm py-1 rounded-full bg-surface-container-low text-secondary font-label-md text-[11px] font-medium shrink-0" data-filtre="style">Style <span class="studio-chip-count"></span></button>
        <button type="button" class="studio-chip px-space-sm py-1 rounded-full bg-surface-container-low text-secondary font-label-md text-[11px] font-medium shrink-0" data-filtre="impact">Impact <span class="studio-chip-count"></span></button>
        <button type="button" id="btnRegenererSuggestions" class="ml-auto p-1.5 rounded-lg text-secondary hover:bg-surface-container-low shrink-0" title="Régénérer les suggestions"><span class="material-symbols-outlined text-[16px]">refresh</span></button>
      </div>

      <div id="suggestionsFeed" class="flex-1 overflow-y-auto p-space-md space-y-space-sm">
        <p class="font-body-sm text-[12px] text-secondary text-center">Chargement des suggestions…</p>
      </div>

      <div class="border-t border-outline-variant/40 shrink-0">
        <button type="button" id="btnToggleTonalite" class="w-full px-space-md py-space-xs flex items-center justify-between gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] font-bold uppercase tracking-wider text-secondary">Tonalité &amp; actions</span>
          <span id="iconeToggleTonalite" class="material-symbols-outlined text-[18px] text-secondary transition-transform">expand_less</span>
        </button>
        <div id="zoneTonalite" class="px-space-md pb-space-sm flex flex-col gap-space-xs">
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-md text-[10px] font-bold uppercase tracking-wider text-secondary">Tonalité</span>
            <select id="selectTonalite" class="h-8 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <option value="Professionnel, sobre, adapté à un cabinet de recrutement">Professionnel (cabinet)</option>
              <option value="Percutant et commercial, orienté résultats">Percutant / commercial</option>
              <option value="Académique et formel">Académique</option>
              <option value="Chaleureux et humain, tout en restant professionnel">Chaleureux</option>
            </select>
          </label>
          <div class="flex items-center gap-space-2xs">
            <button type="button" id="btnReecrireSection" class="flex-1 h-9 rounded-lg border border-primary-container text-primary font-label-md text-[11px] font-bold flex items-center justify-center gap-1 hover:bg-primary-container/5 transition-colors"><span class="material-symbols-outlined text-[15px]">auto_fix_high</span>Réécrire la section</button>
            <button type="button" id="btnToutAccepter" class="flex-1 h-9 rounded-lg bg-[#10b981] hover:bg-[#059669] text-white font-label-md text-[11px] font-bold flex items-center justify-center gap-1 transition-colors"><span class="material-symbols-outlined text-[15px]">done_all</span>Tout accepter <span id="toutAccepterCompte"></span></button>
          </div>
          <p class="font-body-sm text-[10px] text-secondary">Prince AI ne modifie jamais rien automatiquement : chaque suggestion est à accepter, modifier ou refuser, et le candidat ne voit que la version transmise.</p>
        </div>
      </div>
    </aside>
  </div>
</div>

<form method="post" action="apercu-correction-pdf.php" target="_blank" id="formApercuStudio" class="hidden">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="dossier_id" value="<?php echo $dossierId; ?>">
  <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($documentType); ?>">
  <input type="hidden" name="contenu_corrige" value="">
</form>

<script>
const DOSSIER_ID = <?php echo json_encode($dossierId); ?>;
const DOCUMENT_TYPE = <?php echo json_encode($documentType); ?>;
const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
const CONTENU_INITIAL = <?php echo json_encode($contenuInitial, JSON_UNESCAPED_UNICODE); ?>;
const HISTORIQUE_VERSIONS = <?php echo json_encode($historiqueVersionsJs, JSON_UNESCAPED_UNICODE); ?>;

const editeur = document.getElementById('editeurEnrichi');
const vueDiff = document.getElementById('vueDiff');
const indicateurSauvegarde = document.getElementById('indicateurSauvegarde');
const statutAutosave = document.getElementById('statutAutosave');
let autosaveTimer = null;
let autosaveJeton = 0;
let sauvegardesEnAttente = 0;
let suggestionsActuelles = [];
let filtreActuel = 'all';

function majEtatBoutonTransmettre() {
  const btn = document.getElementById('btnTransmettre');
  if (!btn) return;
  btn.disabled = sauvegardesEnAttente > 0;
  btn.title = sauvegardesEnAttente > 0 ? 'Veuillez attendre la fin de la sauvegarde en cours.' : '';
}

function majCompteurs() {
  const texte = editeur.innerText || '';
  const mots = texte.trim() === '' ? 0 : texte.trim().split(/\s+/).length;
  document.getElementById('compteMots').textContent = mots + ' mots';
  document.getElementById('compteCaracteres').textContent = texte.length + ' caractères';
}

const statutAutosaveStrip = document.getElementById('statutAutosaveStrip');

function programmerAutosave() {
  indicateurSauvegarde.innerHTML = '<span class="material-symbols-outlined text-[14px]">cloud_sync</span>Enregistrement…';
  statutAutosave.textContent = 'Modifications en cours…';
  statutAutosaveStrip.textContent = 'Enregistrement…';
  clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(enregistrerBrouillon, 1500);
}

async function enregistrerBrouillon() {
  // Jeton anti-course : si une sauvegarde plus récente démarre avant que
  // celle-ci ne se termine, sa réponse (même tardive) ne doit plus mettre à
  // jour l'indicateur — sinon un contenu obsolète pourrait s'afficher comme
  // "Enregistré" après un contenu plus récent.
  const monJeton = ++autosaveJeton;
  sauvegardesEnAttente++;
  majEtatBoutonTransmettre();
  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('action', 'enregistrer_correction');
  donnees.append('document_type', DOCUMENT_TYPE);
  donnees.append('contenu_initial', CONTENU_INITIAL);
  donnees.append('contenu_corrige', editeur.innerText);
  try {
    const r = await fetch('consultant-corrections-classic.php?dossier=' + DOSSIER_ID, { method: 'POST', body: donnees, redirect: 'manual' });
    // Ce handler redirige (302) uniquement en cas de succès réel ; toute
    // autre réponse (403 session expirée, erreur serveur...) est un échec —
    // on ne doit jamais annoncer "Enregistré" sans cette confirmation.
    if (r.type !== 'opaqueredirect') {
      throw new Error('HTTP ' + r.status);
    }
    if (monJeton === autosaveJeton) {
      indicateurSauvegarde.innerHTML = '<span class="material-symbols-outlined text-[14px]">cloud_done</span>Enregistré automatiquement';
      const heure = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
      statutAutosave.textContent = 'Enregistré à ' + heure;
      statutAutosaveStrip.textContent = 'Enregistré à ' + heure;
    }
  } catch (e) {
    if (monJeton === autosaveJeton) {
      indicateurSauvegarde.innerHTML = '<span class="material-symbols-outlined text-[14px] text-error">cloud_off</span>Erreur de sauvegarde <button type="button" id="btnReessayerSauvegarde" class="underline font-semibold">Réessayer</button>';
      statutAutosave.textContent = 'Erreur de sauvegarde — modifications non confirmées';
      statutAutosaveStrip.innerHTML = '<span class="material-symbols-outlined text-[14px] text-error">cloud_off</span><span class="text-error">Erreur de sauvegarde</span>';
      const btnRetry = document.getElementById('btnReessayerSauvegarde');
      if (btnRetry) btnRetry.addEventListener('click', function (ev) { ev.stopPropagation(); enregistrerBrouillon(); });
    }
  } finally {
    sauvegardesEnAttente--;
    majEtatBoutonTransmettre();
  }
}

editeur.addEventListener('input', function () { majCompteurs(); programmerAutosave(); });
majCompteurs();

document.querySelectorAll('.studio-fmt').forEach(function (btn) {
  btn.addEventListener('click', function () {
    editeur.focus();
    document.execCommand(btn.dataset.cmd, false, btn.dataset.val || null);
    programmerAutosave();
  });
});

document.querySelectorAll('.studio-fmt-select').forEach(function (sel) {
  sel.addEventListener('change', function () {
    editeur.focus();
    document.execCommand(sel.dataset.cmd, false, sel.value);
    programmerAutosave();
  });
});

document.querySelectorAll('.studio-fmt-color').forEach(function (inp) {
  inp.addEventListener('input', function () {
    editeur.focus();
    document.execCommand(inp.dataset.cmd, false, inp.value);
    programmerAutosave();
  });
});

// --- Diff mot-à-mot (comparaison avec la version initiale) ---
function diffMots(a, b) {
  const motsA = a.split(/(\s+)/);
  const motsB = b.split(/(\s+)/);
  const n = motsA.length, m = motsB.length;
  const dp = Array.from({ length: n + 1 }, function () { return new Array(m + 1).fill(0); });
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      dp[i][j] = motsA[i] === motsB[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
  }
  let i = 0, j = 0;
  const segments = [];
  while (i < n && j < m) {
    if (motsA[i] === motsB[j]) { segments.push({ type: 'egal', texte: motsA[i] }); i++; j++; }
    else if (dp[i + 1][j] >= dp[i][j + 1]) { segments.push({ type: 'suppr', texte: motsA[i] }); i++; }
    else { segments.push({ type: 'ajout', texte: motsB[j] }); j++; }
  }
  while (i < n) { segments.push({ type: 'suppr', texte: motsA[i] }); i++; }
  while (j < m) { segments.push({ type: 'ajout', texte: motsB[j] }); j++; }
  return segments;
}

document.getElementById('chkDiff').addEventListener('change', function (ev) {
  const legende = document.getElementById('diffLegende');
  if (ev.target.checked) {
    const limite = 4000;
    const segments = diffMots((CONTENU_INITIAL || '').slice(0, limite), (editeur.innerText || '').slice(0, limite));
    let html = '';
    segments.forEach(function (s) {
      const texte = s.texte.replace(/&/g, '&amp;').replace(/</g, '&lt;');
      if (s.type === 'egal') html += texte;
      else if (s.type === 'suppr') html += '<span class="studio-diff-suppr">' + texte + '</span>';
      else html += '<span class="studio-diff-ajout">' + texte + '</span>';
    });
    vueDiff.innerHTML = '<p style="white-space:pre-wrap;">' + html + '</p>';
    editeur.classList.add('hidden');
    vueDiff.classList.remove('hidden');
    legende.classList.remove('hidden');
    legende.classList.add('flex');
  } else {
    editeur.classList.remove('hidden');
    vueDiff.classList.add('hidden');
    legende.classList.add('hidden');
    legende.classList.remove('flex');
  }
});

// --- Qualité IA ---
async function chargerQualite() {
  const elErreur = document.getElementById('qualiteErreur');
  const elDetail = document.getElementById('qualiteDetail');
  // Efface systématiquement le résultat précédent avant une nouvelle
  // tentative : sans ça, un score affiché avec succès puis une régénération
  // qui échoue laissait l'ancien score ET la nouvelle erreur visibles en
  // même temps, un état trompeur (le score affiché ne correspond plus
  // forcément au texte actuel de l'éditeur).
  elErreur.classList.add('hidden');
  elErreur.textContent = '';
  elDetail.classList.add('hidden');
  document.getElementById('qualiteScore').textContent = '–';
  document.getElementById('qualiteTitre').textContent = '—';

  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('dossier_id', DOSSIER_ID);
  donnees.append('document_type', DOCUMENT_TYPE);
  donnees.append('contenu', editeur.innerText);
  try {
    const r = await fetch('qualite-ia.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.error) {
      elErreur.textContent = data.error;
      elErreur.classList.remove('hidden');
      return;
    }
    function palier(v) { return v >= 90 ? 'Impeccable' : (v >= 70 ? 'Bon' : (v >= 50 ? 'À optimiser' : 'À revoir')); }
    document.getElementById('qualiteScore').textContent = data.score;
    document.getElementById('qualiteTitre').textContent = data.score >= 80 ? 'Excellent potentiel' : (data.score >= 60 ? 'Bon potentiel' : 'À retravailler');
    document.getElementById('qualiteAnneau').setAttribute('stroke-dasharray', data.score + ', 100');
    document.getElementById('qualiteOrtho').textContent = data.orthographe + '% • ' + palier(data.orthographe);
    document.getElementById('qualiteStyle').textContent = data.style + '% • ' + palier(data.style);
    document.getElementById('qualiteImpact').textContent = data.impact + '% • ' + palier(data.impact);
    document.getElementById('barreOrtho').style.width = data.orthographe + '%';
    document.getElementById('barreStyle').style.width = data.style + '%';
    document.getElementById('barreImpact').style.width = data.impact + '%';
    elDetail.classList.remove('hidden');
  } catch (e) {
    elErreur.textContent = 'Erreur réseau.';
    elErreur.classList.remove('hidden');
  }
}

// --- Suggestions IA ---
function majCompteursChips() {
  const comptes = { all: suggestionsActuelles.length, ortho: 0, style: 0, impact: 0 };
  suggestionsActuelles.forEach(function (s) { if (comptes[s.categorie] !== undefined) comptes[s.categorie]++; });
  document.querySelectorAll('.studio-chip').forEach(function (btn) {
    const span = btn.querySelector('.studio-chip-count');
    if (span) span.textContent = '(' + (comptes[btn.dataset.filtre] || 0) + ')';
  });
  document.getElementById('toutAccepterCompte').textContent = suggestionsActuelles.length ? '(' + suggestionsActuelles.length + ')' : '';
}

function rendreSuggestions() {
  majCompteursChips();
  const feed = document.getElementById('suggestionsFeed');
  const visibles = filtreActuel === 'all' ? suggestionsActuelles : suggestionsActuelles.filter(function (s) { return s.categorie === filtreActuel; });
  if (!visibles.length) {
    feed.innerHTML = '<p class="font-body-sm text-[12px] text-secondary text-center">Aucune suggestion' + (filtreActuel !== 'all' ? ' dans cette catégorie' : '') + '.</p>';
    return;
  }
  const labels = {
    ortho: ['Orthographe & accord', 'spellcheck', 'Priorité haute', 'text-error'],
    style: ['Style & clarté', 'psychology', 'Priorité moyenne', 'text-[#b45309]'],
    impact: ['Impact RH', 'bolt', 'Priorité moyenne', 'text-secondary'],
  };
  feed.innerHTML = '';
  visibles.forEach(function (s, idx) {
    const [label, icone, priorite, couleurPriorite] = labels[s.categorie] || labels.style;
    const carte = document.createElement('div');
    carte.className = 'bg-surface-container-lowest border border-outline-variant/50 p-space-sm rounded-xl shadow-[0_1px_4px_rgba(11,28,48,0.04)] hover:shadow-md transition-all flex flex-col gap-space-xs';
    carte.innerHTML =
      '<div class="flex items-center justify-between">' +
      '<span class="px-space-xs py-0.5 rounded-full bg-surface-container-low text-secondary font-label-md text-[10px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">' + icone + '</span>' + label + '</span>' +
      '<span class="font-label-md text-[10px] font-semibold ' + couleurPriorite + '">' + priorite + '</span>' +
      '</div>' +
      '<div class="text-[12px] text-on-surface-variant">' +
      '<div class="font-label-md text-[10px] text-secondary mb-0.5">Original détecté :</div>' +
      '<div class="studio-diff-suppr p-1 rounded block mb-1">' + s.extrait.replace(/</g, '&lt;') + '</div>' +
      '<div class="font-label-md text-[10px] text-secondary mb-0.5">Suggestion NexRaiz :</div>' +
      '<div class="studio-diff-ajout p-1 rounded block js-correction-affichee">' + s.correction.replace(/</g, '&lt;') + '</div></div>' +
      '<p class="font-body-sm text-[11px] text-secondary">' + s.probleme.replace(/</g, '&lt;') + '</p>' +
      '<div class="flex items-center justify-between pt-1">' +
      '<div class="flex items-center gap-1">' +
      '<button type="button" class="js-ignorer px-2 py-1 text-secondary hover:text-error hover:bg-error-container/30 rounded font-label-md text-[11px]">Ignorer</button>' +
      '<button type="button" class="js-editer px-2 py-1 text-secondary hover:text-on-surface hover:bg-surface-container-low rounded font-label-md text-[11px] flex items-center gap-0.5"><span class="material-symbols-outlined text-[13px]">edit</span>Éditer</button>' +
      '</div>' +
      '<button type="button" class="js-appliquer px-space-sm py-1 bg-[#10b981] hover:bg-[#059669] text-white rounded-lg font-label-md text-[11px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">check</span>Appliquer</button>' +
      '</div>';

    function retirerSuggestion() {
      const idx = suggestionsActuelles.indexOf(s);
      if (idx !== -1) suggestionsActuelles.splice(idx, 1);
      majCompteursChips();
      carte.remove();
    }

    carte.querySelector('.js-ignorer').addEventListener('click', retirerSuggestion);
    carte.querySelector('.js-editer').addEventListener('click', function () {
      const zone = carte.querySelector('.js-correction-affichee');
      if (zone.isContentEditable) { zone.contentEditable = 'false'; zone.classList.remove('ring-2', 'ring-secondary'); return; }
      zone.contentEditable = 'true';
      zone.classList.add('ring-2', 'ring-secondary');
      zone.focus();
    });
    carte.querySelector('.js-appliquer').addEventListener('click', function () {
      const correctionFinale = carte.querySelector('.js-correction-affichee').innerText;
      if (remplacerDansEditeur(s.extrait, correctionFinale)) {
        majCompteurs();
        programmerAutosave();
        retirerSuggestion();
      } else {
        alert("Passage introuvable dans l'éditeur (il a peut-être déjà été modifié).");
      }
    });
    feed.appendChild(carte);
  });
}

function remplacerDansEditeur(extrait, correction) {
  const walker = document.createTreeWalker(editeur, NodeFilter.SHOW_TEXT, null);
  let node;
  while ((node = walker.nextNode())) {
    const idx = node.textContent.indexOf(extrait);
    if (idx !== -1) {
      const avant = node.textContent.slice(0, idx);
      const apres = node.textContent.slice(idx + extrait.length);
      const parent = node.parentNode;
      const fragment = document.createDocumentFragment();
      if (avant) fragment.appendChild(document.createTextNode(avant));
      fragment.appendChild(document.createTextNode(correction));
      if (apres) fragment.appendChild(document.createTextNode(apres));
      parent.replaceChild(fragment, node);
      return true;
    }
  }
  return false;
}

function afficherMessageFeed(texte) {
  // Toujours via textContent (jamais innerHTML) : le message peut provenir
  // d'une erreur renvoyée par le service IA externe, pas d'un texte que
  // l'on contrôle entièrement.
  const feed = document.getElementById('suggestionsFeed');
  feed.innerHTML = '';
  const p = document.createElement('p');
  p.className = 'font-body-sm text-[12px] text-secondary text-center';
  p.textContent = texte;
  feed.appendChild(p);
  // Une tentative qui échoue invalide les suggestions précédemment chargées
  // (elles ne correspondent plus forcément au texte actuel de l'éditeur) :
  // sans ça, les onglets "Tous (6)/Orthographe (3)..." continuaient
  // d'afficher d'anciens chiffres alors que rien n'était plus visible ici.
  suggestionsActuelles = [];
  majCompteursChips();
}

async function chargerSuggestions() {
  afficherMessageFeed('Chargement des suggestions…');
  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('dossier_id', DOSSIER_ID);
  donnees.append('document_type', DOCUMENT_TYPE);
  donnees.append('contenu', editeur.innerText);
  try {
    const r = await fetch('suggestions-ia.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.error) {
      afficherMessageFeed(data.error);
      return;
    }
    suggestionsActuelles = data.suggestions || [];
    rendreSuggestions();
  } catch (e) {
    afficherMessageFeed('Erreur réseau.');
  }
}

document.querySelectorAll('.studio-chip').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.studio-chip').forEach(function (b) { b.classList.remove('studio-chip-active'); b.classList.add('bg-surface-container-low', 'text-secondary'); });
    btn.classList.add('studio-chip-active');
    btn.classList.remove('bg-surface-container-low', 'text-secondary');
    filtreActuel = btn.dataset.filtre;
    rendreSuggestions();
  });
});
document.getElementById('btnRegenererSuggestions').addEventListener('click', function () { chargerSuggestions(); chargerQualite(); });

// --- Repli de la zone Tonalité & actions (pour libérer de l'espace vertical) ---
(function () {
  const btnToggle = document.getElementById('btnToggleTonalite');
  const zone = document.getElementById('zoneTonalite');
  const icone = document.getElementById('iconeToggleTonalite');
  let replie = false;
  try { replie = localStorage.getItem('studioTonaliteRepliee') === '1'; } catch (e) {}
  function appliquer() {
    zone.classList.toggle('hidden', replie);
    icone.textContent = replie ? 'expand_more' : 'expand_less';
  }
  appliquer();
  btnToggle.addEventListener('click', function () {
    replie = !replie;
    appliquer();
    try { localStorage.setItem('studioTonaliteRepliee', replie ? '1' : '0'); } catch (e) {}
  });
})();

chargerQualite();
chargerSuggestions();

// --- Transmettre au candidat ---
document.getElementById('btnTransmettre').addEventListener('click', async function () {
  const btn = this;
  const avant = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">progress_activity</span>Envoi…';
  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('dossier_id', DOSSIER_ID);
  donnees.append('document_type', DOCUMENT_TYPE);
  donnees.append('contenu_initial', CONTENU_INITIAL);
  donnees.append('contenu_corrige', editeur.innerText);
  try {
    const r = await fetch('studio-envoyer.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.ok) {
      btn.innerHTML = '<span class="material-symbols-outlined text-[16px]">check_circle</span>Transmis';
      statutAutosave.textContent = 'Document transmis au candidat.';
    } else {
      throw new Error(data.error || 'Erreur.');
    }
  } catch (err) {
    alert('Erreur : ' + err.message);
    btn.disabled = false;
    btn.innerHTML = avant;
  }
});

// --- Conversion texte brut -> HTML (même heuristique que
// studio_texte_vers_html côté PHP), utilisée quand l'IA renvoie du texte
// brut à intégrer dans l'éditeur enrichi (ex. "Réécrire la section"). ---
function texteVersHtmlJs(texte) {
  const lignes = (texte || '').trim().split(/\r\n|\r|\n/);
  let html = '';
  let enListe = false;
  lignes.forEach(function (ligne) {
    const t = ligne.trim();
    if (t === '') { if (enListe) { html += '</ul>'; enListe = false; } return; }
    const estTitre = t.length <= 42 && t.toUpperCase() === t && /[A-Za-zÀ-ÖØ-öø-ÿ]/.test(t);
    const esc = t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    if (estTitre) { if (enListe) { html += '</ul>'; enListe = false; } html += '<h2>' + esc + '</h2>'; return; }
    if (/^[-•*]\s+/.test(t)) {
      if (!enListe) { html += '<ul>'; enListe = true; }
      html += '<li>' + esc.replace(/^[-•*]\s+/, '') + '</li>';
      return;
    }
    if (enListe) { html += '</ul>'; enListe = false; }
    html += '<p>' + esc + '</p>';
  });
  if (enListe) html += '</ul>';
  return html || '<p><br></p>';
}

// --- Mode manuel / assisté par IA : masque/affiche la colonne Prince AI. ---
const btnModeManuel = document.getElementById('btnModeManuel');
const btnModeIA = document.getElementById('btnModeIA');
const colonneIA = document.getElementById('colonneIA');
function activerModeIA(actif) {
  if (actif) {
    colonneIA.classList.remove('hidden');
    btnModeIA.className = 'studio-mode-btn px-space-sm py-1 rounded bg-surface-container-lowest text-on-surface font-label-md text-[11px] font-semibold shadow-sm flex items-center gap-1';
    btnModeManuel.className = 'studio-mode-btn px-space-sm py-1 rounded font-label-md text-[11px] text-secondary transition-colors';
  } else {
    colonneIA.classList.add('hidden');
    btnModeManuel.className = 'studio-mode-btn px-space-sm py-1 rounded bg-surface-container-lowest text-on-surface font-label-md text-[11px] font-semibold shadow-sm';
    btnModeIA.className = 'studio-mode-btn px-space-sm py-1 rounded font-label-md text-[11px] text-secondary transition-colors flex items-center gap-1';
  }
}
btnModeManuel.addEventListener('click', function () { activerModeIA(false); });
btnModeIA.addEventListener('click', function () { activerModeIA(true); });

// --- 3 Volets / Focus éditeur : masque/affiche la colonne PDF (et IA). ---
const btnVueTrois = document.getElementById('btnVueTrois');
const btnVueFocus = document.getElementById('btnVueFocus');
const colonnePdf = document.getElementById('colonnePdf');
function activerVue(focus) {
  if (focus) {
    colonnePdf.classList.add('hidden');
    colonneIA.classList.add('hidden');
    btnVueFocus.className = 'studio-vue-btn px-space-sm py-1 rounded bg-surface-container-lowest text-on-surface font-label-md text-[11px] font-semibold shadow-sm flex items-center gap-1';
    btnVueTrois.className = 'studio-vue-btn px-space-sm py-1 rounded text-secondary font-label-md text-[11px] flex items-center gap-1';
  } else {
    colonnePdf.classList.remove('hidden');
    activerModeIA(true);
    btnVueTrois.className = 'studio-vue-btn px-space-sm py-1 rounded bg-surface-container-lowest text-on-surface font-label-md text-[11px] font-semibold shadow-sm flex items-center gap-1';
    btnVueFocus.className = 'studio-vue-btn px-space-sm py-1 rounded text-secondary font-label-md text-[11px] flex items-center gap-1';
  }
}
btnVueTrois.addEventListener('click', function () { activerVue(false); });
btnVueFocus.addEventListener('click', function () { activerVue(true); });

// --- Menus déroulants Historique / Exporter ---
function basculerMenu(id, autresIds) {
  const menu = document.getElementById(id);
  const ouvert = !menu.classList.contains('hidden');
  autresIds.concat([id]).forEach(function (i) { document.getElementById(i).classList.add('hidden'); });
  if (!ouvert) menu.classList.remove('hidden');
}
document.getElementById('btnHistorique').addEventListener('click', function (ev) {
  ev.stopPropagation();
  const menu = document.getElementById('menuHistorique');
  if (!HISTORIQUE_VERSIONS.length) {
    menu.innerHTML = '<p class="font-body-sm text-[12px] text-secondary text-center p-space-sm">Aucune version précédente enregistrée.</p>';
  } else {
    menu.innerHTML = '';
    HISTORIQUE_VERSIONS.forEach(function (v) {
      const item = document.createElement('div');
      item.className = 'flex items-center justify-between gap-2 px-3 py-2 hover:bg-surface-container-low rounded-lg';
      item.innerHTML = '<span class="font-label-md text-[11px] text-on-surface">' + v.date + '</span><button type="button" class="js-restaurer px-2 py-1 bg-surface-container-low hover:bg-surface-container-high rounded font-label-md text-[10px] font-semibold text-secondary">Restaurer</button>';
      item.querySelector('.js-restaurer').addEventListener('click', function () {
        if (confirm('Restaurer cette version du ' + v.date + ' ? Le contenu actuel sera remplacé (une nouvelle version, pas une suppression).')) {
          editeur.innerHTML = v.html;
          majCompteurs();
          programmerAutosave();
          document.getElementById('menuHistorique').classList.add('hidden');
        }
      });
      menu.appendChild(item);
    });
  }
  basculerMenu('menuHistorique', ['menuExporter']);
});
document.getElementById('btnExporter').addEventListener('click', function (ev) {
  ev.stopPropagation();
  basculerMenu('menuExporter', ['menuHistorique']);
});
document.addEventListener('click', function () {
  document.getElementById('menuHistorique').classList.add('hidden');
  document.getElementById('menuExporter').classList.add('hidden');
});

document.getElementById('btnApercuPdf').addEventListener('click', async function () {
  const btn = this;
  const avant = btn.innerHTML;
  btn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">progress_activity</span>Génération…';
  try {
    await enregistrerBrouillon();
    const texte = editeur.innerText.trim();
    if (!texte) {
      alert("Rien à prévisualiser : le document est vide.");
      return;
    }
    // Réutilise la même passerelle que l'atelier principal (aucun fichier
    // écrit sur disque, rendu strictement identique au PDF final) plutôt
    // que de dupliquer la génération — voir apercu-correction-pdf.php.
    const form = document.getElementById('formApercuStudio');
    form.querySelector('[name="contenu_corrige"]').value = texte;
    form.submit();
  } catch (e) {
    alert('Erreur réseau.');
  } finally {
    btn.innerHTML = avant;
    document.getElementById('menuExporter').classList.add('hidden');
  }
});

// --- Réécrire la section (texte entier de l'éditeur) avec la tonalité choisie ---
document.getElementById('btnReecrireSection').addEventListener('click', async function () {
  const btn = this;
  const avant = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-[15px] animate-spin">progress_activity</span>Réécriture…';
  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('dossier_id', DOSSIER_ID);
  donnees.append('document_type', DOCUMENT_TYPE);
  donnees.append('contenu', editeur.innerText);
  donnees.append('tonalite', document.getElementById('selectTonalite').value);
  try {
    const r = await fetch('ameliorer-document-ia.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.error) { alert(data.error); return; }
    const extrait = data.texte.length > 300 ? data.texte.slice(0, 300) + '…' : data.texte;
    if (confirm('Proposition Prince AI (aperçu) :\n\n' + extrait + '\n\nAppliquer cette réécriture à tout le document ?')) {
      editeur.innerHTML = texteVersHtmlJs(data.texte);
      majCompteurs();
      programmerAutosave();
    }
  } catch (e) {
    alert('Erreur réseau.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = avant;
  }
});

// --- Tout accepter : applique toutes les suggestions actuellement listées ---
document.getElementById('btnToutAccepter').addEventListener('click', function () {
  if (!suggestionsActuelles.length) return;
  let appliquees = 0;
  suggestionsActuelles.slice().forEach(function (s) {
    if (remplacerDansEditeur(s.extrait, s.correction)) appliquees++;
  });
  suggestionsActuelles = [];
  majCompteurs();
  programmerAutosave();
  rendreSuggestions();
  if (appliquees > 0) { /* feed re-rendered empty by rendreSuggestions() above */ }
});
</script>

<?php if ($originalEstPdf): ?>
<script type="module">
import * as pdfjsLib from './assets/pdfjs/pdf.min.mjs';
pdfjsLib.GlobalWorkerOptions.workerSrc = './assets/pdfjs/pdf.worker.min.mjs';

const SOURCE_URL = <?php echo json_encode($documentOriginal ? 'classic-document-telecharger.php?id=' . $documentOriginal['id'] : ''); ?>;
let pdfDoc = null;
let pageActuelle = 1;
let scale = 1.0;

async function chargerApercuPdf() {
  pdfDoc = await pdfjsLib.getDocument({ url: SOURCE_URL }).promise;
  const thumbs = document.getElementById('pdfThumbs');
  for (let n = 1; n <= pdfDoc.numPages; n++) {
    const page = await pdfDoc.getPage(n);
    const vp = page.getViewport({ scale: 0.14 });
    const canvas = document.createElement('canvas');
    canvas.width = vp.width;
    canvas.height = vp.height;
    canvas.className = 'rounded shadow-sm cursor-pointer border-2 border-transparent';
    canvas.dataset.page = n;
    await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    canvas.addEventListener('click', function () { afficherPagePdf(parseInt(canvas.dataset.page, 10)); });
    thumbs.appendChild(canvas);
  }
  await afficherPagePdf(1);
}

async function afficherPagePdf(numero) {
  pageActuelle = numero;
  document.getElementById('pdfPageLabel').textContent = 'Page ' + numero + '/' + pdfDoc.numPages;
  document.querySelectorAll('#pdfThumbs canvas').forEach(function (c) {
    c.style.borderColor = parseInt(c.dataset.page, 10) === numero ? '#0051d5' : 'transparent';
  });
  const page = await pdfDoc.getPage(numero);
  const viewport = page.getViewport({ scale });
  const stage = document.getElementById('pdfStage');
  stage.innerHTML = '';
  const canvas = document.createElement('canvas');
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  canvas.className = 'shadow-md';
  stage.appendChild(canvas);
  await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
}

document.getElementById('btnZoomInPdf').addEventListener('click', function () { scale = Math.min(2.5, scale + 0.15); document.getElementById('pdfZoomLabel').textContent = Math.round(scale * 100) + '%'; afficherPagePdf(pageActuelle); });
document.getElementById('btnZoomOutPdf').addEventListener('click', function () { scale = Math.max(0.5, scale - 0.15); document.getElementById('pdfZoomLabel').textContent = Math.round(scale * 100) + '%'; afficherPagePdf(pageActuelle); });

chargerApercuPdf().catch(function (err) {
  document.getElementById('pdfStage').innerHTML = '<p class="font-body-sm text-[12px] text-error text-center p-space-md">Erreur de chargement du PDF : ' + err.message + '</p>';
});
</script>
<?php endif; ?>
</body>
</html>
