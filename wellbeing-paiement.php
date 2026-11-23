<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

$dossier = require_dossier('wellbeing', 'wellbeing-espace-client.php');
$montant = 91450; // FCFA (XOF) — 75 000 Pack + 2 500 frais de service + 13 950 TVA (18%)

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
                    $baseUrl . '/wellbeing-paiement.php'
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
            header('Location: ' . step_url(7, 'wellbeing') . '?paiement=' . $paiementId . '&methode=virement');
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$rdv = $stmt->fetch();
$formatLabels = ['ligne' => 'Séance en ligne (Visioconférence)', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir avec NexRaiz'];

render_head('Pack Wellbeing — Paiement');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'wellbeing',
    6,
    'Finalisez votre inscription',
    'Vous êtes à une étape de votre mieux-être. Effectuez votre paiement en toute sécurité pour confirmer votre accompagnement.',
    'payments',
    []
);
render_stepper('wellbeing', 6);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-start">

    <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-md">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Informations de paiement</h2>
      <p class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[16px] text-primary">lock</span>Paiement 100% sécurisé et crypté</p>

      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" id="paymentForm" class="flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="methode" id="methodeInput" value="stripe">
        <div class="flex flex-col gap-space-sm">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Méthode de paiement</h3>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm" id="paymentMethodGroup">
            <button type="button" data-value="stripe" class="payment-method selected relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-primary-container bg-primary-container/5">
              <span class="absolute top-2 right-2 w-4 h-4 rounded-full border-2 border-primary-container check-dot flex items-center justify-center"><span class="w-2 h-2 rounded-full bg-primary-container"></span></span>
              <span class="material-symbols-outlined text-[24px] text-primary">credit_card</span>
              <span class="font-label-md text-label-md text-on-surface font-semibold">Carte bancaire</span>
              <span class="font-body-sm text-[11px] text-secondary">via Stripe</span>
            </button>
            <button type="button" data-value="neemii" class="payment-method relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
              <span class="absolute top-2 right-2 w-4 h-4 rounded-full border-2 border-outline-variant check-dot"></span>
              <span class="material-symbols-outlined text-[24px] text-primary">smartphone</span>
              <span class="font-label-md text-label-md text-on-surface font-semibold">Mobile money</span>
              <span class="font-body-sm text-[11px] text-secondary">via Neemii Pay</span>
            </button>
            <button type="button" data-value="virement" class="payment-method relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
              <span class="absolute top-2 right-2 w-4 h-4 rounded-full border-2 border-outline-variant check-dot"></span>
              <span class="material-symbols-outlined text-[24px] text-primary">account_balance</span>
              <span class="font-label-md text-label-md text-on-surface font-semibold">Virement bancaire</span>
            </button>
          </div>
        </div>

        <p id="noteStripe" class="payment-note font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vous serez redirigé vers une page de paiement sécurisée Stripe pour saisir votre carte. Aucune donnée bancaire ne transite par NexRaiz.</p>
        <p id="noteNeemii" class="payment-note hidden font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vous serez redirigé vers Neemii Pay pour finaliser le paiement par Mobile Money (Orange Money, MTN, Moov, Wave...).</p>
        <p id="noteVirement" class="payment-note hidden font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary">info</span>Les coordonnées bancaires NexRaiz vous seront envoyées par email. Votre dossier avance dès réception du virement, sous 1 à 2 jours ouvrés.</p>
      </form>

      <p class="flex items-start gap-space-xs bg-surface-container-low rounded-xl p-space-sm font-body-sm text-[12px] text-secondary">
        <span class="material-symbols-outlined text-[18px] text-primary">shield</span>
        <span><strong class="text-on-surface">Vos données sont sécurisées.</strong> Nous utilisons un cryptage SSL 256 bits pour protéger vos informations bancaires.</span>
      </p>
    </div>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Récapitulatif de votre commande</h3>
        <div class="flex items-center gap-space-xs pb-space-sm border-b border-outline-variant/60">
          <div class="w-11 h-11 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0">A</div>
          <div><p class="font-body-sm text-[11px] text-secondary">Produit</p><p class="font-label-lg text-label-lg text-on-surface font-bold">Pack Wellbeing</p></div>
        </div>
        <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary mt-0.5">calendar_today</span><div><p class="font-body-sm text-[12px] text-secondary">Date et heure du rendez-vous</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p></div></div>
        <div class="flex items-start gap-space-xs pb-space-sm border-b border-outline-variant/60"><span class="material-symbols-outlined text-[18px] text-primary mt-0.5">videocam</span><div><p class="font-body-sm text-[12px] text-secondary">Format</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($formatLabels[$rdv['format'] ?? 'ligne'] ?? 'Non défini'); ?></p></div></div>

        <div class="flex items-center justify-between font-body-sm text-body-sm text-secondary"><span>Prix du Pack</span><span>75 000 FCFA</span></div>
        <div class="flex items-center justify-between font-body-sm text-body-sm text-secondary"><span class="flex items-center gap-1">Frais de service <span class="material-symbols-outlined text-[14px] text-outline">info</span></span><span>2 500 FCFA</span></div>
        <div class="flex items-center justify-between font-body-sm text-body-sm text-secondary pb-space-sm border-b border-outline-variant/60"><span>TVA (18%)</span><span>13 950 FCFA</span></div>
        <div class="flex items-center justify-between"><span class="font-label-lg text-label-lg text-on-surface font-bold">Total</span><span class="font-headline-md text-headline-md text-primary font-bold"><?php echo number_format($montant, 0, ',', ' '); ?> FCFA</span></div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <p class="flex items-center gap-space-xs font-label-md text-label-md text-on-surface font-semibold"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Paiement sécurisé</p>
        <p class="font-body-sm text-[12px] text-secondary">Vos paiements sont protégés par nos partenaires de confiance.</p>
        <div class="flex items-center gap-space-sm pt-space-xs">
          <span class="px-space-xs py-0.5 rounded bg-surface-container-low font-label-md text-[10px] font-bold text-secondary">PCI DSS</span>
          <span class="px-space-xs py-0.5 rounded bg-surface-container-low font-label-md text-[10px] font-bold text-secondary">SSL</span>
          <span class="px-space-xs py-0.5 rounded bg-surface-container-low font-label-md text-[10px] font-bold text-secondary">Verified by VISA</span>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex items-start gap-space-xs">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">check_circle</span>
        <div><p class="font-label-md text-label-md text-on-surface font-semibold">Politique de report et d'annulation</p><p class="font-body-sm text-[12px] text-secondary">Vous pouvez reporter votre séance jusqu'à 24h avant le rendez-vous sans frais. Pour toute annulation, veuillez consulter nos conditions générales d'utilisation.</p></div>
      </div>

      <div class="flex flex-col gap-space-xs">
        <button type="submit" form="paymentForm" class="h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span class="material-symbols-outlined text-[18px]">credit_card</span>
          <span>Payer maintenant</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <a href="<?php echo step_url(5, 'wellbeing'); ?>" class="h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">arrow_back</span>
          <span>Retourner</span>
        </a>
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
          var dot = b.querySelector('.check-dot');
          dot.innerHTML = '';
          dot.classList.remove('border-primary-container');
          dot.classList.add('border-outline-variant');
        });
        btn.classList.add('border-primary-container', 'bg-primary-container/5', 'selected');
        var dot = btn.querySelector('.check-dot');
        dot.classList.remove('border-outline-variant');
        dot.classList.add('border-primary-container');
        dot.innerHTML = '<span class="w-2 h-2 rounded-full bg-primary-container"></span>';
        dot.classList.add('flex', 'items-center', 'justify-center');
        var valeur = btn.getAttribute('data-value');
        methodeInput.value = valeur;
        Object.keys(notes).forEach(function(k) { if (notes[k]) notes[k].classList.toggle('hidden', k !== valeur); });
      });
    });
  }
})();
</script>

<?php render_footer(); ?>
