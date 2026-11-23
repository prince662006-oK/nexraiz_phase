<?php
/**
 * NexRaiz — Éditeur visuel "Canva" du CV / lettre de motivation (Pack
 * Classic). Le consultant modifie le texte directement sur le rendu du
 * document original (PDF.js) : seuls les blocs réellement modifiés
 * reçoivent un correctif positionné (fond + texte à la bonne position, taille
 * et couleur approximées depuis le rendu) — tout le reste du document
 * (mise en page, couleurs, polices, colonnes...) reste le rendu exact de
 * l'original, jamais régénéré à partir d'un modèle générique.
 *
 * Le brouillon (includes/canvas-editions.php) n'est jamais visible par le
 * candidat. Seul le clic sur "Valider le document final" transmet la liste
 * des blocs modifiés à canvas-valider.php, qui appelle Python/PyMuPDF
 * (includes/pdf-editeur-python.php) pour réellement supprimer le texte
 * d'origine du PDF (pas un correctif dessiné par-dessus) avant d'insérer le
 * nouveau, puis publie le résultat comme livrable via le mécanisme existant
 * (includes/livrables-classic.php) — même titre de livrable que l'éditeur
 * texte existant de consultant-corrections-classic.php, pour ne pas
 * dupliquer de canal de publication.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/canvas-editions.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$dossierId = (int) ($_GET['dossier'] ?? 0);
$documentType = $_GET['type'] ?? 'cv';
if (!in_array($documentType, ['cv', 'lettre'], true)) {
    $documentType = 'cv';
}

$stmt = db()->prepare(
    'SELECT ds.id, ds.user_id, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    header('Location: consultant-analyse-classic.php');
    exit;
}

// Le premier consultant qui ouvre un dossier non assigné se l'approprie
// (même règle que consultant-corrections-classic.php).
$stmtAssign = db()->prepare('SELECT consultant_id FROM dossiers WHERE id = ?');
$stmtAssign->execute([$dossierId]);
if ($stmtAssign->fetchColumn() === null) {
    db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ?')->execute([$consultant['id'], $dossierId]);
}

$stmt = db()->prepare('SELECT id, nom_original, chemin, uploaded_at FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$dossierId]);
$documentOriginal = null;
foreach ($stmt->fetchAll() as $d) {
    if (strpos($d['chemin'], '/' . $documentType . '/') !== false) {
        $documentOriginal = $d;
        break;
    }
}

$extensionOriginale = $documentOriginal ? strtolower(pathinfo($documentOriginal['nom_original'], PATHINFO_EXTENSION)) : null;
if (!$documentOriginal || $extensionOriginale !== 'pdf') {
    // L'éditeur visuel repose sur le rendu PDF.js du fichier original : il
    // ne peut s'appliquer qu'à un PDF réellement déposé (pas de .doc/.docx,
    // pas de CV scanné en image, pas de document absent).
    header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '&onglet=' . $documentType . '&canvas_erreur=1');
    exit;
}

$edition = canvas_edition_get($dossierId, $documentType);
$editsExistants = $edition ? (json_decode($edition['edits_json'] ?? '[]', true) ?: []) : [];

$historiqueBrut = canvas_edition_historique($dossierId, $documentType);
$historiqueJs = array_map(function (array $h): array {
    return [
        'date' => date('d/m/Y à H:i', strtotime($h['created_at'])),
        'auteur' => $h['prenom'] ? trim($h['prenom'] . ' ' . $h['nom']) : 'Auteur inconnu',
        'edits' => json_decode($h['edits_json'] ?? '[]', true) ?: [],
    ];
}, $historiqueBrut);

$libelleType = $documentType === 'lettre' ? 'Lettre de motivation' : 'CV';

render_head('Éditeur visuel — ' . $libelleType . ' — NexRaiz', 'Éditeur visuel du document original, Pack Classic.');
?>
<style>
  body{overflow:hidden;}
  #canvasStage{background:#e7e9ee repeating-linear-gradient(45deg,#e7e9ee,#e7e9ee 10px,#dfe2e8 10px,#dfe2e8 20px);}
  .canvas-page-wrap{position:relative;margin:0 auto;box-shadow:0 2px 14px rgba(0,0,0,.18);background:#fff;}
  .canvas-page-wrap canvas{display:block;}
  .canvas-text-layer{position:absolute;inset:0;overflow:hidden;line-height:1;}
  /* PDF.js (TextLayer moderne) pose --font-height et --scale-x en style
     inline sur chaque span, mais ne fournit plus sa propre feuille de style
     côté consommateur : sans ces deux règles, tous les spans héritaient de la
     taille par défaut du navigateur (16px, sans mise à l'échelle), invisible
     tant que le texte reste transparent (cas normal, non édité) mais rendant
     le bloc visiblement surdimensionné — et donc chevauchant la ligne du
     dessous — dès qu'on l'activait ou qu'on y tapait du texte. */
  .canvas-text-layer span{position:absolute;white-space:pre;cursor:text;color:transparent;transform-origin:0 0;font-size:var(--font-height,11px);transform:scaleX(var(--scale-x,1));}
  .canvas-text-layer span.canvas-run-edited{color:var(--run-color,#111);background:transparent;outline:none;}
  .canvas-text-layer span.canvas-run-active{outline:2px solid #2563eb;outline-offset:1px;color:var(--run-color,#111) !important;}
  .canvas-text-layer span:hover{outline:1px dashed rgba(37,99,235,.55);}
  .canvas-compare .canvas-text-layer span.canvas-run-edited{color:transparent !important;outline:none !important;}
  .canvas-compare .canvas-patch-layer{display:none;}
  .canvas-ocr-zone{outline:1px dashed rgba(37,99,235,.4) !important;}
  .canvas-ocr-zone-incertaine{outline:1px dashed rgba(234,88,12,.55) !important;}
  .canvas-compare .canvas-ocr-zone{outline:none !important;}
  #iaPropositionBox{display:none;}
  .canvas-spinner{border:3px solid #e2e5ea;border-top-color:#0B192C;border-radius:9999px;width:28px;height:28px;animation:canvas-spin 0.8s linear infinite;}
  @keyframes canvas-spin{to{transform:rotate(360deg);}}
</style>

<div class="flex flex-col h-screen">

  <header class="h-14 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-space-md gap-space-sm">
    <div class="flex items-center gap-space-sm min-w-0">
      <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>&onglet=<?php echo $documentType; ?>" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors shrink-0" title="Retour au dossier"><span class="material-symbols-outlined text-[20px]">arrow_back</span></a>
      <div class="min-w-0">
        <p class="font-label-md text-label-md text-on-surface font-bold truncate">Éditeur visuel — <?php echo htmlspecialchars($libelleType); ?></p>
        <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?> · <?php echo htmlspecialchars($documentOriginal['nom_original']); ?></p>
      </div>
    </div>
    <div class="flex items-center gap-space-2xs shrink-0">
      <button type="button" id="btnUndo" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors disabled:opacity-30" title="Annuler" disabled><span class="material-symbols-outlined text-[20px]">undo</span></button>
      <button type="button" id="btnRedo" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors disabled:opacity-30" title="Rétablir" disabled><span class="material-symbols-outlined text-[20px]">redo</span></button>
      <span class="w-px h-6 bg-outline-variant/60 mx-1"></span>
      <button type="button" id="btnZoomOut" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors" title="Zoom -"><span class="material-symbols-outlined text-[20px]">zoom_out</span></button>
      <span id="zoomLabel" class="font-label-md text-[12px] text-on-surface w-11 text-center">100%</span>
      <button type="button" id="btnZoomIn" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors" title="Zoom +"><span class="material-symbols-outlined text-[20px]">zoom_in</span></button>
      <span class="w-px h-6 bg-outline-variant/60 mx-1"></span>
      <button type="button" id="btnPrevPage" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors" title="Page précédente"><span class="material-symbols-outlined text-[20px]">chevron_left</span></button>
      <span id="pageLabel" class="font-label-md text-[12px] text-on-surface w-16 text-center">Page 1/1</span>
      <button type="button" id="btnNextPage" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors" title="Page suivante"><span class="material-symbols-outlined text-[20px]">chevron_right</span></button>
      <span class="w-px h-6 bg-outline-variant/60 mx-1"></span>
      <label class="flex items-center gap-1 font-label-md text-[12px] text-on-surface cursor-pointer select-none px-space-2xs">
        <input type="checkbox" id="chkComparer" class="w-4 h-4">Comparer avec l'original
      </label>
      <div class="relative">
        <button type="button" id="btnHistoriqueCanvas" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">history</span>Historique</button>
        <div id="menuHistoriqueCanvas" class="hidden absolute right-0 top-full mt-1 w-72 max-h-96 overflow-y-auto bg-surface-container-lowest rounded-xl shadow-[0_8px_24px_rgba(11,28,48,0.12)] p-1 z-50 border border-outline-variant/40"></div>
      </div>
      <span id="statutBrouillon" class="font-body-sm text-[11px] text-secondary px-space-xs"></span>
      <button type="button" id="btnApercu" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">visibility</span>Aperçu</button>
      <button type="button" id="btnValider" class="h-9 px-space-md rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">verified</span>Valider le document final</button>
    </div>
  </header>

  <div class="flex flex-1 min-w-0 min-h-0">
    <main id="canvasStage" class="flex-1 overflow-auto p-10 flex items-start justify-center">
      <div id="loadingState" class="flex flex-col items-center gap-space-sm mt-24">
        <div class="canvas-spinner"></div>
        <p class="font-body-sm text-body-sm text-secondary">Chargement du document original…</p>
      </div>
      <div id="pageWrapOuter" class="hidden"></div>
    </main>

    <aside class="w-80 shrink-0 border-l border-outline-variant/50 bg-surface-container-lowest flex flex-col overflow-y-auto p-space-md gap-space-md">
      <div>
        <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">info</span>Bloc sélectionné</p>
        <div id="selectionInfo" class="font-body-sm text-[12px] text-secondary mt-space-2xs">Cliquez sur un passage du document pour le modifier.</div>
      </div>

      <div class="border-t border-outline-variant/40 pt-space-sm">
        <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">format_color_text</span>Mise en forme</p>
        <p class="font-body-sm text-[11px] text-secondary mt-space-2xs">S'applique au bloc actuellement sélectionné.</p>
        <div class="mt-space-xs flex flex-col gap-space-xs">
          <div class="flex items-center gap-space-2xs">
            <button type="button" class="canvas-police flex-1 h-8 rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface disabled:opacity-40" style="font-family:Helvetica,Arial,sans-serif" data-police="helvetica" disabled title="Helvetica / Arial">Helv.</button>
            <button type="button" class="canvas-police flex-1 h-8 rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface disabled:opacity-40" style="font-family:'Times New Roman',Times,serif" data-police="times" disabled title="Times New Roman">Times</button>
            <button type="button" class="canvas-police flex-1 h-8 rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface disabled:opacity-40" style="font-family:'Courier New',Courier,monospace" data-police="courier" disabled title="Courier">Cour.</button>
          </div>
          <div class="flex items-center gap-space-2xs">
            <button type="button" id="canvasGras" class="flex-1 h-8 rounded-lg border border-outline-variant font-label-md text-[12px] font-bold text-on-surface disabled:opacity-40" disabled title="Gras">G</button>
            <button type="button" id="canvasItalique" class="flex-1 h-8 rounded-lg border border-outline-variant font-label-md text-[12px] italic text-on-surface disabled:opacity-40" disabled title="Italique">I</button>
            <button type="button" id="canvasSouligne" class="flex-1 h-8 rounded-lg border border-outline-variant font-label-md text-[12px] underline text-on-surface disabled:opacity-40" disabled title="Souligné">S</button>
          </div>
          <div class="flex items-center gap-space-2xs">
            <button type="button" id="canvasTailleMoins" class="w-8 h-8 rounded-lg border border-outline-variant text-on-surface flex items-center justify-center disabled:opacity-40" disabled title="Réduire la taille"><span class="material-symbols-outlined text-[16px]">remove</span></button>
            <span id="canvasTailleLabel" class="flex-1 text-center font-label-md text-[12px] text-secondary">– pt</span>
            <button type="button" id="canvasTaillePlus" class="w-8 h-8 rounded-lg border border-outline-variant text-on-surface flex items-center justify-center disabled:opacity-40" disabled title="Augmenter la taille"><span class="material-symbols-outlined text-[16px]">add</span></button>
            <input type="color" id="canvasCouleur" class="w-8 h-8 rounded-lg cursor-pointer border border-outline-variant p-0 bg-transparent disabled:opacity-40" value="#111111" disabled title="Couleur du texte">
          </div>
        </div>
      </div>

      <div class="border-t border-outline-variant/40 pt-space-sm">
        <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">auto_awesome</span>Modifier avec Prince AI</p>
        <p class="font-body-sm text-[11px] text-secondary mt-space-2xs">Sélectionnez un bloc de texte, puis proposez une amélioration. L'IA ne modifie rien automatiquement : vous validez, modifiez ou refusez chaque proposition.</p>
        <textarea id="iaInstruction" rows="2" placeholder="Consigne optionnelle (ex. : adapter au poste de comptable)..." class="w-full mt-space-xs p-space-xs rounded-lg border border-outline-variant bg-surface-container-low font-body-sm text-[12px] focus:outline-none focus:border-primary resize-none" disabled></textarea>
        <button type="button" id="btnIaProposer" class="w-full mt-space-xs h-9 rounded-lg border border-primary-container text-primary font-label-md text-[12px] font-bold flex items-center justify-center gap-1 hover:bg-primary-container/5 transition-colors disabled:opacity-40" disabled><span class="material-symbols-outlined text-[16px]">auto_awesome</span>Proposer une amélioration</button>

        <div id="iaPropositionBox" class="mt-space-sm rounded-xl border border-primary-container/40 bg-primary-container/5 p-space-sm flex flex-col gap-space-xs">
          <p class="font-label-md text-[11px] text-primary font-bold">Proposition Prince AI</p>
          <textarea id="iaPropositionTexte" rows="4" class="w-full p-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary"></textarea>
          <div class="flex gap-space-2xs">
            <button type="button" id="btnIaAccepter" class="flex-1 h-8 rounded-lg bg-primary-container text-white font-label-md text-[11px] font-bold">Appliquer</button>
            <button type="button" id="btnIaRefuser" class="flex-1 h-8 rounded-lg border border-outline-variant text-on-surface font-label-md text-[11px]">Refuser</button>
          </div>
        </div>
        <p id="iaErreur" class="font-body-sm text-[11px] text-error mt-space-xs hidden"></p>
      </div>

      <div class="border-t border-outline-variant/40 pt-space-sm mt-auto">
        <p class="font-body-sm text-[11px] text-secondary">Seuls les passages que vous modifiez sont retouchés sur le document ; tout le reste (mise en page, polices, couleurs) reste le rendu exact de l'original. Le candidat ne voit jamais ce brouillon.</p>
      </div>
    </aside>
  </div>
</div>

<!-- Modale Aperçu : rendu de toutes les pages, lecture seule -->
<div id="modalApercu" class="hidden fixed inset-0 z-50 bg-black/60 items-center justify-center p-gutter-mobile" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');}">
  <div class="bg-surface-container-lowest rounded-2xl shadow-xl w-full max-w-3xl max-h-[92vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-space-lg py-space-md border-b border-outline-variant/50 shrink-0">
      <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Aperçu du document</h3>
      <button type="button" id="btnFermerApercu" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px]">close</span></button>
    </div>
    <div id="apercuContenu" class="flex-1 overflow-y-auto p-space-lg flex flex-col gap-space-md bg-surface-container-low"></div>
  </div>
</div>

<script type="module">
import * as pdfjsLib from './assets/pdfjs/pdf.min.mjs';
pdfjsLib.GlobalWorkerOptions.workerSrc = './assets/pdfjs/pdf.worker.min.mjs';

const DOSSIER_ID = <?php echo json_encode($dossierId); ?>;
const DOCUMENT_TYPE = <?php echo json_encode($documentType); ?>;
const SOURCE_URL = <?php echo json_encode('classic-document-telecharger.php?id=' . $documentOriginal['id']); ?>;
const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
let editsByPage = <?php echo json_encode($editsExistants, JSON_UNESCAPED_UNICODE) ?: '{}'; ?>;
const HISTORIQUE_CANVAS = <?php echo json_encode($historiqueJs, JSON_UNESCAPED_UNICODE); ?>;
// Les clés JSON sont toujours des chaînes ; on s'assure que editsByPage est
// bien un objet {page: {runKey: {...}}}, jamais un tableau.
if (Array.isArray(editsByPage)) editsByPage = {};

let pdfDoc = null;
let currentPage = 1;
let totalPages = 1;
let scale = 1.0;
let comparerMode = false;
let selectedSpan = null;
let selectedRunKey = null;
let selectedPageNum = null;
let rectOriginalActif = null; // rectangle du bloc en cours d'édition, mesuré AVANT toute frappe
let texteOriginalActif = null; // texte réel du bloc en cours d'édition, capturé AVANT toute frappe
let formatActif = null; // mise en forme du bloc en cours d'édition : {gras,italique,souligne,police,taillePolice}
let ocrByPage = {};    // {page: [{texte,confiance,left,top,width,height}, ...]} en points PDF (scale 1), une fois l'OCR lancé
let pageEstScannee = {}; // {page: bool} — déterminé après la première lecture du calque de texte natif
let undoStack = [];
let redoStack = [];
let autosaveTimer = null;
let dernierePropositionIA = null;

const stageLoading = document.getElementById('loadingState');
const pageWrapOuter = document.getElementById('pageWrapOuter');
const zoomLabel = document.getElementById('zoomLabel');
const pageLabel = document.getElementById('pageLabel');
const statutBrouillon = document.getElementById('statutBrouillon');
const selectionInfo = document.getElementById('selectionInfo');
const iaInstruction = document.getElementById('iaInstruction');
const btnIaProposer = document.getElementById('btnIaProposer');
const iaPropositionBox = document.getElementById('iaPropositionBox');
const iaPropositionTexte = document.getElementById('iaPropositionTexte');
const iaErreur = document.getElementById('iaErreur');
const canvasPoliceBoutons = Array.from(document.querySelectorAll('.canvas-police'));
const canvasGras = document.getElementById('canvasGras');
const canvasItalique = document.getElementById('canvasItalique');
const canvasSouligne = document.getElementById('canvasSouligne');
const canvasTailleMoins = document.getElementById('canvasTailleMoins');
const canvasTaillePlus = document.getElementById('canvasTaillePlus');
const canvasTailleLabel = document.getElementById('canvasTailleLabel');
const canvasCouleur = document.getElementById('canvasCouleur');

const POLICES_CSS = {
  helvetica: 'Helvetica, Arial, sans-serif',
  times: '"Times New Roman", Times, serif',
  courier: '"Courier New", Courier, monospace',
};

function rgbVersHex(rgb) {
  const m = /rgb\((\d+),\s*(\d+),\s*(\d+)\)/.exec(rgb || '');
  if (!m) return '#111111';
  return '#' + [m[1], m[2], m[3]].map(function (v) { return (+v).toString(16).padStart(2, '0'); }).join('');
}
function hexVersRgb(hex) {
  const r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
  return 'rgb(' + r + ',' + g + ',' + b + ')';
}

function cloneEdits(o) { return JSON.parse(JSON.stringify(o)); }

function pushHistory() {
  undoStack.push(cloneEdits(editsByPage));
  if (undoStack.length > 50) undoStack.shift();
  redoStack = [];
  refreshUndoRedoButtons();
}
function refreshUndoRedoButtons() {
  document.getElementById('btnUndo').disabled = undoStack.length === 0;
  document.getElementById('btnRedo').disabled = redoStack.length === 0;
}

async function chargerDocument() {
  const task = pdfjsLib.getDocument({ url: SOURCE_URL });
  pdfDoc = await task.promise;
  totalPages = pdfDoc.numPages;

  stageLoading.classList.add('hidden');
  pageWrapOuter.classList.remove('hidden');
  await afficherPage(currentPage);
}

async function afficherPage(numero) {
  currentPage = Math.min(Math.max(1, numero), totalPages);
  pageLabel.textContent = 'Page ' + currentPage + '/' + totalPages;

  const page = await pdfDoc.getPage(currentPage);
  const viewport = page.getViewport({ scale });

  pageWrapOuter.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'canvas-page-wrap' + (comparerMode ? ' canvas-compare' : '');
  wrap.style.width = viewport.width + 'px';
  wrap.style.height = viewport.height + 'px';

  const canvas = document.createElement('canvas');
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  wrap.appendChild(canvas);

  const patchCanvas = document.createElement('canvas');
  patchCanvas.width = viewport.width;
  patchCanvas.height = viewport.height;
  patchCanvas.className = 'canvas-patch-layer';
  patchCanvas.style.position = 'absolute';
  patchCanvas.style.inset = '0';
  wrap.appendChild(patchCanvas);

  const textLayerDiv = document.createElement('div');
  textLayerDiv.className = 'canvas-text-layer';
  textLayerDiv.style.width = viewport.width + 'px';
  textLayerDiv.style.height = viewport.height + 'px';
  wrap.appendChild(textLayerDiv);

  pageWrapOuter.appendChild(wrap);

  const ctx = canvas.getContext('2d');
  await page.render({ canvasContext: ctx, viewport }).promise;

  const textContent = await page.getTextContent();
  const estScannee = textContent.items.length === 0;
  pageEstScannee[currentPage] = estScannee;

  let spans = [];
  if (!estScannee) {
    const textLayer = new pdfjsLib.TextLayer({ textContentSource: textContent, container: textLayerDiv, viewport });
    await textLayer.render();
    spans = Array.from(textLayerDiv.querySelectorAll('span'));
  } else if (ocrByPage[currentPage]) {
    spans = construireZonesOcr(ocrByPage[currentPage], textLayerDiv);
  } else {
    afficherBanniereOcr(wrap);
  }

  const edits = editsByPage[String(currentPage)] || {};

  spans.forEach(function (span, index) {
    const runKey = String(index);
    span.dataset.runKey = runKey;
    span.addEventListener('mousedown', function (ev) { ev.preventDefault(); activerBloc(span, runKey, currentPage, ctx, patchCanvas); });
    span.addEventListener('blur', function () { validerEditionBloc(span, runKey, currentPage, ctx); });
    span.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); span.blur(); }
      if (ev.key === 'Escape') { ev.preventDefault(); span.blur(); }
    });

    if (edits[runKey]) {
      appliquerStyleEdite(span, edits[runKey]);
      span.textContent = edits[runKey].texte;
    }
  });

  redessinerPatches(patchCanvas, ctx, edits);
}

