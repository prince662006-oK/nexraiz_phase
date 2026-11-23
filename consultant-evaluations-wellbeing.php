<?php
/**
 * Vue globale des évaluations Wellbeing (Stress/Burnout, Gestion du temps,
 * Équilibre vie pro/perso) pour tous les clients : permet de repérer
 * rapidement qui n'a pas encore été évalué ou dont le score se dégrade.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-wellbeing.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('wellbeing');

$typesEvaluation = wellbeing_types_evaluation();

$stmt = db()->prepare(
    'SELECT ds.id, ds.numero, u.nom, u.prenom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ? ORDER BY u.prenom, u.nom'
);
$stmt->execute([$packId]);
$dossiers = $stmt->fetchAll();

$stmt = db()->prepare('SELECT dossier_id, type, score, updated_at FROM wellbeing_evaluations WHERE dossier_id IN (SELECT id FROM dossiers WHERE pack_id = ?)');
$stmt->execute([$packId]);
$evaluationsParDossier = [];
foreach ($stmt->fetchAll() as $e) {
    $evaluationsParDossier[$e['dossier_id']][$e['type']] = $e;
}

$filtre = $_GET['filtre'] ?? '';

render_head('Espace NexRaiz — Évaluations', 'Vue d\'ensemble des évaluations Wellbeing par client.');
?>
<div class="flex w-full min-h-screen">

  <?php render_wellbeing_sidebar($packId, 'evaluations'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_wellbeing_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Évaluations</h1>
        <p class="font-body-sm text-body-sm text-secondary">Vue d'ensemble des 3 modules d'évaluation pour tous vos clients Pack Wellbeing.</p>
      </div>

      <form method="get" class="flex items-center gap-space-sm">
        <select name="filtre" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Tous les clients</option>
          <option value="incomplet" <?php echo $filtre === 'incomplet' ? 'selected' : ''; ?>>Évaluations incomplètes</option>
          <option value="vigilance" <?php echo $filtre === 'vigilance' ? 'selected' : ''; ?>>Au moins un score en vigilance</option>
        </select>
      </form>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                <?php foreach ($typesEvaluation as $meta): ?>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm"><?php echo htmlspecialchars($meta['titre']); ?></th>
                <?php endforeach; ?>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière évaluation</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php
              $lignesAffichees = 0;
              foreach ($dossiers as $d):
                  $evals = $evaluationsParDossier[$d['id']] ?? [];
                  $nbComplet = count($evals);
                  $aVigilance = (bool) array_filter($evals, fn ($e) => (int) $e['score'] < 55);
                  if ($filtre === 'incomplet' && $nbComplet >= count($typesEvaluation)) continue;
                  if ($filtre === 'vigilance' && !$aVigilance) continue;
                  $lignesAffichees++;
                  $dernieres = array_filter(array_column($evals, 'updated_at'));
                  $derniereMaj = $dernieres ? max($dernieres) : null;
              ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                <td class="py-space-sm pr-space-sm">
                  <span class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)); ?></span>
                    <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['prenom'] . ' ' . $d['nom']); ?></span>
                  </span>
                </td>
                <?php foreach (array_keys($typesEvaluation) as $type): $e = $evals[$type] ?? null; ?>
                <td class="py-space-sm pr-space-sm">
                  <?php if ($e): $badge = wellbeing_badge_score((int) $e['score']); ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $badge['classe']; ?>"><?php echo $e['score']; ?>/100</span>
                  <?php else: ?>
                  <span class="font-body-sm text-[12px] text-secondary">—</span>
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo $derniereMaj ? htmlspecialchars(date('d/m/Y', strtotime($derniereMaj))) : '—'; ?></td>
                <td class="py-space-sm text-right"><a href="consultant-diagnostic-wellbeing.php?dossier=<?php echo $d['id']; ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le diagnostic"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
              </tr>
              <?php endforeach; ?>
              <?php if ($lignesAffichees === 0): ?>
              <tr><td colspan="<?php echo count($typesEvaluation) + 3; ?>" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun client ne correspond à ce filtre.</td></tr>
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
