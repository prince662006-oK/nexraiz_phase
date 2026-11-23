<?php
require __DIR__ . '/includes/bootstrap.php';

$coordonnees = [
    ['icone' => 'mail', 'titre' => 'Email', 'valeur' => 'support@nexraiz.com'],
    ['icone' => 'call', 'titre' => 'Téléphone', 'valeur' => '01 84 80 00 40', 'note' => 'Lun. – Ven. de 9h à 18h'],
    ['icone' => 'chat', 'titre' => 'Chat en ligne', 'valeur' => 'Accessible depuis votre espace client', 'note' => 'Réponse sous 5 min en moyenne'],
    ['icone' => 'location_on', 'titre' => 'Adresse', 'valeur' => '12 rue de la Paix, 75002 Paris', 'note' => 'Sur rendez-vous uniquement'],
];

$sujets = [
    'Question générale',
    'Question sur un pack',
    'Support technique',
    'Facturation',
    'Partenariat',
    'Autre',
];

render_head('Contact — NexRaiz', 'Une question ? Contactez l\'équipe NexRaiz, nous vous répondons sous 24h.');
render_top_header('contact');
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col items-center text-center gap-space-sm">
    <span class="inline-flex items-center px-space-sm py-1.5 rounded-full bg-primary-container/10 text-primary font-label-md text-label-md font-bold">Contact</span>
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-on-surface font-bold tracking-tight">Contactez-nous</h1>
    <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-secondary max-w-max-width-reading">Une question sur nos packs, votre dossier ou notre méthode ? Notre équipe vous répond sous 24h.</p>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.3fr_1fr] gap-space-lg items-start">

    <div class="flex flex-col gap-space-md">
    <form id="contactForm" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md" novalidate>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Envoyez-nous un message</h2>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nom <span class="text-error">*</span></span>
          <input required type="text" placeholder="Votre nom" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Prénom <span class="text-error">*</span></span>
          <input required type="text" placeholder="Votre prénom" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email <span class="text-error">*</span></span>
          <input required type="email" placeholder="exemple@domaine.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone <span class="font-body-sm text-[12px] text-secondary font-normal">(optionnel)</span></span>
          <input type="tel" placeholder="06 12 34 56 78" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
      </div>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Sujet <span class="text-error">*</span></span>
        <select required class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Sélectionnez un sujet</option>
          <?php foreach ($sujets as $s): ?>
          <option><?php echo htmlspecialchars($s); ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Message <span class="text-error">*</span></span>
        <textarea required rows="5" placeholder="Décrivez votre demande..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
      </label>

      <label class="flex items-start gap-space-xs">
        <input required type="checkbox" class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'accepte que mes informations soient utilisées pour traiter ma demande, conformément à la <a href="#" class="text-primary underline">Politique de confidentialité</a>. <span class="text-error">*</span></span>
      </label>

      <button type="submit" class="h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity">
        <span>Envoyer le message</span>
        <span class="material-symbols-outlined text-[18px]">send</span>
      </button>
    </form>
      <div id="successMessage" class="hidden items-center gap-space-xs bg-green-50 border border-green-200 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[20px] text-green-700">check_circle</span>
        <p class="font-body-sm text-body-sm text-green-800">Votre message a bien été envoyé ! Notre équipe vous répondra sous 24h.</p>
      </div>
    </div>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Nos coordonnées</h3>
        <?php foreach ($coordonnees as $c): ?>
        <div class="flex items-start gap-space-sm py-space-xs">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]"><?php echo $c['icone']; ?></span></div>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($c['titre']); ?></p>
            <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($c['valeur']); ?></p>
            <?php if (!empty($c['note'])): ?><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($c['note']); ?></p><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="bg-primary-container/5 rounded-xl p-space-lg flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs font-label-lg text-label-lg text-on-surface font-bold"><span class="material-symbols-outlined text-[18px] text-primary">support_agent</span>Déjà client NexRaiz ?</h3>
        <p class="font-body-sm text-body-sm text-secondary">Pour toute question liée à votre dossier en cours, connectez-vous à votre espace client pour échanger directement avec votre consultant.</p>
        <a href="mon-espace.php" class="h-11 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span>Accéder à mon espace</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </a>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex items-start gap-space-xs">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">lock</span>
        <p class="font-body-sm text-body-sm text-secondary">Vos données sont traitées de manière confidentielle et ne sont jamais partagées avec des tiers.</p>
      </div>
    </div>
  </div>
</section>

<script>
(function() {
  var form = document.getElementById('contactForm');
  var successMessage = document.getElementById('successMessage');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      form.classList.add('hidden');
      if (successMessage) {
        successMessage.classList.remove('hidden');
        successMessage.classList.add('flex');
      }
    });
  }
})();
</script>

<?php render_footer(); ?>
