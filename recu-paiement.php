<?php
/**
 * Reçu de paiement consultable par le client (et son consultant), généré à
 * la volée à partir des données réelles du dossier et du paiement — aucun
 * fichier statique, générique pour tous les packs.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$user = require_login('mon-espace.php');
$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom, u.email, pk.nom AS pack_nom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id JOIN packs pk ON pk.id = ds.pack_id
     WHERE ds.id = ?'
);
$stmt->execute([$dossierId]);
$dossier = $stmt->fetch();

$autorise = $dossier && ((int) $dossier['user_id'] === (int) $user['id'] || in_array($user['role'], ['consultant', 'admin'], true));
if (!$dossier || !$autorise) {
    header('Location: mon-espace.php');
    exit;
}

$stmt = db()->prepare("SELECT * FROM paiements WHERE dossier_id = ? AND statut = 'paye' ORDER BY id DESC LIMIT 1");
$stmt->execute([$dossierId]);
$paiement = $stmt->fetch();

if (!$paiement) {
    header('Location: mon-espace.php');
    exit;
}

$methodeLabels = ['stripe' => 'Carte bancaire (Stripe)', 'cinetpay' => 'Mobile Money (CinetPay)', 'neemii' => 'Mobile Money (Neemii Pay)', 'virement' => 'Virement bancaire'];

render_head('Reçu de paiement — Dossier ' . htmlspecialchars($dossier['numero']));
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-reading mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Reçu de paiement</span>
    </nav>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <div class="flex items-center gap-space-sm">
        <div class="w-12 h-12 rounded-full bg-primary-container text-white flex items-center justify-center"><span class="material-symbols-outlined text-[24px]">receipt_long</span></div>
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Reçu de paiement</h1>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($dossier['pack_nom']); ?> · Dossier <?php echo htmlspecialchars($dossier['numero']); ?></p>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm pt-space-sm border-t border-outline-variant/60">
        <div><p class="font-body-sm text-[12px] text-secondary">Facturé à</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p><p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($dossier['email']); ?></p></div>
        <div><p class="font-body-sm text-[12px] text-secondary">Date du paiement</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($paiement['updated_at']))); ?></p></div>
        <div><p class="font-body-sm text-[12px] text-secondary">Moyen de paiement</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($methodeLabels[$paiement['methode']] ?? $paiement['methode']); ?></p></div>
        <div><p class="font-body-sm text-[12px] text-secondary">Référence</p><p class="font-label-lg text-label-lg text-on-surface font-semibold">#<?php echo htmlspecialchars((string) $paiement['id']); ?></p></div>
      </div>

      <div class="flex items-center justify-between pt-space-sm border-t border-outline-variant/60">
        <span class="font-label-lg text-label-lg text-on-surface font-bold">Montant payé</span>
        <span class="font-headline-md text-headline-md text-primary font-bold"><?php echo number_format((float) $paiement['montant'], 0, ',', ' '); ?> <?php echo htmlspecialchars($paiement['devise']); ?></span>
      </div>

      <a href="mon-espace.php" class="self-start h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors mt-space-xs">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Retour à mon espace</span>
      </a>
    </div>
  </div>
</section>
<?php render_footer(); ?>
