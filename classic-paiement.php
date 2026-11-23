<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$montant = 49900; // FCFA (XOF)

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
                    $baseUrl . '/classic-paiement.php'
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
                // Interstitiel au lieu d'une redirection directe : la page de
                // paiement Neemii Pay met parfois plusieurs secondes à charger
                // et peut rester bloquée sur un état partiel (montant, moyens
                // de paiement et bouton "Payer" absents) tant qu'elle n'a pas
                // été rechargée une fois — comportement constaté côté Neemii,
                // hors de notre contrôle. On prévient donc le client avant de
                // le rediriger, avec un bouton de secours et l'astuce du
                // rechargement si la page arrive vide.
                ?>
                <!DOCTYPE html>
                <html lang="fr">
                <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Redirection vers Neemii Pay…</title>
                <style>
                  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#faf8ff;font-family:"Segoe UI",Arial,sans-serif;padding:24px;box-sizing:border-box;}
                  .card{max-width:28rem;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(11,25,44,.08);padding:32px 28px;text-align:center;}
                  .spinner{width:40px;height:40px;border:4px solid #dbe1ff;border-top-color:#1E3E62;border-radius:50%;margin:0 auto 20px;animation:spin .8s linear infinite;}
                  @keyframes spin{to{transform:rotate(360deg);}}
                  h1{font-size:1.15rem;color:#131b2e;margin:0 0 8px;}
                  p{font-size:.9rem;color:#525f75;line-height:1.5;margin:0 0 20px;}
                  a.btn{display:inline-flex;align-items:center;gap:6px;background:#1E3E62;color:#fff;text-decoration:none;padding:12px 24px;border-radius:12px;font-weight:600;font-size:.9rem;}
                  a.btn:hover{opacity:.9;}
                  .hint{margin-top:18px;font-size:.78rem;color:#737686;}
                  @media (prefers-reduced-motion:reduce){.spinner{animation:none;}}
                </style>
                </head>
                <body>
                <div class="card">
                  <div class="spinner" aria-hidden="true"></div>
                  <h1>Redirection vers Neemii Pay…</h1>
                  <p>Vous allez être redirigé·e vers Neemii Pay pour finaliser votre paiement Mobile Money (Orange Money, MTN, Moov, Wave…).</p>
                  <a class="btn" href="<?php echo htmlspecialchars($url); ?>">Continuer vers Neemii Pay <span aria-hidden="true">→</span></a>
                  <p class="hint">Si la page Neemii Pay met du temps à s'afficher ou arrive vide (sans montant ni bouton « Payer »), actualisez-la une fois (F5, ou glissez vers le bas sur mobile) : c'est un petit délai de chargement connu de leur côté, votre paiement n'est pas perdu.</p>
                </div>
                <script>setTimeout(function () { window.location.href = <?php echo json_encode($url); ?>; }, 1200);</script>
                </body>
                </html>
                <?php
                exit;
            }

            // Virement bancaire : suivi manuel, le dossier reste "en attente" jusqu'à réception.
            update_dossier_step((int) $dossier['id'], 8);
            log_activite((int) $dossier['id'], 'Virement bancaire choisi — en attente de réception');
            header('Location: ' . step_url(8, 'classic') . '?paiement=' . $paiementId . '&methode=virement');
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

$stmt = db()->prepare('SELECT COUNT(*) FROM documents WHERE dossier_id = ?');
$stmt->execute([$dossier['id']]);
$nbDocuments = (int) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$rdv = $stmt->fetch();
$formatLabels = ['ligne' => 'En ligne (visioconférence)', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir avec NexRaiz'];

$contenus = [
    'Optimisation du CV',
    'Lettre de motivation',
    'Profil LinkedIn',
    'Préparation entretien',
    'Accès à votre tableau de bord',
];

$apres = [
    ['icone' => 'receipt_long', 'titre' => 'Reçu de paiement', 'texte' => 'Votre facture officielle et le reçu de transaction.'],
    ['icone' => 'event_available', 'titre' => 'Confirmation de rendez-vous', 'texte' => "Détails du rendez-vous avec lien d'accès (si visio)."],
    ['icone' => 'summarize', 'titre' => 'Récapitulatif de la demande', 'texte' => 'Résumé complet de votre demande et des services inclus.'],
    ['icone' => 'space_dashboard', 'titre' => 'Accès au tableau de bord', 'texte' => 'Accès immédiat à votre espace personnel NexRaiz.'],
];

render_head('Pack Classic — Paiement');
render_top_header();
?>
<style>
main{overflow-x:hidden;}
.reveal-up,.reveal-scale,.reveal-left,.reveal-right{opacity:0;transition:opacity .7s cubic-bezier(.22,.61,.36,1),transform .7s cubic-bezier(.22,.61,.36,1);will-change:opacity,transform;}
.reveal-up{transform:translateY(24px);}
.reveal-scale{transform:scale(.94);}
.reveal-left{transform:translateX(-24px);}
.reveal-right{transform:translateX(24px);}
.reveal-up.is-visible,.reveal-scale.is-visible,.reveal-left.is-visible,.reveal-right.is-visible{opacity:1;transform:translate(0,0) scale(1);}
@media (prefers-reduced-motion:reduce){.reveal-up,.reveal-scale,.reveal-left,.reveal-right{transition:none;opacity:1;transform:none;}}
@keyframes pay-pulse{0%,100%{box-shadow:0 0 0 0 rgba(240,168,62,.35);}50%{box-shadow:0 0 0 7px rgba(240,168,62,0);}}
.pay-pulse{animation:pay-pulse 2.2s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.pay-pulse{animation:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
$totalEtapesClassic = count(pack_steps('classic'));
?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-[18px]">home</span>
      </a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="<?php echo htmlspecialchars(step_url(1, 'classic')); ?>" class="hover:text-primary transition-colors">Pack Classic</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Parcours client</span>
    </nav>

    <div class="reveal-up is-visible grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-center">
      <div class="flex flex-col gap-space-xs">
        <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-primary-container text-white font-label-md text-label-md font-bold">
          Étape 7 sur <?php echo $totalEtapesClassic; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Paiement</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Finalisez votre Pack Classic et confirmez votre rendez-vous</p>
      </div>

      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
        <img src="img_classic/payement.png" alt="Candidate confirmant son paiement NexRaiz depuis son téléphone" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</section>
<?php render_stepper('classic', 7); ?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1fr_1.4fr] gap-space-lg items-start">

    <div class="reveal-left bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-md">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
        <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">receipt_long</span></span>
        Récapitulatif de la commande
      </h2>
      <div class="flex items-center justify-between pb-space-sm border-b border-outline-variant/60">
        <p class="font-body-sm text-[12px] text-secondary">Produit</p>
        <span class="px-space-sm py-0.5 rounded-full bg-primary-container/10 text-primary font-label-md text-label-md font-bold">Pack Classic</span>
      </div>

      <div class="flex flex-col gap-space-2xs pb-space-sm border-b border-outline-variant/60">
        <p class="font-label-md text-label-md text-secondary uppercase tracking-wider font-bold">Contenus inclus</p>
        <?php foreach ($contenus as $c): ?>
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary">check_circle</span>
          <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($c); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="flex items-center justify-between pb-space-sm border-b border-outline-variant/60">
        <p class="font-body-sm text-[12px] text-secondary">Documents transmis</p>
        <a href="<?php echo step_url(4, 'classic'); ?>" class="font-label-md text-label-md text-primary flex items-center gap-1"><?php echo $nbDocuments; ?> fichier<?php echo $nbDocuments > 1 ? 's' : ''; ?> <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>
      <div class="flex items-start gap-space-xs pb-space-sm border-b border-outline-variant/60">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">calendar_today</span>
        <div><p class="font-body-sm text-[12px] text-secondary">Date et heure du rendez-vous</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p></div>
      </div>
      <div class="flex items-start gap-space-xs pb-space-sm border-b border-outline-variant/60">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">videocam</span>
        <div><p class="font-body-sm text-[12px] text-secondary">Format choisi</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($formatLabels[$rdv['format'] ?? 'ligne'] ?? 'Non défini'); ?></p></div>
      </div>

      <div class="flex items-center justify-between rounded-xl bg-gradient-to-br from-[#0B192C] to-[#1E3E62] p-space-md">
        <span class="font-label-lg text-label-lg text-white font-bold">Total à payer</span>
        <span class="font-headline-md text-headline-md text-[#F0A83E] font-bold"><?php echo number_format($montant, 0, ',', ' '); ?> FCFA</span>
      </div>

      <div class="flex items-start gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
        <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">settings</span></span>
        <div><p class="font-label-md text-label-md text-on-surface font-semibold">Satisfaction garantie</p><p class="font-body-sm text-[12px] text-secondary">Un accompagnement de qualité ou nous refaisons le travail sans frais.</p></div>
      </div>
    </div>

    <div class="reveal-right flex flex-col gap-space-md" style="transition-delay:80ms">
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-md">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
          <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#1E3E62] to-[#0B192C] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">credit_card</span></span>
          Choisissez votre moyen de paiement
        </h2>
        <?php if ($erreurs): ?>
        <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
          <?php foreach ($erreurs as $e): ?>
          <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" id="paymentForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="methode" id="methodeInput" value="stripe">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm" id="paymentMethodGroup">
          <button type="button" data-value="stripe" class="payment-method selected relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-primary-container bg-primary-container/5 transition-all duration-200 hover:-translate-y-0.5">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center check-badge"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <div class="w-11 h-11 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">credit_card</span></div>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Carte bancaire</span>
            <span class="font-body-sm text-[11px] text-secondary">via Stripe</span>
          </button>
          <button type="button" data-value="neemii" class="payment-method relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container hover:-translate-y-0.5 transition-all duration-200">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <div class="w-11 h-11 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">smartphone</span></div>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mobile Money</span>
            <span class="font-body-sm text-[11px] text-secondary">via Neemii Pay</span>
          </button>
          <button type="button" data-value="virement" class="payment-method relative flex flex-col items-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container hover:-translate-y-0.5 transition-all duration-200">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <div class="w-11 h-11 rounded-full bg-gradient-to-br from-[#1E3E62] to-[#0B192C] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">account_balance</span></div>
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Virement bancaire</span>
          </button>
        </div>

        <p id="noteStripe" class="payment-note font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs mt-space-md"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vous serez redirigé vers une page de paiement sécurisée Stripe pour saisir votre carte. Aucune donnée bancaire ne transite par NexRaiz.</p>
        <p id="noteNeemii" class="payment-note hidden font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs mt-space-md"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vous serez redirigé vers Neemii Pay pour finaliser le paiement par Mobile Money (Orange Money, MTN, Moov, Wave...).</p>
        <p id="noteVirement" class="payment-note hidden font-body-sm text-body-sm text-secondary bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs mt-space-md"><span class="material-symbols-outlined text-[18px] text-primary">info</span>Les coordonnées bancaires NexRaiz vous seront envoyées par email. Votre dossier avance dès réception du virement, sous 1 à 2 jours ouvrés.</p>

        <p class="flex items-center gap-space-2xs font-body-sm text-[12px] text-secondary bg-surface-container-low rounded-xl p-space-sm mt-space-sm"><span class="material-symbols-outlined text-[18px] text-primary">lock</span>Vos données sont chiffrées et sécurisées. Nous ne stockons jamais les informations de votre carte bancaire.</p>
        </form>
      </div>

      <div class="reveal-up bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs" style="transition-delay:140ms">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Après votre paiement, vous recevrez :</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm">
          <?php
          $degradesApres = ['from-[#2563EB] to-[#1E3E62]', 'from-[#F0A83E] to-[#824500]', 'from-[#1E3E62] to-[#0B192C]', 'from-[#8FB2FF] to-[#2563EB]'];
          foreach ($apres as $i => $a):
          ?>
          <div class="flex flex-col items-center text-center gap-space-2xs">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br <?php echo $degradesApres[$i % count($degradesApres)]; ?> text-white flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo $a['icone']; ?></span></div>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($a['texte']); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="reveal-scale flex flex-col sm:flex-row gap-space-sm" style="transition-delay:200ms">
        <button type="submit" form="paymentForm" class="pay-pulse flex-1 h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
          <span>Payer maintenant</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </div>
      <div class="flex flex-col sm:flex-row sm:justify-between gap-space-2xs font-label-md text-[11px] text-secondary">
        <span>Paiement sécurisé, rapide et sans engagement</span>
        <span>Vérifier les détails avant de payer</span>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm pt-space-xs">
        <div class="flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px] text-primary">lock</span><span class="font-label-md text-[11px] text-secondary">Paiement 100% sécurisé</span></div>
        <div class="flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px] text-primary">verified_user</span><span class="font-label-md text-[11px] text-secondary">Données protégées</span></div>
        <div class="flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px] text-primary">headset_mic</span><span class="font-label-md text-[11px] text-secondary">Support Lun-Ven, 8h-18h (GMT+1)</span></div>
        <div class="flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px] text-primary">settings</span><span class="font-label-md text-[11px] text-secondary">Garantie satisfaction</span></div>
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

  // --- Animations au scroll ---
  var cibles = document.querySelectorAll('.reveal-up, .reveal-scale, .reveal-left, .reveal-right');
  if ('IntersectionObserver' in window) {
    var observateur = new IntersectionObserver(function (entrees) {
      entrees.forEach(function (entree) {
        if (entree.isIntersecting) {
          entree.target.classList.add('is-visible');
          observateur.unobserve(entree.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    cibles.forEach(function (el) { observateur.observe(el); });
  } else {
    cibles.forEach(function (el) { el.classList.add('is-visible'); });
  }
})();
</script>

<?php render_footer(); ?>
