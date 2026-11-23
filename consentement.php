<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('assistance', 'espace-client.php');

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!isset($_POST['consent'])) {
        $erreurs[] = "Merci d'approuver la charte de consentement pour continuer.";
    } else {
        $stmt = db()->prepare(
            'INSERT INTO consentements (dossier_id, accepte, accepte_le) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE accepte = 1, accepte_le = NOW()'
        );
        $stmt->execute([$dossier['id']]);
        update_dossier_step((int) $dossier['id'], 6);
        log_activite((int) $dossier['id'], 'Charte de consentement approuvée');
        header('Location: ' . step_url(6, 'assistance'));
        exit;
    }
}

$blocs = [
    ['icone' => 'lock', 'titre' => 'Confidentialité des informations', 'texte' => 'Toutes les informations que vous partagez avec NexRaiz sont traitées avec la plus grande confidentialité. Elles sont accessibles uniquement aux équipes en charge de votre accompagnement et ne sont jamais divulguées à des tiers sans votre accord.'],
    ['icone' => 'balance', 'titre' => 'Limites de mission', 'texte' => "Le Pack Assistance a une vocation d'information, d'orientation et de soutien dans la structuration de votre démarche. NexRaiz n'est pas un avocat, ne fournit pas de conseil juridique et ne garantit pas de résultat légal ou administratif."],
    ['icone' => 'person', 'titre' => 'Orientation vers un professionnel', 'texte' => 'Selon la nature et la complexité de votre situation, nous pourrons vous recommander de consulter un professionnel du droit (avocat, juriste, notaire, expert-comptable, etc.). Cette orientation vise à vous apporter un accompagnement adapté et sécurisé.'],
];

$blocs2 = [
    ['icone' => 'event_busy', 'titre' => 'Report et annulation', 'texte' => "Vous pouvez reporter ou annuler un rendez-vous jusqu'à 24h ouvrées avant l'échéance. En cas d'annulation tardive ou d'absence, NexRaiz se réserve le droit de facturer la prestation planifiée.", 'lien' => 'Voir les conditions complètes dans la charte de consentement'],
    ['icone' => 'shield', 'titre' => 'Protection des données', 'texte' => 'Vos données personnelles sont collectées et traitées conformément au Règlement Général sur la Protection des Données (RGPD). Vous disposez d\'un droit d\'accès, de rectification, d\'effacement, de limitation et d\'opposition sur vos données.', 'lien' => 'En savoir plus sur notre politique de confidentialité'],
];

render_head('Pack Assistance — Consentement et confidentialité');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'assistance',
    5,
    'Consentement et confidentialité',
    "Validez le cadre d'accompagnement avant de poursuivre",
    'gpp_good',
    ['person', 'description', 'check_circle']
);
render_stepper('assistance', 5);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-space-sm">
      <?php foreach ($blocs as $i => $b): ?>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $b['icone']; ?></span></div>
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo ($i + 1) . '. ' . htmlspecialchars($b['titre']); ?></h3>
        <p class="font-body-sm text-body-sm text-secondary leading-relaxed"><?php echo htmlspecialchars($b['texte']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
      <?php foreach ($blocs2 as $i => $b): ?>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $b['icone']; ?></span></div>
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo ($i + 4) . '. ' . htmlspecialchars($b['titre']); ?></h3>
        <p class="font-body-sm text-body-sm text-secondary leading-relaxed"><?php echo htmlspecialchars($b['texte']); ?></p>
        <a href="#" class="font-body-sm text-body-sm text-primary underline w-fit"><?php echo htmlspecialchars($b['lien']); ?></a>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
      <div class="flex items-center gap-space-sm">
        <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">description</span></div>
        <div>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Charte de consentement</p>
          <p class="font-body-sm text-body-sm text-secondary">Consultez l'intégralité des règles de confidentialité, des limites de mission et des conditions d'utilisation du Pack Assistance.</p>
        </div>
      </div>
      <a href="#" class="shrink-0 h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
        <span class="material-symbols-outlined text-[18px]">download</span>
        <span>Télécharger la charte (PDF)<br class="hidden sm:block"><span class="font-body-sm text-[11px] text-secondary font-normal">PDF · 214 Ko</span></span>
      </a>
    </div>

    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col sm:flex-row sm:items-center gap-space-md">
      <?php echo csrf_field(); ?>
      <div class="w-14 h-14 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[28px]">verified_user</span></div>
      <label class="flex items-start gap-space-xs flex-1">
        <input type="checkbox" name="consent" id="consentCheckbox" class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'ai lu et j'approuve la charte de consentement, les règles de confidentialité et les limites de mission du Pack Assistance.</span>
      </label>
      <div class="flex flex-col sm:flex-row gap-space-xs shrink-0">
        <button type="submit" id="continueBtn" class="pointer-events-none opacity-50 h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm transition-colors">
          <span>Continuer vers le paiement</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <a href="#" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">download</span>
          <span>Télécharger la charte</span>
        </a>
      </div>
    </form>
    <div class="flex items-center justify-center gap-space-2xs">
      <span class="material-symbols-outlined text-[16px] text-primary">lock</span>
      <span class="font-label-md text-[11px] text-secondary">Paiement 100 % sécurisé</span>
    </div>
  </div>
</section>

<script>
(function() {
  var checkbox = document.getElementById('consentCheckbox');
  var continueBtn = document.getElementById('continueBtn');
  if (checkbox && continueBtn) {
    checkbox.addEventListener('change', function() {
      continueBtn.classList.toggle('pointer-events-none', !checkbox.checked);
      continueBtn.classList.toggle('opacity-50', !checkbox.checked);
    });
  }
})();
</script>

<?php render_footer(); ?>
