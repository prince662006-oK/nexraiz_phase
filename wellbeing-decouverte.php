<?php
require __DIR__ . '/includes/bootstrap.php';

$pourquoi = [
    ['icone' => 'monitor_heart', 'titre' => 'Réduction du stress', 'texte' => 'Apprenez à gérer le stress au quotidien.'],
    ['icone' => 'psychology', 'titre' => 'Sérénité mentale', 'texte' => 'Accédez à des outils pour apaiser votre esprit.'],
    ['icone' => 'balance', 'titre' => 'Équilibre de vie', 'texte' => "Retrouvez l'harmonie entre travail, famille et loisirs."],
    ['icone' => 'directions_run', 'titre' => 'Meilleure santé', 'texte' => 'Prenez soin de votre corps et de votre mental.'],
];

$espaceChecklist = [
    'Programmes bien-être personnalisés',
    'Exercices de relaxation et méditation',
    'Suivi de votre humeur et de votre progrès',
];

$avantagesChecklist = [
    "Conseils d'experts en bien-être",
    'Programmes sur mesure',
    'Accès à des ressources exclusives',
    'Accompagnement bienveillant',
];

$temoignages = [
    ['photo' => 'img_well/temoin-2.png', 'nom' => 'Aminata Koné', 'texte' => "Grâce au Pack Wellbeing, j'ai appris à gérer mon stress et à mieux profiter de ma vie."],
    ['photo' => 'img_well/temoin-1.png', 'nom' => 'Koffi Yao', 'texte' => 'Les conseils sont pratiques et vraiment efficaces. Je me sens plus serein au travail.'],
    ['photo' => 'img_well/temoin-3.png', 'nom' => 'Diarra Fatou', 'texte' => "Une expérience incroyable pour retrouver l'équilibre."],
];

