<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-wellbeing.php';
require_once __DIR__ . '/includes/message-attachments.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('wellbeing');

$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare(
    'SELECT ds.id, ds.numero, u.id AS user_id, u.nom, u.prenom,
        (SELECT corps FROM messages m WHERE m.dossier_id = ds.id ORDER BY m.id DESC LIMIT 1) AS dernier_corps,
        (SELECT expediteur_type FROM messages m WHERE m.dossier_id = ds.id ORDER BY m.id DESC LIMIT 1) AS dernier_type,
        (SELECT created_at FROM messages m WHERE m.dossier_id = ds.id ORDER BY m.id DESC LIMIT 1) AS dernier_at,
        (SELECT COUNT(*) FROM messages m WHERE m.dossier_id = ds.id AND m.expediteur_type = "client" AND m.lu_par_consultant = 0) AS non_lus
     FROM dossiers ds
     JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ?
     ORDER BY (dernier_at IS NULL) ASC, dernier_at DESC'
);
$stmt->execute([$packId]);
$conversations = $stmt->fetchAll();

if (!$dossierId && $conversations) {
    $dossierId = (int) $conversations[0]['id'];
}

$dossier = null;
foreach ($conversations as $c) {
    if ((int) $c['id'] === $dossierId) {
        $dossier = $c;
        break;
    }
}

$erreurs = [];
if ($dossier && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)'
        );
        $stmt->execute([$dossierId, $consultant['id'], 'consultant', $corps, $pieceJointe['nom'] ?? null, $pieceJointe['chemin'] ?? null, $pieceJointe['taille'] ?? null]);
        header('Location: consultant-messages-wellbeing.php?dossier=' . $dossierId);
        exit;
    }
}

$filMessages = [];
if ($dossier) {
    $stmt = db()->prepare("UPDATE messages SET lu_par_consultant = 1 WHERE dossier_id = ? AND expediteur_type = 'client'");
    $stmt->execute([$dossierId]);

    $stmt = db()->prepare('SELECT expediteur_type, corps, piece_jointe_nom, piece_jointe_chemin, piece_jointe_taille, created_at, lu_par_client FROM messages WHERE dossier_id = ? ORDER BY id ASC');
    $stmt->execute([$dossierId]);
    $filMessages = $stmt->fetchAll();
}

