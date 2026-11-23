<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// Un visiteur déjà connecté en tant que client peut avoir besoin d'un
// second pack : s'il a déjà un dossier Classic, on le renvoie directement
// dessus ; sinon on lui propose de l'ajouter à son compte existant.
$utilisateurConnecte = current_user();
$estClientConnecte = $utilisateurConnecte && $utilisateurConnecte['role'] === 'client';
if ($estClientConnecte) {
    $dossierExistant = client_dossier_existant((int) $utilisateurConnecte['id'], 'classic');
    if ($dossierExistant) {
        // current_step mémorise la DERNIÈRE étape terminée : la page à
        // montrer est donc la suivante, jamais current_step lui-même.
        header('Location: ' . step_url(min((int) $dossierExistant['current_step'] + 1, 8), 'classic'));
        exit;
    }
}

$erreurs = [];
$valeurs = ['nom' => '', 'prenom' => '', 'email' => '', 'telephone' => '', 'ville' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($estClientConnecte && ($_POST['action'] ?? '') === 'ajouter_pack') {
        get_or_create_dossier((int) $utilisateurConnecte['id'], 'classic');
        header('Location: ' . step_url(3, 'classic'));
        exit;
    }
    $valeurs['nom'] = trim($_POST['nom'] ?? '');
    $valeurs['prenom'] = trim($_POST['prenom'] ?? '');
    $valeurs['email'] = trim($_POST['email'] ?? '');
    $valeurs['telephone'] = trim($_POST['telephone'] ?? '');
    $valeurs['ville'] = trim($_POST['ville'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $cguAcceptee = isset($_POST['cgu']);

    if ($valeurs['nom'] === '' || $valeurs['prenom'] === '' || $valeurs['email'] === '' || $valeurs['telephone'] === '') {
        $erreurs[] = 'Merci de renseigner tous les champs obligatoires.';
    } elseif (!filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Adresse email invalide.';
    } elseif (strlen($password) < 8) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $erreurs[] = 'Les mots de passe ne correspondent pas.';
    } elseif (!$cguAcceptee) {
        $erreurs[] = 'Vous devez accepter la politique de confidentialité.';
    } else {
        try {
            $user = register_client($valeurs['nom'], $valeurs['prenom'], $valeurs['email'], $valeurs['telephone'], $password);
            $dossier = get_or_create_dossier($user['id'], 'classic');
            $stmt = db()->prepare(
                'INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)'
            );
            $stmt->execute([$dossier['id'], json_encode([
                'ville_pays' => $valeurs['ville'],
                'statut_actuel' => $_POST['statut'] ?? '',
                'niveau_experience' => $_POST['experience'] ?? '',
            ], JSON_UNESCAPED_UNICODE)]);
            header('Location: ' . step_url(3, 'classic'));
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

$avantages = [
    ['icone' => 'lock', 'titre' => 'Sécuriser vos informations', 'texte' => 'Vos données personnelles sont protégées et vous restez maître de vos informations.'],
    ['icone' => 'timeline', 'titre' => "Suivre l'avancement de votre parcours", 'texte' => 'Visualisez chaque étape de votre accompagnement et vos prochaines actions.'],
    ['icone' => 'event', 'titre' => 'Accéder à vos rendez-vous', 'texte' => 'Retrouvez vos échanges, notes et rendez-vous en un seul endroit.'],
    ['icone' => 'folder', 'titre' => 'Récupérer vos livrables', 'texte' => 'Téléchargez vos documents et ressources à tout moment.'],
];

$statuts = [
    ['icone' => 'school', 'label' => 'Étudiant'],
    ['icone' => 'work', 'label' => 'Salarié'],
    ['icone' => 'search', 'label' => 'En recherche'],
    ['icone' => 'sync_alt', 'label' => 'En reconversion'],
    ['icone' => 'person', 'label' => 'Freelance'],
];

$experiences = [
    ['icone' => 'star', 'label' => 'Débutant'],
    ['icone' => 'bar_chart', 'label' => 'Junior'],
    ['icone' => 'workspace_premium', 'label' => 'Confirmé'],
    ['icone' => 'diamond', 'label' => 'Senior'],
];

render_head('Pack Classic — Création de l\'espace candidat');
render_top_header();
?>
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

    <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-center">
      <div class="flex flex-col gap-space-xs">
        <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-primary-container text-white font-label-md text-label-md font-bold">
          Étape 2 sur <?php echo $totalEtapesClassic; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Création de l'espace candidat</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Créez votre compte sécurisé pour accéder au Pack Classic</p>
      </div>

      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
        <img src="img_classic/creer_compte.png" alt="Candidate créant son espace candidat NexRaiz depuis son ordinateur" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</section>
<?php
render_stepper('classic', 2);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-start">

    <?php if ($estClientConnecte): ?>
    <div class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm items-start">
      <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[24px]">add_circle</span></div>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Ajouter le Pack Classic à votre compte</h2>
      <p class="font-body-sm text-body-sm text-secondary">Vous êtes connecté en tant que <strong><?php echo htmlspecialchars($utilisateurConnecte['prenom'] . ' ' . $utilisateurConnecte['nom']); ?></strong> (<?php echo htmlspecialchars($utilisateurConnecte['email']); ?>). Ce pack s'ajoute à votre compte existant — inutile de créer un nouvel espace ni un nouveau mot de passe.</p>
      <form method="post" class="w-full">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter_pack">
        <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Ajouter le Pack Classic</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </form>
      <a href="mon-espace.php" class="font-label-md text-label-md text-primary hover:underline">Retour à mon espace</a>
    </div>
    <?php else: ?>
    <form id="signupForm" method="post" class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <h2 class="flex items-center gap-space-xs font-headline-md text-headline-md text-on-surface font-bold">
        <span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">person</span></span>
        Vos informations
      </h2>
      <p class="font-body-sm text-body-sm text-secondary -mt-space-sm">Pour commencer votre accompagnement, créez votre espace candidat. Vous pourrez y déposer vos informations, suivre l'avancement de votre parcours et retrouver vos documents et livrables à tout moment.</p>

      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <input type="hidden" name="statut" id="statutInput" value="">
      <input type="hidden" name="experience" id="experienceInput" value="">

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

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email <span class="text-error">*</span></span>
          <input required type="email" name="email" value="<?php echo htmlspecialchars($valeurs['email']); ?>" placeholder="exemple@email.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone <span class="text-error">*</span></span>
          <input required type="tel" name="telephone" value="<?php echo htmlspecialchars($valeurs['telephone']); ?>" placeholder="06 12 34 56 78" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mot de passe <span class="text-error">*</span></span>
          <div class="relative flex items-center">
            <input required type="password" name="password" id="passwordInput" placeholder="••••••••" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordInput" aria-label="Afficher le mot de passe"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
          </div>
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Confirmation du mot de passe <span class="text-error">*</span></span>
          <div class="relative flex items-center">
            <input required type="password" name="password_confirm" id="passwordConfirmInput" placeholder="••••••••" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordConfirmInput" aria-label="Afficher le mot de passe"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
          </div>
        </label>
      </div>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Ville / Pays <span class="text-error">*</span></span>
        <input type="text" name="ville" value="<?php echo htmlspecialchars($valeurs['ville']); ?>" placeholder="Ex : Paris, France" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>

      <div class="flex flex-col gap-space-sm">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Statut actuel <span class="text-error">*</span></span>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-space-sm" id="statutGroup">
          <?php foreach ($statuts as $i => $s): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($s['label']); ?>" class="option-radio flex flex-col items-center gap-space-2xs px-space-sm py-space-sm rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="material-symbols-outlined text-[20px] text-primary"><?php echo $s['icone']; ?></span>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo $s['label']; ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Niveau d'expérience <span class="text-error">*</span></span>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm" id="experienceGroup">
          <?php foreach ($experiences as $i => $e): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($e['label']); ?>" class="option-radio flex flex-col items-center gap-space-2xs px-space-sm py-space-sm rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="material-symbols-outlined text-[20px] text-primary"><?php echo $e['icone']; ?></span>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo $e['label']; ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <label class="flex items-start gap-space-xs pt-space-xs">
        <input required type="checkbox" name="cgu" checked class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'accepte la création de mon espace candidat et la collecte de mes données conformément à la <a href="#" class="text-primary underline">Politique de confidentialité</a>.</span>
      </label>

      <div class="flex flex-col sm:flex-row gap-space-xs pt-space-xs">
        <button type="submit" class="h-12 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Créer mon espace candidat</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <a href="mon-espace.php" class="h-12 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span>J'ai déjà un compte</span>
        </a>
      </div>
      <p class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">lock</span>Vos données sont sécurisées et confidentielles.</p>
    </form>
    <?php endif; ?>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
          <span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">shield</span></span>
          Pourquoi créer un espace candidat ?
        </h3>
        <div class="flex flex-col gap-space-sm">
          <?php foreach ($avantages as $a): ?>
          <div class="bg-surface-container-low rounded-xl p-space-sm flex items-start gap-space-xs">
            <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $a['icone']; ?></span></div>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></p><p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($a['texte']); ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary pt-space-xs border-t border-outline-variant/60"><span class="material-symbols-outlined text-[16px] text-primary">verified_user</span>Espace sécurisé et conforme au RGPD</p>
      </div>
    </div>
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

  function bindRadioGroup(groupId, hiddenInputId) {
    var group = document.getElementById(groupId);
    var hiddenInput = document.getElementById(hiddenInputId);
    if (!group) return;
    group.querySelectorAll('.option-radio').forEach(function(opt) {
      opt.addEventListener('click', function() {
        group.querySelectorAll('.option-radio').forEach(function(o) { o.classList.remove('border-primary-container', 'bg-primary-container/5'); });
        opt.classList.add('border-primary-container', 'bg-primary-container/5');
        if (hiddenInput) hiddenInput.value = opt.getAttribute('data-label');
      });
    });
  }
  bindRadioGroup('statutGroup', 'statutInput');
  bindRadioGroup('experienceGroup', 'experienceInput');
})();
</script>

<?php render_footer(); ?>