render_head('Pack Wellbeing — Découverte', "Le Pack Wellbeing NexRaiz vous accompagne pour réduire le stress, améliorer votre bien-être et retrouver une vie plus sereine, au travail comme dans la vie.");
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
@keyframes well-float{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
.well-float{animation:well-float 4.5s ease-in-out infinite;}
@keyframes well-pulse{0%,100%{box-shadow:0 0 0 0 rgba(37,99,235,.35);}50%{box-shadow:0 0 0 7px rgba(37,99,235,0);}}
.well-pulse{animation:well-pulse 2.4s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.well-float,.well-pulse{animation:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<!-- Hero -->
<section class="relative w-full overflow-hidden">
  <div class="hidden lg:block absolute inset-y-0 right-0 w-[48%] xl:w-[50%] z-0">
    <img src="img_well/hero.png" alt="Candidate NexRaiz prenant un moment de bien-être" class="w-full h-full object-cover">
    <div class="absolute inset-y-0 left-0 w-32 xl:w-48 bg-gradient-to-r from-surface to-transparent"></div>

    <span class="absolute top-10 right-10 xl:right-16 -rotate-6 italic font-serif text-[22px] text-on-surface font-semibold text-right leading-snug drop-shadow-sm">
      Bien dans<br>votre esprit,<br>plus performant !
    </span>

    <div class="absolute -bottom-10 -right-8 w-52 h-52 rounded-[42%_58%_61%_39%/48%_42%_58%_52%] bg-gradient-to-br from-[#2563EB] to-[#0B192C] rotate-12 opacity-95"></div>
    <div class="well-float absolute bottom-10 right-10 bg-tertiary-fixed text-tertiary rounded-2xl shadow-lg p-space-sm flex items-center gap-space-xs">
      <span class="w-10 h-10 rounded-full bg-white/70 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px] text-tertiary">spa</span></span>
      <span class="font-label-md text-[13px] font-bold leading-tight">Équilibre<br>au quotidien</span>
    </div>
  </div>

  <div class="relative z-10 max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop pt-space-xl pb-space-2xl flex flex-col gap-space-xl">

    <div class="grid grid-cols-1 lg:grid-cols-[1.15fr_0.85fr] gap-space-2xl items-center">
      <div class="reveal-left is-visible flex flex-col gap-space-lg lg:py-space-3xl">
        <span class="inline-flex self-start items-center gap-space-2xs px-space-sm py-1.5 rounded-full bg-tertiary-fixed text-tertiary font-label-md text-label-md font-bold">
          <span class="material-symbols-outlined text-[18px]">spa</span>
          Pack Wellbeing
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-on-surface font-bold tracking-tight">
          Prenez soin de vous, trouvez
          <span class="text-primary">votre équilibre</span> !
        </h1>
        <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-secondary max-w-max-width-reading">
          Le Pack Wellbeing vous accompagne pour réduire le stress, améliorer votre bien-être et retrouver une vie plus sereine, au travail comme dans la vie.
        </p>
        <ul class="flex flex-col gap-space-sm pt-space-2xs">
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[20px] text-primary">check_circle</span><span class="font-body-md text-body-md text-on-surface">Gestion du stress et des émotions</span></li>
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[20px] text-primary">check_circle</span><span class="font-body-md text-body-md text-on-surface">Conseils bien-être personnalisés</span></li>
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[20px] text-primary">check_circle</span><span class="font-body-md text-body-md text-on-surface">Équilibre vie pro / vie perso</span></li>
        </ul>
        <div class="flex flex-col sm:flex-row gap-space-sm pt-space-2xs">
          <a href="<?php echo htmlspecialchars(step_url(2, 'wellbeing')); ?>" class="h-12 px-space-lg rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
            <span>Découvrir le Pack Wellbeing</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
          </a>
          <a href="#contenu" class="h-12 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
            <span class="material-symbols-outlined text-[18px] text-primary">play_circle</span>
            Voir la présentation
          </a>
        </div>
      </div>

      <div class="reveal-right is-visible relative mx-auto max-w-sm lg:hidden">
        <div class="relative rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
          <img src="img_well/hero.png" alt="Candidate NexRaiz prenant un moment de bien-être" class="w-full h-64 sm:h-72 object-cover">
        </div>
        <div class="well-float absolute -bottom-6 -right-4 sm:-right-8 bg-tertiary-fixed text-tertiary rounded-2xl shadow-lg p-space-sm flex items-center gap-space-xs">
          <span class="w-10 h-10 rounded-full bg-white/70 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px] text-tertiary">spa</span></span>
          <span class="font-label-md text-[13px] font-bold leading-tight">Équilibre<br>au quotidien</span>
        </div>
      </div>
    </div>
  </div>
</section>

<?php render_stepper('wellbeing', 1); ?>

<!-- Avant-goût gratuit : test de stress -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto">
    <div class="reveal-up bg-gradient-to-r from-[#2F6F5E] to-[#163830] rounded-2xl p-space-lg md:p-space-xl flex flex-col md:flex-row md:items-center gap-space-md md:gap-space-lg">
      <span class="w-14 h-14 rounded-full bg-white/15 backdrop-blur-md flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[28px] text-white">quiz</span></span>
      <div class="flex-1 flex flex-col gap-space-2xs">
        <span class="inline-flex self-start items-center gap-space-2xs px-space-sm py-1 rounded-full bg-white/15 text-white font-label-md text-[11px] font-bold uppercase tracking-wider">Avant de commencer · Gratuit · Sans inscription</span>
        <h2 class="font-headline-sm text-headline-sm text-white font-bold">Testez votre niveau de stress en 2 minutes</h2>
        <p class="font-body-sm text-body-sm text-white/75">Un court auto-diagnostic pour faire le point avant de découvrir votre accompagnement.</p>
      </div>
      <a href="wellbeing-quiz-stress.php" class="well-pulse shrink-0 h-12 px-space-lg rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
        <span>Faire le test</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>
  </div>
</section>

<!-- Pourquoi choisir le Pack Wellbeing -->
<section id="contenu" class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[0.34fr_1fr] gap-space-2xl items-center">
    <div class="reveal-left relative mx-auto max-w-[14rem] lg:max-w-none">
      <div class="absolute -left-6 -top-6 w-36 h-36 rounded-full border-[14px] border-tertiary-fixed -z-10"></div>
      <img src="img_well/meditation.png" alt="Candidat pratiquant la pleine conscience" class="w-full h-auto rounded-full drop-shadow-xl">
    </div>

    <div class="reveal-up flex flex-col gap-space-lg">
      <div class="flex flex-col gap-space-2xs">
        <span class="w-10 h-1 rounded-full bg-primary"></span>
        <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Pourquoi choisir le Pack Wellbeing ?</h2>
      </div>
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-md">
        <?php foreach ($pourquoi as $i => $p): ?>
        <div class="reveal-up group flex flex-col gap-space-sm bg-[#EEF1FC] rounded-2xl p-space-lg hover:shadow-lg hover:-translate-y-1.5 transition-all duration-300" style="transition-delay:<?php echo $i * 70; ?>ms">
          <span class="material-symbols-outlined text-[32px] text-primary group-hover:scale-110 transition-transform duration-300"><?php echo $p['icone']; ?></span>
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($p['titre']); ?></h3>
          <p class="font-body-sm text-[12px] text-secondary leading-relaxed"><?php echo htmlspecialchars($p['texte']); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- Votre espace Wellbeing -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-2xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1fr_1.05fr_1fr] gap-space-lg items-stretch">

    <div class="reveal-left bg-gradient-to-br from-[#0B192C] to-[#1E3E62] rounded-2xl p-space-xl flex flex-col gap-space-md">
      <span class="inline-flex self-start items-center gap-space-2xs px-space-sm py-1.5 rounded-full bg-tertiary-fixed text-tertiary font-label-md text-label-md font-bold">
        <span class="material-symbols-outlined text-[18px]">dashboard</span>
        Votre espace Wellbeing
      </span>
      <h3 class="font-headline-sm text-headline-sm text-white font-bold">Un accompagnement complet et personnalisé</h3>
      <p class="font-body-sm text-body-sm text-white/70 leading-relaxed">
        Accédez à votre espace bien-être pour suivre vos progrès, consulter des conseils et profiter de nos ressources exclusives.
      </p>
      <ul class="flex flex-col gap-space-sm pt-space-2xs">
        <?php foreach ($espaceChecklist as $item): ?>
        <li class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-[#F0A83E] mt-0.5">check_circle</span>
          <span class="font-body-sm text-body-sm text-white"><?php echo htmlspecialchars($item); ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <a href="mon-espace.php" class="self-start h-12 px-space-lg rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 mt-space-2xs">
        <span>Accéder à mon espace</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>

    <div class="reveal-up relative mx-auto max-w-md lg:max-w-none flex items-center justify-center">
      <img src="img_well/dashboard.png" alt="Espace bien-être NexRaiz sur ordinateur portable" class="well-float w-full h-auto drop-shadow-2xl">
    </div>

    <div class="reveal-right bg-[#EEF1FC] rounded-2xl p-space-xl flex flex-col gap-space-md">
      <span class="inline-flex self-start items-center gap-space-2xs px-space-sm py-1.5 rounded-full bg-tertiary-fixed text-tertiary font-label-md text-label-md font-bold">
        <span class="material-symbols-outlined text-[18px]">star</span>
        Nos avantages
      </span>
      <ul class="flex flex-col gap-space-sm pt-space-2xs">
        <?php foreach ($avantagesChecklist as $item): ?>
        <li class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">check_circle</span>
          <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($item); ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="font-label-lg text-label-lg text-on-surface font-bold pt-space-2xs">Offrez-vous une vie plus sereine avec NexRaiz !</p>
      <a href="<?php echo htmlspecialchars(step_url(2, 'wellbeing')); ?>" class="self-start h-12 px-space-lg rounded-xl bg-[#0B192C] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 mt-space-2xs">
        <span>Choisir le Pack Wellbeing</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>
  </div>
</section>

<!-- Témoignages -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[0.8fr_2fr] gap-space-xl items-center">
    <div class="reveal-left flex flex-col gap-space-md">
      <span class="inline-flex self-start items-center gap-space-2xs px-space-sm py-1.5 rounded-full bg-tertiary-fixed text-tertiary font-label-md text-label-md font-bold">
        <span class="material-symbols-outlined text-[18px]">format_quote</span>
        Témoignages
      </span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Ils ont retrouvé leur équilibre grâce à NexRaiz</h2>
      <p class="font-body-md text-body-md text-secondary leading-relaxed">
        Découvrez les témoignages de nos utilisateurs qui ont transformé leur bien-être.
      </p>
      <a href="#" class="self-start h-12 px-space-lg rounded-xl bg-[#0B192C] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 mt-space-2xs">
        Lire leurs histoires
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-md">
      <?php foreach ($temoignages as $i => $t): ?>
      <div class="reveal-up bg-[#EEF1FC] rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300" style="transition-delay:<?php echo $i * 90; ?>ms">
        <img src="<?php echo htmlspecialchars($t['photo']); ?>" alt="<?php echo htmlspecialchars($t['nom']); ?>" class="w-14 h-14 rounded-full object-cover shrink-0">
        <p class="font-body-sm text-body-sm text-on-surface leading-relaxed">« <?php echo htmlspecialchars($t['texte']); ?> »</p>
        <div>
          <p class="font-label-lg text-label-lg text-on-surface font-bold leading-tight"><?php echo htmlspecialchars($t['nom']); ?></p>
          <span class="text-[#F0A83E] text-[13px]">★★★★★</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA finale -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="max-w-max-width-content mx-auto relative rounded-2xl overflow-hidden bg-gradient-to-r from-[#0B192C] to-[#1E3E62] min-h-[12rem]">
    <div class="hidden md:block absolute inset-y-0 right-0 w-[38%]">
      <img src="img_well/cta-finale.png" alt="Silhouette au lever du soleil, symbole de sérénité retrouvée" class="w-full h-full object-cover">
      <div class="absolute inset-y-0 left-0 w-24 bg-gradient-to-r from-[#0B192C] to-transparent"></div>
    </div>
    <div class="reveal-up relative z-10 flex flex-col sm:flex-row sm:items-center gap-space-md p-space-xl md:pr-[40%]">
      <span class="w-14 h-14 rounded-full bg-[#F0A83E] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[26px] text-[#0B192C]">spa</span></span>
      <div class="flex-1 flex flex-col gap-space-2xs">
        <h3 class="font-headline-sm text-headline-sm text-white font-bold">Rejoignez la communauté NexRaiz</h3>
        <p class="font-body-sm text-body-sm text-white/70">Prenez soin de vous aujourd'hui, pour un avenir plus heureux.</p>
      </div>
      <a href="<?php echo htmlspecialchars(step_url(2, 'wellbeing')); ?>" class="well-pulse shrink-0 h-12 px-space-lg rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
        <span>Activer mon Pack Wellbeing</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>
  </div>
</section>

<script>
(function () {
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
