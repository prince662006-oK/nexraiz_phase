<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// Un visiteur déjà connecté en tant que client peut avoir besoin d'un
// second pack : s'il a déjà un dossier Wellbeing, on le renvoie directement
// dessus ; sinon on lui propose de l'ajouter à son compte existant.
$utilisateurConnecte = current_user();
$estClientConnecte = $utilisateurConnecte && $utilisateurConnecte['role'] === 'client';
if ($estClientConnecte) {
    $dossierExistant = client_dossier_existant((int) $utilisateurConnecte['id'], 'wellbeing');
    if ($dossierExistant) {
        // current_step mémorise la DERNIÈRE étape terminée : la page à
        // montrer est donc la suivante, jamais current_step lui-même.
        header('Location: ' . step_url(min((int) $dossierExistant['current_step'] + 1, 8), 'wellbeing'));
        exit;
    }
}

$erreurs = [];
$valeurs = ['nom' => '', 'prenom' => '', 'email' => '', 'telephone' => '', 'poste' => '', 'ville' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($estClientConnecte && ($_POST['action'] ?? '') === 'ajouter_pack') {
        get_or_create_dossier((int) $utilisateurConnecte['id'], 'wellbeing');
        header('Location: ' . step_url(3, 'wellbeing'));
        exit;
    }
    $valeurs['nom'] = trim($_POST['nom'] ?? '');
    $valeurs['prenom'] = trim($_POST['prenom'] ?? '');
    $valeurs['email'] = trim($_POST['email'] ?? '');
    $valeurs['telephone'] = trim($_POST['telephone'] ?? '');
    $valeurs['poste'] = trim($_POST['poste'] ?? '');
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
        $erreurs[] = "Vous devez accepter les conditions d'utilisation et la politique de confidentialité.";
    } else {
        try {
            $user = register_client($valeurs['nom'], $valeurs['prenom'], $valeurs['email'], $valeurs['telephone'], $password);
            $dossier = get_or_create_dossier($user['id'], 'wellbeing');
            $stmt = db()->prepare(
                'INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)'
            );
            $stmt->execute([$dossier['id'], json_encode([
                'poste_actuel' => $valeurs['poste'],
                'secteur_activite' => $_POST['secteur'] ?? '',
                'ville' => $valeurs['ville'],
                'pays' => $_POST['pays'] ?? '',
            ], JSON_UNESCAPED_UNICODE)]);
            header('Location: ' . step_url(3, 'wellbeing'));
            exit;
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

render_head('Pack Wellbeing — Créez votre espace client');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'wellbeing',
    2,
    'Créez votre espace client',
    'Accédez à votre parcours Wellbeing en toute sécurité. Vos données sont protégées et confidentielles.',
    'person_add',
    ['shield', 'lock']
);
render_stepper('wellbeing', 2);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-start">

    <?php if ($estClientConnecte): ?>
    <div class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm items-start">
      <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[24px]">add_circle</span></div>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Ajouter le Pack Wellbeing à votre compte</h2>
      <p class="font-body-sm text-body-sm text-secondary">Vous êtes connecté en tant que <strong><?php echo htmlspecialchars($utilisateurConnecte['prenom'] . ' ' . $utilisateurConnecte['nom']); ?></strong> (<?php echo htmlspecialchars($utilisateurConnecte['email']); ?>). Ce pack s'ajoute à votre compte existant — inutile de créer un nouvel espace ni un nouveau mot de passe.</p>
      <form method="post" class="w-full">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter_pack">
        <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Ajouter le Pack Wellbeing</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </form>
      <a href="mon-espace.php" class="font-label-md text-label-md text-primary hover:underline">Retour à mon espace</a>
    </div>
    <?php else: ?>
    <form id="signupForm" method="post" class="flex flex-col gap-space-md bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Vos informations</h2>

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
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email professionnel <span class="text-error">*</span></span>
        <input required type="email" name="email" value="<?php echo htmlspecialchars($valeurs['email']); ?>" placeholder="exemple@entreprise.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>

      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone <span class="text-error">*</span></span>
        <div class="flex gap-space-2xs">
          <select class="h-11 px-space-2xs rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option>🇫🇷 +33</option>
            <option>🇸🇳 +221</option>
          </select>
          <input required type="tel" name="telephone" value="<?php echo htmlspecialchars($valeurs['telephone']); ?>" placeholder="06 12 34 56 78" class="flex-1 h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </div>
      </label>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mot de passe <span class="text-error">*</span></span>
          <div class="relative flex items-center">
            <input required type="password" name="password" id="passwordInput" placeholder="Minimum 8 caractères" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordInput" aria-label="Afficher le mot de passe"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
          </div>
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Confirmation du mot de passe <span class="text-error">*</span></span>
          <div class="relative flex items-center">
            <input required type="password" name="password_confirm" id="passwordConfirmInput" placeholder="Confirmez votre mot de passe" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="passwordConfirmInput" aria-label="Afficher le mot de passe"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
          </div>
        </label>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Poste actuel <span class="text-error">*</span></span>
          <input type="text" name="poste" value="<?php echo htmlspecialchars($valeurs['poste']); ?>" placeholder="Ex. : Responsable RH" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Secteur d'activité <span class="text-error">*</span></span>
          <select name="secteur" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Sélectionnez votre secteur</option>
            <option>Tech &amp; Digital</option>
            <option>Santé</option>
            <option>Finance &amp; Banque</option>
            <option>Industrie</option>
            <option>Autre</option>
          </select>
        </label>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Ville <span class="text-error">*</span></span>
          <input type="text" name="ville" value="<?php echo htmlspecialchars($valeurs['ville']); ?>" placeholder="Votre ville" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Pays <span class="text-error">*</span></span>
          <select name="pays" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Sélectionnez votre pays</option>
            <option>France</option>
            <option>Sénégal</option>
            <option>Côte d'Ivoire</option>
          </select>
        </label>
      </div>

      <label class="flex items-start gap-space-xs">
        <input required type="checkbox" name="cgu" checked class="mt-1 w-4 h-4 accent-primary-container rounded">
        <span class="font-body-sm text-body-sm text-on-surface">J'accepte les <a href="#" class="text-primary underline">Conditions d'utilisation</a> et la <a href="#" class="text-primary underline">Politique de confidentialité</a>. <span class="text-error">*</span></span>
      </label>

      <div class="flex flex-col items-center gap-space-xs pt-space-xs">
        <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span class="material-symbols-outlined text-[18px]">lock</span>
          <span>Créer mon espace sécurisé</span>
        </button>
        <a href="mon-espace.php" class="font-label-lg text-label-lg text-primary underline">J'ai déjà un compte</a>
      </div>
    </form>
    <?php endif; ?>

    <div class="flex flex-col gap-space-md">
      <div class="relative w-full h-64 rounded-xl overflow-hidden shadow-sm">
        <img src="img_well/meditation.png" alt="Candidat pratiquant la pleine conscience" class="w-full h-full object-cover">
        <div class="absolute top-3 right-3 w-9 h-9 rounded-full bg-white shadow flex items-center justify-center text-primary"><span class="material-symbols-outlined text-[18px]">self_improvement</span></div>
      </div>
      <div class="flex flex-col gap-space-sm">
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex items-start gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">shield</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Espace sécurisé</p><p class="font-body-sm text-[12px] text-secondary">Plateforme conforme aux standards de sécurité</p></div>
        </div>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex items-start gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">lock</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Données confidentielles</p><p class="font-body-sm text-[12px] text-secondary">Vos informations sont chiffrées et protégées</p></div>
        </div>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex items-start gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">bar_chart</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Accès au tableau de bord</p><p class="font-body-sm text-[12px] text-secondary">Suivez votre progression en temps réel</p></div>
        </div>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex items-start gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">person</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Suivi personnalisé</p><p class="font-body-sm text-[12px] text-secondary">Un accompagnement adapté à vos besoins</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
    <div class="flex items-start gap-space-sm bg-surface-container-lowest rounded-xl p-space-md shadow-sm">
      <div class="w-10 h-10 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">event</span></div>
      <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Accès à vos sessions</p><p class="font-body-sm text-body-sm text-secondary">Retrouvez toutes vos séances, ressources et rendez-vous dans votre espace personnel.</p></div>
    </div>
    <div class="flex items-start gap-space-sm bg-surface-container-lowest rounded-xl p-space-md shadow-sm">
      <div class="w-10 h-10 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">check_circle</span></div>
      <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Suivi de vos actions</p><p class="font-body-sm text-body-sm text-secondary">Visualisez vos avancées, vos objectifs et les recommandations de votre coach.</p></div>
    </div>
    <div class="flex items-start gap-space-sm bg-surface-container-lowest rounded-xl p-space-md shadow-sm">
      <div class="w-10 h-10 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">folder</span></div>
      <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Documents et livrables</p><p class="font-body-sm text-body-sm text-secondary">Accédez à vos documents, exercices et livrables à tout moment, en quelques clics.</p></div>
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
})();
</script>

<?php render_footer(); ?>