/**
 * Construit des blocs modifiables à partir des lignes détectées par l'OCR
 * (coordonnées en points PDF, scale 1) — repositionnés au zoom courant, puis
 * branchés sur exactement les mêmes gestionnaires (activerBloc,
 * validerEditionBloc) que les blocs natifs d'un PDF textuel : même
 * mécanisme d'échantillonnage de couleur, de correctif et d'export.
 * Limite connue : la hauteur de police est approximée depuis la hauteur de
 * la zone détectée (pas de métrique de police réelle, contrairement au
 * calque de texte natif de PDF.js) — à ajuster manuellement si besoin.
 */
function construireZonesOcr(lignes, container) {
  return lignes.map(function (ligne) {
    const span = document.createElement('span');
    span.textContent = ligne.texte;
    span.dataset.confiance = String(ligne.confiance);
    span.title = 'Détection OCR — confiance ' + ligne.confiance + '%';
    span.className = 'canvas-ocr-zone' + (ligne.confiance < 70 ? ' canvas-ocr-zone-incertaine' : '');
    span.style.position = 'absolute';
    span.style.left = (ligne.left * scale) + 'px';
    span.style.top = (ligne.top * scale) + 'px';
    span.style.width = (ligne.width * scale) + 'px';
    span.style.height = (ligne.height * scale) + 'px';
    const taillePolicePx = Math.max(6, ligne.height * 0.8 * scale);
    span.style.fontSize = taillePolicePx + 'px';
    span.style.lineHeight = taillePolicePx + 'px';
    span.style.whiteSpace = 'pre';
    container.appendChild(span);
    return span;
  });
}

