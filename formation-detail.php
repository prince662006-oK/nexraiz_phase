<?php
/**
 * NexRaiz — Détail d'une formation : présentation, leçons (si le contenu
 * pédagogique interne existe), progression réelle, démarrage/reprise.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';
require_once __DIR__ . '/includes/formations.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$userId = (int) $consultant['id'];
$formationId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM formations WHERE id = ?');
$stmt->execute([$formationId]);
$formation = $stmt->fetch();
if (!$formation) {
    header('Location: formations.php');
    exit;
}

$erreur = '';
$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'demarrer') {
        formation_demarrer($formationId, $userId);
        $succes = 'Formation commencée.';
    } elseif ($action === 'marquer_lecon_terminee') {
        $inscriptionId = (int) ($_POST['inscription_id'] ?? 0);
        $leconId = (int) ($_POST['lecon_id'] ?? 0);
        if (formation_marquer_lecon_terminee($inscriptionId, $leconId, $userId)) {
            $succes = 'Leçon marquée comme terminée.';
        } else {
            $erreur = 'Impossible de marquer cette leçon.';
        }
    } elseif ($action === 'declarer_terminee') {
        $inscriptionId = (int) ($_POST['inscription_id'] ?? 0);
        if (formation_declarer_terminee($inscriptionId, $userId)) {
            $succes = 'Formation marquée comme terminée.';
        } else {
            $erreur = 'Impossible de marquer cette formation comme terminée.';
        }
    }
    if ($erreur === '') {
        header('Location: formation-detail.php?id=' . $formationId . '&succes=' . urlencode($succes));
        exit;
    }
}
if (isset($_GET['succes']) && $_GET['succes'] !== '') { $succes = $_GET['succes']; }

$lecons = formation_lecons($formationId);
$inscription = formation_inscription($formationId, $userId);
$leconsTermineesIds = [];
if ($inscription) {
    $stmt = db()->prepare('SELECT lecon_id FROM formation_lecons_terminees WHERE inscription_id = ?');
    $stmt->execute([$inscription['id']]);
    $leconsTermineesIds = array_column($stmt->fetchAll(), 'lecon_id');
}
$progression = $inscription ? formation_progression($formationId, (int) $inscription['id']) : null;

$stmt = db()->prepare('SELECT nom FROM packs WHERE id = ?');
$stmt->execute([$formation['pack_id']]);
$packNom = $formation['pack_id'] ? $stmt->fetchColumn() : null;

render_head('Espace NexRaiz — ' . $formation['titre'], 'Détail et suivi de la formation.');
?>
<div class="flex w-full min-h-screen">

  <?php render_consultant_pack_sidebar($consultant, 'formations'); ?>

  <div class="flex-1 flex flex-col min-w-0">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
    </a>
    <div class="flex items-center gap-space-sm shrink-0 ml-auto">
      <a href="formations.php" class="font-label-md text-label-md text-secondary hover:text-on-surface transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour au catalogue</a>
    </div>
  </header>

  <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-max-width-content mx-auto w-full">

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm"><span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p></div>
    <?php endif; ?>
    <?php if ($erreur): ?>
    <div class="flex items-start gap-space-xs bg-error-container/20 rounded-xl p-space-sm"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($erreur); ?></p></div>
    <?php endif; ?>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <div class="flex items-start justify-between gap-space-sm flex-wrap">
        <div class="flex items-center gap-space-sm">
          <div class="w-14 h-14 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[28px]">school</span></div>
          <div>
            <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo htmlspecialchars($formation['titre']); ?></h1>
            <p class="font-body-sm text-[12px] text-secondary flex flex-wrap gap-x-1"><?php echo htmlspecialchars($packNom ?: 'Général'); ?><?php if ($formation['organisme']): ?>· <?php echo htmlspecialchars($formation['organisme']); ?><?php endif; ?><?php if ($formation['duree']): ?>· <?php echo htmlspecialchars($formation['duree']); ?><?php endif; ?></p>
          </div>
        </div>
        <?php if ($inscription): ?>
        <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-bold <?php echo $inscription['statut'] === 'termine' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'; ?>"><?php echo $inscription['statut'] === 'termine' ? 'Terminée' : 'En cours'; ?></span>
        <?php else: ?>
        <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-bold bg-surface-container-high text-secondary">À commencer</span>
        <?php endif; ?>
      </div>

      <?php if ($formation['description']): ?>
      <div class="flex flex-col gap-0.5">
        <p class="font-label-md text-label-md text-on-surface font-bold">Présentation</p>
        <p class="font-body-sm text-body-sm text-secondary whitespace-pre-line"><?php echo htmlspecialchars($formation['description']); ?></p>
      </div>
      <?php endif; ?>

      <?php if ($progression !== null): ?>
      <div class="flex flex-col gap-0.5">
        <div class="flex items-center justify-between"><p class="font-label-md text-label-md text-on-surface font-bold">Progression</p><p class="font-body-sm text-[12px] text-secondary"><?php echo $progression; ?>%</p></div>
        <div class="w-full h-2 rounded-full bg-surface-container-high overflow-hidden"><div class="h-full rounded-full bg-primary-container" style="width:<?php echo $progression; ?>%"></div></div>
      </div>
      <?php endif; ?>

      <div class="flex items-center gap-space-xs flex-wrap">
        <?php if (!$inscription): ?>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="demarrer">
          <button type="submit" class="h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[18px]">play_arrow</span>Commencer la formation</button>
        </form>
        <?php elseif ($inscription['statut'] !== 'termine' && !$lecons): ?>
        <form method="post" onsubmit="return confirm('Marquer cette formation comme terminée ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="declarer_terminee"><input type="hidden" name="inscription_id" value="<?php echo $inscription['id']; ?>">
          <button type="submit" class="h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[18px]">check_circle</span>Marquer comme terminée</button>
        </form>
        <?php endif; ?>
        <?php if ($formation['lien']): ?>
        <a href="<?php echo htmlspecialchars($formation['lien']); ?>" target="_blank" rel="noopener" class="h-11 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">open_in_new</span>Ouvrir la ressource externe</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Programme</h2>
      <?php if (!$lecons): ?>
      <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
        <span class="material-symbols-outlined text-[32px] text-outline-variant">menu_book</span>
        <p class="font-body-sm text-body-sm text-secondary">Contenu pédagogique non disponible pour le moment.<?php if ($formation['lien']): ?><br>Cette formation se déroule via la ressource externe ci-dessus.<?php endif; ?></p>
      </div>
      <?php else: ?>
      <?php foreach ($lecons as $i => $l): $faite = in_array($l['id'], $leconsTermineesIds); ?>
      <div class="flex items-center justify-between gap-space-sm border-b border-outline-variant/30 py-space-sm last:border-0">
        <div class="flex items-center gap-space-xs min-w-0">
          <span class="w-8 h-8 rounded-full <?php echo $faite ? 'bg-green-100 text-green-700' : 'bg-surface-container-high text-secondary'; ?> flex items-center justify-center font-label-md text-[12px] font-bold shrink-0"><?php echo $faite ? '<span class="material-symbols-outlined text-[16px]">check</span>' : ($i + 1); ?></span>
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p>
            <?php if ($l['description']): ?><p class="font-body-sm text-[12px] text-secondary truncate"><?php echo htmlspecialchars($l['description']); ?></p><?php endif; ?>
          </div>
        </div>
        <div class="flex items-center gap-space-xs shrink-0">
          <?php if ($l['ressource_lien']): ?>
          <a href="<?php echo htmlspecialchars($l['ressource_lien']); ?>" target="_blank" rel="noopener" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir</a>
          <?php endif; ?>
          <?php if ($inscription && !$faite): ?>
          <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="marquer_lecon_terminee"><input type="hidden" name="inscription_id" value="<?php echo $inscription['id']; ?>"><input type="hidden" name="lecon_id" value="<?php echo $l['id']; ?>">
            <button type="submit" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px]">Marquer terminée</button>
          </form>
          <?php elseif (!$inscription): ?>
          <span class="font-body-sm text-[11px] text-secondary">Commencez la formation</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </main>
  </div>
</div>
</body>
</html>
