<?php
/**
 * Reporting Pack Wellbeing : indicateurs réels calculés depuis les dossiers,
 * évaluations et points de vigilance, avec export CSV de la vue filtrée.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-wellbeing.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('wellbeing');

$dossiers = wellbeing_dossiers_apercu($packId, $consultant);

$nbDossiers = count($dossiers);
$nbEnCours = count(array_filter($dossiers, fn ($d) => $d['statut_label'] !== 'Clôturé'));
$nbClotures = count(array_filter($dossiers, fn ($d) => $d['statut_label'] === 'Clôturé'));
$nbNonEvalues = count(array_filter($dossiers, fn ($d) => $d['nb_evaluations'] === 0));

$stmt = db()->prepare('SELECT COUNT(*) FROM dossier_points_vigilance dv JOIN dossiers ds ON ds.id = dv.dossier_id WHERE ds.pack_id = ?');
$stmt->execute([$packId]);
$nbVigilance = (int) $stmt->fetchColumn();

$scoresValides = array_filter(array_column($dossiers, 'score_moyen'), fn ($s) => $s !== null);
$scoreMoyenGlobal = $scoresValides ? (int) round(array_sum($scoresValides) / count($scoresValides)) : null;

$stats = [
    ['icone' => 'folder', 'couleur' => 'bg-primary-container/10 text-primary', 'valeur' => $nbDossiers, 'label' => 'Dossiers clients', 'delta' => $nbDossiers . ' au total'],
    ['icone' => 'hourglass_top', 'couleur' => 'bg-orange-100 text-orange-600', 'valeur' => $nbEnCours, 'label' => 'Suivis en cours', 'delta' => 'à accompagner'],
    ['icone' => 'monitor_heart', 'couleur' => 'bg-blue-100 text-blue-700', 'valeur' => $scoreMoyenGlobal ?? '—', 'label' => 'Score moyen global', 'delta' => $scoreMoyenGlobal !== null ? '/100' : 'aucune évaluation'],
    ['icone' => 'shield', 'couleur' => 'bg-red-100 text-red-700', 'valeur' => $nbVigilance, 'label' => 'Points de vigilance', 'delta' => 'tous dossiers'],
];

// Répartition par statut d'avancement.
$parStatut = [];
foreach ($dossiers as $d) {
    $parStatut[$d['statut_label']] = ($parStatut[$d['statut_label']] ?? 0) + 1;
}

// Répartition par niveau de score (Vigilance / Attention / Stable).
$parNiveau = ['Vigilance' => 0, 'Attention' => 0, 'Stable' => 0, 'Non évalué' => 0];
foreach ($dossiers as $d) {
    if ($d['score_moyen'] === null) {
        $parNiveau['Non évalué']++;
    } else {
        $parNiveau[wellbeing_badge_score($d['score_moyen'])['label']]++;
    }
}

$statutFiltre = $_GET['statut'] ?? '';
$dossiersFiltres = array_values(array_filter($dossiers, fn ($d) => $statutFiltre === '' || $d['statut_label'] === $statutFiltre));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporting-wellbeing-' . date('Y-m-d') . '.csv"');
    $sortie = fopen('php://output', 'w');
    fputs($sortie, "\xEF\xBB\xBF");
    fputcsv($sortie, ['Client', 'Numero', 'Motif', 'Score moyen', 'Statut', 'Cree le', 'Maj le'], ';');
    foreach ($dossiersFiltres as $d) {
        fputcsv($sortie, [$d['client'], $d['numero'], $d['motif'], $d['score_moyen'] ?? 'Non évalué', $d['statut_label'], $d['created_at'], $d['maj']], ';');
    }
    fclose($sortie);
    exit;
}

render_head('Espace NexRaiz — Reporting', 'Reporting et indicateurs Pack Wellbeing.');
?>
<div class="flex w-full min-h-screen">

  <?php render_wellbeing_sidebar($packId, 'reporting'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_wellbeing_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div class="flex items-center justify-between flex-wrap gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Reporting Pack Wellbeing</h1>
          <p class="font-body-sm text-body-sm text-secondary">Indicateurs réels de l'activité et du suivi bien-être des clients.</p>
        </div>
        <a href="consultant-reporting-wellbeing.php?export=csv<?php echo $statutFiltre !== '' ? '&statut=' . urlencode($statutFiltre) : ''; ?>" class="h-10 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">ios_share</span>Exporter en CSV</a>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <?php foreach ($stats as $s): ?>
        <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs">
          <div class="w-11 h-11 rounded-full <?php echo $s['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $s['icone']; ?></span></div>
          <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo $s['valeur']; ?></p>
          <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($s['label']); ?></p>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($s['delta']); ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Répartition par statut</h2>
          <?php if (!$parStatut): ?><p class="font-body-sm text-body-sm text-secondary">Aucune donnée.</p><?php endif; ?>
          <?php foreach ($parStatut as $label => $nb): $pct = $nbDossiers > 0 ? round($nb / $nbDossiers * 100) : 0; ?>
          <a href="consultant-reporting-wellbeing.php?statut=<?php echo urlencode($label); ?>" class="flex flex-col gap-space-2xs hover:opacity-80 transition-opacity">
            <div class="flex items-center justify-between font-body-sm text-body-sm">
              <span class="text-on-surface font-semibold"><?php echo htmlspecialchars($label); ?></span>
              <span class="text-secondary"><?php echo $nb; ?> (<?php echo $pct; ?>%)</span>
            </div>
            <div class="h-2 rounded-full bg-surface-container-high overflow-hidden"><div class="h-full bg-primary-container" style="width: <?php echo $pct; ?>%"></div></div>
          </a>
          <?php endforeach; ?>
          <?php if ($statutFiltre !== ''): ?>
          <a href="consultant-reporting-wellbeing.php" class="self-start font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">close</span>Réinitialiser le filtre</a>
          <?php endif; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Répartition par niveau de bien-être</h2>
          <?php $couleurNiveau = ['Vigilance' => 'bg-red-500', 'Attention' => 'bg-orange-500', 'Stable' => 'bg-green-500', 'Non évalué' => 'bg-outline-variant']; ?>
          <?php foreach ($parNiveau as $label => $nb): $pct = $nbDossiers > 0 ? round($nb / $nbDossiers * 100) : 0; ?>
          <div class="flex flex-col gap-space-2xs">
            <div class="flex items-center justify-between font-body-sm text-body-sm">
              <span class="text-on-surface font-semibold"><?php echo htmlspecialchars($label); ?></span>
              <span class="text-secondary"><?php echo $nb; ?> (<?php echo $pct; ?>%)</span>
            </div>
            <div class="h-2 rounded-full bg-surface-container-high overflow-hidden"><div class="h-full <?php echo $couleurNiveau[$label]; ?>" style="width: <?php echo $pct; ?>%"></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center justify-between flex-wrap gap-space-xs">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Dossiers <?php echo $statutFiltre !== '' ? '— ' . htmlspecialchars($statutFiltre) : ''; ?></h2>
          <?php if ($nbNonEvalues > 0): ?>
          <span class="flex items-center gap-1 font-label-md text-[12px] text-orange-700"><span class="material-symbols-outlined text-[16px]">warning</span><?php echo $nbNonEvalues; ?> dossier(s) sans évaluation</span>
          <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[640px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Motif</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Score moyen</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dossiersFiltres as $d): $badge = $d['score_moyen'] !== null ? wellbeing_badge_score($d['score_moyen']) : null; ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                <td class="py-space-sm pr-space-sm">
                  <span class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo $d['initiales']; ?></span>
                    <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['client']); ?></span>
                  </span>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['motif']); ?></td>
                <td class="py-space-sm pr-space-sm">
                  <?php if ($badge): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $badge['classe']; ?>"><?php echo $d['score_moyen']; ?>/100</span>
                  <?php else: ?>
                  <span class="font-body-sm text-[12px] text-secondary">Non évalué</span>
                  <?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $d['statut_classe']; ?>"><?php echo htmlspecialchars($d['statut_label']); ?></span></td>
                <td class="py-space-sm text-right"><a href="consultant-diagnostic-wellbeing.php?dossier=<?php echo $d['id']; ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le dossier"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$dossiersFiltres): ?>
              <tr><td colspan="5" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à ce filtre.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</div>
</body>
</html>