function afficherBanniereOcr(wrap) {
  const banniere = document.createElement('div');
  banniere.className = 'absolute inset-0 flex items-center justify-center';
  banniere.style.background = 'rgba(255,255,255,0.35)';
  banniere.innerHTML =
    '<div class="bg-surface-container-lowest rounded-xl shadow-lg p-space-md flex flex-col items-center gap-space-xs max-w-xs text-center">' +
    '<span class="material-symbols-outlined text-[32px] text-primary">document_scanner</span>' +
    '<p class="font-label-md text-label-md text-on-surface font-bold">Document scanné détecté</p>' +
    '<p class="font-body-sm text-[11px] text-secondary">Aucun texte sélectionnable sur cette page — lancez la reconnaissance optique (OCR) pour détecter des zones modifiables. La précision n\'est jamais garantie à 100 % : vérifiez chaque zone avant de la corriger.</p>' +
    '<button type="button" id="btnLancerOcr" class="h-9 px-space-md rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">auto_fix_high</span>Lancer l\'OCR sur cette page</button>' +
    '</div>';
  wrap.style.position = 'relative';
  wrap.appendChild(banniere);
  document.getElementById('btnLancerOcr').addEventListener('click', function () { lancerOcr(currentPage); });
}

async function lancerOcr(numeroPage) {
  const btn = document.getElementById('btnLancerOcr');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">progress_activity</span>Analyse en cours…';
  }
  try {
    const page = await pdfDoc.getPage(numeroPage);
    // Rendu à l'échelle 1 spécifiquement pour l'OCR, indépendant du zoom
    // d'affichage courant : les coordonnées obtenues sont alors directement
    // des points PDF, exactement comme rectCssDepuisSpan() pour les blocs
    // natifs — aucune conversion supplémentaire nécessaire ensuite.
    const viewport1 = page.getViewport({ scale: 1 });
    const canvasOcr = document.createElement('canvas');
    canvasOcr.width = viewport1.width;
    canvasOcr.height = viewport1.height;
    await page.render({ canvasContext: canvasOcr.getContext('2d'), viewport: viewport1 }).promise;
    const imageBase64 = canvasOcr.toDataURL('image/png');

    const donnees = new FormData();
    donnees.append('_csrf', CSRF_TOKEN);
    donnees.append('dossier_id', DOSSIER_ID);
    donnees.append('image_base64', imageBase64);
    const r = await fetch('canvas-ocr.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.error) {
      alert(data.error);
      if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined text-[16px]">auto_fix_high</span>Lancer l\'OCR sur cette page'; }
      return;
    }
    ocrByPage[numeroPage] = data.lignes;
    afficherPage(numeroPage);
  } catch (e) {
    alert('Erreur réseau pendant l\'OCR.');
    if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined text-[16px]">auto_fix_high</span>Lancer l\'OCR sur cette page'; }
  }
}

