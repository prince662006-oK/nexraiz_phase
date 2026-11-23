<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');
$montant = 150000; // FCFA (XOF)

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $methode = $_POST['methode'] ?? '';

    if (!in_array($methode, ['stripe', 'neemii', 'virement'], true)) {
        $erreurs[] = 'Merci de choisir un moyen de paiement.';
    } else {
        $paiementId = create_paiement_record((int) $dossier['id'], $montant, $methode);

        try {
            if ($methode === 'stripe') {
                $url = create_stripe_checkout_url(
                    $paiementId,
                    $montant,
                    $dossier['numero'],
                    $baseUrl . '/paiement-retour.php',
                    $baseUrl . '/booster-paiement.php'
                );
                header('Location: ' . $url);
                exit;
            }

            if ($methode === 'neemii') {
                $clientCourant = current_user();
                $url = create_neemii_payment_url(
                    $paiementId,
                    $montant,
                    $dossier['numero'],
                    $clientCourant['email'],
                    $clientCourant['prenom'] . ' ' . $clientCourant['nom'],
                    $baseUrl . '/paiement-retour-neemii.php?paiement=' . $paiementId
                );
                header('Location: ' . $url);
                exit;
            }

            // Virement bancaire : suivi manuel, le dossier reste "en attente" jusqu'à réception.
            update_dossier_step((int) $dossier['id'], 7);
            log_activite((int) $dossier['id'], 'Virement bancaire choisi — en attente de réception');
            header('Location: ' . step_url(7, 'booster') . '?paiement=' . $paiementId . '&methode=virement');
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

$modules = [
    'Diagnostic initial',
    'Bilan de compétences',
    'Carrière & reconversion',
    'Formations & certifications',
    'Communication assertive',
    'Prise de poste',
];

$apres = [
    ['icone' => 'receipt_long', 'titre' => 'Reçu de paiement', 'texte' => 'Une confirmation immédiate de votre paiement par email.'],
    ['icone' => 'event_available', 'titre' => 'Confirmation de rendez-vous', 'texte' => 'Détails de votre rendez-vous avec le coach assigné.'],
    ['icone' => 'summarize', 'titre' => 'Récapitulatif de la demande', 'texte' => 'Un résumé complet de votre Pack Booster et des prochaines étapes.'],
    ['icone' => 'space_dashboard', 'titre' => 'Accès au tableau de bord', 'texte' => 'Accès immédiat à votre espace client pour suivre votre parcours.'],
];

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$rdv = $stmt->fetch();
$formatLabels = ['ligne' => 'En ligne (visioconférence)', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir avec NexRaiz'];

render_head('Pack Booster — Paiement');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'booster',
    6,
    'Finalisez votre Pack Booster',
    'Choisissez votre mode de paiement et confirmez votre inscription',
    'payments',
    ['verified', 'person', 'description']
);
render_stepper('booster', 6);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1fr_1.4fr] gap-space-lg items-start">

    <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-md">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Récapitulatif de la commande</h2>
      <div class="flex items-center gap-space-sm pb-space-sm border-b border-outline-variant/60">
        <div class="w-11 h-11 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">inventory_2</span></div>
        <div><p class="font-body-sm text-[12px] text-secondary">Produit</p><p class="font-label-lg text-label-lg text-on-surface font-bold">Pack Booster</p></div>
      </div>

      <div class="flex items-start gap-space-xs pb-space-sm border-b border-outline-variant/60">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">calendar_today</span>
        <div><p class="font-body-sm text-[12px] text-secondary">Date du rendez-vous</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p></div>
      </div>
      <div class="flex items-start gap-space-xs pb-space-sm border-b border-outline-variant/60">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">videocam</span>
        <div><p class="font-body-sm text-[12px] text-secondary">Format choisi</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($formatLabels[$rdv['format'] ?? 'ligne'] ?? 'Non défini'); ?></p></div>
      </div>

      <div class="flex flex-col gap-space-2xs pb-space-sm border-b border-outline-variant/60">
        <p class="font-label-md text-label-md text-secondary uppercase tracking-wider font-bold">Modules inclus</p>
        <?php foreach ($modules as $m): ?>
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary">check_circle</span>
          <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($m); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="flex items-center justify-between pt-space-xs">
        <span class="font-label-lg text-label-lg text-on-surface font-bold">Total</span>
        <span class="font-headline-md text-headline-md text-primary font-bold"><?php echo number_format($montant, 0, ',', ' '); ?> FCFA</span>
      </div>
      <p class="font-body-sm text-[11px] text-secondary -mt-space-xs">Toutes taxes comprises</p>
    </div>

    <div class="flex flex-col gap-space-md">
      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <form method="post" id="paymentForm" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="methode" id="methodeInput" value="stripe">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Informations de paiement</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm" id="paymentMethodGroup">
          <button type="button" data-value="stripe" class="payment-method selected relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-primary-container bg-primary-container/5">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center check-badge"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <span class="material-symbols-outlined text-[26px] text-primary">credit_card</span>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Carte bancaire</span>
            <span class="font-body-sm text-[11px] text-secondary">via Stripe</span>
          </button>
          <button type="button" data-value="neemii" class="payment-method relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <span class="material-symbols-outlined text-[26px] text-primary">smartphone</span>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mobile Money</span>
            <span class="font-body-sm text-[11px] text-secondary">via Neemii Pay</span>
          </button>
          <button type="button" data-value="virement" class="payment-method relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <span class="material-symbols-outlined text-[26px] text-primary">account_balance</span>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Virement bancaire</span>
            <span class="font-body-sm text-[11px] text-secondary">Tous bancs</span>
          </button>
        </div>

        <p id="noteStripe" class="payment-note font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vous serez redirigé vers une page de paiement sécurisée Stripe pour saisir votre carte. Aucune donnée bancaire ne transite par NexRaiz.</p>
        <p id="noteNeemii" class="payment-note hidden font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vous serez redirigé vers Neemii Pay pour finaliser le paiement par Mobile Money (Orange Money, MTN, Moov, Wave...).</p>
        <p id="noteVirement" class="payment-note hidden font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary">info</span>Les coordonnées bancaires NexRaiz vous seront envoyées par email. Votre dossier avance dès réception du virement, sous 1 à 2 jours ouvrés.</p>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm bg-surface-container-low rounded-xl p-space-sm">
          <div class="flex items-center gap-space-xs">
            <span class="material-symbols-outlined text-[20px] text-primary">lock</span>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Paiement 100% sécurisé</p><p class="font-body-sm text-[11px] text-secondary">Vos données sont chiffrées et protégées.</p></div>
          </div>
          <span class="font-label-md text-[11px] text-secondary font-bold">PCI DSS · 3D Secure · TLS Encrypted</span>
        </div>

        <button type="submit" class="h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span class="material-symbols-outlined text-[18px]">credit_card</span>
          <span>Payer maintenant</span>
        </button>
        <p class="text-center font-body-sm text-[11px] text-secondary">En cliquant, vous acceptez nos <a href="#" class="text-primary underline">Conditions Générales de Vente</a>.</p>
      </form>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Après votre paiement, vous recevrez</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm">
          <?php foreach ($apres as $a): ?>
          <div class="flex flex-col items-center text-center gap-space-2xs">
            <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo $a['icone']; ?></span></div>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($a['texte']); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function() {
  var group = document.getElementById('paymentMethodGroup');
  var methodeInput = document.getElementById('methodeInput');
  var notes = { stripe: document.getElementById('noteStripe'), neemii: document.getElementById('noteNeemii'), virement: document.getElementById('noteVirement') };
  if (group) {
    group.querySelectorAll('.payment-method').forEach(function(btn) {
      btn.addEventListener('click', function() {
        group.querySelectorAll('.payment-method').forEach(function(b) {
          b.classList.remove('border-primary-container', 'bg-primary-container/5', 'selected');
          var badge = b.querySelector('.check-badge');
          if (badge) { badge.classList.add('hidden'); badge.classList.remove('flex'); }
        });
        btn.classList.add('border-primary-container', 'bg-primary-container/5', 'selected');
        var badge = btn.querySelector('.check-badge');
        if (badge) { badge.classList.remove('hidden'); badge.classList.add('flex'); }
        var valeur = btn.getAttribute('data-value');
        methodeInput.value = valeur;
        Object.keys(notes).forEach(function(k) { if (notes[k]) notes[k].classList.toggle('hidden', k !== valeur); });
      });
    });
  }
})();
</script>

<?php render_footer(); ?>
