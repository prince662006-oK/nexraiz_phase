<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// Un visiteur déjà connecté en tant que client peut avoir besoin d'un
// second pack : s'il a déjà un dossier Assistance, on le renvoie
// directement dessus ; sinon on lui propose de l'ajouter à son compte.
$utilisateurConnecte = current_user();
$estClientConnecte = $utilisateurConnecte && $utilisateurConnecte['role'] === 'client';
if ($estClientConnecte) {
    $dossierExistant = client_dossier_existant((int) $utilisateurConnecte['id'], 'assistance');
    if ($dossierExistant) {
        // current_step mémorise la DERNIÈRE étape terminée : la page à
        // montrer est donc la suivante, jamais current_step lui-même.
        header('Location: ' . step_url(min((int) $dossierExistant['current_step'] + 1, 8), 'assistance'));
        exit;
    }
}

$erreurs = [];
$valeurs = ['nom' => '', 'prenom' => '', 'email' => '', 'telephone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($estClientConnecte && ($_POST['action'] ?? '') === 'ajouter_pack') {
        get_or_create_dossier((int) $utilisateurConnecte['id'], 'assistance');
        header('Location: ' . step_url(3, 'assistance'));
        exit;
    }
    $valeurs['nom'] = trim($_POST['nom'] ?? '');
    $valeurs['prenom'] = trim($_POST['prenom'] ?? '');
    $valeurs['email'] = trim($_POST['email'] ?? '');
    $valeurs['telephone'] = trim($_POST['telephone'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $cguAcceptees = isset($_POST['cgu']);

    if ($valeurs['nom'] === '' || $valeurs['prenom'] === '' || $valeurs['email'] === '' || $valeurs['telephone'] === '') {
        $erreurs[] = 'Merci de renseigner tous les champs obligatoires.';
    } elseif (!filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Adresse email invalide.';
    } elseif (strlen($password) < 8) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $erreurs[] = 'Les mots de passe ne correspondent pas.';
    } elseif (!$cguAcceptees) {
        $erreurs[] = "Vous devez accepter les conditions générales d'utilisation.";
    } else {
        try {
            $user = register_client($valeurs['nom'], $valeurs['prenom'], $valeurs['email'], $valeurs['telephone'], $password);
            get_or_create_dossier($user['id'], 'assistance');
            header('Location: ' . step_url(3, 'assistance'));
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

$avantages = [
    ['icone' => 'folder_shared', 'titre' => 'Suivi du dossier', 'texte' => "Suivez l'avancement de votre accompagnement en temps réel."],
    ['icone' => 'description', 'titre' => 'Accès à vos documents', 'texte' => 'Retrouvez tous vos documents au même endroit, à tout moment.'],
    ['icone' => 'event', 'titre' => 'Rendez-vous centralisés', 'texte' => 'Gérez vos rendez-vous et échanges avec votre conseiller en toute simplicité.'],
    ['icone' => 'shield', 'titre' => 'Confidentialité garantie', 'texte' => 'Vos données sont protégées et ne sont jamais partagées.'],
];

$garanties = [
    ['icone' => 'lock', 'titre' => 'Espace sécurisé', 'texte' => 'Notre plateforme utilise un chiffrement de pointe pour protéger vos informations.'],
    ['icone' => 'schedule', 'titre' => 'Accès 24/7', 'texte' => 'Accédez à votre espace client à tout moment, où que vous soyez.'],
    ['icone' => 'verified_user', 'titre' => 'Données confidentielles', 'texte' => 'Vos données sont strictement confidentielles et ne sont jamais partagées.'],
];

render_head('Pack Assistance — Création de l\'espace client');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'assistance',
    2,
    "Création de l'espace client",
    'Créez votre espace sécurisé pour démarrer votre accompagnement',
    'person_add',
    ['verified', 'lock']
);
render_stepper('assistance', 2);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-start">

    <?php if ($estClientConnecte): ?>
    <div class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm items-start">
      <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[24px]">add_circle</span></div>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Ajouter le Pack Assistance à votre compte</h2>
      <p class="font-body-sm text-body-sm text-secondary">Vous êtes connecté en tant que <strong><?php echo htmlspecialchars($utilisateurConnecte['prenom'] . ' ' . $utilisateurConnecte['nom']); ?></strong> (<?php echo htmlspecialchars($utilisateurConnecte['email']); ?>). Ce pack s'ajoute à votre compte existant — inutile de créer un nouvel espace ni un nouveau mot de passe.</p>
      <form method="post" class="w-full">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter_pack">
        <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Ajouter le Pack Assistance</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </form>
      <a href="mon-espace.php" class="font-label-md text-label-md text-primary hover:underline">Retour à mon espace</a>
    </div>
    <?php else: ?>
    <form id="signupForm" method="post" class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Créez votre compte</h2>

      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nom <span class="text-error">*</span></span>
          <input required type="text" name="nom" value="<?php echo htmlspecialchars($valeurs['nom']); ?>" placeholder="Votre nom" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Prénom <span class="text-error">*</span></span>
          <input required type="text" name="prenom" value="<?php echo htmlspecialchars($valeurs['prenom']); ?>" placeholder="Votre prénom" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
      </div>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email <span class="text-error">*</span></span>
        <input required type="email" name="email" value="<?php echo htmlspecialchars($valeurs['email']); ?>" placeholder="exemple@domaine.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone <span class="text-error">*</span></span>
        <input required type="tel" name="telephone" value="<?php echo htmlspecialchars($valeurs['telephone']); ?>" placeholder="06 12 34 56 78" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mot de passe <span class="text-error">*</span></span>
        <div class="relative flex items-center">
          <input required type="password" name="password" id="passwordInput" placeholder="••••••••" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordInput" aria-label="Afficher le mot de passe">
            <span class="material-symbols-outlined text-[20px]">visibility</span>
          </button>
        </div>
        <span class="font-body-sm text-[12px] text-secondary">8 caractères minimum avec une majuscule, un chiffre et un caractère spécial.</span>
      </label>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Confirmer le mot de passe <span class="text-error">*</span></span>
        <div class="relative flex items-center">
          <input required type="password" name="password_confirm" id="passwordConfirmInput" placeholder="••••••••" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordConfirmInput" aria-label="Afficher le mot de passe">
            <span class="material-symbols-outlined text-[20px]">visibility</span>
          </button>
        </div>
      </label>

      <label class="flex items-start gap-space-xs">
        <input required type="checkbox" name="cgu" checked class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'accepte les <a href="#" class="text-primary underline">Conditions générales d'utilisation</a> et la <a href="#" class="text-primary underline">Politique de confidentialité</a>. <span class="text-error">*</span></span>
      </label>
      <label class="flex items-start gap-space-xs">
        <input type="checkbox" name="newsletter" checked class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">Je souhaite recevoir des emails d'information de NexRaiz (événements, actualités, conseils).</span>
      </label>

      <div class="flex flex-col gap-space-xs pt-space-xs">
        <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Créer mon espace sécurisé</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <a href="mon-espace.php" class="w-full h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center hover:bg-surface-container-low transition-colors">J'ai déjà un compte</a>
      </div>
    </form>
    <?php endif; ?>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Pourquoi créer un espace ?</h3>
        <div class="flex flex-col divide-y divide-outline-variant/60">
          <?php foreach ($avantages as $a): ?>
          <div class="flex items-start gap-space-sm py-space-sm">
            <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0">
              <span class="material-symbols-outlined text-[20px]"><?php echo $a['icone']; ?></span>
            </div>
            <div>
              <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></p>
              <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($a['texte']); ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="relative w-full h-40 rounded-xl overflow-hidden shadow-sm">
        <img src="img_assistance/ordinateur.png" alt="Tableau de bord client NexRaiz sur ordinateur portable" class="w-full h-full object-cover">
        <div class="absolute top-3 right-3 w-9 h-9 rounded-full bg-white shadow flex items-center justify-center text-primary"><span class="material-symbols-outlined text-[18px]">verified</span></div>
        <div class="absolute bottom-3 left-3 w-9 h-9 rounded-full bg-white shadow flex items-center justify-center text-primary"><span class="material-symbols-outlined text-[18px]">lock</span></div>
      </div>
    </div>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
    <?php foreach ($garanties as $g): ?>
    <div class="flex items-start gap-space-sm bg-surface-container-lowest rounded-xl p-space-md shadow-sm">
      <div class="w-10 h-10 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[22px]"><?php echo $g['icone']; ?></span>
      </div>
      <div>
        <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($g['titre']); ?></p>
        <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($g['texte']); ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<script>
(function() {
  document.querySelectorAll('.password-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var input = document.getElementById(btn.getAttribute('data-target'));
      var icon = btn.querySelector('.material-symbols-outlined');
      if (!input) return;
      var isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      icon.textContent = isPassword ? 'visibility_off' : 'visibility';
    });
  });

})();
</script>

<?php render_footer(); ?>
