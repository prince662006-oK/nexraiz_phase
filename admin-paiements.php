<?php
/**
 * Vue transverse de tous les paiements, tous packs confondus (ou limité au
 * pack de rattachement pour un administrateur de pack), avec confirmation
 * centralisée des virements bancaires et accès au justificatif déposé par
 * le client.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';
require_once __DIR__ . '/includes/payments.php';

$admin = require_role(['admin', 'super_admin'], 'mon-espace.php');
require_permission_section($admin, 'paiements', 'admin-dashboard.php');
$estSuperAdmin = $admin['role'] === 'super_admin';

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'confirmer_virement') {
        $paiementId = (int) ($_POST['paiement_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT p.id, d.pack_id FROM paiements p
             JOIN dossiers d ON d.id = p.dossier_id
             WHERE p.id = ? AND p.methode = "virement" AND p.statut = "en_attente"'
        );
        $stmt->execute([$paiementId]);
        $cible = $stmt->fetch();

        if ($cible && ($estSuperAdmin || (int) $cible['pack_id'] === (int) $admin['pack_id'])) {
            marquer_paiement_paye($paiementId);
            $succes = 'Virement confirmé : le paiement a été marqué comme reçu.';
        }
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs WHERE slug != \'particulier\' ORDER BY nom');
$packs = $stmt->fetchAll();

$packFiltre = $_GET['pack'] ?? '';
$statutFiltre = $_GET['statut'] ?? '';
$methodeFiltre = $_GET['methode'] ?? '';

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
    $conditions[] = 'pa.statut = ?';
    $params[] = $statutFiltre;
}
if ($methodeFiltre !== '') {
    $conditions[] = 'pa.methode = ?';
    $params[] = $methodeFiltre;
}
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stmt = db()->prepare(
    "SELECT pa.id, pa.montant, pa.devise, pa.methode, pa.statut, pa.created_at,
            pa.justificatif_nom, pa.justificatif_chemin,
            d.numero AS dossier_numero, u.nom, u.prenom, u.email,
            pk.nom AS pack_nom
     FROM paiements pa
     JOIN dossiers d ON d.id = pa.dossier_id
     JOIN users u ON u.id = d.user_id
     JOIN packs pk ON pk.id = d.pack_id
     {$where}
     ORDER BY pa.created_at DESC
     LIMIT 300"
);
$stmt->execute($params);
$paiements = $stmt->fetchAll();

$totalPaye = 0.0;
$totalEnAttente = 0.0;
foreach ($paiements as $p) {
    if ($p['statut'] === 'paye') $totalPaye += (float) $p['montant'];
    if ($p['statut'] === 'en_attente') $totalEnAttente += (float) $p['montant'];
}

$statutLabels = [
    'en_attente' => ['En attente', 'bg-orange-100 text-orange-700'],
    'paye'       => ['Payé', 'bg-green-100 text-green-700'],
    'echoue'     => ['Échoué', 'bg-error-container text-error'],
    'rembourse'  => ['Remboursé', 'bg-surface-container-high text-secondary'],
];
$methodeLabels = ['stripe' => 'Carte (Stripe)', 'cinetpay' => 'Mobile Money (CinetPay)', 'neemii' => 'Mobile Money (Neemii)', 'virement' => 'Virement'];

render_head('Paiements — Administration NexRaiz', 'Suivi et confirmation des paiements NexRaiz.');
?>
<div class="flex w-full min-h-screen">

  <?php render_admin_sidebar('paiements'); ?>

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
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Paiements</h1>
      <p class="font-body-sm text-body-sm text-secondary"><?php echo count($paiements); ?> paiement(s) <?php echo $estSuperAdmin ? 'tous packs confondus' : 'pour votre pack'; ?>.</p>
    </div>

    <?php if ($succes): ?>
    <div class="bg-green-100 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-space-sm max-w-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm">
        <p class="font-body-sm text-[12px] text-secondary">Encaissé (résultats affichés)</p>
        <p class="font-headline-sm text-headline-sm text-green-700 font-bold"><?php echo number_format($totalPaye, 0, ',', ' '); ?> FCFA</p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm">
        <p class="font-body-sm text-[12px] text-secondary">En attente (résultats affichés)</p>
        <p class="font-headline-sm text-headline-sm text-orange-600 font-bold"><?php echo number_format($totalEnAttente, 0, ',', ' '); ?> FCFA</p>
      </div>
    </div>

    <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-wrap items-end gap-space-sm">
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
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-secondary">Méthode</span>
        <select name="methode" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Toutes</option>
          <?php foreach ($methodeLabels as $k => $v): ?>
          <option value="<?php echo $k; ?>" <?php echo $methodeFiltre === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="h-10 px-space-md rounded-lg bg-primary-container text-white font-label-md text-label-md">Filtrer</button>
      <?php if ($packFiltre || $statutFiltre || $methodeFiltre): ?>
      <a href="admin-paiements.php" class="h-10 px-space-md rounded-lg border border-outline-variant flex items-center font-label-md text-label-md text-secondary">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm overflow-x-auto">
      <table class="w-full min-w-[1000px] border-collapse">
        <thead>
          <tr class="border-b border-outline-variant/60 text-left">
            <th class="p-space-sm font-label-md text-label-md text-secondary">Dossier</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Candidat</th>
            <?php if ($estSuperAdmin): ?><th class="p-space-sm font-label-md text-label-md text-secondary">Pack</th><?php endif; ?>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Montant</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Méthode</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Statut</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Justificatif</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Date</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($paiements as $p): $statutInfo = $statutLabels[$p['statut']] ?? [$p['statut'], 'bg-surface-container-high text-secondary']; ?>
          <tr class="border-b border-outline-variant/30 last:border-0 align-top">
            <td class="p-space-sm font-label-md text-label-md text-on-surface font-semibold whitespace-nowrap"><?php echo htmlspecialchars($p['dossier_numero']); ?></td>
            <td class="p-space-sm">
              <p class="font-body-sm text-body-sm text-on-surface font-semibold"><?php echo htmlspecialchars($p['prenom'] . ' ' . $p['nom']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($p['email']); ?></p>
            </td>
            <?php if ($estSuperAdmin): ?><td class="p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($p['pack_nom']); ?></td><?php endif; ?>
            <td class="p-space-sm font-body-sm text-body-sm text-on-surface whitespace-nowrap"><?php echo number_format((float) $p['montant'], 0, ',', ' '); ?> <?php echo htmlspecialchars($p['devise']); ?></td>
            <td class="p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($methodeLabels[$p['methode']] ?? $p['methode']); ?></td>
            <td class="p-space-sm"><span class="inline-flex px-space-xs py-1 rounded-full font-label-md text-[11px] font-bold <?php echo $statutInfo[1]; ?>"><?php echo htmlspecialchars($statutInfo[0]); ?></span></td>
            <td class="p-space-sm">
              <?php if ($p['justificatif_chemin']): ?>
              <a href="<?php echo htmlspecialchars($p['justificatif_chemin']); ?>" target="_blank" class="font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">description</span>Voir</a>
              <?php else: ?>
              <span class="font-body-sm text-[11px] text-outline">—</span>
              <?php endif; ?>
            </td>
            <td class="p-space-sm font-body-sm text-[12px] text-secondary whitespace-nowrap"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($p['created_at']))); ?></td>
            <td class="p-space-sm">
              <?php if ($p['methode'] === 'virement' && $p['statut'] === 'en_attente'): ?>
              <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="confirmer_virement">
                <input type="hidden" name="paiement_id" value="<?php echo $p['id']; ?>">
                <button type="submit" class="h-8 px-space-xs rounded-lg bg-green-600 text-white font-label-md text-[11px] whitespace-nowrap">Confirmer</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$paiements): ?>
          <tr><td colspan="9" class="p-space-lg text-center font-body-sm text-body-sm text-secondary">Aucun paiement ne correspond à ces critères.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  </div>
</div>
</body>
</html>
