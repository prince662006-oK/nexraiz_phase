<?php
/**
 * Vue transverse de tous les rendez-vous, tous packs confondus (ou limité
 * au pack de rattachement pour un administrateur de pack).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';

$admin = require_role(['admin', 'super_admin'], 'mon-espace.php');
require_permission_section($admin, 'rendezvous', 'admin-dashboard.php');
$estSuperAdmin = $admin['role'] === 'super_admin';

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'annuler_rdv') {
        $rdvId = (int) ($_POST['rdv_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT r.id, d.pack_id, d.user_id, d.numero FROM rendezvous r
             JOIN dossiers d ON d.id = r.dossier_id
             WHERE r.id = ?'
        );
        $stmt->execute([$rdvId]);
        $cible = $stmt->fetch();

        if ($cible && ($estSuperAdmin || (int) $cible['pack_id'] === (int) $admin['pack_id'])) {
            db()->prepare("UPDATE rendezvous SET statut = 'annule' WHERE id = ?")->execute([$rdvId]);
            notify((int) $cible['user_id'], 'rendez_vous', 'Rendez-vous annulé', 'Le rendez-vous de votre dossier ' . $cible['numero'] . ' a été annulé par NexRaiz. Contactez votre consultant pour en reprogrammer un.', null);
            $succes = 'Rendez-vous annulé.';
        }
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs WHERE slug != \'particulier\' ORDER BY nom');
$packs = $stmt->fetchAll();

$packFiltre = $_GET['pack'] ?? '';
$statutFiltre = $_GET['statut'] ?? '';
$periode = $_GET['periode'] ?? 'a_venir';

$conditions = [];
$params = [];

if (!$estSuperAdmin) {
    $conditions[] = 'd.pack_id = ?';
    $params[] = $admin['pack_id'];
} elseif ($packFiltre !== '') {
    $conditions[] = 'pk.slug = ?';
    $params[] = $packFiltre;
}
if ($statutFiltre !== '') {
    $conditions[] = 'r.statut = ?';
    $params[] = $statutFiltre;
}
if ($periode === 'a_venir') {
    $conditions[] = 'r.date_heure >= NOW()';
} elseif ($periode === 'passes') {
    $conditions[] = 'r.date_heure < NOW()';
}
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
$ordre = $periode === 'passes' ? 'r.date_heure DESC' : 'r.date_heure ASC';

$stmt = db()->prepare(
    "SELECT r.id, r.date_heure, r.format, r.statut,
            d.numero AS dossier_numero, u.nom, u.prenom, u.email,
            pk.nom AS pack_nom,
            c.nom AS consultant_nom, c.prenom AS consultant_prenom
     FROM rendezvous r
     JOIN dossiers d ON d.id = r.dossier_id
     JOIN users u ON u.id = d.user_id
     JOIN packs pk ON pk.id = d.pack_id
     LEFT JOIN users c ON c.id = d.consultant_id
     {$where}
     ORDER BY {$ordre}
     LIMIT 300"
);
$stmt->execute($params);
$rendezvous = $stmt->fetchAll();

$statutLabels = [
    'planifie' => ['Planifié', 'bg-blue-100 text-blue-700'],
    'confirme' => ['Confirmé', 'bg-green-100 text-green-700'],
    'annule'   => ['Annulé', 'bg-error-container text-error'],
    'termine'  => ['Terminé', 'bg-surface-container-high text-secondary'],
];
$formatLabels = ['ligne' => 'Visioconférence', 'presentiel' => 'Présentiel', 'a_definir' => 'À définir', 'hybride' => 'Hybride'];

render_head('Rendez-vous — Administration NexRaiz', 'Vue transverse de tous les rendez-vous NexRaiz.');
?>
<div class="flex w-full min-h-screen">

  <?php render_admin_sidebar('rendezvous'); ?>

  <div class="flex-1 flex flex-col min-w-0">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
    </a>
    <div class="flex items-center gap-space-sm shrink-0 ml-auto">
      <?php render_notification_bell((int) $admin['id']); ?>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-max-width-content mx-auto w-full">
    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Rendez-vous</h1>
      <p class="font-body-sm text-body-sm text-secondary"><?php echo count($rendezvous); ?> rendez-vous <?php echo $estSuperAdmin ? 'tous packs confondus' : 'pour votre pack'; ?>.</p>
    </div>

    <?php if ($succes): ?>
    <div class="bg-green-100 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div>
    <?php endif; ?>

    <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-wrap items-end gap-space-sm">
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-secondary">Période</span>
        <select name="periode" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="a_venir" <?php echo $periode === 'a_venir' ? 'selected' : ''; ?>>À venir</option>
          <option value="passes" <?php echo $periode === 'passes' ? 'selected' : ''; ?>>Passés</option>
          <option value="" <?php echo $periode === '' ? 'selected' : ''; ?>>Tous</option>
        </select>
      </label>
      <?php if ($estSuperAdmin): ?>
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-secondary">Pack</span>
        <select name="pack" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Tous</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo htmlspecialchars($p['slug']); ?>" <?php echo $packFiltre === $p['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-secondary">Statut</span>
        <select name="statut" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Tous</option>
          <?php foreach ($statutLabels as $k => $v): ?>
          <option value="<?php echo $k; ?>" <?php echo $statutFiltre === $k ? 'selected' : ''; ?>><?php echo $v[0]; ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="h-10 px-space-md rounded-lg bg-primary-container text-white font-label-md text-label-md">Filtrer</button>
    </form>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm overflow-x-auto">
      <table class="w-full min-w-[1000px] border-collapse">
        <thead>
          <tr class="border-b border-outline-variant/60 text-left">
            <th class="p-space-sm font-label-md text-label-md text-secondary">Date &amp; heure</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Dossier</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Candidat</th>
            <?php if ($estSuperAdmin): ?><th class="p-space-sm font-label-md text-label-md text-secondary">Pack</th><?php endif; ?>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Consultant</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Format</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Statut</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rendezvous as $r): $statutInfo = $statutLabels[$r['statut']] ?? [$r['statut'], 'bg-surface-container-high text-secondary']; ?>
          <tr class="border-b border-outline-variant/30 last:border-0 align-top">
            <td class="p-space-sm font-label-md text-label-md text-on-surface font-semibold whitespace-nowrap"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($r['date_heure']))); ?></td>
            <td class="p-space-sm font-body-sm text-body-sm text-on-surface whitespace-nowrap"><?php echo htmlspecialchars($r['dossier_numero']); ?></td>
            <td class="p-space-sm">
              <p class="font-body-sm text-body-sm text-on-surface font-semibold"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($r['email']); ?></p>
            </td>
            <?php if ($estSuperAdmin): ?><td class="p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($r['pack_nom']); ?></td><?php endif; ?>
            <td class="p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $r['consultant_nom'] ? htmlspecialchars($r['consultant_prenom'] . ' ' . $r['consultant_nom']) : '—'; ?></td>
            <td class="p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($formatLabels[$r['format']] ?? $r['format']); ?></td>
            <td class="p-space-sm"><span class="inline-flex px-space-xs py-1 rounded-full font-label-md text-[11px] font-bold <?php echo $statutInfo[1]; ?>"><?php echo htmlspecialchars($statutInfo[0]); ?></span></td>
            <td class="p-space-sm">
              <?php if (!in_array($r['statut'], ['annule', 'termine'], true)): ?>
              <form method="post" onsubmit="return confirm('Annuler ce rendez-vous ?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="annuler_rdv">
                <input type="hidden" name="rdv_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="h-8 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-error whitespace-nowrap">Annuler</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rendezvous): ?>
          <tr><td colspan="8" class="p-space-lg text-center font-body-sm text-body-sm text-secondary">Aucun rendez-vous ne correspond à ces critères.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  </div>
</div>
</body>
</html>
