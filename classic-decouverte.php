<?php
require __DIR__ . '/includes/bootstrap.php';

$raisons = [
    ['icone' => 'forum', 'citation' => "J'envoie des CV, mais peu de réponses", 'texte' => 'Nous identifions ce qui bloque et retravaillons vos premières secondes de lecture.', 'degrade' => 'from-[#2563EB] to-[#1E3E62]'],
    ['icone' => 'star', 'citation' => 'Je ne sais pas valoriser mon parcours', 'texte' => 'Ensemble, nous identifions vos expériences marquantes pour présenter un parcours convaincant.', 'degrade' => 'from-[#F0A83E] to-[#824500]'],
    ['icone' => 'chat', 'citation' => "J'appréhende les entretiens", 'texte' => 'Simulations et questions types : nous vous préparons à aborder chaque entretien avec confiance.', 'degrade' => 'from-[#1E3E62] to-[#0B192C]'],
    ['icone' => 'trending_up', 'citation' => "Mon parcours n'est pas linéaire", 'texte' => 'Reconversion, pause de carrière, changement de secteur : nous transformons votre histoire en atout.', 'degrade' => 'from-[#8FB2FF] to-[#2563EB]'],
];

$apporte = [
    ['numero' => '01', 'titre' => 'Valoriser votre parcours', 'texte' => 'Un bilan complet pour révéler vos compétences clés, vos réussites chiffrées et ce qui vous rend unique sur le marché.'],
    ['numero' => '02', 'titre' => 'Renforcer votre CV', 'texte' => "Structure, mots-clés, résultats mesurables : un CV limpide et moderne qui donne envie aux recruteurs de vous rencontrer."],
    ['numero' => '03', 'titre' => 'Lettre & profil en ligne', 'texte' => 'Une lettre de motivation ciblée et un profil LinkedIn cohérent avec votre nouveau positionnement professionnel.'],
    ['numero' => '04', 'titre' => 'Préparer vos entretiens', 'texte' => "Simulations réelles avec votre coach, réponses aux questions pièges et argumentaire d'entretien sur-mesure.", 'mis_en_avant' => true],
];

$etapesAccompagnement = [
    ['numero' => 1, 'titre' => 'Premier échange', 'texte' => 'Nous faisons connaissance avec votre parcours, vos objectifs et vos blocages actuels.', 'couleur' => 'primary'],
    ['numero' => 2, 'titre' => 'Bilan de parcours', 'texte' => "Votre coach analyse votre CV, vos candidatures et identifie vos axes d'amélioration.", 'couleur' => 'primary'],
    ['numero' => 3, 'titre' => 'Candidature retravaillée', 'texte' => 'CV, lettre et profil en ligne sont réécrits avec vous, pas à votre place.', 'couleur' => 'primary'],
    ['numero' => 4, 'titre' => "Simulation d'entretien", 'texte' => 'Mise en situation réelle pour aborder vos prochains entretiens avec confiance.', 'couleur' => 'tertiary'],
];

$equipe = [
    ['nom' => 'Aboubacar Diop', 'role' => 'Coach Talent Acquisition', 'photo' => 'img_classic/equipe-2.png', 'tags' => ['CV & LinkedIn', 'RH']],
    ['nom' => 'Koamé B.', 'role' => 'Consultant Carrière & CRM', 'initiales' => 'KB', 'couleur' => 'bg-[#1E3E62]', 'tags' => ['Recrutement', 'Négociation']],
    ['nom' => "Marième N'Diaye", 'role' => 'Coach Formation RH', 'photo' => 'img_classic/equipe-3.png', 'tags' => ['Entretiens', 'Soft skills']],
];

$temoignages = [
    ['nom' => 'Awa T.', 'role' => "Recherche d'emploi, Dakar", 'initiales' => 'AT', 'couleur' => 'bg-[#2563EB]', 'citation' => "Après seulement deux semaines d'accompagnement, j'ai reçu trois propositions d'entretien. Mon CV ne ressemblait plus du tout à l'ancien."],
    ['nom' => 'Moussa K.', 'role' => 'Reconversion professionnelle', 'initiales' => 'MK', 'couleur' => 'bg-tertiary', 'citation' => 'Mon coach a su mettre des mots sur mon parcours atypique. Je me sens enfin légitime face aux recruteurs.'],
    ['nom' => 'Fatou S.', 'role' => 'Responsable RH, Dakar', 'initiales' => 'FS', 'couleur' => 'bg-[#1E3E62]', 'citation' => "Simulation d'entretien bluffante de réalisme : j'étais bien plus à l'aise le jour J."],
    ['nom' => 'Ibrahima D.', 'role' => "Étudiant, 1ère recherche d'emploi", 'initiales' => 'ID', 'couleur' => 'bg-[#F0A83E] text-[#0B192C]', 'citation' => 'Un accompagnement humain, rapide et efficace. Je recommande à tous ceux qui cherchent un nouvel emploi.'],
    ['nom' => 'Nadia B.', 'role' => 'Changement de poste', 'initiales' => 'NB', 'couleur' => 'bg-[#14304f]', 'citation' => "Mon profil LinkedIn a enfin un vrai fil conducteur. J'ai doublé mes vues en un mois."],
    ['nom' => 'Cheikh T.', 'role' => 'Cheffe de projet digital, Abidjan', 'initiales' => 'CT', 'couleur' => 'bg-[#2563EB]', 'citation' => 'Clair, structuré, et surtout très concret. On repart avec des outils que l\'on garde pour toutes ses prochaines candidatures.'],
];

