<?php
require __DIR__ . '/includes/bootstrap.php';

$etapes = [
    ['icone' => 'person', 'titre' => 'Créer un compte', 'texte' => "Choisissez le pack qui correspond à votre besoin et créez votre espace client en quelques minutes. Vos informations restent confidentielles et sécurisées."],
    ['icone' => 'assignment', 'titre' => 'Qualifier le besoin', 'texte' => "Répondez à un court formulaire pour nous aider à comprendre votre situation, vos objectifs et vos priorités. Cela permet à votre consultant de préparer un accompagnement sur-mesure."],
    ['icone' => 'calendar_month', 'titre' => 'Prendre rendez-vous', 'texte' => "Choisissez une date, une heure et un format (en ligne, en présentiel ou hybride) qui vous conviennent. Un consultant NexRaiz vous est assigné selon votre besoin."],
    ['icone' => 'credit_card', 'titre' => 'Effectuer le paiement', 'texte' => "Réglez votre accompagnement en toute sécurité (carte bancaire, mobile money ou virement), et recevez immédiatement votre confirmation de rendez-vous."],
    ['icone' => 'show_chart', 'titre' => 'Suivre son dossier', 'texte' => "Accédez à votre tableau de bord pour suivre l'avancement de votre parcours, échanger avec votre consultant et retrouver tous vos documents et livrables."],
];

$faq = [
    ['q' => 'Combien de temps dure un accompagnement NexRaiz ?', 'r' => "Cela dépend du pack choisi : de quelques jours pour le Pack Assistance à plusieurs semaines pour un accompagnement Booster ou Wellbeing plus complet. Le rythme est toujours adapté à votre disponibilité."],
    ['q' => 'Mes informations sont-elles confidentielles ?', 'r' => "Oui. Toutes les informations que vous partagez sont strictement confidentielles et ne sont accessibles qu'aux équipes en charge de votre accompagnement, jamais partagées avec des tiers sans votre accord."],
    ['q' => 'Puis-je changer de pack en cours de route ?', 'r' => "Oui, si votre situation évolue, votre consultant peut vous orienter vers un pack plus adapté. Contactez notre équipe pour en discuter."],
    ['q' => "Que se passe-t-il si je dois annuler ou reporter mon rendez-vous ?", 'r' => "Vous pouvez reporter ou annuler gratuitement jusqu'à 24h avant votre rendez-vous depuis votre espace client. Passé ce délai, des conditions spécifiques à chaque pack peuvent s'appliquer."],
    ['q' => 'NexRaiz remplace-t-il un avocat ou un professionnel de santé ?', 'r' => "Non. NexRaiz propose de l'information, de l'orientation et du coaching. En cas de besoin spécifique (juridique, médical, psychologique), nous vous orientons vers un professionnel qualifié."],
];