/**
 * Boîte visuelle RÉELLE d'un span, relative à son offsetParent — contrairement
 * à offsetLeft/offsetTop/offsetWidth/offsetHeight, getBoundingClientRect()
 * tient compte des transformations CSS. PDF.js applique fréquemment un
 * transform: scaleX(...) sur les spans du calque de texte pour corriger la
 * largeur réelle du texte extrait par rapport au rendu : en ignorant ce
 * transform, le rectangle de correctif pouvait être trop étroit et laisser
 * dépasser l'ancien texte sur les côtés — c'est la cause du problème
 * persistant signalé lors de l'édition.
 */
function rectVisuelSpan(span) {
  const rectSpan = span.getBoundingClientRect();
  const parent = span.offsetParent || span.parentElement;
  const rectParent = parent.getBoundingClientRect();
  return {
    left: rectSpan.left - rectParent.left,
    top: rectSpan.top - rectParent.top,
    width: rectSpan.width,
    height: rectSpan.height,
  };
}

function rectCssDepuisSpan(span) {
  // Position/taille normalisées "PDF points" (scale 1), indépendantes du
  // zoom courant : permet de rouvrir l'éditeur ou de valider le document à
  // n'importe quel niveau de zoom sans recalcul.
  const r = rectVisuelSpan(span);
  return {
    left: r.left / scale,
    top: r.top / scale,
    width: r.width / scale,
    height: r.height / scale,
    fontSize: parseFloat(span.style.fontSize || '0') / scale,
  };
}

