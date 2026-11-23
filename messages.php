<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/message-attachments.php';

$user = require_login('mon-espace.php');

$dossierId = (int) ($_GET['dossier'] ?? 0);
$stmt = db()->prepare(
    'SELECT ds.*, pk.slug AS pack_slug, pk.nom AS pack_nom
     FROM dossiers ds
     JOIN packs pk ON pk.id = ds.pack_id
     WHERE ds.id = ? AND ds.user_id = ?'
);
$stmt->execute([$dossierId, $user['id']]);
$dossier = $stmt->fetch();

if (!$dossier) {
    header('Location: mon-espace.php');
    exit;
}

$consultantNom = 'Votre consultant';
$consultantInitiales = 'NX';
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    if ($c = $stmt->fetch()) {
        $consultantNom = $c['prenom'] . ' ' . $c['nom'];
        $consultantInitiales = strtoupper(mb_substr($c['prenom'], 0, 1) . mb_substr($c['nom'], 0, 1));
    }
}

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $corps = trim($_POST['corps'] ?? '');
    try {
        $pieceJointe = traiter_piece_jointe_message();
    } catch (RuntimeException $e) {
        $erreurs[] = $e->getMessage();
        $pieceJointe = null;
    }
    if ($corps === '' && !$pieceJointe && !$erreurs) {
        $erreurs[] = 'Merci de saisir un message ou de joindre un fichier avant de l\'envoyer.';
    }
    if (!$erreurs) {
        $stmt = db()->prepare(
            'INSERT INTO messages (dossier_id, expediteur_id, expediteur_type, corps, piece_jointe_nom, piece_jointe_chemin, piece_jointe_taille, lu_par_client, lu_par_consultant)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)'
        );
        $stmt->execute([$dossierId, $user['id'], 'client', $corps, $pieceJointe['nom'] ?? null, $pieceJointe['chemin'] ?? null, $pieceJointe['taille'] ?? null]);
        header('Location: messages.php?dossier=' . $dossierId);
        exit;
    }
}

// Marque comme lus les messages du consultant à l'ouverture du fil.
$stmt = db()->prepare("UPDATE messages SET lu_par_client = 1 WHERE dossier_id = ? AND expediteur_type = 'consultant'");
$stmt->execute([$dossierId]);

$stmt = db()->prepare('SELECT expediteur_type, corps, piece_jointe_nom, piece_jointe_chemin, piece_jointe_taille, created_at, lu_par_consultant FROM messages WHERE dossier_id = ? ORDER BY id ASC');
$stmt->execute([$dossierId]);
$fil = $stmt->fetchAll();

// --- Regroupe les messages par jour (séparateurs "Aujourd'hui"/"Hier"/date) et repère
// le début de chaque groupe de messages consécutifs d'un même expéditeur (effet "bulle"),
// exactement comme dans la messagerie de l'espace consultant. ---
$moisNoms = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$aujourdhui = date('Y-m-d');
$hier = date('Y-m-d', strtotime('-1 day'));
$elementsFil = [];
$jourPrecedent = null;
$typePrecedent = null;
foreach ($fil as $m) {
    $jour = date('Y-m-d', strtotime($m['created_at']));
    if ($jour !== $jourPrecedent) {
        if ($jour === $aujourdhui) {
            $label = "Aujourd'hui";
        } elseif ($jour === $hier) {
            $label = 'Hier';
        } else {
            $label = (int) date('j', strtotime($m['created_at'])) . ' ' . $moisNoms[(int) date('n', strtotime($m['created_at']))] . ' ' . date('Y', strtotime($m['created_at']));
        }
        $elementsFil[] = ['type' => 'separateur', 'label' => $label];
        $jourPrecedent = $jour;
        $typePrecedent = null;
    }
    $m['nouveau_groupe'] = $m['expediteur_type'] !== $typePrecedent;
    $elementsFil[] = ['type' => 'message'] + $m;
    $typePrecedent = $m['expediteur_type'];
}