$moisNoms = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$aujourdhui = date('Y-m-d');
$hier = date('Y-m-d', strtotime('-1 day'));
$elementsFil = [];
$jourPrecedent = null;
$typePrecedent = null;
foreach ($filMessages as $m) {
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

render_head('Espace NexRaiz — Messages', 'Messagerie avec les clients du Pack Wellbeing.');
?>
<div class="flex w-full h-screen overflow-hidden">

  <?php render_wellbeing_sidebar($packId, 'messages'); ?>

  <div class="flex-1 flex flex-col min-w-0 min-h-0">
    <?php render_wellbeing_header($consultant); ?>

    <main class="flex-1 flex flex-col min-h-0 p-gutter-mobile lg:p-gutter-desktop">

    <div class="flex flex-1 min-h-0 bg-white rounded-xl shadow-sm overflow-hidden border border-outline-variant/40">

      <div class="w-full sm:w-[22rem] shrink-0 border-r border-outline-variant/60 flex flex-col bg-white min-h-0 <?php echo $dossier ? 'hidden sm:flex' : 'flex'; ?>">
        <div class="h-16 shrink-0 flex items-center px-space-sm">
          <h1 class="font-headline-sm text-headline-sm text-on-surface font-bold">Messages</h1>
        </div>
        <div class="px-space-sm pb-space-sm shrink-0">
          <label class="flex items-center gap-space-xs h-10 px-space-sm rounded-full bg-[#f0f2f5]">
            <span class="material-symbols-outlined text-[18px] text-secondary">search</span>
            <input type="text" id="convSearch" placeholder="Rechercher une conversation" class="flex-1 bg-transparent border-0 outline-none font-body-sm text-[13px] text-on-surface placeholder:text-secondary">
          </label>
        </div>
        <div class="flex-1 overflow-y-auto" id="convList">
          <?php if (!$conversations): ?>
          <p class="font-body-sm text-body-sm text-secondary text-center p-space-lg">Aucun dossier Pack Wellbeing pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($conversations as $c): $actif = (int) $c['id'] === $dossierId; ?>
          <a href="consultant-messages-wellbeing.php?dossier=<?php echo $c['id']; ?>" data-nom="<?php echo htmlspecialchars(mb_strtolower($c['prenom'] . ' ' . $c['nom'])); ?>" class="flex items-center gap-space-xs px-space-sm py-space-xs transition-colors <?php echo $actif ? 'bg-[#f0f2f5]' : 'hover:bg-[#f5f6f6]'; ?>">
            <span class="w-12 h-12 rounded-full bg-primary-container/15 text-primary flex items-center justify-center font-label-lg text-[15px] font-bold shrink-0"><?php echo strtoupper(mb_substr($c['prenom'], 0, 1) . mb_substr($c['nom'], 0, 1)); ?></span>
            <div class="min-w-0 flex-1 py-space-xs border-b border-outline-variant/30">
              <div class="flex items-center justify-between gap-space-2xs">
                <p class="font-label-md text-label-md text-on-surface <?php echo $c['non_lus'] > 0 ? 'font-bold' : 'font-semibold'; ?> truncate"><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></p>
                <span class="shrink-0 font-body-sm text-[11px] <?php echo $c['non_lus'] > 0 ? 'text-primary font-bold' : 'text-secondary'; ?>"><?php echo $c['dernier_at'] ? htmlspecialchars(date('H:i', strtotime($c['dernier_at']))) : ''; ?></span>
              </div>
              <div class="flex items-center justify-between gap-space-2xs">
                <p class="font-body-sm text-[12.5px] <?php echo $c['non_lus'] > 0 ? 'text-on-surface font-semibold' : 'text-secondary'; ?> truncate"><?php echo $c['dernier_corps'] ? htmlspecialchars(($c['dernier_type'] === 'consultant' ? 'Vous : ' : '') . $c['dernier_corps']) : 'Aucun message pour le moment'; ?></p>
                <?php if ($c['non_lus'] > 0): ?>
                <span class="shrink-0 min-w-[19px] h-[19px] px-1 rounded-full bg-primary text-white text-[10.5px] font-bold flex items-center justify-center"><?php echo $c['non_lus']; ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex-1 flex flex-col min-w-0 min-h-0 <?php echo $dossier ? 'flex' : 'hidden sm:flex'; ?>">
        <?php if (!$dossier): ?>
        <div class="flex-1 flex flex-col items-center justify-center gap-space-xs text-center p-space-lg bg-[#f0f2f5]">
          <span class="material-symbols-outlined text-[64px] text-outline-variant">forum</span>
          <p class="font-body-sm text-body-sm text-secondary">Sélectionnez une conversation pour l'afficher.</p>
        </div>
        <?php else: ?>

        <div class="h-16 shrink-0 border-b border-outline-variant/60 flex items-center gap-space-xs px-space-sm bg-[#f0f2f5]">
          <a href="consultant-messages-wellbeing.php" class="sm:hidden shrink-0 text-secondary"><span class="material-symbols-outlined text-[20px]">arrow_back</span></a>
          <span class="w-10 h-10 rounded-full bg-primary-container/15 text-primary flex items-center justify-center font-label-md text-[13px] font-bold shrink-0"><?php echo strtoupper(mb_substr($dossier['prenom'], 0, 1) . mb_substr($dossier['nom'], 0, 1)); ?></span>
          <div class="min-w-0 flex-1">
            <p class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary">Dossier <?php echo htmlspecialchars($dossier['numero']); ?></p>
          </div>
          <button type="button" onclick="NexRaizCall.appeler(<?php echo (int) $dossier['user_id']; ?>, '<?php echo addslashes($dossier['prenom'] . ' ' . $dossier['nom']); ?>')" class="shrink-0 w-9 h-9 rounded-full text-primary hover:bg-black/5 flex items-center justify-center transition-colors" title="Appeler le client"><span class="material-symbols-outlined text-[20px]">call</span></button>
          <a href="consultant-diagnostic-wellbeing.php?dossier=<?php echo $dossier['id']; ?>" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant bg-white font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
        </div>

        <div class="flex-1 flex flex-col gap-space-2xs overflow-y-auto p-space-md bg-[#efeae2] bg-[radial-gradient(#e2dcd0_1px,transparent_1px)] bg-[length:16px_16px]" id="messageThread">
          <?php if (!$filMessages): ?>
          <div class="flex-1 flex flex-col items-center justify-center gap-space-2xs">
            <p class="font-body-sm text-[13px] text-secondary bg-white/80 rounded-lg px-space-sm py-space-xs">Aucun message pour le moment. Envoyez le premier message au client.</p>
          </div>
          <?php endif; ?>
          <?php foreach ($elementsFil as $el): ?>
            <?php if ($el['type'] === 'separateur'): ?>
            <div class="flex justify-center my-space-xs">
              <span class="font-body-sm text-[11.5px] text-secondary bg-white/90 rounded-lg px-space-sm py-1 shadow-sm"><?php echo htmlspecialchars($el['label']); ?></span>
            </div>
            <?php else: $estConsultant = $el['expediteur_type'] === 'consultant'; ?>
            <div class="flex flex-col <?php echo $estConsultant ? 'items-end' : 'items-start'; ?> <?php echo $el['nouveau_groupe'] ? 'mt-space-2xs' : ''; ?>">
              <div class="max-w-[75%] sm:max-w-[60%] rounded-xl px-space-sm py-space-xs shadow-sm <?php echo $estConsultant ? 'bg-[#d9fdd3] text-[#111b21] ' . ($el['nouveau_groupe'] ? 'rounded-tr-none' : '') : 'bg-white text-[#111b21] ' . ($el['nouveau_groupe'] ? 'rounded-tl-none' : ''); ?>">
                <?php render_message_attachment($el['piece_jointe_nom'], $el['piece_jointe_chemin'], $el['piece_jointe_taille'], $estConsultant); ?>
                <?php if ($el['corps'] !== ''): ?>
                <p class="font-body-sm text-[14px] whitespace-pre-wrap break-words"><?php echo nl2br(htmlspecialchars($el['corps'])); ?></p>
                <?php endif; ?>
                <span class="flex items-center justify-end gap-1 mt-0.5">
                  <span class="font-body-sm text-[10.5px] text-[#667781]"><?php echo htmlspecialchars(date('H:i', strtotime($el['created_at']))); ?></span>
                  <?php if ($estConsultant): ?>
                  <span class="material-symbols-outlined text-[15px] <?php echo $el['lu_par_client'] ? 'text-[#53bdeb]' : 'text-[#667781]'; ?>"><?php echo $el['lu_par_client'] ? 'done_all' : 'done'; ?></span>
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

        <form method="post" enctype="multipart/form-data" class="shrink-0 flex flex-col gap-1 p-space-sm bg-[#f0f2f5]">
          <?php echo csrf_field(); ?>
          <div id="filePreview" hidden class="items-center gap-2 px-space-xs">
            <span class="material-symbols-outlined text-[16px] text-primary">attach_file</span>
            <span id="filePreviewName" class="font-body-sm text-[12px] text-on-surface flex-1 truncate"></span>
            <button type="button" onclick="nxAnnulerFichier(this)" class="text-secondary"><span class="material-symbols-outlined text-[14px]">close</span></button>
          </div>
          <div class="flex items-end gap-space-xs">
            <label class="shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-secondary hover:bg-black/5 cursor-pointer transition-colors" title="Joindre un document">
              <span class="material-symbols-outlined text-[22px]">attach_file</span>
              <input type="file" name="piece_jointe" class="hidden" onchange="nxAfficherFichier(this)">
            </label>
            <textarea name="corps" rows="1" placeholder="Écrivez un message" class="flex-1 max-h-28 p-space-sm rounded-3xl border-0 bg-white font-body-sm text-[14px] text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 resize-none"></textarea>
            <button type="submit" class="shrink-0 w-11 h-11 rounded-full bg-primary-container text-white flex items-center justify-center shadow-sm hover:bg-primary transition-colors">
              <span class="material-symbols-outlined text-[20px]">send</span>
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

  </main>
  </div>
</div>

<script>
(function() {
  var thread = document.getElementById('messageThread');
  if (thread) thread.scrollTop = thread.scrollHeight;

  var search = document.getElementById('convSearch');
  var list = document.getElementById('convList');
  if (search && list) {
    search.addEventListener('input', function() {
      var q = search.value.trim().toLowerCase();
      Array.prototype.forEach.call(list.querySelectorAll('a[data-nom]'), function(item) {
        item.hidden = q !== '' && item.getAttribute('data-nom').indexOf(q) === -1;
      });
    });
  }
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

<?php require_once __DIR__ . '/includes/webrtc-call.php'; render_call_widget((int) $consultant['id']); ?>

</body>
</html>