/**
 * Échantillonne la zone en ajoutant une marge généreuse (20 % de la hauteur)
 * autour du rectangle visuel, pour couvrir les hampes/jambages (ascendantes/
 * descendantes de lettres comme b, g, j, p, q, y) et le liseré d'anti-
 * crénelage qui dépassent souvent la boîte logique d'un glyphe.
 */
function echantillonnerCouleurs(ctx, leftCss, topCss, widthCss, heightCss) {
  const marge = Math.max(2, heightCss * 0.2);
  const x = Math.max(0, Math.round(leftCss - marge));
  const y = Math.max(0, Math.round(topCss - marge));
  const w = Math.max(1, Math.round(widthCss + marge * 2));
  const h = Math.max(1, Math.round(heightCss + marge * 2));
  let data;
  try {
    data = ctx.getImageData(x, y, Math.min(w, ctx.canvas.width - x), Math.min(h, ctx.canvas.height - y)).data;
  } catch (e) {
    return { bg: 'rgb(255,255,255)', texte: 'rgb(17,17,17)', fiable: false };
  }
  const comptes = new Map();
  for (let i = 0; i < data.length; i += 4) {
    const cle = (data[i] >> 3) + ',' + (data[i + 1] >> 3) + ',' + (data[i + 2] >> 3);
    comptes.set(cle, (comptes.get(cle) || 0) + 1);
  }
  let cleFond = null, maxCompte = -1;
  comptes.forEach(function (c, cle) { if (c > maxCompte) { maxCompte = c; cleFond = cle; } });
  const nbPixels = data.length / 4;
  // Si aucune couleur ne domine clairement (fond texturé, dégradé, image),
  // l'échantillonnage n'est pas fiable : mieux vaut un blanc prévisible
  // qu'une couleur devinée au hasard qui ne masquerait pas correctement
  // l'ancien texte.
  const fiable = nbPixels > 0 && (maxCompte / nbPixels) >= 0.35;
  if (!fiable) {
    return { bg: 'rgb(255,255,255)', texte: 'rgb(17,17,17)', fiable: false };
  }
  const fond = cleFond.split(',').map(function (v) { return parseInt(v, 10) * 8; });
  let tr = 0, tg = 0, tb = 0, tn = 0;
  for (let i = 0; i < data.length; i += 4) {
    const dist = Math.abs(data[i] - fond[0]) + Math.abs(data[i + 1] - fond[1]) + Math.abs(data[i + 2] - fond[2]);
    if (dist > 60) { tr += data[i]; tg += data[i + 1]; tb += data[i + 2]; tn++; }
  }
  const texte = tn > 0 ? [Math.round(tr / tn), Math.round(tg / tn), Math.round(tb / tn)] : [17, 17, 17];
  return { bg: 'rgb(' + fond.join(',') + ')', texte: 'rgb(' + texte.join(',') + ')', fiable: true };
}

function activerBloc(span, runKey, pageNum, ctx, patchCanvas) {
  if (selectedSpan && selectedSpan !== span) {
    selectedSpan.blur();
  }
  document.querySelectorAll('.canvas-run-active').forEach(function (s) { s.classList.remove('canvas-run-active'); });
  selectedSpan = span;
  selectedRunKey = runKey;
  selectedPageNum = pageNum;
  span.classList.add('canvas-run-active');

  const existant = (editsByPage[String(pageNum)] || {})[runKey];
  // Capture le rectangle AVANT toute frappe : pour un bloc jamais modifié,
  // c'est la boîte du texte encore original. Pour un bloc déjà modifié
  // qu'on rouvre, on reprend le rectangle déjà enregistré plutôt que de
  // re-mesurer le span (qui a maintenant la taille du texte déjà remplacé,
  // pas celle du texte d'origine à recouvrir). Mémorisé pour que
  // validerEditionBloc() réutilise CE rectangle plutôt que de re-mesurer le
  // span après la frappe — sinon un remplacement plus court que l'original
  // ferait rétrécir le rectangle enregistré, et donc le correctif final,
  // laissant dépasser la fin de l'ancien texte.
  const rectOriginal = existant ? existant.rect : rectCssDepuisSpan(span);
  rectOriginalActif = rectOriginal;
  // Capture le texte réellement original AVANT toute frappe — jamais
  // reconstruit après coup depuis le span, qui contient déjà le nouveau
  // texte au moment où validerEditionBloc() s'exécute (sur "blur"). Sans
  // ça, le tout premier changement d'un bloc était silencieusement perdu :
  // le texte "original" était lu sur le span déjà modifié, donc identique
  // au texte final, ce qui faisait croire à "aucun changement réel".
  texteOriginalActif = existant ? existant.original : span.textContent;
  let couleurs;
  if (existant && existant.couleurTexte) {
    couleurs = { bg: existant.couleurFond, texte: existant.couleurTexte };
  } else {
    const rVisuel = rectVisuelSpan(span);
    couleurs = echantillonnerCouleurs(ctx, rVisuel.left, rVisuel.top, rVisuel.width, rVisuel.height);
  }
  span.style.setProperty('--run-color', couleurs.texte);
  span.dataset.couleurFond = couleurs.bg;
  span.dataset.couleurTexte = couleurs.texte;

  // Dessine immédiatement le correctif, AVANT que le consultant ne tape quoi
  // que ce soit : sans ça, le rectangle n'apparaissait qu'après avoir quitté
  // le bloc (sur "blur"), donc le nouveau texte s'affichait par-dessus
  // l'ancien, encore entièrement visible, pendant toute la frappe. Ce
  // correctif est seulement visuel (aperçu local) — rien n'est encore écrit
  // dans editsByPage tant que le bloc n'est pas réellement modifié.
  if (patchCanvas) {
    const edits = editsByPage[String(pageNum)] || {};
    const apercu = Object.assign({}, edits);
    apercu[runKey] = { rect: rectOriginal, couleurFond: couleurs.bg, couleurTexte: couleurs.texte };
    redessinerPatches(patchCanvas, ctx, apercu);
  }

  span.contentEditable = 'true';
  span.classList.add('canvas-run-edited');
  span.focus();

  const sel = window.getSelection();
  const range = document.createRange();
  range.selectNodeContents(span);
  sel.removeAllRanges();
  sel.addRange(range);

  const noteFond = couleurs.fiable === false
    ? '<br><span class="text-orange-600">Fond non reconnu avec certitude — recouvert en blanc par défaut, vérifiez le rendu.</span>'
    : '';
  selectionInfo.innerHTML = 'Bloc actif : <strong>' + (span.textContent.length > 40 ? span.textContent.slice(0, 40) + '…' : span.textContent) + '</strong><br>Modifiez le texte directement, ou utilisez Prince AI ci-dessous.' + noteFond;
  iaInstruction.disabled = false;
  btnIaProposer.disabled = false;
  iaPropositionBox.style.display = 'none';
  iaErreur.classList.add('hidden');

  // --- Mise en forme : reprend le style déjà enregistré pour ce bloc, s'il y
  // en a un. Pour un bloc jamais retouché, "police" et "taillePolice" restent
  // à null (= ne rien forcer, garder le rendu natif du PDF) : les appliquer
  // par défaut (ex. Helvetica 11pt) changeait visuellement CHAQUE bloc dès
  // qu'on cliquait pour l'éditer, même sans toucher la mise en forme — un
  // bloc à police/taille différente de ce défaut grandissait ou changeait de
  // police au clic, débordant sur la ligne du dessous. "tailleOriginale" sert
  // de point de départ réel (mesuré sur le rendu actuel) pour les boutons +/-,
  // sans jamais être appliquée tant que le consultant n'y touche pas.
  formatActif = {
    gras: !!(existant && existant.gras),
    italique: !!(existant && existant.italique),
    souligne: !!(existant && existant.souligne),
    police: (existant && existant.police) || null,
    taillePolice: (existant && existant.taillePolice) || null,
    tailleOriginale: (existant && existant.taillePolice) || parseFloat(getComputedStyle(span).fontSize) / scale || rectOriginal.fontSize || 11,
  };
  canvasPoliceBoutons.concat([canvasGras, canvasItalique, canvasSouligne, canvasTailleMoins, canvasTaillePlus, canvasCouleur]).forEach(function (el) { el.disabled = false; });
  canvasPoliceBoutons.forEach(function (b) {
    const actif = b.dataset.police === formatActif.police;
    b.classList.toggle('bg-primary-container', actif);
    b.classList.toggle('text-white', actif);
  });
  canvasGras.classList.toggle('bg-primary-container', formatActif.gras);
  canvasGras.classList.toggle('text-white', formatActif.gras);
  canvasItalique.classList.toggle('bg-primary-container', formatActif.italique);
  canvasItalique.classList.toggle('text-white', formatActif.italique);
  canvasSouligne.classList.toggle('bg-primary-container', formatActif.souligne);
  canvasSouligne.classList.toggle('text-white', formatActif.souligne);
  canvasTailleLabel.textContent = Math.round(formatActif.taillePolice || formatActif.tailleOriginale) + ' pt';
  canvasCouleur.value = rgbVersHex(couleurs.texte);
  appliquerFormatActifAuSpan();
}