render_head('Messagerie — ' . htmlspecialchars($dossier['pack_nom']));
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="<?php echo htmlspecialchars(step_url(8, $dossier['pack_slug'])); ?>" class="hover:text-primary transition-colors"><?php echo htmlspecialchars($dossier['pack_nom']); ?></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Messagerie</span>
    </nav>

    <div class="flex items-center gap-space-sm">
      <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#2563EB] to-[#13203F] shadow-sm flex items-center justify-center text-white shrink-0"><span class="material-symbols-outlined text-[24px]">chat</span></div>
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Messagerie</h1>
        <p class="font-body-sm text-body-sm text-secondary">Échangez directement avec votre consultant au sujet de votre dossier <?php echo htmlspecialchars($dossier['numero']); ?>.</p>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-md overflow-hidden border border-outline-variant/40 flex flex-col h-[36rem]">

      <div class="h-16 shrink-0 flex items-center gap-space-xs px-space-sm bg-gradient-to-r from-[#13203F] to-[#1E3E62]">
        <span class="w-10 h-10 rounded-full bg-white/15 text-white flex items-center justify-center font-label-md text-[13px] font-bold shrink-0"><?php echo htmlspecialchars($consultantInitiales); ?></span>
        <div class="min-w-0 flex-1">
          <p class="font-label-lg text-label-lg text-white font-bold truncate"><?php echo htmlspecialchars($consultantNom); ?></p>
          <p class="font-body-sm text-[11px] text-white/60">Dossier <?php echo htmlspecialchars($dossier['numero']); ?></p>
        </div>
        <?php if ($dossier['consultant_id']): ?>
        <button type="button" onclick="NexRaizCall.appeler(<?php echo (int) $dossier['consultant_id']; ?>, '<?php echo addslashes($consultantNom); ?>')" class="shrink-0 w-10 h-10 rounded-full text-white hover:bg-white/10 flex items-center justify-center transition-colors" title="Appeler votre consultant"><span class="material-symbols-outlined text-[22px]">call</span></button>
        <?php endif; ?>
      </div>

      <div class="flex-1 flex flex-col gap-space-2xs overflow-y-auto p-space-md bg-[#F7F9FF] bg-[radial-gradient(#E5EEFF_1.5px,transparent_1.5px)] bg-[length:18px_18px]" id="messageThread">
        <?php if (!$fil): ?>
        <div class="flex-1 flex flex-col items-center justify-center gap-space-xs">
          <span class="material-symbols-outlined text-[32px] text-[#2563EB]/40">forum</span>
          <p class="font-body-sm text-[13px] text-secondary bg-white/90 rounded-lg px-space-sm py-space-xs shadow-sm">Aucun message pour le moment. Envoyez le premier message à votre consultant.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($elementsFil as $el): ?>
          <?php if ($el['type'] === 'separateur'): ?>
          <div class="flex justify-center my-space-xs">
            <span class="font-body-sm text-[11.5px] text-secondary bg-white/90 rounded-lg px-space-sm py-1 shadow-sm"><?php echo htmlspecialchars($el['label']); ?></span>
          </div>
          <?php else: $estClient = $el['expediteur_type'] === 'client'; ?>
          <div class="flex flex-col <?php echo $estClient ? 'items-end' : 'items-start'; ?> <?php echo $el['nouveau_groupe'] ? 'mt-space-2xs' : ''; ?>">
            <div class="max-w-[75%] sm:max-w-[60%] rounded-xl px-space-sm py-space-xs shadow-sm <?php echo $estClient ? 'bg-[#DCE9FF] text-[#111b21] ' . ($el['nouveau_groupe'] ? 'rounded-tr-none' : '') : 'bg-white text-[#111b21] ' . ($el['nouveau_groupe'] ? 'rounded-tl-none' : ''); ?>">
              <?php render_message_attachment($el['piece_jointe_nom'], $el['piece_jointe_chemin'], $el['piece_jointe_taille'], $estClient); ?>
              <?php if ($el['corps'] !== ''): ?>
              <p class="font-body-sm text-[14px] whitespace-pre-wrap break-words"><?php echo nl2br(htmlspecialchars($el['corps'])); ?></p>
              <?php endif; ?>
              <span class="flex items-center justify-end gap-1 mt-0.5">
                <span class="font-body-sm text-[10.5px] text-[#667781]"><?php echo htmlspecialchars(date('H:i', strtotime($el['created_at']))); ?></span>
                <?php if ($estClient): ?>
                <span class="material-symbols-outlined text-[15px] <?php echo $el['lu_par_consultant'] ? 'text-[#2563EB]' : 'text-[#667781]'; ?>"><?php echo $el['lu_par_consultant'] ? 'done_all' : 'done'; ?></span>
                <?php endif; ?>
              </span>
            </div>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm mx-space-sm mt-space-xs">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="shrink-0 flex flex-col gap-1 p-space-sm bg-white border-t border-outline-variant/40">
        <?php echo csrf_field(); ?>
        <div id="filePreview" hidden class="items-center gap-2 px-space-xs">
          <span class="material-symbols-outlined text-[16px] text-[#2563EB]">attach_file</span>
          <span id="filePreviewName" class="font-body-sm text-[12px] text-on-surface flex-1 truncate"></span>
          <button type="button" onclick="nxAnnulerFichier(this)" class="text-secondary"><span class="material-symbols-outlined text-[14px]">close</span></button>
        </div>
        <div class="flex items-end gap-space-xs">
          <label class="shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low cursor-pointer transition-colors" title="Joindre un document">
            <span class="material-symbols-outlined text-[22px]">attach_file</span>
            <input type="file" name="piece_jointe" class="hidden" onchange="nxAfficherFichier(this)">
          </label>
          <textarea name="corps" rows="1" placeholder="Écrivez un message" class="flex-1 max-h-28 p-space-sm rounded-3xl border border-outline-variant/50 bg-surface-container-low font-body-sm text-[14px] text-on-surface focus:outline-none focus:ring-2 focus:ring-[#2563EB]/30 focus:bg-white resize-none"></textarea>
          <button type="submit" class="shrink-0 w-11 h-11 rounded-full bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white flex items-center justify-center shadow-sm hover:opacity-90 hover:scale-[1.05] active:scale-[0.95] transition-all duration-200">
            <span class="material-symbols-outlined text-[20px]">send</span>
          </button>
        </div>
      </form>
    </div>

    <a href="<?php echo htmlspecialchars(step_url(8, $dossier['pack_slug'])); ?>" class="self-start h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
      <span class="material-symbols-outlined text-[18px]">arrow_back</span>
      <span>Retour au tableau de bord</span>
    </a>
  </div>
</section>

<script>
(function() {
  var thread = document.getElementById('messageThread');
  if (thread) thread.scrollTop = thread.scrollHeight;
})();

function nxAfficherFichier(input) {
  var preview = document.getElementById('filePreview');
  var name = document.getElementById('filePreviewName');
  if (input.files && input.files[0]) {
    name.textContent = input.files[0].name;
    preview.hidden = false;
    preview.classList.add('flex');
  }
}
function nxAnnulerFichier(btn) {
  var form = btn.closest('form');
  form.querySelector('input[type=file]').value = '';
  var preview = document.getElementById('filePreview');
  preview.hidden = true;
  preview.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/includes/webrtc-call.php'; render_call_widget((int) $user['id']); ?>

<?php render_footer(); ?>
