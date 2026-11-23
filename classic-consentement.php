<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['consent'] ?? '') !== '1') {
        $erreurs[] = 'Merci de valider les conditions de confidentialité pour continuer.';
    } else {
        $stmt = db()->prepare(
            'INSERT INTO consentements (dossier_id, accepte, accepte_le) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE accepte = 1, accepte_le = NOW()'
        );
        $stmt->execute([$dossier['id']]);

        update_dossier_step((int) $dossier['id'], 7);
        log_activite((int) $dossier['id'], 'Charte de consentement approuvée');

        header('Location: ' . step_url(7, 'classic'));
        exit;
    }
}

$sections = [
    ['icone' => 'shield', 'titre' => 'Confidentialité des documents transmis', 'texte' => 'Tous les documents que vous nous transmettez sont traités de manière strictement confidentielle. Ils sont accessibles uniquement aux consultants NexRaiz directement impliqués dans votre accompagnement.', 'degrade' => 'from-[#2563EB] to-[#1E3E62]'],
    ['icone' => 'person', 'titre' => "Utilisation des informations dans le cadre de l'accompagnement", 'texte' => 'Les informations collectées sont utilisées exclusivement pour la réalisation des prestations du Pack Classic : analyse, conseils, recommandations et préparation à vos candidatures.', 'degrade' => 'from-[#F0A83E] to-[#824500]'],
    ['icone' => 'block', 'titre' => "Absence de garantie d'embauche", 'texte' => "Le Pack Classic a pour objectif d'optimiser votre candidature et de renforcer votre impact auprès des recruteurs. NexRaiz ne garantit en aucun cas l'obtention d'un entretien ou d'une embauche.", 'degrade' => 'from-[#1E3E62] to-[#0B192C]'],
    ['icone' => 'person', 'titre' => 'Engagement du client à fournir des informations exactes', 'texte' => 'Vous vous engagez à fournir des informations exactes, complètes et à jour. Vous êtes responsable des contenus transmis et de leur véracité.', 'degrade' => 'from-[#8FB2FF] to-[#2563EB]'],
    ['icone' => 'event', 'titre' => "Conditions de report ou d'annulation", 'texte' => "Les rendez-vous peuvent être reportés jusqu'à 24h à l'avance. Toute annulation effectuée moins de 24h avant le rendez-vous pourra entraîner la perte de la séance.", 'degrade' => 'from-[#824500] to-[#0B192C]'],
];

$pourquoi = [
    ['icone' => 'lock', 'titre' => 'Protéger vos données', 'texte' => 'Nous nous engageons à protéger vos informations personnelles avec le plus haut niveau de sécurité.', 'degrade' => 'from-[#2563EB] to-[#1E3E62]'],
    ['icone' => 'person', 'titre' => 'Clarifier le cadre de la mission', 'texte' => 'Cette validation définit le périmètre exact de notre accompagnement et évite toute ambiguïté.', 'degrade' => 'from-[#F0A83E] to-[#824500]'],
    ['icone' => 'handshake', 'titre' => 'Instaurer une relation de confiance', 'texte' => 'Une collaboration saine repose sur la transparence et l\'engagement mutuel.', 'degrade' => 'from-[#1E3E62] to-[#0B192C]'],
    ['icone' => 'description', 'titre' => 'Respecter nos engagements légaux', 'texte' => 'Cette validation nous permet de respecter nos obligations en matière de conformité et de protection des données.', 'degrade' => 'from-[#8FB2FF] to-[#2563EB]'],
];

render_head('Pack Classic — Consentement et confidentialité');
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
          Étape 6 sur <?php echo $totalEtapesClassic; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Consentement et confidentialité</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Validez le cadre du Pack Classic avant de finaliser votre commande</p>
      </div>

      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
        <img src="img_classic/consentement.png" alt="Candidate et coach NexRaiz scellant leur accord" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</section>
<?php render_stepper('classic', 6); ?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-lg items-start">

    <form method="post" class="reveal-left flex flex-col gap-space-md">
      <?php echo csrf_field(); ?>
      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="flex flex-col gap-space-sm" id="accordionGroup">
        <?php foreach ($sections as $i => $s): ?>
        <div class="reveal-up accordion-item bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow duration-300" style="transition-delay:<?php echo $i * 70; ?>ms">
          <button type="button" class="accordion-trigger w-full flex items-center gap-space-sm p-space-md text-left">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br <?php echo $s['degrade']; ?> text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]"><?php echo $s['icone']; ?></span></div>
            <span class="flex-1 font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo ($i + 1) . '. ' . htmlspecialchars($s['titre']); ?></span>
            <span class="material-symbols-outlined text-[20px] text-secondary accordion-chevron transition-transform">expand_more</span>
          </button>
          <div class="accordion-panel px-space-md pb-space-md pl-space-md" style="margin-left:3.25rem">
            <p class="font-body-sm text-body-sm text-secondary leading-relaxed"><?php echo htmlspecialchars($s['texte']); ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="reveal-scale bg-gradient-to-br from-[#0B192C] to-[#1E3E62] rounded-xl p-space-lg shadow-sm flex items-start gap-space-sm">
        <div class="w-10 h-10 rounded-full bg-white/10 text-[#F0A83E] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">gpp_good</span></div>
        <label class="flex items-start gap-space-xs flex-1">
          <input type="checkbox" id="consentCheckbox" class="mt-1 w-4 h-4 accent-[#F0A83E] rounded">
          <span class="font-body-sm text-body-sm text-white">J'ai lu et j'approuve les conditions de confidentialité, les limites de mission et le cadre du Pack Classic.</span>
        </label>
        <input type="hidden" name="consent" id="consentInput" value="0">
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
        <a href="<?php echo step_url(5, 'classic'); ?>" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">arrow_back</span>
          <span>Retour</span>
        </a>
        <button type="submit" id="continueBtn" disabled class="pointer-events-none opacity-50 h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
          <span>J'accepte et je continue</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </div>
      <p class="flex items-center justify-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">lock</span>Rapide, sécurisé et sans engagement</p>
    </form>

    <div class="reveal-right flex flex-col gap-space-md lg:sticky lg:top-24">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
          <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">verified_user</span></span>
          Pourquoi cette validation est importante
        </h3>
        <div class="flex flex-col gap-space-sm">
          <?php foreach ($pourquoi as $p): ?>
          <div class="flex items-start gap-space-xs p-space-2xs rounded-xl hover:bg-surface-container-low transition-colors duration-200">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br <?php echo $p['degrade']; ?> text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $p['icone']; ?></span></div>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($p['titre']); ?></p><p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($p['texte']); ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
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
