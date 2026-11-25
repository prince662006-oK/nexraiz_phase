<?php
require __DIR__ . '/includes/bootstrap.php';

// Identité visuelle dédiée au Pack Assistance RH (maquette "Cabinet
// d'excellence" fournie par la patronne), simplifiée à une seule famille de
// bleus NexRaiz pour une palette plus sobre et douce : bleu marine #13203F
// (fonds sombres), bleu NexRaiz #2563EB (identique au bouton "Commencer" du
// site — accents, CTA), bleu clair #93C5FD / #60A5FA (accents sur fond
// sombre), fond de page #F8F9FF, surfaces #EFF4FF / #E5EEFF / #DCE9FF,
// texte #0B1C30, texte secondaire #444653.

$situations = [
    ['slug' => 'negociation', 'icone' => 'account_balance_wallet', 'label' => 'Négociation salariale', 'texte' => "Faire le point sur vos arguments et préparer sereinement la discussion."],
    ['slug' => 'rupture', 'icone' => 'handshake', 'label' => 'Rupture conventionnelle', 'texte' => 'Comprendre vos options et construire une démarche adaptée à votre situation.'],
    ['slug' => 'conflit', 'icone' => 'groups', 'label' => "Conflit avec l'employeur", 'texte' => 'Prendre du recul et préparer un échange apaisé avec votre hiérarchie.'],
    ['slug' => 'harcelement', 'icone' => 'front_hand', 'label' => 'Harcèlement présumé', 'texte' => 'Être écouté·e sans jugement et documenter chaque élément important.'],
    ['slug' => 'licenciement', 'icone' => 'description', 'label' => 'Licenciement contesté', 'texte' => 'Structurer votre dossier et vos démarches, étape par étape.'],
    ['slug' => 'autre', 'icone' => 'explore', 'label' => 'Autre situation', 'texte' => "Votre situation ne rentre dans aucune case ? Parlons-en simplement."],
];

$piliers = [
    ['icone' => 'support_agent', 'titre' => 'Un consultant dédié à votre écoute', 'texte' => "NexRaiz constitue votre interlocuteur unique pour avancer sereinement face à une situation professionnelle délicate : écoute, analyse de votre situation et préparation de vos échanges, à votre rythme.", 'lien' => 'En savoir plus sur notre accompagnement'],
    ['icone' => 'volunteer_activism', 'titre' => 'Une approche résolument humaine et bienveillante', 'texte' => "Nos intervenants sont formés à l'écoute et aux relations de travail. Ils assurent un climat de confiance, sans jugement, pour vous aider à reprendre la main sur votre situation.", 'lien' => 'Découvrir nos intervenants'],
    ['icone' => 'route', 'titre' => 'Une méthode claire en 4 étapes', 'texte' => "Diagnostic, analyse, accompagnement, plan d'action : un parcours structuré et transparent, pour savoir à tout moment où vous en êtes.", 'lien' => "Voir le déroulement de l'accompagnement"],
];

$etapesAccompagnement = [
    ['titre' => 'Diagnostic', 'texte' => 'Nous faisons le point ensemble sur votre situation, en toute confidentialité.', 'icone' => 'search'],
    ['titre' => 'Analyse', 'texte' => "Nous évaluons les risques, l'urgence et les leviers possibles.", 'icone' => 'troubleshoot'],
    ['titre' => 'Accompagnement', 'texte' => 'Nous vous accompagnons dans vos échanges et vos démarches.', 'icone' => 'forum'],
    ['titre' => "Plan d'action", 'texte' => 'Vous repartez avec un plan clair et des prochaines étapes concrètes.', 'icone' => 'flag'],
];

$intervenants = [
    ['nom' => 'Akissi Brou', 'role' => 'Conseillère RH', 'specialite' => 'Écoute et orientation', 'photo' => 'img1_assistance/distinguished_executive_portrait_a_confident_warm_mature_c_level_black.png'],
    ['nom' => 'Jean-Marc Koffi', 'role' => 'Médiation & relations sociales', 'specialite' => 'Conflits et négociation', 'photo' => 'img1_assistance/corporate_portrait_and_discussion_two_executive_colleagues_a_business_woman_in.png'],
    ['nom' => 'Réseau partenaire', 'role' => 'Avocats, juristes, psychologues', 'specialite' => 'Orientation professionnelle', 'photo' => null],
];