function appliquerFormatActifAuSpan() {
  if (!selectedSpan || !formatActif) return;
  selectedSpan.style.fontWeight = formatActif.gras ? 'bold' : '';
  selectedSpan.style.fontStyle = formatActif.italique ? 'italic' : '';
  selectedSpan.style.textDecoration = formatActif.souligne ? 'underline' : '';
  // "police"/"taillePolice" restent null tant que le consultant n'a rien
  // choisi explicitement : ne touche alors pas au rendu natif du span.
  if (formatActif.police) selectedSpan.style.fontFamily = POLICES_CSS[formatActif.police] || '';
  if (formatActif.taillePolice) selectedSpan.style.fontSize = (formatActif.taillePolice * scale) + 'px';
}

function validerEditionBloc(span, runKey, pageNum, ctx) {
  const texteFinal = span.textContent;
  // Le texte original vient de activerBloc() (capturé avant toute frappe) —
  // jamais reconstruit ici depuis le span, qui contient déjà le texte final
  // à ce stade (sur "blur"). L'ancien code comparait le texte final à
  // lui-même pour un premier changement, concluait "aucune modification" et
  // perdait silencieusement l'édition : c'était le bug derrière le
  // problème persistant signalé par l'utilisateur.
  const original = texteOriginalActif !== null ? texteOriginalActif : texteFinal;

  const page = String(pageNum);
  if (!editsByPage[page]) editsByPage[page] = {};
  const edits = editsByPage[page];

  const inchange = texteFinal === original;
  // Une mise en forme (gras/italique/souligné/police/taille) peut être
  // appliquée SANS que le texte change : sans ce calcul, un bloc dont seul le
  // style a été modifié aurait été supprimé ci-dessous comme "aucune
  // modification réelle", perdant silencieusement le style choisi.
  const formatModifie = !!(formatActif && (
    formatActif.gras || formatActif.italique || formatActif.souligne ||
    formatActif.police || formatActif.taillePolice !== null
  ));

  pushHistory();

  if (texteFinal.trim() === '' || (inchange && !formatModifie && !edits[runKey])) {
    // Rien à conserver pour ce bloc : pas de modification réelle.
    delete edits[runKey];
  } else {
    // Utilise le rectangle mesuré AVANT la frappe (activerBloc), jamais une
    // nouvelle mesure du span après édition : un remplacement plus court
    // ferait sinon rétrécir la zone de correctif en dessous de la taille
    // réelle de l'ancien texte.
    const rect = rectOriginalActif || rectCssDepuisSpan(span);
    edits[runKey] = {
      original: original,
      texte: texteFinal,
      rect: rect,
      gras: formatActif ? formatActif.gras : false,
      italique: formatActif ? formatActif.italique : false,
      souligne: formatActif ? formatActif.souligne : false,
      police: (formatActif && formatActif.police) || 'helvetica',
      taillePolice: (formatActif && (formatActif.taillePolice || formatActif.tailleOriginale)) || rect.fontSize || 11,
      couleurFond: span.dataset.couleurFond,
      couleurTexte: span.dataset.couleurTexte,
    };
  }

  rectOriginalActif = null;
  texteOriginalActif = null;
  formatActif = null;
  canvasPoliceBoutons.concat([canvasGras, canvasItalique, canvasSouligne, canvasTailleMoins, canvasTaillePlus, canvasCouleur]).forEach(function (el) { el.disabled = true; });
  programmerAutosave();
  // Reconstruction visuelle complète de la page pour régénérer correctement
  // les correctifs (fond) dans l'ordre, et repasser le bloc en lecture.
  afficherPage(currentPage);
}

function appliquerStyleEdite(span, edit) {
  span.classList.add('canvas-run-edited');
  span.style.setProperty('--run-color', edit.couleurTexte || '#111');
  span.dataset.couleurFond = edit.couleurFond;
  span.dataset.couleurTexte = edit.couleurTexte;
  span.style.fontWeight = edit.gras ? 'bold' : '';
  span.style.fontStyle = edit.italique ? 'italic' : '';
  span.style.textDecoration = edit.souligne ? 'underline' : '';
  span.style.fontFamily = POLICES_CSS[edit.police] || '';
  if (edit.taillePolice) {
    span.style.fontSize = (edit.taillePolice * scale) + 'px';
  }
}

function redessinerPatches(patchCanvas, baseCtx, edits) {
  const pctx = patchCanvas.getContext('2d');
  pctx.clearRect(0, 0, patchCanvas.width, patchCanvas.height);
  Object.keys(edits).forEach(function (runKey) {
    const e = edits[runKey];
    const left = e.rect.left * scale;
    const top = e.rect.top * scale;
    const width = e.rect.width * scale;
    const height = e.rect.height * scale;
    // Marge généreuse (pas seulement 1px) : couvre les hampes/jambages et
    // tout écart résiduel entre la boîte mesurée par le calque de texte de
    // PDF.js et l'encre réellement imprimée par le rendu canvas — vérifié
    // sur un cas réel (texte de 24 caractères) où la boîte du calque de
    // texte sous-estimait la largeur réellement dessinée d'environ 15 % de
    // la largeur totale, concentrée en fin de texte (dernières lettres
    // visibles après le correctif). La marge droite est donc nettement plus
    // généreuse que la gauche, qui correspond déjà au point de départ réel
    // du texte. Mieux vaut déborder sur le fond environnant (en général
    // uni) que laisser dépasser l'ancien texte.
    const margeGauche = Math.max(3, width * 0.03);
    const margeDroite = Math.max(18, width * 0.22);
    const margeY = Math.max(4, height * 0.22);
    pctx.fillStyle = e.couleurFond || 'rgb(255,255,255)';
    pctx.fillRect(left - margeGauche, top - margeY, width + margeGauche + margeDroite, height + margeY * 2);
  });
}

