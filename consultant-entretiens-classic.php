<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');
$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

$stmt = db()->prepare(
    'SELECT rdv.id, rdv.date_heure, rdv.format, rdv.statut, ds.id AS dossier_id, ds.numero, u.nom, u.prenom
     FROM rendezvous rdv
     JOIN dossiers ds ON ds.id = rdv.dossier_id
     JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ?
     ORDER BY rdv.date_heure ASC'
);
$stmt->execute([$packId]);
$rendezvousBruts = $stmt->fetchAll();

$maintenant = new DateTimeImmutable('now');
$aVenir = [];
$passes = [];
foreach ($rendezvousBruts as $r) {
    if (new DateTimeImmutable($r['date_heure']) >= $maintenant) {
        $aVenir[] = $r;
    } else {
        $passes[] = $r;
    }
}
$passes = array_reverse($passes);

$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir'];

render_head('Espace NexRaiz — Entretiens', 'Rendez-vous et entretiens planifiés pour le Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <nav class="hidden md:flex items-center gap-space-2xs">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">home</span>Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">folder</span>Dossiers</a>
        <a href="consultant-analyse-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">bar_chart</span>Analyses</a>
        <a href="consultant-corrections-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">fact_check</span>Corrections</a>
        <a href="consultant-entretiens-classic.php" class="px-space-sm h-9 flex items-center gap-1 rounded-xl bg-surface-container-low text-primary font-label-lg text-label-lg font-bold border-b-2 border-primary"><span class="material-symbols-outlined text-[18px]">groups</span>Entretiens</a>
        <a href="parametres.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">settings</span>Paramètres</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <div class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
      <div class="hidden sm:flex flex-col leading-tight">
        <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
        <span class="font-body-sm text-[11px] text-secondary"><?php echo $consultant['role'] === 'admin' ? 'Administrateur' : 'Consultant'; ?></span>
      </div>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiersApercu, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Espace NexRaiz : Entretiens</h1>
      <p class="font-body-sm text-body-sm text-secondary">Rendez-vous pris par les candidats du Pack Classic</p>
    </div>

    <div class="grid grid-cols-2 gap-space-sm max-w-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs">
        <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">event_upcoming</span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo count($aVenir); ?></p>
        <p class="font-label-lg text-label-lg text-on-surface font-semibold">À venir</p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs">
        <div class="w-11 h-11 rounded-full bg-surface-container-high text-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">event_available</span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo count($passes); ?></p>
        <p class="font-label-lg text-label-lg text-on-surface font-semibold">Passés</p>
      </div>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Entretiens à venir</h2>
      <?php if (!$aVenir): ?>
      <p class="font-body-sm text-body-sm text-secondary">Aucun entretien planifié pour le moment.</p>
      <?php endif; ?>
      <?php foreach ($aVenir as $r): ?>
      <div class="flex items-center justify-between gap-space-sm bg-surface-container-low rounded-xl p-space-sm flex-wrap">
        <div class="flex items-center gap-space-xs min-w-0">
          <span class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo strtoupper(mb_substr($r['prenom'], 0, 1) . mb_substr($r['nom'], 0, 1)); ?></span>
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($r['date_heure']))); ?> · <?php echo htmlspecialchars($formatLabels[$r['format']] ?? $r['format']); ?></p>
          </div>
        </div>
        <a href="consultant-corrections-classic.php?dossier=<?php echo $r['dossier_id']; ?>" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Entretiens passés</h2>
      <?php if (!$passes): ?>
      <p class="font-body-sm text-body-sm text-secondary">Aucun entretien passé pour le moment.</p>
      <?php endif; ?>
      <?php foreach ($passes as $r): ?>
      <div class="flex items-center justify-between gap-space-sm bg-surface-container-low rounded-xl p-space-sm flex-wrap">
        <div class="flex items-center gap-space-xs min-w-0">
          <span class="w-9 h-9 rounded-full bg-surface-container-high text-secondary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo strtoupper(mb_substr($r['prenom'], 0, 1) . mb_substr($r['nom'], 0, 1)); ?></span>
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($r['date_heure']))); ?> · <?php echo htmlspecialchars($formatLabels[$r['format']] ?? $r['format']); ?></p>
          </div>
        </div>
        <a href="consultant-corrections-classic.php?dossier=<?php echo $r['dossier_id']; ?>" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      </div>
      <?php endforeach; ?>
    </div>

  </main>
  </div>

  <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm max-w-max-width-content mx-auto w-full">
      <div class="flex items-center gap-1.5">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span>
      </div>
      <nav class="flex flex-wrap items-center gap-space-md">
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">À propos</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">CGU</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Confidentialité</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Mentions légales</a>
        <a href="contact.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Contact</a>
      </nav>
    </div>
  </footer>
</div>
</body>
</html>