$temoignages = [
    ['photo' => 'img_assistance/temoin-1.png', 'nom' => 'Koffi Yann', 'role' => 'Candidat satisfait', 'texte' => "Grâce au Pack Assistance, j'ai pu suivre mon dossier facilement et recevoir mes conseils rapidement. Une équipe au top !"],
    ['photo' => 'img_assistance/temoin-2.png', 'nom' => 'Aminata Traoré', 'role' => 'Candidate satisfaite', 'texte' => 'Un accompagnement professionnel et bienveillant. Je me sens vraiment guidée dans ma carrière.'],
    ['photo' => 'img_assistance/temoin-3.png', 'nom' => 'Diomandé Ibrahim', 'role' => 'Candidat satisfait', 'texte' => "Le tableau de bord est très pratique et l'équipe est toujours disponible. Merci NexRaiz !"],
];

render_head('Pack Assistance RH — Découverte', "Le Pack Assistance RH NexRaiz vous écoute, vous rassure et vous guide pas à pas face à une difficulté professionnelle, en toute confidentialité.");
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
@keyframes pa-pulse{0%,100%{box-shadow:0 0 0 0 rgba(37,99,235,.4);}50%{box-shadow:0 0 0 7px rgba(37,99,235,0);}}
.pa-pulse{animation:pa-pulse 2.4s ease-in-out infinite;}
.pa-photo-tile{transform:rotate(-1deg);transition:transform .5s ease;}
.pa-photo-tile:hover{transform:rotate(0deg);}
@media (prefers-reduced-motion:reduce){.pa-pulse{animation:none;}.pa-photo-tile{transition:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-[#F8F9FF] min-h-[calc(100vh-5rem)]">

<!-- Bandeau utilitaire : urgence + confidentialité -->
<section class="w-full bg-[#1E3650] text-white px-gutter-mobile lg:px-gutter-desktop py-space-xs">
  <div class="max-w-max-width-content mx-auto flex flex-wrap items-center justify-between gap-space-sm font-body-sm text-[12px]">
    <span class="flex items-center gap-space-xs flex-wrap">
      <span class="material-symbols-outlined text-[16px] text-[#93C5FD]">support_agent</span>
      <span class="font-label-md font-bold">Ligne d'écoute :</span>
      <span>[numéro d'urgence local]</span>
      <span class="text-white/30">|</span>
      <span class="hidden sm:inline text-white/70">Pack Assistance RH · Écoute, orientation et accompagnement confidentiel</span>
    </span>
    <a href="mon-espace.php" class="flex items-center gap-space-2xs text-white/80 hover:text-white transition-colors">
      <span class="material-symbols-outlined text-[16px]">lock</span>
      <span class="font-label-md text-[11px]">Espace client sécurisé</span>
    </a>
  </div>
</section>

<!-- HERO -->
<section class="relative w-full overflow-hidden bg-[#13203F] text-white">
  <div class="absolute -right-24 -top-24 w-96 h-96 rounded-full bg-[#2563EB]/40 blur-3xl pointer-events-none"></div>
  <div class="absolute -left-20 bottom-0 w-80 h-80 rounded-full bg-[#2563EB]/10 blur-2xl pointer-events-none"></div>
  <div class="relative z-10 max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop pt-space-xl pb-space-2xl">
    <nav aria-label="Fil d'ariane" class="reveal-up is-visible flex items-center gap-space-2xs font-body-sm text-body-sm text-white/60 flex-wrap pb-space-md">
      <a href="index.php#accompagnements" class="hover:text-white transition-colors">Nos Packs</a>
      <span class="material-symbols-outlined text-[16px] text-white/30">chevron_right</span>
      <span class="text-white font-semibold">Pack Assistance RH</span>
    </nav>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center">
      <div class="lg:col-span-7 reveal-left is-visible flex flex-col items-start gap-space-md">
        <div class="inline-flex items-center gap-space-xs px-space-sm py-1.5 rounded-full bg-white/10 backdrop-blur-md">
          <span class="w-2 h-2 rounded-full bg-[#2563EB]"></span>
          <span class="font-label-md text-[11px] tracking-wide text-white/90 uppercase">Pack Assistance RH · NexRaiz</span>
        </div>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-white font-bold tracking-tight leading-[1.1]">
          Une difficulté au travail ?<br>Vous n'avez pas à l'affronter <span class="text-[#93C5FD]">seul·e</span>.
        </h1>
        <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-white/75 max-w-2xl leading-relaxed">
          NexRaiz vous écoute, vous aide à y voir clair et vous guide pas à pas face à une situation professionnelle sensible : négociation, conflit, harcèlement présumé ou licenciement contesté — en toute confidentialité.
        </p>

        <div class="w-full flex flex-col sm:flex-row items-stretch sm:items-center gap-space-md pt-space-xs">
          <div class="inline-flex p-1 bg-[#2563EB] rounded-xl shadow-inner" id="heroToggle">
            <button type="button" class="px-space-md py-2.5 rounded-lg font-label-lg text-label-lg bg-white text-[#13203F] shadow-sm transition-all duration-200" data-audience="salarie" onclick="selectHeroAudience('salarie')" id="toggle-salarie">Pour vous-même</button>
            <button type="button" class="px-space-md py-2.5 rounded-lg font-label-lg text-label-lg text-white/80 hover:text-white transition-all duration-200" data-audience="rh" onclick="selectHeroAudience('rh')" id="toggle-rh">Pour orienter un collaborateur</button>
          </div>
        </div>
        <div class="flex flex-wrap gap-space-sm pt-space-2xs">
          <a href="<?php echo htmlspecialchars(step_url(2, 'assistance')); ?>" class="pa-pulse h-12 px-space-lg rounded-lg bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-[0_4px_16px_rgba(37,99,235,0.35)] hover:bg-[#1d4ed8] hover:-translate-y-0.5 transition-all duration-200">
            <span>Échanger avec un conseiller</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
          </a>
        </div>

        <div class="pt-space-sm flex flex-wrap items-center gap-space-lg text-white/80 font-body-sm text-[13px]">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[#2563EB] text-[18px]">lock</span>100 % confidentiel</span>
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[#2563EB] text-[18px]">spa</span>Écoute sans jugement</span>
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[#2563EB] text-[18px]">verified_user</span>Conforme au RGPD</span>
        </div>
      </div>

      <div class="lg:col-span-5 reveal-right is-visible relative mt-space-md lg:mt-0 flex justify-center lg:justify-end">
        <div class="relative w-full max-w-lg aspect-[4/3]">
          <div class="pa-photo-tile absolute inset-0 rounded-2xl overflow-hidden shadow-2xl bg-white/5">
            <img src="img1_assistance/high_end_corporate_professional_photography_two_cheerful_colleagues_or_career.png" alt="Échange entre une conseillère NexRaiz et une personne accompagnée" class="w-full h-full object-cover">
            <div class="absolute inset-0 bg-gradient-to-t from-[#13203F]/50 via-transparent to-transparent"></div>
          </div>
          <div class="absolute -bottom-6 -left-4 sm:left-4 bg-white text-[#0B1C30] p-space-md rounded-xl shadow-[0_12px_32px_rgba(11,28,48,0.25)] max-w-xs flex items-center gap-space-sm z-20">
            <div class="w-12 h-12 rounded-lg bg-[#EFF4FF] flex items-center justify-center text-[#13203F] shrink-0">
              <span class="material-symbols-outlined text-[26px]">psychology</span>
            </div>
            <div class="flex flex-col">
              <span class="font-label-lg text-label-lg font-bold text-[#13203F]">Accompagnement humain</span>
              <span class="font-body-sm text-[12px] text-[#444653]">Écoute, orientation et confidentialité</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php render_stepper('assistance', 1); ?>

<!-- Module d'orientation rapide -->
<section class="relative z-30 max-w-6xl mx-auto px-gutter-mobile lg:px-gutter-desktop pt-space-lg pb-space-sm w-full">
  <div class="reveal-up is-visible bg-white rounded-2xl p-space-lg shadow-[0_10px_35px_rgba(11,28,48,0.1)]">
    <div class="mb-space-sm text-center">
      <h2 class="font-headline-sm text-headline-sm text-[#0B1C30] font-bold">Dites-nous en quelques mots ce qui vous amène</h2>
      <p class="font-body-sm text-body-sm text-[#444653] mt-1">Nous vous mettons en relation avec le bon conseiller, rapidement et en toute confidentialité.</p>
    </div>
    <form class="mt-space-md grid grid-cols-1 md:grid-cols-12 gap-space-sm items-center" onsubmit="event.preventDefault(); window.location.href='<?php echo htmlspecialchars(step_url(2, 'assistance')); ?>';">
      <div class="md:col-span-9 relative">
        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[#444653] text-[20px]">search</span>
        <input class="w-full h-12 pl-11 pr-4 rounded-lg bg-[#F8F9FF] text-[#0B1C30] placeholder:text-[#444653] font-body-md text-body-md focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#13203F] transition-all" placeholder="Décrivez votre situation en quelques mots..." type="text">
      </div>
      <div class="md:col-span-3">
        <button type="submit" class="w-full h-12 rounded-lg bg-[#13203F] hover:bg-[#2563EB] text-white font-label-lg text-label-lg transition-colors flex items-center justify-center gap-space-xs shadow-md">
          <span>Être orienté·e</span>
        </button>
      </div>
    </form>
    <div class="mt-space-md pt-space-sm flex flex-wrap items-center justify-center gap-space-xs text-body-sm text-[#444653]">
      <span class="font-label-sm text-[11px] text-[#0B1C30] font-bold mr-1">Situations fréquentes :</span>
      <?php foreach ($situations as $s): ?>
      <span class="px-2.5 py-1 rounded-full bg-[#EFF4FF] text-[#0B1C30] font-body-sm text-[12px]"><?php echo htmlspecialchars($s['label']); ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- 3 Piliers -->
<section class="w-full max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="text-center max-w-3xl mx-auto mb-space-xl">
    <span class="font-label-md text-label-md text-[#2563EB] uppercase tracking-wider font-bold">Notre méthode</span>
    <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#0B1C30] font-bold tracking-tight mt-2">Un accompagnement humain, structuré et confidentiel</h2>
  </div>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
    <?php
    $fondsPiliers = ['bg-[#13203F]', 'bg-[#1E3650]', 'bg-[#2563EB]'];
    foreach ($piliers as $i => $p):
    ?>
    <div class="reveal-up group relative rounded-2xl <?php echo $fondsPiliers[$i % count($fondsPiliers)]; ?> text-white p-space-lg flex flex-col justify-between shadow-[0_4px_20px_rgba(0,40,142,0.15)] hover:shadow-[0_12px_32px_rgba(0,40,142,0.25)] hover:-translate-y-1.5 transition-all duration-300" style="transition-delay:<?php echo $i * 90; ?>ms">
      <div>
        <div class="w-14 h-14 rounded-xl bg-white/15 flex items-center justify-center text-white mb-space-lg group-hover:scale-110 transition-transform">
          <span class="material-symbols-outlined text-[30px]"><?php echo $p['icone']; ?></span>
        </div>
        <h3 class="font-headline-sm text-headline-sm text-white font-bold mb-space-sm"><?php echo htmlspecialchars($p['titre']); ?></h3>
        <p class="font-body-md text-body-md text-white/80 leading-relaxed"><?php echo htmlspecialchars($p['texte']); ?></p>
      </div>
      <div class="pt-space-lg">
        <span class="inline-flex items-center gap-1 font-label-md text-label-md text-[#93C5FD] font-semibold group-hover:translate-x-1 transition-transform">
          <?php echo htmlspecialchars($p['lien']); ?> <span class="material-symbols-outlined text-[16px]">chevron_right</span>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Bloc audience 1 : salarié·e -->
<section class="w-full bg-[#EFF4FF] py-space-2xl">
  <div class="max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center">
      <div class="lg:col-span-6 order-2 lg:order-1 reveal-left flex flex-col items-start gap-space-md">
        <span class="font-label-md text-label-md text-[#13203F] uppercase font-bold tracking-wider">Vous vivez une situation difficile</span>
        <h2 class="font-headline-lg text-headline-lg text-[#0B1C30] font-bold tracking-tight">Reprenez la main sur votre situation</h2>
        <p class="font-body-lg text-body-lg text-[#444653] leading-relaxed">
          Un changement ou une difficulté au travail ne doit jamais être subi seul·e. Que vous viviez une négociation délicate, un conflit, une rupture conventionnelle ou un licenciement contesté, un consultant NexRaiz vous aide à construire un plan d'action clair et confidentiel.
        </p>
        <ul class="flex flex-col gap-space-xs font-body-md text-body-md text-[#0B1C30] py-space-2xs">
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#2563EB] text-[18px]">check_circle</span>Diagnostic de votre situation et de vos options</li>
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#2563EB] text-[18px]">check_circle</span>Préparation de vos échanges et de vos documents</li>
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#2563EB] text-[18px]">check_circle</span>Orientation vers un professionnel si nécessaire</li>
        </ul>
        <a href="<?php echo htmlspecialchars(step_url(2, 'assistance')); ?>" class="mt-space-2xs inline-flex items-center gap-space-xs px-space-md py-3 rounded-lg bg-[#13203F] hover:bg-[#2563EB] text-white font-label-lg text-label-lg transition-colors shadow-md">
          <span>Commencer ma demande</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </a>
      </div>
      <div class="lg:col-span-6 order-1 lg:order-2 reveal-right">
        <div class="relative rounded-2xl overflow-hidden shadow-xl aspect-[16/10] bg-white">
          <img src="img1_assistance/corporate_coaching_and_career_transition_photography_a_professional_career.png" alt="Consultant NexRaiz accompagnant une personne dans sa situation professionnelle" class="w-full h-full object-cover hover:scale-105 transition-transform duration-700">
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Bloc audience 2 : managers / RH -->
<section class="w-full py-space-2xl">
  <div class="max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center">
      <div class="lg:col-span-6 reveal-left">
        <div class="relative rounded-2xl overflow-hidden shadow-xl aspect-[16/10] bg-white">
          <img src="img1_assistance/corporate_workshop_and_hr_strategy_meeting_a_dynamic_team_of_diverse_business.png" alt="Équipe RH échangeant sur l'orientation d'un collaborateur" class="w-full h-full object-cover hover:scale-105 transition-transform duration-700">
        </div>
      </div>
      <div class="lg:col-span-6 reveal-right flex flex-col items-start gap-space-md">
        <span class="font-label-md text-label-md text-[#2563EB] uppercase font-bold tracking-wider">Managers & Ressources Humaines</span>
        <h2 class="font-headline-lg text-headline-lg text-[#0B1C30] font-bold tracking-tight">Orientez un collaborateur en toute sérénité</h2>
        <p class="font-body-lg text-body-lg text-[#444653] leading-relaxed">
          Vous accompagnez une équipe ou un collaborateur qui traverse une période difficile ? NexRaiz propose un espace d'écoute confidentiel, indépendant de la ligne hiérarchique, pour l'aider à avancer — sans jamais partager le contenu des échanges sans accord.
        </p>
        <ul class="flex flex-col gap-space-xs font-body-md text-body-md text-[#0B1C30] py-space-2xs">
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#13203F] text-[18px]">check_circle</span>Confidentialité totale vis-à-vis de l'employeur</li>
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#13203F] text-[18px]">check_circle</span>Orientation rapide vers le bon type d'accompagnement</li>
          <li class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[#13203F] text-[18px]">check_circle</span>Un espace neutre, extérieur à l'entreprise</li>
        </ul>
        <a href="contact.php" class="mt-space-2xs inline-flex items-center gap-space-xs px-space-md py-3 rounded-lg bg-[#2563EB] hover:bg-[#1d4ed8] text-white font-label-lg text-label-lg transition-all shadow-[0_4px_14px_rgba(37,99,235,0.3)]">
          <span>Nous contacter</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </a>
      </div>
    </div>
  </div>
</section>

<!-- Quelle situation vous concerne -->
<section class="w-full max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="text-center max-w-3xl mx-auto mb-space-xl">
    <span class="font-label-md text-label-md text-[#2563EB] uppercase font-bold">Vous vous reconnaissez ?</span>
    <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#0B1C30] font-bold mt-2">Quelle situation vous concerne</h2>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-space-md">
    <?php foreach ($situations as $i => $s): ?>
    <a href="<?php echo htmlspecialchars(step_url(2, 'assistance')); ?>" class="reveal-up bg-white p-space-lg rounded-2xl shadow-[0_2px_12px_rgba(11,28,48,0.06)] hover:shadow-lg hover:-translate-y-1 transition-all flex flex-col gap-space-sm" style="transition-delay:<?php echo $i * 60; ?>ms">
      <div class="w-12 h-12 rounded-xl bg-[#EFF4FF] flex items-center justify-center text-[#13203F]">
        <span class="material-symbols-outlined text-[26px]"><?php echo $s['icone']; ?></span>
      </div>
      <h3 class="font-title-md text-title-md text-[#0B1C30] font-bold"><?php echo htmlspecialchars($s['label']); ?></h3>
      <p class="font-body-sm text-body-sm text-[#444653]"><?php echo htmlspecialchars($s['texte']); ?></p>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Ressource gratuite -->
<section class="w-full bg-[#DCE9FF] py-space-xl">
  <div class="max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop">
    <div class="bg-white rounded-2xl shadow-xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 items-center">
      <div class="lg:col-span-5 h-64 lg:h-full relative bg-[#EFF4FF]">
        <img src="img1_assistance/professional_corporate_meeting_in_tech_and_data_analysis_two_professionals.png" alt="Préparer son dossier avec un consultant NexRaiz" class="w-full h-full object-cover">
        <div class="absolute top-4 left-4 bg-[#13203F] text-white px-3 py-1 rounded-md font-label-sm text-[11px] shadow">Ressource gratuite</div>
      </div>
      <div class="lg:col-span-7 p-space-lg lg:p-space-xl flex flex-col items-start">
        <span class="font-label-md text-label-md text-[#2563EB] uppercase font-bold tracking-wide">Carnet « Journal des faits »</span>
        <h2 class="font-headline-lg text-headline-lg text-[#0B1C30] font-bold mt-space-2xs">Gardez une trace claire de ce que vous vivez</h2>
        <p class="font-body-md text-body-md text-[#444653] mt-space-sm max-w-xl leading-relaxed">
          Un support simple pour noter dates, faits et témoins au fur et à mesure — utile pour y voir clair, et pour préparer votre dossier si vous décidez d'aller plus loin.
        </p>
        <a href="ressources-assistance/journal-des-faits.txt" download class="mt-space-lg inline-flex items-center gap-space-xs h-12 px-space-lg rounded-lg bg-[#2563EB] hover:bg-[#1d4ed8] text-white font-label-lg text-label-lg transition-colors shadow-md">
          <span class="material-symbols-outlined text-[18px]">download</span>
          <span>Télécharger gratuitement</span>
        </a>
        <p class="font-body-sm text-body-sm text-[#444653] mt-space-sm">Téléchargement direct, sans inscription.</p>
      </div>
    </div>
  </div>
</section>

<!-- Comment se déroule l'accompagnement -->
<section class="w-full bg-[#EFF4FF] py-space-2xl">
  <div class="max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop">
    <div class="text-center max-w-3xl mx-auto mb-space-xl">
      <span class="font-label-md text-label-md text-[#2563EB] uppercase font-bold">Comment se déroule l'accompagnement</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#0B1C30] font-bold mt-2">Un parcours en 4 étapes</h2>
    </div>
    <div class="relative grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-lg">
      <div class="hidden lg:block absolute top-10 left-[12%] right-[12%] h-0 border-t-2 border-dashed border-[#2563EB]/25"></div>
      <?php
      $degradesEtapes = ['from-[#2563EB] to-[#13203F]', 'from-[#60A5FA] to-[#2563EB]', 'from-[#13203F] to-[#0B192C]', 'from-[#2563EB] to-[#1E3650]'];
      foreach ($etapesAccompagnement as $i => $e):
      ?>
      <div class="reveal-up group relative bg-white rounded-2xl p-space-lg pt-space-xl shadow-[0_4px_20px_rgba(11,28,48,0.07)] hover:shadow-[0_16px_36px_rgba(11,28,48,0.14)] hover:-translate-y-2 transition-all duration-300 flex flex-col items-center text-center gap-space-sm" style="transition-delay:<?php echo $i * 90; ?>ms">
        <span class="absolute -top-6 w-12 h-12 rounded-full bg-white shadow-md flex items-center justify-center font-headline-sm text-headline-sm font-bold text-[#13203F] border-2 border-[#EFF4FF] z-10"><?php echo $i + 1; ?></span>
        <div class="relative w-20 h-20 rounded-2xl bg-gradient-to-br <?php echo $degradesEtapes[$i % count($degradesEtapes)]; ?> flex items-center justify-center text-white shadow-lg group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
          <span class="material-symbols-outlined text-[40px]"><?php echo $e['icone']; ?></span>
        </div>
        <h3 class="font-label-lg text-label-lg text-[#0B1C30] font-bold pt-space-2xs"><?php echo htmlspecialchars($e['titre']); ?></h3>
        <p class="font-body-sm text-body-sm text-[#444653] leading-relaxed"><?php echo htmlspecialchars($e['texte']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Vos intervenants -->
<section class="w-full bg-[#13203F] py-space-2xl relative overflow-hidden">
  <div class="absolute -right-24 top-10 w-80 h-80 rounded-full bg-[#2563EB]/20 blur-3xl pointer-events-none"></div>
  <div class="absolute -left-20 bottom-0 w-72 h-72 rounded-full bg-[#2563EB]/10 blur-3xl pointer-events-none"></div>
  <div class="relative max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop flex flex-col gap-space-xl">
    <div class="reveal-up flex flex-col items-center text-center gap-space-2xs">
      <span class="font-label-md text-label-md text-[#2563EB] uppercase font-bold tracking-wider">Vos intervenants</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-white font-bold tracking-tight">Des personnes formées pour vous écouter</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-lg">
      <?php foreach ($intervenants as $i => $it): ?>
      <div class="reveal-scale group relative bg-white/5 backdrop-blur-sm border border-white/10 rounded-2xl p-space-lg pt-space-xl flex flex-col items-center text-center gap-space-xs hover:bg-white/10 hover:-translate-y-2 transition-all duration-300" style="transition-delay:<?php echo $i * 100; ?>ms">
        <?php if ($it['photo']): ?>
        <div class="relative w-28 h-28 rounded-full p-1 bg-gradient-to-br from-[#60A5FA] to-[#2563EB] group-hover:scale-105 transition-transform duration-300">
          <img src="<?php echo htmlspecialchars($it['photo']); ?>" alt="Photo d'illustration — <?php echo htmlspecialchars($it['role']); ?>" class="w-full h-full rounded-full object-cover ring-4 ring-[#13203F]">
        </div>
        <?php else: ?>
        <div class="w-28 h-28 rounded-full p-1 bg-gradient-to-br from-[#60A5FA] to-[#2563EB] group-hover:scale-105 transition-transform duration-300">
          <span class="w-full h-full rounded-full bg-[#1E3650] ring-4 ring-[#13203F] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[44px]">diversity_3</span></span>
        </div>
        <?php endif; ?>
        <p class="font-headline-sm text-headline-sm text-white font-bold pt-space-xs"><?php echo htmlspecialchars($it['nom']); ?></p>
        <p class="font-label-lg text-label-lg text-[#93C5FD]"><?php echo htmlspecialchars($it['role']); ?></p>
        <span class="mt-space-2xs inline-flex items-center gap-1 px-space-sm py-1 rounded-full bg-white/10 font-label-md text-[11px] text-white/70"><?php echo htmlspecialchars($it['specialite']); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Témoignages -->
<section class="w-full py-space-2xl">
  <div class="max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop flex flex-col gap-space-lg">
    <div class="reveal-up flex flex-col sm:flex-row sm:items-end sm:justify-between gap-space-sm">
      <div class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-[#13203F] uppercase font-bold">Témoignages</span>
        <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#0B1C30] font-bold tracking-tight">Ils ont fait confiance à NexRaiz</h2>
      </div>
      <a href="#" class="shrink-0 h-11 px-space-md rounded-full border border-[#0B1C30]/15 text-[#0B1C30] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-[#F8F9FF] transition-all duration-200">
        Voir plus d'avis <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
      <?php foreach ($temoignages as $i => $t): ?>
      <div class="reveal-scale group relative bg-white rounded-2xl p-space-xl pt-space-2xl shadow-[0_4px_20px_rgba(11,28,48,0.07)] hover:shadow-[0_16px_40px_rgba(11,28,48,0.16)] hover:-translate-y-2 transition-all duration-300 flex flex-col items-center text-center gap-space-sm" style="transition-delay:<?php echo $i * 100; ?>ms">
        <span class="absolute top-space-md left-space-md material-symbols-outlined text-[36px] text-[#EFF4FF] group-hover:text-[#DBEAFE] transition-colors duration-300">format_quote</span>
        <div class="relative w-20 h-20 rounded-full p-1 bg-gradient-to-br from-[#2563EB] to-[#60A5FA] group-hover:scale-110 transition-transform duration-300">
          <img src="<?php echo htmlspecialchars($t['photo']); ?>" alt="<?php echo htmlspecialchars($t['nom']); ?>" class="w-full h-full rounded-full object-cover ring-4 ring-white">
        </div>
        <span class="text-[#FACC15] text-[16px] tracking-wide">★★★★★</span>
        <p class="font-body-sm text-body-sm text-[#0B1C30] leading-relaxed">« <?php echo htmlspecialchars($t['texte']); ?> »</p>
        <div class="pt-space-xs border-t border-[#0B1C30]/8 w-full">
          <p class="font-label-lg text-label-lg text-[#0B1C30] font-bold leading-tight pt-space-sm"><?php echo htmlspecialchars($t['nom']); ?></p>
          <p class="font-body-sm text-[11px] text-[#444653]"><?php echo htmlspecialchars($t['role']); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA finale -->
<section class="w-full bg-[#13203F] text-white relative overflow-hidden">
  <div class="absolute right-0 top-0 w-96 h-full bg-[#2563EB]/30 pointer-events-none transform skew-x-12"></div>
  <div class="max-w-max-width-content mx-auto px-gutter-mobile lg:px-gutter-desktop py-space-2xl relative z-10">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center">
      <div class="lg:col-span-7 reveal-left flex flex-col items-start">
        <span class="font-label-md text-label-md text-[#93C5FD] uppercase tracking-wider font-bold">Contact & écoute confidentielle</span>
        <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:text-display-lg font-bold tracking-tight text-white mt-space-2xs">
          Parler de ce que vous vivez, c'est déjà reprendre la main.
        </h2>
        <p class="font-body-lg text-body-lg text-white/75 mt-space-sm max-w-xl leading-relaxed">
          Un premier échange confidentiel pour faire le point, à votre rythme. [Engagement NexRaiz à définir, par exemple : un premier retour sous 48 h ouvrées]
        </p>
        <div class="mt-space-lg flex flex-wrap gap-space-sm">
          <a href="<?php echo htmlspecialchars(step_url(2, 'assistance')); ?>" class="h-12 px-space-lg rounded-lg bg-white text-[#13203F] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:-translate-y-0.5 transition-all duration-200">
            <span>Je fais le premier pas</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
          </a>
          <a href="comment-ca-marche.php" class="h-12 px-space-lg rounded-lg border border-white/40 text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-white/10 transition-colors">
            Voir le déroulement du pack
          </a>
        </div>
        <div class="flex items-center gap-space-md mt-space-md text-white/80 font-body-sm text-body-sm">
          <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-[#93C5FD]">lock</span>Confidentialité absolue garantie</span>
          <span>•</span>
          <span>Paiement sécurisé · Carte bancaire, Mobile Money ou virement</span>
        </div>
      </div>
      <div class="lg:col-span-5 reveal-right flex justify-center lg:justify-end mt-space-lg lg:mt-0">
        <div class="relative w-full max-w-sm rounded-2xl overflow-hidden shadow-2xl bg-white/5 aspect-[4/5] flex flex-col items-center justify-center gap-space-md border border-white/10">
          <div class="absolute -right-10 -top-10 w-40 h-40 rounded-full bg-[#2563EB]/15 blur-2xl"></div>
          <div class="absolute -left-10 -bottom-10 w-40 h-40 rounded-full bg-[#2563EB]/30 blur-2xl"></div>
          <span class="relative w-20 h-20 rounded-full bg-white/10 flex items-center justify-center"><span class="material-symbols-outlined text-[40px] text-[#93C5FD]">favorite</span></span>
          <p class="relative font-title-md text-title-md font-bold text-white text-center px-space-lg">Pack Assistance RH — NexRaiz</p>
          <p class="relative font-body-sm text-body-sm text-white/70">Raiz People, Raiz Teams</p>
        </div>
      </div>
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

function selectHeroAudience(type) {
  var btnSalarie = document.getElementById('toggle-salarie');
  var btnRh = document.getElementById('toggle-rh');
  if (type === 'salarie') {
    btnSalarie.className = "px-space-md py-2.5 rounded-lg font-label-lg text-label-lg bg-white text-[#13203F] shadow-sm transition-all duration-200";
    btnRh.className = "px-space-md py-2.5 rounded-lg font-label-lg text-label-lg text-white/80 hover:text-white transition-all duration-200";
  } else {
    btnRh.className = "px-space-md py-2.5 rounded-lg font-label-lg text-label-lg bg-white text-[#13203F] shadow-sm transition-all duration-200";
    btnSalarie.className = "px-space-md py-2.5 rounded-lg font-label-lg text-label-lg text-white/80 hover:text-white transition-all duration-200";
  }
}
</script>

<?php render_footer(); ?>
