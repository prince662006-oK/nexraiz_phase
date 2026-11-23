<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('wellbeing', 'wellbeing-espace-client.php');

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['consent'] ?? '') !== '1') {
        $erreurs[] = 'Merci de valider la charte de consentement pour continuer.';
    } else {
        $stmt = db()->prepare(
            'INSERT INTO consentements (dossier_id, accepte, accepte_le) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE accepte = 1, accepte_le = NOW()'
        );
        $stmt->execute([$dossier['id']]);

        update_dossier_step((int) $dossier['id'], 6);
        log_activite((int) $dossier['id'], 'Charte de consentement approuvée');

        header('Location: ' . step_url(6, 'wellbeing'));
        exit;
    }
}

$sections = [
    ['icone' => 'lock', 'titre' => 'Confidentialité des informations', 'texte' => 'Toutes les informations que vous partagez avec NexRaiz sont strictement confidentielles. Elles sont utilisées uniquement dans le cadre de votre accompagnement Wellbeing et ne sont jamais partagées avec des tiers sans votre accord, sauf obligation légale.'],
    ['icone' => 'warning', 'titre' => 'Limites de mission', 'texte' => 'NexRaiz propose un accompagnement en coaching wellbeing axé sur le bien-être global, la gestion du stress, l\'équilibre vie pro / vie perso et le développement personnel. Cet accompagnement ne se substitue en aucun cas à un avis, un diagnostic ou un traitement médical ou psychologique.'],
    ['icone' => 'person', 'titre' => 'Cadre du coaching Wellbeing', 'texte' => "Le coaching Wellbeing est un espace d'écoute, de soutien et de co-construction d'actions concrètes pour améliorer votre bien-être et vos ressources. Les séances ne constituent ni une thérapie, ni un acte médical, ni une intervention clinique."],
    ['icone' => 'signpost', 'titre' => 'Orientation vers un professionnel qualifié si nécessaire', 'texte' => "Si au cours de l'accompagnement il apparaît que votre situation dépasse le cadre du coaching Wellbeing, NexRaiz se réserve le droit de vous recommander de vous orienter vers un médecin, un psychologue, un psychiatre ou un autre professionnel de santé qualifié."],
    ['icone' => 'event_busy', 'titre' => "Conditions de report / annulation", 'texte' => "Les conditions de report et d'annulation sont précisées dans nos Conditions générales d'utilisation et vous seront rappelées lors de la planification des séances et sur votre espace client."],
];

render_head('Pack Wellbeing — Consentement et cadre');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'wellbeing',
    5,
    'Consentement, confidentialité et cadre d\'accompagnement',
    'Avant de poursuivre votre parcours, veuillez lire attentivement les informations ci-dessous et valider votre accord. Elles définissent le cadre de notre accompagnement.',
    'gpp_good',
    []
);
render_stepper('wellbeing', 5);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="flex flex-col gap-space-md">
    <?php echo csrf_field(); ?>
    <div class="flex flex-col gap-space-sm" id="accordionGroup">
      <?php foreach ($sections as $s): ?>
      <div class="accordion-item bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden">
        <button type="button" class="accordion-trigger w-full flex items-center gap-space-sm p-space-md text-left">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]"><?php echo $s['icone']; ?></span></div>
          <span class="flex-1 font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo htmlspecialchars($s['titre']); ?></span>
          <span class="material-symbols-outlined text-[20px] text-secondary accordion-chevron transition-transform">expand_more</span>
        </button>
        <div class="accordion-panel px-space-md pb-space-md" style="margin-left:3.25rem">
          <p class="font-body-sm text-body-sm text-secondary leading-relaxed"><?php echo htmlspecialchars($s['texte']); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-primary-container/5 rounded-xl p-space-lg flex items-start gap-space-sm">
      <input type="checkbox" id="consentCheckbox" class="mt-1 w-4 h-4 accent-primary-container rounded shrink-0">
      <label for="consentCheckbox" class="font-body-sm text-body-sm text-on-surface">J'ai lu et j'approuve la charte de consentement, les règles de confidentialité et les limites de mission du Pack Wellbeing.</label>
      <input type="hidden" name="consent" id="consentInput" value="0">
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
      <a href="<?php echo step_url(4, 'wellbeing'); ?>" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Précédent</span>
      </a>
      <button type="submit" id="continueBtn" disabled class="pointer-events-none opacity-50 h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm transition-colors">
        <span>Continuer vers le paiement</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </button>
    </div>
    </form>
  </div>
</section>

<script>
(function() {
  document.querySelectorAll('.accordion-trigger').forEach(function(trigger) {
    var panel = trigger.nextElementSibling;
    var chevron = trigger.querySelector('.accordion-chevron');
    panel.style.display = 'block';
    trigger.addEventListener('click', function() {
      var isOpen = panel.style.display !== 'none';
      panel.style.display = isOpen ? 'none' : 'block';
      chevron.style.transform = isOpen ? 'rotate(-90deg)' : 'rotate(0deg)';
    });
  });

  var checkbox = document.getElementById('consentCheckbox');
  var continueBtn = document.getElementById('continueBtn');
  var consentInput = document.getElementById('consentInput');
  if (checkbox && continueBtn) {
    checkbox.addEventListener('change', function() {
      continueBtn.classList.toggle('pointer-events-none', !checkbox.checked);
      continueBtn.classList.toggle('opacity-50', !checkbox.checked);
      continueBtn.disabled = !checkbox.checked;
      if (consentInput) consentInput.value = checkbox.checked ? '1' : '0';
    });
  }
})();
</script>

<?php render_footer(); ?>
