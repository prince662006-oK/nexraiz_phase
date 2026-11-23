<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// Un visiteur déjà connecté en tant que client peut avoir besoin d'un
// second pack (voir client_dossier_existant()) : s'il a déjà un dossier
// Booster, on le renvoie directement dessus ; sinon on lui propose de
// l'ajouter à son compte existant, sans repasser par une inscription.
$utilisateurConnecte = current_user();
$estClientConnecte = $utilisateurConnecte && $utilisateurConnecte['role'] === 'client';
if ($estClientConnecte) {
    $dossierExistant = client_dossier_existant((int) $utilisateurConnecte['id'], 'booster');
    if ($dossierExistant) {
        // current_step mémorise la DERNIÈRE étape terminée (voir
        // update_dossier_step() / booster-qualification-projet.php) : la
        // page à montrer est donc la suivante, jamais current_step lui-même
        // (qui, juste après l'inscription, vaudrait 2 = cette page même).
        header('Location: ' . step_url(min((int) $dossierExistant['current_step'] + 1, 8), 'booster'));
        exit;
    }
}

$erreurs = [];
$valeurs = ['nom' => '', 'prenom' => '', 'email' => '', 'telephone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($estClientConnecte && ($_POST['action'] ?? '') === 'ajouter_pack') {
        get_or_create_dossier((int) $utilisateurConnecte['id'], 'booster');
        header('Location: ' . step_url(3, 'booster'));
        exit;
    }
    $valeurs['nom'] = trim($_POST['nom'] ?? '');
    $valeurs['prenom'] = trim($_POST['prenom'] ?? '');
    $valeurs['email'] = trim($_POST['email'] ?? '');
    $valeurs['telephone'] = trim($_POST['telephone'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $cguAcceptees = isset($_POST['cgu']) && isset($_POST['confidentialite']);

    if ($valeurs['nom'] === '' || $valeurs['prenom'] === '' || $valeurs['email'] === '' || $valeurs['telephone'] === '') {
        $erreurs[] = 'Merci de renseigner tous les champs obligatoires.';
    } elseif (!filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Adresse email invalide.';
    } elseif (strlen($password) < 8) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $erreurs[] = 'Les mots de passe ne correspondent pas.';
    } elseif (!$cguAcceptees) {
        $erreurs[] = "Vous devez accepter les conditions d'utilisation et la politique de confidentialité.";
    } else {
        try {
            $user = register_client($valeurs['nom'], $valeurs['prenom'], $valeurs['email'], $valeurs['telephone'], $password);
            $dossier = get_or_create_dossier($user['id'], 'booster');
            $stmt = db()->prepare(
                'INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)'
            );
            $stmt->execute([$dossier['id'], json_encode(['situation_actuelle' => $_POST['situation'] ?? '', 'niveau_experience' => $_POST['experience'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            header('Location: ' . step_url(3, 'booster'));
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

$avantages = [
    ['icone' => 'space_dashboard', 'titre' => 'Suivre vos sessions', 'texte' => "Accédez à votre tableau de bord et suivez l'avancement de votre parcours."],
    ['icone' => 'description', 'titre' => 'Déposer vos documents', 'texte' => 'Importez vos CV, lettres et autres documents pour un accompagnement personnalisé.'],
    ['icone' => 'folder', 'titre' => 'Retrouver vos livrables', 'texte' => "Téléchargez vos rapports, plans d'action et ressources à tout moment."],
    ['icone' => 'chat', 'titre' => 'Échanger avec NexRaiz', 'texte' => 'Communiquez facilement avec vos consultants via votre espace sécurisé.'],
];

$situations = [
    ['icone' => 'work', 'label' => 'En poste'],
    ['icone' => 'sync_alt', 'label' => 'En transition'],
    ['icone' => 'autorenew', 'label' => 'En reconversion'],
    ['icone' => 'search', 'label' => 'En recherche'],
];

$experiences = [
    ['icone' => 'star', 'label' => 'Débutant'],
    ['icone' => 'bar_chart', 'label' => 'Junior'],
    ['icone' => 'military_tech', 'label' => 'Confirmé'],
    ['icone' => 'workspace_premium', 'label' => 'Senior'],
];

render_head('Pack Booster — Créez votre espace client');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'booster',
    2,
    'Créez votre espace client',
    'Accédez à votre parcours Booster en toute sécurité',
    'person_add',
    ['verified', 'lock']
);
render_stepper('booster', 2);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-start">

    <?php if ($estClientConnecte): ?>
    <div class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm items-start">
      <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[24px]">add_circle</span></div>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Ajouter le Pack Booster à votre compte</h2>
      <p class="font-body-sm text-body-sm text-secondary">Vous êtes connecté en tant que <strong><?php echo htmlspecialchars($utilisateurConnecte['prenom'] . ' ' . $utilisateurConnecte['nom']); ?></strong> (<?php echo htmlspecialchars($utilisateurConnecte['email']); ?>). Ce pack s'ajoute à votre compte existant — inutile de créer un nouvel espace ni un nouveau mot de passe.</p>
      <form method="post" class="w-full">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter_pack">
        <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Ajouter le Pack Booster</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </form>
      <a href="mon-espace.php" class="font-label-md text-label-md text-primary hover:underline">Retour à mon espace</a>
    </div>
    <?php else: ?>
    <form id="signupForm" method="post" class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Créez votre espace client</h2>
      <p class="font-body-sm text-body-sm text-secondary -mt-space-sm">Créez votre compte personnel pour bénéficier d'un accompagnement sur-mesure, suivre vos progrès et accéder à tous vos outils et ressources.</p>

      <?php if ($erreurs): ?>
      <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
        <?php foreach ($erreurs as $e): ?>
        <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <input type="hidden" name="situation" id="situationInput" value="">
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
          <input required type="email" name="email" value="<?php echo htmlspecialchars($valeurs['email']); ?>" placeholder="exemple@domaine.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
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
            <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordInput" aria-label="Afficher le mot de passe">
              <span class="material-symbols-outlined text-[20px]">visibility</span>
            </button>
          </div>
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
      </div>

      <div class="flex flex-col gap-space-sm">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Situation actuelle <span class="text-error">*</span></span>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm" id="situationGroup">
          <?php foreach ($situations as $i => $s): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($s['label']); ?>" class="option-radio situation-radio flex items-center justify-between gap-space-xs px-space-sm h-11 rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="flex items-center gap-space-2xs font-label-md text-label-md text-on-surface font-semibold"><span class="material-symbols-outlined text-[18px] text-primary"><?php echo $s['icone']; ?></span><?php echo $s['label']; ?></span>
            <span class="w-4 h-4 rounded-full border-2 border-outline-variant radio-dot shrink-0"></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Niveau d'expérience <span class="text-error">*</span></span>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm" id="experienceGroup">
          <?php foreach ($experiences as $i => $e): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($e['label']); ?>" class="option-radio experience-radio flex items-center justify-between gap-space-xs px-space-sm h-11 rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="flex items-center gap-space-2xs font-label-md text-label-md text-on-surface font-semibold"><span class="material-symbols-outlined text-[18px] text-primary"><?php echo $e['icone']; ?></span><?php echo $e['label']; ?></span>
            <span class="w-4 h-4 rounded-full border-2 border-outline-variant radio-dot shrink-0"></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <label class="flex items-start gap-space-xs pt-space-xs">
        <input required type="checkbox" name="cgu" checked class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'accepte les <a href="#" class="text-primary underline">Conditions Générales d'Utilisation</a> de NexRaiz. <span class="text-error">*</span></span>
      </label>
      <label class="flex items-start gap-space-xs">
        <input required type="checkbox" name="confidentialite" checked class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'accepte la <a href="#" class="text-primary underline">Politique de confidentialité</a> et le traitement de mes données personnelles. <span class="text-error">*</span></span>
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
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Pourquoi créer un compte ?</h3>
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
        <div class="flex items-center gap-space-2xs pt-space-xs border-t border-outline-variant/60">
          <span class="material-symbols-outlined text-[16px] text-primary">lock</span>
          <span class="font-label-md text-[11px] text-secondary">Vos données sont protégées et confidentielles.</span>
        </div>
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
        group.querySelectorAll('.option-radio').forEach(function(o) {
          o.classList.remove('border-primary-container', 'bg-primary-container/5');
          var dot = o.querySelector('.radio-dot');
          dot.classList.remove('bg-primary-container', 'border-primary-container');
        });
        opt.classList.add('border-primary-container', 'bg-primary-container/5');
        opt.querySelector('.radio-dot').classList.add('bg-primary-container', 'border-primary-container');
        if (hiddenInput) hiddenInput.value = opt.getAttribute('data-label');
      });
    });
  }
  bindRadioGroup('situationGroup', 'situationInput');
  bindRadioGroup('experienceGroup', 'experienceInput');
})();
</script>

<?php render_footer(); ?>