function programmerAutosave() {
  statutBrouillon.textContent = 'Modifications non enregistrées…';
  clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(enregistrerBrouillon, 1200);
}

async function enregistrerBrouillon() {
  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('dossier_id', DOSSIER_ID);
  donnees.append('document_type', DOCUMENT_TYPE);
  donnees.append('source_chemin', SOURCE_URL);
  donnees.append('edits', JSON.stringify(editsByPage));
  try {
    const r = await fetch('canvas-sauvegarder.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.ok) {
      statutBrouillon.textContent = 'Brouillon enregistré à ' + data.enregistre_le;
    } else {
      statutBrouillon.textContent = 'Erreur d\'enregistrement du brouillon.';
    }
  } catch (e) {
    statutBrouillon.textContent = 'Erreur réseau — brouillon non enregistré.';
  }
}

// --- Undo / Redo ---
document.getElementById('btnUndo').addEventListener('click', function () {
  if (!undoStack.length) return;
  redoStack.push(cloneEdits(editsByPage));
  editsByPage = undoStack.pop();
  refreshUndoRedoButtons();
  programmerAutosave();
  afficherPage(currentPage);
});
document.getElementById('btnRedo').addEventListener('click', function () {
  if (!redoStack.length) return;
  undoStack.push(cloneEdits(editsByPage));
  editsByPage = redoStack.pop();
  refreshUndoRedoButtons();
  programmerAutosave();
  afficherPage(currentPage);
});

// --- Zoom ---
document.getElementById('btnZoomIn').addEventListener('click', function () { changerZoom(0.15); });
document.getElementById('btnZoomOut').addEventListener('click', function () { changerZoom(-0.15); });
function changerZoom(delta) {
  scale = Math.min(2.5, Math.max(0.5, Math.round((scale + delta) * 100) / 100));
  zoomLabel.textContent = Math.round(scale * 100) + '%';
  afficherPage(currentPage);
}

// --- Navigation pages ---
document.getElementById('btnPrevPage').addEventListener('click', function () { afficherPage(currentPage - 1); });
document.getElementById('btnNextPage').addEventListener('click', function () { afficherPage(currentPage + 1); });

// --- Comparer avec l'original ---
document.getElementById('chkComparer').addEventListener('change', function (ev) {
  comparerMode = ev.target.checked;
  afficherPage(currentPage);
});

// --- Mise en forme du bloc sélectionné (style appliqué immédiatement sur
// l'écran ; persisté dans editsByPage seulement à la validation du bloc,
// voir validerEditionBloc()). Chaque contrôle annule son "mousedown" : sans
// ça, cliquer dessus retire le focus du span en cours d'édition, ce qui
// déclenche son "blur" AVANT le clic et referme/valide le bloc (le span
// édité est alors détruit et reconstruit par afficherPage()) — le contrôle
// agirait sur un bloc déjà refermé, silencieusement sans effet visible. ---
canvasPoliceBoutons.concat([canvasGras, canvasItalique, canvasSouligne, canvasTailleMoins, canvasTaillePlus, canvasCouleur]).forEach(function (el) {
  el.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
});

function basculerFormatBouton(bouton, cle) {
  bouton.addEventListener('click', function () {
    if (!formatActif) return;
    formatActif[cle] = !formatActif[cle];
    bouton.classList.toggle('bg-primary-container', formatActif[cle]);
    bouton.classList.toggle('text-white', formatActif[cle]);
    appliquerFormatActifAuSpan();
  });
}
basculerFormatBouton(canvasGras, 'gras');
basculerFormatBouton(canvasItalique, 'italique');
basculerFormatBouton(canvasSouligne, 'souligne');

canvasPoliceBoutons.forEach(function (bouton) {
  bouton.addEventListener('click', function () {
    if (!formatActif) return;
    formatActif.police = bouton.dataset.police;
    canvasPoliceBoutons.forEach(function (b) {
      const actif = b === bouton;
      b.classList.toggle('bg-primary-container', actif);
      b.classList.toggle('text-white', actif);
    });
    appliquerFormatActifAuSpan();
  });
});

function changerTaillePolice(delta) {
  if (!formatActif) return;
  const base = formatActif.taillePolice || formatActif.tailleOriginale;
  formatActif.taillePolice = Math.min(72, Math.max(5, Math.round((base + delta) * 2) / 2));
  canvasTailleLabel.textContent = Math.round(formatActif.taillePolice) + ' pt';
  appliquerFormatActifAuSpan();
}
canvasTailleMoins.addEventListener('click', function () { changerTaillePolice(-1); });
canvasTaillePlus.addEventListener('click', function () { changerTaillePolice(1); });

canvasCouleur.addEventListener('input', function () {
  if (!selectedSpan) return;
  const rgb = hexVersRgb(canvasCouleur.value);
  selectedSpan.dataset.couleurTexte = rgb;
  selectedSpan.style.setProperty('--run-color', rgb);
});


// --- Historique des sauvegardes (persiste entre deux ouvertures de l'éditeur,
// contrairement à Annuler/Rétablir qui ne vit que pendant la session). ---
document.getElementById('btnHistoriqueCanvas').addEventListener('click', function (ev) {
  ev.stopPropagation();
  const menu = document.getElementById('menuHistoriqueCanvas');
  const estOuvert = !menu.classList.contains('hidden');
  if (estOuvert) { menu.classList.add('hidden'); return; }
  if (!HISTORIQUE_CANVAS.length) {
    menu.innerHTML = '<p class="font-body-sm text-[12px] text-secondary text-center p-space-sm">Aucune sauvegarde antérieure.</p>';
  } else {
    menu.innerHTML = '';
    HISTORIQUE_CANVAS.forEach(function (h) {
      const item = document.createElement('div');
      item.className = 'flex items-center justify-between gap-2 px-3 py-2 hover:bg-surface-container-low rounded-lg';
      item.innerHTML = '<span class="font-label-md text-[11px] text-on-surface">' + h.date + '<br><span class="text-secondary">' + h.auteur.replace(/</g, '&lt;') + '</span></span>' +
        '<button type="button" class="js-restaurer-canvas px-2 py-1 bg-surface-container-low hover:bg-surface-container-high rounded font-label-md text-[10px] font-semibold text-secondary shrink-0">Restaurer</button>';
      item.querySelector('.js-restaurer-canvas').addEventListener('click', function () {
        if (!confirm('Restaurer la version du ' + h.date + ' ? L\'état actuel sera remplacé (une nouvelle sauvegarde, rien n\'est supprimé).')) return;
        pushHistory();
        editsByPage = cloneEdits(h.edits);
        if (selectedSpan) { selectedSpan.blur(); }
        afficherPage(currentPage);
        programmerAutosave();
        menu.classList.add('hidden');
      });
      menu.appendChild(item);
    });
  }
  menu.classList.remove('hidden');
});
document.addEventListener('click', function () {
  document.getElementById('menuHistoriqueCanvas').classList.add('hidden');
});