render_head('Comment ça marche — NexRaiz', 'Découvrez les 5 étapes du parcours NexRaiz, de la création de votre compte au suivi de votre dossier.');
render_top_header('marche');
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl md:py-space-2xl relative overflow-hidden">
  <div class="absolute -top-12 -right-12 w-72 h-72 rounded-full bg-primary-container/5 blur-3xl pointer-events-none"></div>
  <div class="max-w-max-width-content mx-auto flex flex-col items-center text-center gap-space-md relative z-10">
    <span class="inline-flex items-center px-space-sm py-1.5 rounded-full bg-primary-container/10 text-primary font-label-md text-label-md font-bold">Notre méthode</span>
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-on-surface font-bold tracking-tight max-w-3xl">Comment ça marche ?</h1>
    <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-secondary max-w-max-width-reading">Un parcours simple et sécurisé, pensé pour vous accompagner à chaque étape : de la création de votre compte jusqu'au suivi de votre dossier.</p>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">
    <?php foreach ($etapes as $i => $e): ?>
    <div class="grid grid-cols-1 md:grid-cols-[auto_1fr] gap-space-md items-start bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <div class="flex md:flex-col items-center gap-space-sm md:gap-space-xs">
        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white flex items-center justify-center font-headline-sm text-headline-sm font-bold shrink-0"><?php echo $i + 1; ?></div>
        <?php if ($i < count($etapes) - 1): ?><div class="hidden md:block w-0.5 flex-1 min-h-[2rem] bg-outline-variant"></div><?php endif; ?>
      </div>
      <div class="flex flex-col gap-space-2xs">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[22px] text-primary"><?php echo $e['icone']; ?></span>
          <h2 class="font-headline-md text-headline-md text-on-surface font-bold"><?php echo htmlspecialchars($e['titre']); ?></h2>
        </div>
        <p class="font-body-md text-body-md text-secondary leading-relaxed max-w-max-width-reading"><?php echo htmlspecialchars($e['texte']); ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">
    <h2 class="text-center font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Deux espaces complémentaires</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
      <div class="rounded-xl bg-gradient-to-br from-primary-container/10 to-surface-container-lowest p-space-lg flex flex-col gap-space-sm">
        <div class="w-11 h-11 rounded-full bg-primary-container/15 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">person</span></div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Espace Client</h3>
        <p class="font-body-sm text-body-sm text-secondary">Votre espace personnel pour avancer à votre rythme.</p>
        <ul class="flex flex-col gap-space-2xs pt-space-2xs">
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">check</span>Formulaire de qualification</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">check</span>Prise de rendez-vous</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">check</span>Paiement sécurisé</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">check</span>Tableau de bord</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">check</span>Documents &amp; livrables</li>
        </ul>
      </div>
      <div class="rounded-xl bg-gradient-to-br from-blue-100/60 to-surface-container-lowest p-space-lg flex flex-col gap-space-sm">
        <div class="w-11 h-11 rounded-full bg-blue-200/60 text-blue-700 flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">groups</span></div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Espace NexRaiz</h3>
        <p class="font-body-sm text-body-sm text-secondary">L'espace de travail de vos consultants.</p>
        <ul class="flex flex-col gap-space-2xs pt-space-2xs">
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-blue-700">check</span>Gestion des dossiers</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-blue-700">check</span>Évaluations</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-blue-700">check</span>Notes consultant</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-blue-700">check</span>Plan d'action</li>
          <li class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-blue-700">check</span>Suivi &amp; mise à jour</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">
    <h2 class="text-center font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">Questions fréquentes</h2>
    <div class="max-w-max-width-reading mx-auto w-full flex flex-col gap-space-sm" id="faqAccordion">
      <?php foreach ($faq as $item): ?>
      <div class="accordion-item bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden">
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
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white p-space-xl flex flex-col items-center text-center gap-space-sm">
    <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#F0A83E] font-bold">Prêt à vous lancer ?</span>
    <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl font-bold">Prêt à démarrer votre parcours ?</h2>
    <p class="font-body-md text-body-md text-white/80 max-w-md">Choisissez le pack qui correspond à votre besoin et créez votre espace en quelques minutes.</p>
    <div class="flex flex-col sm:flex-row gap-space-sm pt-space-2xs">
      <a href="index.php#accompagnements" class="h-12 px-space-lg rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-lg shadow-black/20 hover:opacity-90 transition-opacity">Découvrir les packs</a>
      <a href="contact.php" class="h-12 px-space-lg rounded-xl border border-white/30 text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-white/10 transition-colors">Nous contacter</a>
    </div>
  </div>
</section>

<script>
(function() {
  document.querySelectorAll('.accordion-trigger').forEach(function(trigger) {
    var panel = trigger.nextElementSibling;
    var chevron = trigger.querySelector('.accordion-chevron');
    trigger.addEventListener('click', function() {
      var isOpen = panel.style.display === 'block';
      panel.style.display = isOpen ? 'none' : 'block';
      chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
    });
  });
})();
</script>
<?php render_footer(); ?>
