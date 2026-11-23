<?php
require __DIR__ . '/includes/bootstrap.php';

// Identité visuelle (même palette que le Pack Assistance, maquette
// "Cabinet d'excellence" fournie par la patronne) : bleu marine #13203F
// (fonds sombres), bleu NexRaiz #2563EB (identique au bouton "Commencer"),
// jaune or #FFC700 (accents), jaune or foncé #8A6D00 (texte sur fond
// clair), fond de page #F8F9FF, texte #0B1C30, texte secondaire #444653.

$volets = [
    ['titre' => 'Accélérateur Carrière', 'texte' => "Pour les professionnel·les prêt·es à se repositionner et décrocher un poste clé.", 'liste' => ['Direction et positionnement stratégique', 'CV, LinkedIn et discours d\'entretien', 'Stratégie de visibilité et réseau', 'Suivi régulier de votre progression'], 'photo' => 'img1_booster/professional_corporate_meeting_in_tech_and_data_analysis_two_professionals.png'],
    ['titre' => 'Branding & Positionnement', 'texte' => 'Des supports soignés pour des opportunités à forts enjeux.', 'liste' => ['CV et profil LinkedIn retravaillés', 'Lettres de motivation ciblées', 'Mise en valeur de votre parcours réel', 'Cohérence entre vos supports'], 'photo' => 'img1_booster/corporate_portrait_and_discussion_two_executive_colleagues_a_business_woman_in.png'],
    ['titre' => 'Leadership', 'texte' => 'Pour communiquer avec plus d\'autorité et fédérer une équipe.', 'liste' => ['Présence et communication', 'Gestion des parties prenantes', 'Préparation aux conversations difficiles', 'Plan de développement personnel'], 'photo' => 'img1_booster/corporate_workshop_and_hr_strategy_meeting_a_dynamic_team_of_diverse_business.png'],
    ['titre' => 'Coaching individuel', 'texte' => 'Un accompagnement continu à chaque étape clé de votre parcours.', 'liste' => ['Espace confidentiel et à votre rythme', 'Stratégie adaptée à votre situation', 'Priorisation de vos objectifs', 'Consultant dédié tout au long du pack'], 'photo' => 'img1_booster/high_end_corporate_professional_photography_two_cheerful_colleagues_or_career.png'],
];

$ressourcesBooster = [
    ['titre' => 'Guide de rebond professionnel', 'texte' => 'Les repères essentiels pour avancer avec méthode après une transition.', 'photo' => "img1_booster/Capture d'écran 2026-11-21 143225.png", 'fichier' => 'guide-rebond-professionnel.txt'],
    ['titre' => 'Guide express CV & LinkedIn', 'texte' => 'Des conseils concrets pour actualiser votre CV et votre profil.', 'photo' => "img1_booster/Capture d'écran 2026-11-21 143247.png", 'fichier' => 'guide-cv-linkedin.txt'],
];

$etapesBooster = [
    ['titre' => 'Échange stratégique', 'texte' => 'Nous clarifions votre objectif et construisons votre feuille de route.', 'icone' => 'explore'],
    ['titre' => 'Choix du plan d\'action', 'texte' => "Carrière, branding, leadership ou coaching : nous adaptons l'accompagnement à votre priorité.", 'icone' => 'route'],
    ['titre' => 'Mise en mouvement', 'texte' => 'Vous avancez avec structure, soutien et des priorités concrètes à chaque étape.', 'icone' => 'trending_up'],
];

$temoignagesBooster = [
    ['nom' => 'Marie-Claire Yao', 'role' => 'Responsable Marketing', 'texte' => "Le Pack Booster m'a aidée à clarifier mon positionnement et à structurer ma recherche. J'ai décroché un poste qui me correspond vraiment."],
    ['nom' => 'Boateng Kouadio', 'role' => 'Chef de projet', 'texte' => "Un accompagnement sérieux et humain. Mon CV et mon profil LinkedIn ont enfin la même histoire à raconter."],
    ['nom' => 'Ange Touré', 'role' => 'Manager commercial', 'texte' => "J'ai gagné en confiance pour mener mes équipes et préparer mes échanges les plus importants."],
];