$faq = [
    ['q' => "Combien de temps dure l'accompagnement ?", 'r' => "Le Pack Classic se déroule en général sur 2 à 4 semaines, selon votre disponibilité et le nombre de livrables à retravailler (CV, lettre, LinkedIn, entretien)."],
    ['q' => 'Les séances se font-elles en ligne ?', 'r' => "Oui, l'ensemble de l'accompagnement se déroule à distance (visio ou téléphone), avec un suivi écrit disponible à tout moment depuis votre espace candidat."],
    ['q' => 'Je suis en reconversion, le pack est-il adapté ?', 'r' => "Tout à fait. Beaucoup de nos candidats sont en reconversion ou en repositionnement professionnel : votre coach adapte l'accompagnement à votre situation."],
    ['q' => 'Quelle différence avec le Pack Booster ?', 'r' => "Le Pack Classic se concentre sur vos outils de candidature (CV, lettre, LinkedIn, entretien). Le Pack Booster va plus loin, avec une stratégie de recherche d'emploi complète."],
];

render_head('Pack Classic — Optimisez votre candidature', "Le Pack Classic NexRaiz vous aide à retravailler votre CV, votre lettre de motivation et votre préparation aux entretiens, avec un coach dédié.");
render_top_header('packs');
?>
<style>
main{overflow-x:hidden;}
.reveal-up,.reveal-scale,.reveal-left,.reveal-right{opacity:0;transition:opacity .7s cubic-bezier(.22,.61,.36,1),transform .7s cubic-bezier(.22,.61,.36,1);will-change:opacity,transform;}
.reveal-up{transform:translateY(28px);}
.reveal-scale{transform:scale(.92);}
.reveal-left{transform:translateX(-24px);}
.reveal-right{transform:translateX(24px);}
.reveal-up.is-visible,.reveal-scale.is-visible,.reveal-left.is-visible,.reveal-right.is-visible{opacity:1;transform:translate(0,0) scale(1);}
@media (prefers-reduced-motion:reduce){.reveal-up,.reveal-scale,.reveal-left,.reveal-right{transition:none;opacity:1;transform:none;}}
@keyframes classic-pulse-dot{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.45);}50%{box-shadow:0 0 0 6px rgba(34,197,94,0);}}
.classic-pulse-dot{animation:classic-pulse-dot 2s ease-in-out infinite;}
@keyframes classic-float{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
.classic-float{animation:classic-float 4.5s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.classic-pulse-dot,.classic-float{animation:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface">

<!-- Hero -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-lg pb-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php#accompagnements" class="hover:text-primary transition-colors">Nos Packs</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Pack Classic</span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-[1.1fr_0.9fr] gap-space-2xl items-center">
      <div class="reveal-left is-visible flex flex-col gap-space-md">
        <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-tertiary-fixed/60 text-tertiary font-label-md text-label-md font-bold">
          Pack Classic · Candidature &amp; entretien
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-on-surface font-bold tracking-tight">
          Votre parcours a de la valeur.<br>
          <span class="text-primary">Faisons-la voir aux recruteurs.</span>
        </h1>
        <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-secondary max-w-max-width-reading">
          Un accompagnement concret pour mettre en lumière vos réussites, renforcer votre CV, votre lettre de motivation et votre profil en ligne, et avancer en entretien sûr·e de vous.
        </p>
        <div class="flex flex-col sm:flex-row gap-space-sm pt-space-2xs">
          <a href="#quiz" class="h-12 px-space-lg rounded-xl bg-[#0B192C] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
            <span>Tester ma candidature gratuitement</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
          </a>
          <a href="#contenu" class="h-12 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
            Découvrir le pack
          </a>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center gap-space-xs sm:gap-space-lg pt-space-2xs">
          <span class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[16px] text-primary">menu_book</span>Ressources gratuites sans inscription</span>
          <span class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[16px] text-primary">support_agent</span>Coach dédié</span>
          <span class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[16px] text-primary">lock</span>Sans engagement</span>
        </div>
      </div>

      <div class="reveal-right is-visible relative mx-auto max-w-sm lg:max-w-none">
        <div class="relative rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30 bg-surface-container-lowest">
          <img src="img_classic/a_charismatic_articulate_west_african_professional_career_coach_looking.png" alt="Coach NexRaiz en visio avec un candidat" class="w-full h-72 sm:h-80 object-cover">
        </div>
        <div class="classic-float absolute -top-4 -right-4 bg-[#0B192C] text-white rounded-full pl-1 pr-space-sm py-1 shadow-lg flex items-center gap-space-2xs">
          <span class="w-7 h-7 rounded-full bg-[#F0A83E] flex items-center justify-center"><span class="material-symbols-outlined text-[16px] text-[#0B192C]">bolt</span></span>
          <span class="flex flex-col leading-tight">
            <span class="font-label-md text-[9px] text-white/70 uppercase tracking-wide">Disponible maintenant</span>
            <span class="font-label-md text-[11px] font-bold">Coach dédié &amp; réactif</span>
          </span>
        </div>
        <div class="absolute -bottom-6 left-1/2 -translate-x-1/2 sm:left-auto sm:translate-x-0 sm:-right-6 w-60 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-sm flex flex-col gap-space-xs">
          <div class="flex items-center justify-between">
            <span class="font-label-md text-label-md text-on-surface font-bold">Votre candidature, pas à pas</span>
            <span class="classic-pulse-dot w-2 h-2 rounded-full bg-green-500"></span>
          </div>
          <div class="flex items-center justify-between gap-space-xs">
            <span class="font-body-sm text-[11px] text-secondary">Mise en avant des résultats</span>
            <span class="font-label-md text-[10px] px-space-2xs py-0.5 rounded-full bg-green-100 text-green-700 font-bold">Renforcé</span>
          </div>
          <div class="flex items-center justify-between gap-space-xs">
            <span class="font-body-sm text-[11px] text-secondary">Clarté de présentation</span>
            <span class="font-label-md text-[10px] px-space-2xs py-0.5 rounded-full bg-blue-100 text-blue-700 font-bold">En cours</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php render_stepper('classic', 1); ?>

<!-- Vous vous reconnaissez ? -->
<section id="contenu" class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">
    <div class="reveal-up flex flex-col gap-space-2xs">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-tertiary font-bold">Vous vous reconnaissez ?</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Le Pack Classic est fait pour vous si...</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm">
      <?php foreach ($raisons as $i => $r): ?>
      <div class="reveal-up group relative overflow-hidden bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm hover:shadow-xl flex flex-col gap-space-xs border border-transparent hover:border-outline-variant/40 transition-all duration-300 hover:-translate-y-1.5" style="transition-delay:<?php echo $i * 90; ?>ms">
        <span class="absolute -top-3 -right-1 font-headline-xl text-headline-xl text-on-surface/5 select-none pointer-events-none leading-none">”</span>
        <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br <?php echo $r['degrade']; ?> text-white flex items-center justify-center shadow-sm group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
          <span class="material-symbols-outlined text-[22px]"><?php echo $r['icone']; ?></span>
        </div>
        <h3 class="relative font-headline-sm text-headline-sm text-on-surface font-bold leading-snug">« <?php echo htmlspecialchars($r['citation']); ?> »</h3>
        <p class="relative font-body-sm text-body-sm text-secondary leading-relaxed"><?php echo htmlspecialchars($r['texte']); ?></p>
        <div class="relative mt-space-2xs h-1 w-9 rounded-full bg-gradient-to-r <?php echo $r['degrade']; ?> group-hover:w-16 transition-all duration-300"></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Notre approche -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-2 gap-space-2xl items-center">
    <div class="reveal-left relative mx-auto max-w-md lg:max-w-none h-[26rem] sm:h-[32rem] lg:h-[36rem] flex items-end justify-center">
      <div class="absolute inset-0 flex items-center justify-center">
        <div class="w-64 h-64 sm:w-80 sm:h-80 rounded-full bg-gradient-to-br from-primary-container/25 via-tertiary-fixed/30 to-transparent blur-3xl"></div>
      </div>
      <img src="img_classic/full-body-cutout-transparent.png" alt="Candidate accompagnée par NexRaiz, heureuse d'avoir obtenu son offre d'embauche" class="relative z-10 h-full max-w-full w-auto object-contain drop-shadow-2xl classic-float">
      <div class="absolute bottom-6 -right-2 sm:-right-8 z-20 w-64 bg-[#0B192C] text-white rounded-xl shadow-lg p-space-sm flex flex-col gap-space-2xs">
        <span class="font-label-badge text-label-badge uppercase tracking-wide text-[#F0A83E] font-bold">Témoignage</span>
        <p class="font-body-sm text-body-sm text-white leading-snug">« Grâce à l'accompagnement, j'ai enfin un CV qui me ressemble. »</p>
        <p class="font-label-md text-[10px] text-white/60">Amina K. — Cliente accompagnée</p>
      </div>
    </div>
    <div class="reveal-right flex flex-col gap-space-sm">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-primary font-bold">Notre approche</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">On part de votre histoire, pas d'un modèle tout fait</h2>
      <p class="font-body-md text-body-md text-secondary leading-relaxed">
        Chaque parcours est unique. Votre coach vous écoute, repère vos points forts et vous aide à les présenter avec vos propres mots, pour une candidature authentique et convaincante auprès des recruteurs.
      </p>
      <ul class="flex flex-col gap-space-sm pt-space-2xs">
        <li class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">check_circle</span>
          <span class="font-body-sm text-body-sm text-on-surface"><span class="font-bold">Écoute et diagnostic sur-mesure :</span> un premier échange pour analyser vos candidatures et retours actuels.</span>
        </li>
        <li class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">check_circle</span>
          <span class="font-body-sm text-body-sm text-on-surface"><span class="font-bold">Des recommandations concrètes et applicables :</span> réécriture de vos documents, optimisation LinkedIn, arguments d'entretien.</span>
        </li>
        <li class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">check_circle</span>
          <span class="font-body-sm text-body-sm text-on-surface"><span class="font-bold">Un suivi humain, pas seulement un modèle :</span> votre coach reste disponible tout au long de votre accompagnement.</span>
        </li>
      </ul>
      <a href="#quiz" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 mt-space-2xs">
        <span>Commencer maintenant</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>
  </div>
</section>

<!-- Quiz -->
<section id="quiz" class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">
    <div class="reveal-up flex flex-col gap-space-2xs">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-tertiary font-bold">Gratuit · Commencez maintenant</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Faites le point sur votre candidature en 2 minutes</h2>
      <p class="font-body-md text-body-md text-secondary max-w-max-width-reading">Répondez à 5 questions rapides pour recevoir un premier avis sur votre candidature, sans inscription.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-md items-start">

      <div id="quizCard" class="reveal-scale bg-[#0B192C] rounded-2xl shadow-lg p-space-lg flex flex-col gap-space-md min-h-[22rem]">
        <div class="flex items-center justify-between">
          <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#F0A83E] font-bold">Quiz · Ma candidature en 5 questions</span>
          <span id="quizProgressBadge" class="font-label-md text-[11px] px-space-sm py-1 rounded-full bg-white/10 text-white font-bold">Question 1/5</span>
        </div>

        <div id="quizBarWrap" class="w-full h-1.5 rounded-full bg-white/10 overflow-hidden">
          <div id="quizBar" class="h-full bg-[#F0A83E] transition-all" style="width:20%"></div>
        </div>

        <div id="quizQuestions"></div>

        <div id="quizResult" class="hidden flex-col gap-space-sm"></div>

        <div class="flex items-center justify-between pt-space-2xs">
          <span class="font-label-md text-[11px] text-white/50">Résultat immédiat, gratuit</span>
          <button type="button" id="quizNextBtn" disabled class="h-11 px-space-lg rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs disabled:opacity-40 disabled:cursor-not-allowed hover:opacity-90 transition-opacity">
            <span id="quizNextLabel">Continuer</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
          </button>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <div class="reveal-up bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex items-center gap-space-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
          <div class="w-11 h-11 rounded-lg bg-tertiary-fixed/60 text-tertiary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">picture_as_pdf</span></div>
          <div class="flex-1">
            <p class="font-label-lg text-label-lg text-on-surface font-bold leading-tight">Checklist : CV prêt à envoyer</p>
            <p class="font-body-sm text-[11px] text-secondary">Les points à vérifier avant de postuler.</p>
          </div>
        </div>
        <div class="reveal-up bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex items-center gap-space-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300" style="transition-delay:90ms">
          <div class="w-11 h-11 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">edit_note</span></div>
          <div class="flex-1">
            <p class="font-label-lg text-label-lg text-on-surface font-bold leading-tight">Exercice : vos 5 réussites clés</p>
            <p class="font-body-sm text-[11px] text-secondary">Un support pour préparer votre entretien.</p>
          </div>
        </div>
        <div class="reveal-up rounded-xl p-space-md flex flex-col gap-space-xs bg-tertiary-fixed/30 border border-tertiary-fixed" style="transition-delay:180ms">
          <span class="font-label-md text-[10px] px-space-xs py-0.5 rounded-full bg-tertiary text-white self-start font-bold uppercase tracking-wide">Résultats</span>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Prêt·e à booster votre candidature ?</p>
          <p class="font-body-sm text-[11px] text-secondary leading-relaxed">Terminez le quiz pour recevoir votre résultat, puis créez votre espace candidat gratuit pour démarrer votre accompagnement.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Conseils courts -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">
    <div class="reveal-up flex flex-col gap-space-2xs">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-primary font-bold">À écouter · À regarder</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Des conseils courts, à votre rythme</h2>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">

      <div class="reveal-up bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-space-sm hover:shadow-md transition-shadow duration-300">
        <div class="flex items-center justify-between">
          <span class="flex items-center gap-space-2xs font-label-lg text-label-lg text-on-surface font-bold"><span class="material-symbols-outlined text-[18px] text-primary">headphones</span>Capsules audio</span>
          <span class="font-label-md text-[10px] px-space-xs py-0.5 rounded-full bg-blue-100 text-blue-700 font-bold uppercase tracking-wide">Bientôt disponible</span>
        </div>
        <div class="rounded-xl bg-[#0B192C] p-space-sm flex items-center gap-space-sm">
          <button type="button" disabled class="w-11 h-11 rounded-full bg-[#F0A83E] text-[#0B192C] flex items-center justify-center shrink-0 opacity-70 cursor-not-allowed" aria-label="Lecture bientôt disponible">
            <span class="material-symbols-outlined text-[22px]">play_arrow</span>
          </button>
          <div class="flex-1 min-w-0">
            <p class="font-label-lg text-label-lg text-white font-bold truncate">Comment reformuler votre expérience</p>
            <div class="flex items-end gap-0.5 h-4 mt-1" aria-hidden="true">
              <?php foreach ([40,70,55,90,35,60,45,80,50,65,30,70,55,45,60] as $h): ?>
              <span class="w-1 bg-white/25 rounded-full" style="height:<?php echo $h; ?>%"></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <p class="font-body-sm text-[11px] text-secondary">Les extraits audio arrivent très prochainement — revenez bientôt les écouter ici.</p>
      </div>

      <div class="reveal-up bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-space-sm hover:shadow-md transition-shadow duration-300" style="transition-delay:120ms">
        <div class="flex items-center justify-between">
          <span class="flex items-center gap-space-2xs font-label-lg text-label-lg text-on-surface font-bold"><span class="material-symbols-outlined text-[18px] text-primary">smart_display</span>Film &amp; Masterclass</span>
          <span class="font-label-md text-[10px] px-space-xs py-0.5 rounded-full bg-blue-100 text-blue-700 font-bold uppercase tracking-wide">Bientôt disponible</span>
        </div>
        <button type="button" id="videoPlaceholderBtn" class="relative rounded-xl overflow-hidden h-36 group">
          <img src="img_classic/professional_headshot_portrait_of_a_smiling_successful_young_african_woman.png" alt="Masterclass : réussir son entretien" class="w-full h-full object-cover">
          <span class="absolute inset-0 bg-[#0B192C]/40"></span>
          <span class="absolute inset-0 flex items-center justify-center">
            <span class="w-12 h-12 rounded-full bg-white/90 flex items-center justify-center text-primary group-hover:scale-105 transition-transform"><span class="material-symbols-outlined text-[26px]">play_arrow</span></span>
          </span>
        </button>
        <p class="font-body-sm text-[11px] text-secondary">« Réussir son entretien » — la vidéo sera ajoutée ici dès qu'elle sera prête.</p>
      </div>
    </div>
  </div>
</section>

<!-- Ce que le pack apporte -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg items-center text-center">
    <div class="reveal-up flex flex-col gap-space-2xs items-center">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-primary font-bold">Ce que le Pack Classic vous apporte</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold max-w-2xl">Une candidature qui vous ressemble, et qui convainc</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm w-full">
      <?php foreach ($apporte as $i => $a): ?>
      <div class="reveal-up rounded-xl p-space-md text-left flex flex-col gap-space-2xs transition-all duration-300 hover:-translate-y-1 hover:shadow-lg <?php echo !empty($a['mis_en_avant']) ? 'bg-[#0B192C] text-white' : 'bg-surface-container-low text-on-surface'; ?>" style="transition-delay:<?php echo $i * 90; ?>ms">
        <span class="font-headline-md text-headline-md font-bold <?php echo !empty($a['mis_en_avant']) ? 'text-[#F0A83E]' : 'text-primary/60'; ?>"><?php echo $a['numero']; ?></span>
        <h3 class="font-label-lg text-label-lg font-bold"><?php echo htmlspecialchars($a['titre']); ?></h3>
        <p class="font-body-sm text-[11px] leading-relaxed <?php echo !empty($a['mis_en_avant']) ? 'text-white/70' : 'text-secondary'; ?>"><?php echo htmlspecialchars($a['texte']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="reveal-up w-full bg-surface-container-low rounded-2xl p-space-lg flex flex-col gap-space-lg">
      <div class="flex flex-col gap-space-2xs items-center text-center">
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Comment se déroule l'accompagnement ?</h3>
        <p class="font-body-sm text-body-sm text-secondary">4 étapes structurées pour des livrables visibles et durables.</p>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-space-md">
        <?php foreach ($etapesAccompagnement as $i => $e): ?>
        <div class="reveal-up flex flex-col gap-space-xs text-left relative" style="transition-delay:<?php echo $i * 90; ?>ms">
          <?php if ($i < count($etapesAccompagnement) - 1): ?>
          <div class="hidden md:block absolute top-4 left-[2.25rem] w-[calc(100%-1.75rem)] h-0 border-t-2 border-dashed border-outline-variant"></div>
          <?php endif; ?>
          <span class="relative z-10 w-8 h-8 rounded-full flex items-center justify-center font-label-md text-label-md font-bold text-white <?php echo $e['couleur'] === 'tertiary' ? 'bg-tertiary' : 'bg-primary-container'; ?>"><?php echo $e['numero']; ?></span>
          <h4 class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($e['titre']); ?></h4>
          <p class="font-body-sm text-[11px] text-secondary leading-relaxed"><?php echo htmlspecialchars($e['texte']); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- Experts -->
<section id="equipe" class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">
    <div class="reveal-up flex flex-col gap-space-2xs">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-tertiary font-bold">Qui vous accompagne</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Des expert·es du recrutement à vos côtés</h2>
      <p class="font-body-sm text-body-sm text-secondary max-w-max-width-reading">Une équipe de coachs et de professionnel·les RH qui connaissent les attentes réelles des recruteurs.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
      <?php foreach ($equipe as $i => $m): ?>
      <div class="reveal-up bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex items-center gap-space-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300" style="transition-delay:<?php echo $i * 100; ?>ms">
        <?php if (!empty($m['photo'])): ?>
        <img src="<?php echo htmlspecialchars($m['photo']); ?>" alt="<?php echo htmlspecialchars($m['nom']); ?>" class="w-14 h-14 rounded-xl object-cover shrink-0">
        <?php else: ?>
        <div class="w-14 h-14 rounded-xl <?php echo $m['couleur']; ?> text-white flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0"><?php echo htmlspecialchars($m['initiales']); ?></div>
        <?php endif; ?>
        <div class="flex flex-col gap-space-2xs min-w-0">
          <p class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($m['nom']); ?></p>
          <p class="font-body-sm text-[11px] text-primary truncate"><?php echo htmlspecialchars($m['role']); ?></p>
          <div class="flex flex-wrap gap-space-2xs">
            <?php foreach ($m['tags'] as $tag): ?>
            <span class="font-label-md text-[10px] px-space-xs py-0.5 rounded-full bg-surface-container text-secondary"><?php echo htmlspecialchars($tag); ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Avis clients -->
<section class="w-full bg-[#0B192C] px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[0.75fr_1.6fr] gap-space-xl items-start">

    <div class="reveal-up flex flex-col gap-space-sm lg:sticky lg:top-24">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#F0A83E] font-bold">Ils en parlent</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-white font-bold">Témoignages de nos candidat·es <span class="italic text-[#8FB2FF]">satisfait·es</span></h2>
      <p class="font-body-md text-body-md text-white/60">Nos candidat·es sont au cœur de tout ce que nous faisons. Leur réussite est notre priorité.</p>
    </div>

    <div class="[column-fill:_balance] columns-1 sm:columns-2 lg:columns-3 gap-space-md">
      <?php foreach ($temoignages as $i => $t): ?>
      <div class="reveal-up break-inside-avoid mb-space-md rounded-xl p-space-md bg-white/5 border border-white/10 flex flex-col gap-space-sm hover:bg-white/[0.08] hover:-translate-y-1 transition-all duration-300" style="transition-delay:<?php echo ($i % 3) * 110; ?>ms">
        <div class="flex items-center gap-space-xs">
          <div class="w-10 h-10 rounded-full <?php echo $t['couleur']; ?> flex items-center justify-center font-label-lg text-label-lg font-bold text-white shrink-0"><?php echo htmlspecialchars($t['initiales']); ?></div>
          <div class="min-w-0">
            <p class="font-label-lg text-label-lg text-white font-bold leading-tight truncate"><?php echo htmlspecialchars($t['nom']); ?></p>
            <p class="font-body-sm text-[10px] text-white/50 truncate"><?php echo htmlspecialchars($t['role']); ?></p>
          </div>
        </div>
        <p class="font-body-sm text-body-sm text-white/80 leading-relaxed">« <?php echo htmlspecialchars($t['citation']); ?> »</p>
        <span class="text-[#F0A83E] text-[13px] tracking-tight">★★★★★</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Tarif + FAQ -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1fr_1.2fr] gap-space-lg items-start">

    <div class="reveal-left bg-[#0B192C] text-white rounded-2xl p-space-lg flex flex-col gap-space-sm hover:shadow-2xl transition-shadow duration-300">
      <h3 class="font-headline-lg text-headline-lg font-bold">Pack Classic</h3>
      <p class="font-body-sm text-body-sm text-white/70">Un accompagnement complet pour optimiser votre candidature et préparer vos entretiens.</p>
      <ul class="flex flex-col gap-space-xs pt-space-xs">
        <li class="flex items-start gap-space-xs font-body-sm text-body-sm"><span class="material-symbols-outlined text-[16px] text-[#F0A83E] mt-0.5">check</span>Bilan de parcours approfondi avec un coach</li>
        <li class="flex items-start gap-space-xs font-body-sm text-body-sm"><span class="material-symbols-outlined text-[16px] text-[#F0A83E] mt-0.5">check</span>CV et lettre de motivation entièrement retravaillés</li>
        <li class="flex items-start gap-space-xs font-body-sm text-body-sm"><span class="material-symbols-outlined text-[16px] text-[#F0A83E] mt-0.5">check</span>Profil LinkedIn optimisé</li>
        <li class="flex items-start gap-space-xs font-body-sm text-body-sm"><span class="material-symbols-outlined text-[16px] text-[#F0A83E] mt-0.5">check</span>Simulation d'entretien personnalisée</li>
        <li class="flex items-start gap-space-xs font-body-sm text-body-sm"><span class="material-symbols-outlined text-[16px] text-[#F0A83E] mt-0.5">check</span>Accès à votre tableau de bord candidat</li>
      </ul>
      <a href="<?php echo htmlspecialchars(step_url(2, 'classic')); ?>" class="h-12 px-space-lg rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 mt-space-xs">
        <span>Réserver mon accompagnement</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
      <p class="font-body-sm text-[10px] text-white/50 flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[14px]">lock</span>Paiement sécurisé · Sans engagement</p>
    </div>

    <div class="reveal-right flex flex-col gap-space-sm">
      <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Questions fréquentes</h3>
      <div class="flex flex-col gap-space-sm" id="faqAccordion">
        <?php foreach ($faq as $i => $item): ?>
        <div class="reveal-up accordion-item bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow duration-300" style="transition-delay:<?php echo $i * 80; ?>ms">
          <button type="button" class="accordion-trigger w-full flex items-center gap-space-sm p-space-md text-left">
            <span class="flex-1 font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($item['q']); ?></span>
            <span class="material-symbols-outlined text-[20px] text-secondary accordion-chevron transition-transform">expand_more</span>
          </button>
          <div class="accordion-panel px-space-md pb-space-md" style="display:none">
            <p class="font-body-sm text-body-sm text-secondary leading-relaxed"><?php echo htmlspecialchars($item['r']); ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- CTA final -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="reveal-scale max-w-max-width-content mx-auto rounded-2xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white p-space-lg md:p-space-xl flex flex-col md:flex-row md:items-center gap-space-md md:justify-between">
    <div class="flex flex-col gap-space-2xs">
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-lg md:text-headline-lg font-bold max-w-lg">Votre prochaine opportunité commence par une candidature qui vous ressemble.</h2>
      <p class="font-body-sm text-body-sm text-white/80">Un premier échange pour faire le point, sans engagement.</p>
    </div>
    <a href="<?php echo htmlspecialchars(step_url(2, 'classic')); ?>" class="shrink-0 h-12 px-space-lg rounded-xl bg-white text-primary font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
      <span>Je me lance</span>
      <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
    </a>
  </div>
</section>

<script>
(function () {
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

  // --- FAQ ---
  document.querySelectorAll('.accordion-trigger').forEach(function (trigger) {
    var panel = trigger.nextElementSibling;
    var chevron = trigger.querySelector('.accordion-chevron');
    trigger.addEventListener('click', function () {
      var isOpen = panel.style.display === 'block';
      panel.style.display = isOpen ? 'none' : 'block';
      chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
    });
  });

  // --- Vidéo / audio pas encore disponibles ---
  var videoBtn = document.getElementById('videoPlaceholderBtn');
  if (videoBtn) {
    videoBtn.addEventListener('click', function () {
      videoBtn.classList.add('ring-2', 'ring-primary');
      setTimeout(function () { videoBtn.classList.remove('ring-2', 'ring-primary'); }, 600);
    });
  }

  // --- Quiz "Faites le point sur votre candidature" (5 questions) ---
  var QUESTIONS = [
    {
      texte: "Votre CV met-il en avant des résultats concrets (chiffres, missions réussies) ?",
      options: [
        { label: 'Oui, clairement, avec des chiffres précis', score: 2 },
        { label: 'En partie, pour certaines expériences seulement', score: 1 },
        { label: 'Non, il reste assez général', score: 0 }
      ]
    },
    {
      texte: 'Structurez-vous vos expériences de façon claire et chronologique ?',
      options: [
        { label: "Oui, c'est lisible et bien organisé", score: 2 },
        { label: 'Plus ou moins, ça pourrait être clarifié', score: 1 },
        { label: "Non, c'est assez confus", score: 0 }
      ]
    },
    {
      texte: 'Votre lettre de motivation est-elle adaptée à chaque offre ?',
      options: [
        { label: 'Oui, je la personnalise à chaque fois', score: 2 },
        { label: "J'utilise une base que j'ajuste un peu", score: 1 },
        { label: "J'envoie souvent la même lettre", score: 0 }
      ]
    },
    {
      texte: 'Vous sentez-vous à l\'aise pour parler de votre parcours en entretien ?',
      options: [
        { label: 'Oui, je sais valoriser mes expériences', score: 2 },
        { label: "Un peu, mais je manque parfois d'assurance", score: 1 },
        { label: 'Non, je perds souvent mes moyens', score: 0 }
      ]
    },
    {
      texte: 'Savez-vous expliquer ce qui vous différencie des autres candidats ?',
      options: [
        { label: 'Oui, clairement', score: 2 },
        { label: 'Pas toujours facilement', score: 1 },
        { label: 'Non, pas vraiment', score: 0 }
      ]
    }
  ];

  var etape = 0;
  var reponses = new Array(QUESTIONS.length).fill(null);
  var zoneQuestions = document.getElementById('quizQuestions');
  var zoneResultat = document.getElementById('quizResult');
  var badge = document.getElementById('quizProgressBadge');
  var barre = document.getElementById('quizBar');
  var boutonSuivant = document.getElementById('quizNextBtn');
  var labelSuivant = document.getElementById('quizNextLabel');
  var inscriptionUrl = <?php echo json_encode(step_url(2, 'classic')); ?>;

  function afficherQuestion() {
    var q = QUESTIONS[etape];
    badge.textContent = 'Question ' + (etape + 1) + '/' + QUESTIONS.length;
    barre.style.width = (((etape + 1) / QUESTIONS.length) * 100) + '%';

    var html = '<h3 class="font-headline-sm text-headline-sm text-white font-bold leading-snug mb-space-sm">' + q.texte + '</h3>';
    html += '<div class="flex flex-col gap-space-xs">';
    q.options.forEach(function (opt, i) {
      var selected = reponses[etape] === i;
      html += '<button type="button" data-option="' + i + '" class="quiz-option w-full text-left px-space-sm py-space-xs rounded-xl border transition-colors flex items-center gap-space-xs '
        + (selected ? 'bg-white/15 border-[#F0A83E]' : 'bg-white/5 border-white/10 hover:bg-white/10') + '">'
        + '<span class="w-4 h-4 rounded-full border-2 ' + (selected ? 'border-[#F0A83E] bg-[#F0A83E]' : 'border-white/40') + ' shrink-0"></span>'
        + '<span class="font-body-sm text-body-sm text-white">' + opt.label + '</span>'
        + '</button>';
    });
    html += '</div>';
    zoneQuestions.innerHTML = html;
    zoneQuestions.classList.remove('hidden');
    zoneQuestions.style.display = '';
    zoneResultat.classList.add('hidden');
    zoneResultat.style.display = 'none';

    zoneQuestions.querySelectorAll('.quiz-option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        reponses[etape] = parseInt(btn.getAttribute('data-option'), 10);
        boutonSuivant.disabled = false;
        afficherQuestion();
      });
    });

    boutonSuivant.disabled = reponses[etape] === null;
    labelSuivant.textContent = etape === QUESTIONS.length - 1 ? 'Voir mon résultat' : 'Continuer';
  }

  function afficherResultat() {
    var total = reponses.reduce(function (s, v) { return s + (v !== null ? (2 - v) : 0); }, 0);
    var sur10 = reponses.reduce(function (s, v, i) { return s + (v !== null ? QUESTIONS[i].options[v].score : 0); }, 0);

    var niveau, message;
    if (sur10 >= 8) {
      niveau = 'Candidature déjà solide';
      message = "Votre candidature est plutôt bien construite. Un accompagnement ciblé peut encore faire la différence sur les détails qui comptent face aux recruteurs.";
    } else if (sur10 >= 4) {
      niveau = 'Du potentiel à révéler';
      message = "Votre candidature a de bonnes bases, mais plusieurs points méritent d'être retravaillés pour convaincre davantage de recruteurs.";
    } else {
      niveau = 'Un vrai gain à aller chercher';
      message = "Votre candidature gagnerait beaucoup à être retravaillée en profondeur : c'est exactement ce que le Pack Classic est fait pour vous aider à faire.";
    }

    badge.textContent = 'Résultat';
    barre.style.width = '100%';
    zoneQuestions.classList.add('hidden');
    zoneQuestions.style.display = 'none';

    zoneResultat.innerHTML =
      '<div class="flex flex-col gap-space-sm">'
      + '<span class="inline-flex self-start items-center px-space-sm py-1 rounded-full bg-[#F0A83E]/20 text-[#F0A83E] font-label-md text-label-md font-bold">' + niveau + '</span>'
      + '<p class="font-body-sm text-body-sm text-white leading-relaxed">' + message + '</p>'
      + '<a href="' + inscriptionUrl + '" class="h-11 px-space-lg rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:opacity-90 transition-opacity w-fit">'
      + '<span>Commencer mon Pack Classic</span>'
      + '<span class="material-symbols-outlined text-[18px]">arrow_forward</span>'
      + '</a>'
      + '</div>';
    zoneResultat.classList.remove('hidden');
    zoneResultat.style.display = 'flex';
    boutonSuivant.classList.add('hidden');
  }

  boutonSuivant.addEventListener('click', function () {
    if (reponses[etape] === null) return;
    if (etape < QUESTIONS.length - 1) {
      etape++;
      afficherQuestion();
    } else {
      afficherResultat();
    }
  });

  afficherQuestion();
})();
</script>

<?php render_footer(); ?>
