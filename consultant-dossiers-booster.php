<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';
require_once __DIR__ . '/includes/payments.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassigner') {
    require_csrf();
    $dossierId = (int) ($_POST['dossier_id'] ?? 0);
    $nouveauConsultantId = $_POST['nouveau_consultant_id'] !== '' ? (int) $_POST['nouveau_consultant_id'] : null;
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND pack_id = ?');
    $stmt->execute([$nouveauConsultantId, $dossierId, $packId]);
    $succes = 'Consultant réassigné.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmer_virement') {
    require_csrf();
    $paiementId = (int) ($_POST['paiement_id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT p.id FROM paiements p JOIN dossiers d ON d.id = p.dossier_id
         WHERE p.id = ? AND d.pack_id = ? AND p.methode = "virement" AND p.statut = "en_attente"'
    );
    $stmt->execute([$paiementId, $packId]);
    if ($stmt->fetchColumn()) {
        marquer_paiement_paye($paiementId);
        $succes = 'Virement confirmé : le paiement a été marqué comme reçu.';
    }
}

$stmt = db()->prepare("SELECT id, nom, prenom FROM users WHERE role IN ('consultant', 'admin') ORDER BY prenom, nom");
$stmt->execute();
$consultantsDisponibles = $stmt->fetchAll();

$dossiersApercu = booster_dossiers_apercu($packId, $consultant);

$stmt = db()->prepare('SELECT dossier_id, id, statut, methode FROM paiements p1 WHERE p1.id = (SELECT MAX(p2.id) FROM paiements p2 WHERE p2.dossier_id = p1.dossier_id)');
$stmt->execute();
$paiementParDossier = [];
foreach ($stmt->fetchAll() as $p) {
    $paiementParDossier[$p['dossier_id']] = $p;
}
$paiementLabels = ['paye' => ['Payé', 'text-green-700'], 'en_attente' => ['En attente', 'text-orange-600'], 'echoue' => ['Échoué', 'text-error'], 'rembourse' => ['Remboursé', 'text-secondary']];

$recherche = trim($_GET['q'] ?? '');

$dossiersFiltres = array_filter($dossiersApercu, function ($d) use ($recherche) {
    if ($recherche !== '' && stripos($d['client'] . ' ' . $d['numero'], $recherche) === false) return false;
    return true;
});

render_head('Espace NexRaiz — Mes clients', 'Gestion des dossiers candidats du Pack Booster.');
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'clients'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div class="flex items-center justify-between flex-wrap gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mes clients Pack Booster</h1>
          <p class="font-body-sm text-body-sm text-secondary">Objectif, secteur et consultant assigné pour chaque dossier.</p>
        </div>
        <a href="consultant-nouveau-dossier-booster.php" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1"><span class="material-symbols-outlined text-[18px]">add_circle</span>Nouveau dossier</a>
      </div>

      <?php if ($succes): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
      </div>
      <?php endif; ?>

      <form method="get" class="flex items-center gap-space-sm max-w-md">
        <div class="relative flex-1">
          <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom ou numéro de dossier..." class="w-full h-11 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
        </div>
        <button type="submit" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Rechercher</button>
        <?php if ($recherche !== ''): ?>
        <a href="consultant-dossiers-booster.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span></a>
        <?php endif; ?>
      </form>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[900px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Objectif</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Secteur</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultant assigné</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Paiement</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière mise à jour</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dossiersFiltres as $d):
                $paiement = $paiementParDossier[$d['id']] ?? null;
                [$paiementLabel, $paiementClass] = $paiement ? ($paiementLabels[$paiement['statut']] ?? ['—', 'text-secondary']) : ['Aucun paiement', 'text-secondary'];
                $virementAConfirmer = $paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente';
              ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                <td class="py-space-sm pr-space-sm">
                  <span class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo $d['initiales']; ?></span>
                    <span class="flex flex-col">
                      <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['client']); ?></span>
                      <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['numero']); ?></span>
                    </span>
                  </span>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['objectif']); ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['secteur']); ?></td>
                <td class="py-space-sm pr-space-sm">
                  <form method="post" class="flex items-center gap-1">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reassigner">
                    <input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                    <select name="nouveau_consultant_id" onchange="this.form.submit()" class="h-9 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                      <option value="">Non assigné</option>
                      <?php foreach ($consultantsDisponibles as $c): ?>
                      <option value="<?php echo $c['id']; ?>" <?php echo $d['consultant_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $d['statut_classe']; ?>"><?php echo htmlspecialchars($d['statut_label']); ?></span></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px]">
                  <span class="font-semibold <?php echo $paiementClass; ?>"><?php echo htmlspecialchars($paiementLabel); ?></span>
                  <?php if ($virementAConfirmer): ?>
                  <form method="post" class="inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="confirmer_virement">
                    <input type="hidden" name="paiement_id" value="<?php echo $paiement['id']; ?>">
                    <button type="submit" class="ml-1 px-space-2xs py-0.5 rounded-full border border-outline-variant text-[10px] font-bold text-primary hover:bg-primary-container/10 transition-colors" title="Confirmer la réception du virement">Confirmer</button>
                  </form>
                  <?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['maj']))); ?></td>
                <td class="py-space-sm text-right"><a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le dossier"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$dossiersFiltres): ?>
              <tr><td colspan="8" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à cette recherche.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="font-body-sm text-[12px] text-secondary"><?php echo count($dossiersFiltres); ?> dossier<?php echo count($dossiersFiltres) > 1 ? 's' : ''; ?> au total</p>
      </div>

    </main>
  </div>
</div>
</body>
</html>