$faqBooster = [
    ['q' => 'Comment démarrer le Pack Booster ?', 'r' => "Vous commencez par un échange stratégique pour clarifier votre objectif. Vous repartez avec une feuille de route claire et une recommandation sur la suite de l'accompagnement."],
    ['q' => "Et si je ne sais pas encore ce qui me correspond le mieux ?", 'r' => "C'est précisément le rôle de l'échange stratégique initial : nous clarifions votre cap ensemble et adaptons l'accompagnement en conséquence."],
    ['q' => 'Dois-je avoir déjà préparé mon CV avant de commencer ?', 'r' => "Non. Nous commençons toujours par la stratégie. Une fois votre direction clarifiée, nous identifions les supports à créer ou à actualiser en priorité."],
    ['q' => 'Quel est le tarif du Pack Booster ?', 'r' => "Le Pack Booster est proposé à 150 000 FCFA, accompagnement complet inclus. Le détail est présenté lors de votre échange initial."],
];

render_head('Pack Booster — Découverte', "Le Pack Booster NexRaiz vous accompagne pour accélérer votre carrière : positionnement, branding, leadership et coaching individuel.");
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
@keyframes bst-pulse{0%,100%{box-shadow:0 0 0 0 rgba(255,199,0,.4);}50%{box-shadow:0 0 0 7px rgba(255,199,0,0);}}
.bst-pulse{animation:bst-pulse 2.4s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.bst-pulse{animation:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-[#F8F9FF] min-h-[calc(100vh-5rem)]">

<!-- HERO -->
<section class="relative w-full min-h-[480px] lg:min-h-[560px] flex items-center justify-center overflow-hidden bg-[#13203F]">
  <img src="img1_booster/high_end_corporate_professional_photography_two_cheerful_colleagues_or_career.png" alt="Consultant NexRaiz lors d'un échange stratégique" class="absolute inset-0 w-full h-full object-cover">
  <div class="absolute inset-0 bg-gradient-to-b from-[#13203F]/85 via-[#13203F]/75 to-[#13203F]/95"></div>
  <div class="relative z-10 max-w-4xl mx-auto px-gutter-mobile lg:px-gutter-desktop py-space-2xl text-center flex flex-col items-center gap-space-md">
    <nav aria-label="Fil d'ariane" class="reveal-up is-visible flex items-center gap-space-2xs font-body-sm text-body-sm text-white/60 flex-wrap justify-center">
      <a href="index.php#accompagnements" class="hover:text-white transition-colors">Nos Packs</a>
      <span class="material-symbols-outlined text-[16px] text-white/30">chevron_right</span>
      <span class="text-white font-semibold">Pack Booster</span>
    </nav>
    <span class="inline-flex items-center px-space-md py-1.5 rounded-full bg-white/10 backdrop-blur-md text-white font-label-md text-[11px] uppercase tracking-widest">
      Programme d'accompagnement stratégique
    </span>
    <h1 class="font-display-lg text-display-lg-mobile md:font-display-lg md:text-display-lg text-white font-bold tracking-tight">Pack Booster NexRaiz</h1>
    <p class="font-headline-sm text-headline-sm text-white/80 max-w-2xl font-normal">
      Accompagnement stratégique pour propulser votre carrière : positionnement, branding, leadership et coaching individuel.
    </p>
    <div class="flex flex-col sm:flex-row items-center justify-center gap-space-md pt-space-sm">
      <a href="<?php echo htmlspecialchars(step_url(2, 'booster')); ?>" class="bst-pulse inline-flex items-center justify-center px-space-lg py-3.5 rounded-lg bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg font-semibold shadow-lg hover:bg-[#E6B400] hover:-translate-y-0.5 transition-all">
        Réserver mon échange stratégique
      </a>
      <a href="#parcours" class="inline-flex items-center justify-center px-space-lg py-3.5 rounded-lg bg-white/10 backdrop-blur-md text-white font-label-lg text-label-lg font-semibold hover:bg-white/20 transition-all">
        Découvrir le programme
      </a>
    </div>
  </div>
</section>

<?php render_stepper('booster', 1); ?>

<!-- Intro -->
<section class="w-full bg-white py-space-xl px-gutter-mobile lg:px-gutter-desktop shadow-sm">
  <div class="max-w-3xl mx-auto text-center">
    <p class="font-body-lg text-body-lg text-[#444653] leading-relaxed">
      Si vous êtes prêt·e à passer au niveau supérieur, votre positionnement doit parler pour vous avant même votre premier échange. Le <strong class="text-[#13203F] font-semibold">Pack Booster NexRaiz</strong> est l'espace où nous clarifions votre trajectoire, affûtons votre discours et construisons les actifs qui font de vous le choix évident — conçu pour les professionnel·les qui veulent une évolution intentionnelle et maîtrisée.
    </p>
  </div>
</section>

<!-- Échange stratégique -->
<section class="w-full bg-[#EFF4FF] py-space-2xl px-gutter-mobile lg:px-gutter-desktop" id="echange-strategique">
  <div class="max-w-6xl mx-auto">
    <div class="text-center max-w-2xl mx-auto mb-space-xl">
      <span class="font-label-md text-label-md uppercase tracking-widest text-[#FFC700] font-bold">Première étape</span>
      <h2 class="font-headline-lg text-headline-lg text-[#0B1C30] mt-space-2xs">Commencez par un échange stratégique</h2>
      <p class="font-body-lg text-body-lg text-[#444653] mt-space-sm">
        Un échange ciblé pour faire le point sur votre trajectoire. Vous repartez avec une feuille de route claire et une recommandation sur mesure.
      </p>
    </div>
    <div class="reveal-up grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center bg-white rounded-xl p-space-lg lg:p-space-xl shadow-md">
      <div class="lg:col-span-5">
        <div class="rounded-xl overflow-hidden shadow-lg aspect-[4/3]">
          <img src="img1_booster/corporate_coaching_and_career_transition_photography_a_professional_career.png" alt="Échange stratégique avec un consultant NexRaiz" class="w-full h-full object-cover">
        </div>
      </div>
      <div class="lg:col-span-7 flex flex-col gap-space-sm">
        <div>
          <span class="inline-flex items-center px-space-sm py-1 rounded-full bg-[#DCE9FF] text-[#13203F] font-label-md text-[11px] font-bold uppercase tracking-wider mb-space-xs">Diagnostic &amp; plan d'action</span>
          <h3 class="font-headline-md text-headline-md text-[#0B1C30] font-bold">Échange stratégique de positionnement</h3>
          <p class="font-body-md text-body-md text-[#8A6D00] font-medium italic mt-space-2xs">Un échange ciblé pour réaligner votre direction et cartographier vos prochaines étapes.</p>
        </div>
        <p class="font-body-md text-body-md text-[#444653]">
          C'est le point de départ de votre Pack Booster. Nous clarifions votre prochain objectif, affinons le récit de votre parcours et bâtissons un plan d'action immédiatement activable.
        </p>
        <div class="bg-[#F8F9FF] rounded-lg p-space-md">
          <p class="font-label-lg text-label-lg text-[#0B1C30] font-semibold mb-space-xs">Vous repartez avec :</p>
          <ul class="flex flex-col gap-space-xs font-body-md text-body-md text-[#444653]">
            <li class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[#FFC700] text-[20px] shrink-0">check_circle</span><span>Une synthèse claire de votre direction et de votre prochain mouvement</span></li>
            <li class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[#FFC700] text-[20px] shrink-0">check_circle</span><span>Votre feuille de route, avec priorités et étapes concrètes</span></li>
            <li class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[#FFC700] text-[20px] shrink-0">check_circle</span><span>Une recommandation précise pour la suite de votre accompagnement</span></li>
          </ul>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm pt-space-2xs">
          <div>
            <span class="font-label-sm text-[11px] uppercase tracking-wider text-[#444653] block">Pack Booster complet</span>
            <span class="font-title-md text-title-md text-[#13203F] font-bold">150 000 FCFA</span>
          </div>
          <a href="<?php echo htmlspecialchars(step_url(2, 'booster')); ?>" class="inline-flex items-center justify-center px-space-lg py-3 rounded-lg bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg font-semibold shadow-md hover:bg-[#E6B400] transition-all">
            Réserver mon échange
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Pourquoi c'est essentiel -->
<section class="w-full bg-[#13203F] text-white py-space-xl px-gutter-mobile lg:px-gutter-desktop">
  <div class="max-w-3xl mx-auto text-center flex flex-col items-center gap-space-md">
    <h2 class="font-headline-lg text-headline-lg text-white">Pourquoi c'est essentiel</h2>
    <p class="font-body-lg text-body-lg text-white/75">
      Un travail d'excellence ne parle pas toujours de lui-même. Le <strong class="text-white">Pack Booster NexRaiz</strong> comble l'écart entre ce que vous accomplissez réellement et la manière dont vous êtes perçu·e, grâce à la stratégie, au récit et à l'exécution.
    </p>
    <a href="#echange-strategique" class="inline-flex items-center justify-center px-space-lg py-3 rounded-lg bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg font-semibold shadow-lg hover:bg-[#E6B400] hover:-translate-y-0.5 transition-all">
      Réserver mon échange stratégique
    </a>
  </div>
</section>

<!-- Parcours / volets inclus -->
<section class="w-full bg-white py-space-2xl px-gutter-mobile lg:px-gutter-desktop" id="parcours">
  <div class="max-w-6xl mx-auto">
    <div class="text-center max-w-2xl mx-auto mb-space-xl">
      <span class="font-label-md text-label-md uppercase tracking-widest text-[#2563EB] font-bold">Quatre volets inclus</span>
      <h2 class="font-headline-lg text-headline-lg text-[#0B1C30] mt-space-2xs">Ce que couvre votre Pack Booster</h2>
      <p class="font-body-lg text-body-lg text-[#444653] mt-space-sm">
        Un seul pack, un accompagnement complet. Selon votre priorité, nous mettons l'accent sur l'un ou l'autre de ces quatre volets.
      </p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-lg">
      <?php foreach ($volets as $i => $v): ?>
      <div class="reveal-up bg-[#F8F9FF] rounded-xl overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all flex flex-col" style="transition-delay:<?php echo $i * 80; ?>ms">
        <div class="h-56 w-full overflow-hidden">
          <img src="<?php echo htmlspecialchars($v['photo']); ?>" alt="<?php echo htmlspecialchars($v['titre']); ?>" class="w-full h-full object-cover hover:scale-105 transition-transform duration-500">
        </div>
        <div class="p-space-lg flex flex-col flex-grow gap-space-sm">
          <h3 class="font-headline-md text-headline-md text-[#13203F] font-bold"><?php echo htmlspecialchars($v['titre']); ?></h3>
          <p class="font-label-lg text-label-lg text-[#0B1C30] font-semibold"><?php echo htmlspecialchars($v['texte']); ?></p>
          <ul class="flex flex-col gap-space-2xs font-body-md text-body-md text-[#444653] pt-space-2xs">
            <?php foreach ($v['liste'] as $item): ?>
            <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#FFC700] text-[18px]">arrow_right</span><?php echo htmlspecialchars($item); ?></li>
            <?php endforeach; ?>
          </ul>
          <span class="mt-auto pt-space-md inline-flex items-center gap-space-2xs font-label-md text-label-md text-[#2563EB] font-semibold"><span class="material-symbols-outlined text-[18px]">check_circle</span>Inclus dans votre Pack Booster</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-space-xl">
      <p class="font-title-md text-title-md text-[#0B1C30] mb-space-md">Vous hésitez sur la priorité à donner ? Démarrez par l'échange stratégique.</p>
      <a href="#echange-strategique" class="inline-flex items-center justify-center px-space-lg py-3.5 rounded-lg bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg font-semibold shadow-md hover:bg-[#E6B400] hover:-translate-y-0.5 transition-all">
        Réserver mon échange stratégique
      </a>
    </div>
  </div>
</section>

<!-- Comment nous travaillons ensemble -->
<section class="relative w-full h-72 flex items-center justify-center overflow-hidden bg-[#1E3650]">
  <img src="img1_booster/corporate_workshop_and_hr_strategy_meeting_a_dynamic_team_of_diverse_business.png" alt="" aria-hidden="true" class="absolute inset-0 w-full h-full object-cover">
  <div class="absolute inset-0 bg-[#13203F]/75"></div>
  <div class="relative z-10 text-center px-gutter-mobile">
    <h2 class="font-display-lg text-display-lg-mobile md:text-display-lg text-white font-bold">Comment nous travaillons ensemble</h2>
  </div>
</section>

<section class="w-full bg-white py-space-2xl px-gutter-mobile lg:px-gutter-desktop">
  <div class="max-w-5xl mx-auto">
    <div class="text-center mb-space-lg">
      <h3 class="font-headline-lg text-headline-lg text-[#0B1C30]">Une trajectoire simple et claire</h3>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
      <?php foreach ($etapesBooster as $i => $e): ?>
      <div class="reveal-up bg-[#EFF4FF] rounded-xl p-space-lg text-center flex flex-col items-center gap-space-sm shadow-sm" style="transition-delay:<?php echo $i * 90; ?>ms">
        <span class="w-14 h-14 rounded-full bg-[#13203F] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[28px]"><?php echo $e['icone']; ?></span></span>
        <h4 class="font-headline-sm text-headline-sm text-[#13203F] font-bold">Étape <?php echo $i + 1; ?> : <?php echo htmlspecialchars($e['titre']); ?></h4>
        <p class="font-body-md text-body-md text-[#444653]"><?php echo htmlspecialchars($e['texte']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Méthode / philosophie -->
<section class="w-full bg-[#EFF4FF] py-space-2xl px-gutter-mobile lg:px-gutter-desktop">
  <div class="max-w-3xl mx-auto text-center flex flex-col gap-space-sm">
    <span class="font-label-md text-label-md uppercase tracking-widest text-[#FFC700] font-bold">Notre approche</span>
    <h2 class="font-headline-lg text-headline-lg text-[#0B1C30]">La méthode NexRaiz Booster</h2>
    <p class="font-body-md text-body-md text-[#13203F] font-semibold italic">Là où le récit rencontre la stratégie et où le leadership prend forme.</p>
    <p class="font-body-lg text-body-lg text-[#444653] mt-space-2xs">
      Le Pack Booster s'appuie sur une approche qui allie positionnement de carrière, stratégie et préparation à la prise de parole. C'est ainsi que nous construisons votre clarté, renforçons votre message et préparons votre prochaine étape avec intention.
    </p>
  </div>
</section>

<!-- Ressources gratuites -->
<section class="w-full bg-white py-space-2xl px-gutter-mobile lg:px-gutter-desktop">
  <div class="max-w-6xl mx-auto">
    <div class="text-center max-w-2xl mx-auto mb-space-xl">
      <h2 class="font-headline-lg text-headline-lg text-[#0B1C30]">Vos premières ressources</h2>
      <p class="font-body-lg text-body-lg text-[#444653] mt-space-2xs">Des guides gratuits pour avancer dès maintenant, à votre rythme.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
      <?php foreach ($ressourcesBooster as $i => $r): ?>
      <div class="reveal-up bg-[#F8F9FF] rounded-xl p-space-lg flex flex-col gap-space-md shadow-sm hover:shadow-md transition-all" style="transition-delay:<?php echo $i * 80; ?>ms">
        <div class="h-40 rounded-lg overflow-hidden bg-[#DCE9FF]">
          <img src="<?php echo htmlspecialchars($r['photo']); ?>" alt="<?php echo htmlspecialchars($r['titre']); ?>" class="w-full h-full object-cover">
        </div>
        <h3 class="font-title-md text-title-md text-[#0B1C30] font-bold"><?php echo htmlspecialchars($r['titre']); ?></h3>
        <p class="font-body-md text-body-md text-[#444653] flex-grow"><?php echo htmlspecialchars($r['texte']); ?></p>
        <a href="ressources-booster/<?php echo htmlspecialchars($r['fichier']); ?>" download class="inline-flex items-center justify-center gap-space-2xs px-space-md py-2.5 rounded-lg bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-semibold hover:bg-[#E6B400] transition-all">
          <span class="material-symbols-outlined text-[18px]">download</span>Télécharger le guide
        </a>
      </div>
      <?php endforeach; ?>
      <div class="reveal-up bg-[#F8F9FF] rounded-xl p-space-lg flex flex-col gap-space-md shadow-sm" style="transition-delay:160ms">
        <div class="h-40 rounded-lg overflow-hidden bg-[#DCE9FF]">
          <img src="img1_booster/Capture d'écran 2026-11-21 143311.png" alt="Scorecard de marque personnelle" class="w-full h-full object-cover">
        </div>
        <h3 class="font-title-md text-title-md text-[#0B1C30] font-bold">Scorecard de marque personnelle</h3>
        <p class="font-body-md text-body-md text-[#444653] flex-grow">Évaluez et renforcez votre présence professionnelle : visibilité, crédibilité, influence.</p>
        <span class="inline-flex items-center justify-center px-space-md py-2.5 rounded-lg bg-[#E5EEFF] text-[#444653] font-label-md text-label-md font-semibold">Bientôt disponible</span>
      </div>
    </div>
  </div>
</section>

<!-- Stats + témoignage -->
<section class="w-full bg-[#EFF4FF] py-space-2xl px-gutter-mobile lg:px-gutter-desktop">
  <div class="max-w-5xl mx-auto">
    <div class="text-center max-w-2xl mx-auto mb-space-xl">
      <h2 class="font-headline-lg text-headline-lg text-[#0B1C30]">Des résultats concrets. Un nouvel élan.</h2>
      <p class="font-body-lg text-body-lg text-[#444653] mt-space-2xs">
        [Chiffres clés NexRaiz à venir] — en attendant, voici ce que nos clients nous disent.
      </p>
    </div>
    <div class="bg-white rounded-xl p-space-lg lg:p-space-xl shadow-md relative">
      <div class="flex flex-col md:flex-row items-center gap-space-lg" id="bst-testimonial-content">
        <div class="w-24 h-24 rounded-full overflow-hidden shrink-0 shadow-md ring-4 ring-[#FFC700]/30">
          <img id="bst-testimonial-img" src="img1_booster/distinguished_executive_portrait_a_confident_warm_mature_c_level_black.png" alt="" class="w-full h-full object-cover">
        </div>
        <div class="flex flex-col gap-space-sm">
          <span class="text-[#FFC700] text-[16px]">★★★★★</span>
          <p class="font-body-lg text-body-lg text-[#0B1C30] italic" id="bst-testimonial-quote"></p>
          <div>
            <div class="font-title-md text-title-md text-[#13203F] font-bold" id="bst-testimonial-author"></div>
            <div class="font-body-sm text-body-sm text-[#444653]" id="bst-testimonial-role"></div>
          </div>
        </div>
      </div>
      <div class="flex items-center justify-end gap-space-xs mt-space-md">
        <button type="button" id="bst-prev" aria-label="Témoignage précédent" class="w-10 h-10 rounded-full bg-[#F8F9FF] text-[#13203F] hover:bg-[#13203F] hover:text-white flex items-center justify-center transition-all"><span class="material-symbols-outlined">arrow_back</span></button>
        <button type="button" id="bst-next" aria-label="Témoignage suivant" class="w-10 h-10 rounded-full bg-[#13203F] text-white hover:bg-[#2563EB] flex items-center justify-center transition-all"><span class="material-symbols-outlined">arrow_forward</span></button>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="w-full bg-white py-space-2xl px-gutter-mobile lg:px-gutter-desktop" id="faq">
  <div class="max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-space-2xl">
    <div class="lg:col-span-5 flex flex-col gap-space-sm">
      <h2 class="font-headline-lg text-headline-lg text-[#0B1C30]">Questions fréquentes</h2>
      <p class="font-body-md text-body-md text-[#444653]">
        Commencez par cette FAQ. Pour une recommandation personnalisée, réservez votre échange stratégique.
      </p>
      <div class="pt-space-2xs">
        <a href="#echange-strategique" class="inline-flex items-center justify-center px-space-lg py-3 rounded-lg bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-semibold shadow-md hover:bg-[#E6B400] transition-all">
          Réserver mon échange stratégique
        </a>
      </div>
    </div>
    <div class="lg:col-span-7 flex flex-col gap-space-sm" id="bst-faq-accordion">
      <?php foreach ($faqBooster as $item): ?>
      <div class="bg-[#F8F9FF] rounded-xl p-space-md shadow-sm">
        <button type="button" class="bst-faq-toggle w-full flex items-center justify-between gap-space-sm text-left font-title-md text-title-md text-[#0B1C30] font-semibold" aria-expanded="false">
          <span><?php echo htmlspecialchars($item['q']); ?></span>
          <span class="material-symbols-outlined text-[#2563EB] transition-transform shrink-0">add</span>
        </button>
        <div class="bst-faq-content hidden pt-space-sm font-body-md text-body-md text-[#444653]">
          <?php echo htmlspecialchars($item['r']); ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA finale -->
<section class="w-full bg-[#13203F] text-white py-space-2xl px-gutter-mobile lg:px-gutter-desktop">
  <div class="max-w-3xl mx-auto text-center flex flex-col items-center gap-space-md">
    <h2 class="font-display-lg text-display-lg-mobile md:text-display-lg text-white font-bold">Prêt à passer au niveau supérieur ?</h2>
    <p class="font-body-lg text-body-lg text-white/75">
      Commencez par votre échange stratégique et repartez avec un plan d'action immédiatement activable.
    </p>
    <div class="flex flex-col sm:flex-row items-center justify-center gap-space-md pt-space-2xs">
      <a href="<?php echo htmlspecialchars(step_url(2, 'booster')); ?>" class="inline-flex items-center justify-center px-space-lg py-3.5 rounded-lg bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg font-semibold shadow-lg hover:bg-[#E6B400] hover:-translate-y-0.5 transition-all">
        Réserver mon échange stratégique
      </a>
      <a href="#parcours" class="inline-flex items-center justify-center px-space-lg py-3.5 rounded-lg bg-white/10 text-white font-label-lg text-label-lg font-semibold hover:bg-white/20 transition-all">
        Découvrir le programme
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

  // FAQ
  document.querySelectorAll('.bst-faq-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var content = btn.nextElementSibling;
      var icon = btn.querySelector('.material-symbols-outlined');
      var isOpen = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!isOpen));
      content.classList.toggle('hidden');
      icon.textContent = isOpen ? 'add' : 'remove';
    });
  });

  // Témoignages
  var temoignages = <?php echo json_encode($temoignagesBooster, JSON_UNESCAPED_UNICODE); ?>;
  var indexCourant = 0;
  var quoteEl = document.getElementById('bst-testimonial-quote');
  var authorEl = document.getElementById('bst-testimonial-author');
  var roleEl = document.getElementById('bst-testimonial-role');

  function majTemoignage(i) {
    var t = temoignages[i];
    quoteEl.textContent = '« ' + t.texte + ' »';
    authorEl.textContent = t.nom;
    roleEl.textContent = t.role;
  }
  majTemoignage(0);

  document.getElementById('bst-prev').addEventListener('click', function () {
    indexCourant = (indexCourant - 1 + temoignages.length) % temoignages.length;
    majTemoignage(indexCourant);
  });
  document.getElementById('bst-next').addEventListener('click', function () {
    indexCourant = (indexCourant + 1) % temoignages.length;
    majTemoignage(indexCourant);
  });
})();
</script>

<?php render_footer(); ?>