// --- Prince AI sur le bloc sélectionné ---
btnIaProposer.addEventListener('click', async function () {
  if (!selectedSpan) return;
  iaErreur.classList.add('hidden');
  btnIaProposer.disabled = true;
  const donnees = new FormData();
  donnees.append('_csrf', CSRF_TOKEN);
  donnees.append('dossier_id', DOSSIER_ID);
  donnees.append('texte', selectedSpan.textContent);
  donnees.append('instruction', iaInstruction.value.trim());
  try {
    const r = await fetch('canvas-ia-bloc.php', { method: 'POST', body: donnees });
    const data = await r.json();
    if (data.error) {
      iaErreur.textContent = data.error;
      iaErreur.classList.remove('hidden');
    } else {
      dernierePropositionIA = data.texte;
      iaPropositionTexte.value = data.texte;
      iaPropositionBox.style.display = 'flex';
    }
  } catch (e) {
    iaErreur.textContent = 'Erreur réseau, merci de réessayer.';
    iaErreur.classList.remove('hidden');
  } finally {
    btnIaProposer.disabled = false;
  }
});

document.getElementById('btnIaAccepter').addEventListener('click', function () {
  if (!selectedSpan) return;
  selectedSpan.textContent = iaPropositionTexte.value;
  iaPropositionBox.style.display = 'none';
  selectedSpan.blur();
});
document.getElementById('btnIaRefuser').addEventListener('click', function () {
  iaPropositionBox.style.display = 'none';
});

// --- Aperçu (lecture seule, toutes les pages) ---
document.getElementById('btnApercu').addEventListener('click', async function () {
  const modal = document.getElementById('modalApercu');
  const contenu = document.getElementById('apercuContenu');
  contenu.innerHTML = '<p class="font-body-sm text-body-sm text-secondary text-center">Génération de l\'aperçu…</p>';
  modal.classList.remove('hidden');
  modal.classList.add('flex');

  contenu.innerHTML = '';
  for (let n = 1; n <= totalPages; n++) {
    const page = await pdfDoc.getPage(n);
    const viewport = page.getViewport({ scale: 1.1 });
    const wrap = document.createElement('div');
    wrap.className = 'canvas-page-wrap';
    wrap.style.width = viewport.width + 'px';
    wrap.style.height = viewport.height + 'px';
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    wrap.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    await page.render({ canvasContext: ctx, viewport }).promise;

    const edits = editsByPage[String(n)] || {};
    if (Object.keys(edits).length) {
      const textContent = await page.getTextContent();
      const textLayerDiv = document.createElement('div');
      textLayerDiv.className = 'canvas-text-layer';
      textLayerDiv.style.width = viewport.width + 'px';
      textLayerDiv.style.height = viewport.height + 'px';
      wrap.appendChild(textLayerDiv);
      const textLayer = new pdfjsLib.TextLayer({ textContentSource: textContent, container: textLayerDiv, viewport });
      await textLayer.render();
      const spans = textLayerDiv.querySelectorAll('span');
      spans.forEach(function (span, index) {
        const e = edits[String(index)];
        if (e) {
          span.textContent = e.texte;
          span.style.color = e.couleurTexte || '#111';
          const patch = document.createElement('div');
          patch.style.position = 'absolute';
          patch.style.left = (e.rect.left * 1.1) + 'px';
          patch.style.top = (e.rect.top * 1.1) + 'px';
          patch.style.width = (e.rect.width * 1.1 + 2) + 'px';
          patch.style.height = (e.rect.height * 1.1 + 2) + 'px';
          patch.style.background = e.couleurFond || '#fff';
          patch.style.zIndex = '1';
          wrap.insertBefore(patch, textLayerDiv);
        }
      });
    }

    if (n === totalPages) {
      const sticker = document.createElement('div');
      sticker.style.position = 'absolute';
      sticker.style.right = '24px';
      sticker.style.bottom = '20px';
      sticker.style.padding = '4px 10px';
      sticker.style.background = '#0B192C';
      sticker.style.color = '#d9b45c';
      sticker.style.fontSize = '9px';
      sticker.style.fontWeight = 'bold';
      sticker.style.letterSpacing = '0.5px';
      sticker.style.borderRadius = '4px';
      sticker.textContent = '✓ TRAITÉ ET VALIDÉ PAR NEXRAIZ';
      sticker.title = "Aperçu indicatif — l'emplacement réel est calculé précisément à la validation.";
      wrap.appendChild(sticker);
    }

    contenu.appendChild(wrap);
  }
});
document.getElementById('btnFermerApercu').addEventListener('click', function () {
  document.getElementById('modalApercu').classList.add('hidden');
  document.getElementById('modalApercu').classList.remove('flex');
});

// --- Validation finale : l'édition réelle du PDF (suppression véritable du
// texte d'origine, pas un correctif dessiné par-dessus) est désormais faite
// côté serveur par Python/PyMuPDF (includes/pdf-editeur-python.php) — le
// navigateur transmet uniquement la liste structurée des blocs modifiés
// (déjà le format de editsByPage), jamais un PDF reconstruit localement. ---
document.getElementById('btnValider').addEventListener('click', async function () {
  if (selectedSpan) selectedSpan.blur();
  const btn = this;
  const libelleAvant = btn.innerHTML;

  if (!Object.keys(editsByPage).some(function (p) { return Object.keys(editsByPage[p] || {}).length > 0; })) {
    alert('Aucune modification à valider pour le moment.');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">progress_activity</span>Validation en cours…';

  try {
    await enregistrerBrouillon();

    const donneesValidation = new FormData();
    donneesValidation.append('_csrf', CSRF_TOKEN);
    donneesValidation.append('dossier_id', DOSSIER_ID);
    donneesValidation.append('document_type', DOCUMENT_TYPE);
    donneesValidation.append('edits', JSON.stringify(editsByPage));
    const rep = await fetch('canvas-valider.php', { method: 'POST', body: donneesValidation });
    const data = await rep.json();
    if (data.ok) {
      statutBrouillon.textContent = 'Document validé et envoyé au candidat.';
      btn.innerHTML = '<span class="material-symbols-outlined text-[16px]">check_circle</span>Validé';
      if (data.avertissements && data.avertissements.length) {
        alert('Document validé, mais avec des remarques :\n\n' + data.avertissements.join('\n'));
      }
    } else {
      throw new Error(data.error || 'Erreur de validation.');
    }
  } catch (err) {
    alert('Erreur lors de la validation : ' + err.message);
    btn.disabled = false;
    btn.innerHTML = libelleAvant;
  }
});

chargerDocument().catch(function (err) {
  stageLoading.innerHTML = '<p class="font-body-sm text-body-sm text-error text-center">Impossible de charger le document : ' + err.message + '</p>';
});
</script>
</body>
</html>
