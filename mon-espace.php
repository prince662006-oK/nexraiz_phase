<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// Une session déjà active ne doit jamais retomber sur l'écran de connexion :
// on renvoie directement vers l'espace de l'utilisateur (avec, sur cet
// espace, un lien « Déconnexion » toujours accessible).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($utilisateurDejaConnecte = current_user())) {
    redirect_to_dashboard($utilisateurDejaConnecte);
}

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        $user = $email !== '' && $password !== '' ? attempt_login($email, $password) : null;
    } catch (RuntimeException $e) {
        $user = null;
        $erreurs[] = $e->getMessage();
    }

    if (!$user && !$erreurs) {
        $erreurs[] = 'Email ou mot de passe incorrect.';
    } elseif ($user) {
        login_user($user);
        redirect_to_dashboard($user);
    }
}

$avantages = [
    ['icone' => 'space_dashboard', 'titre' => 'Tableau de bord centralisé', 'texte' => "Suivez l'avancement de votre dossier, vos actions et vos livrables en un seul endroit."],
    ['icone' => 'event', 'titre' => 'Rendez-vous en un clic', 'texte' => "Consultez, reportez ou confirmez vos rendez-vous avec votre consultant directement depuis votre espace."],
    ['icone' => 'folder', 'titre' => 'Documents toujours accessibles', 'texte' => "Retrouvez tous vos documents, comptes rendus et plans d'action, téléchargeables à tout moment."],
    ['icone' => 'lock', 'titre' => 'Confidentialité garantie', 'texte' => 'Vos données sont chiffrées et ne sont jamais partagées sans votre accord.'],
];

$demosTableauxDeBord = [
    ['slug' => 'assistance', 'titre' => 'Pack Assistance', 'icone' => 'support_agent', 'url' => 'tableau-bord.php'],
    ['slug' => 'booster', 'titre' => 'Pack Booster', 'icone' => 'rocket_launch', 'url' => 'booster-tableau-bord.php'],
    ['slug' => 'classic', 'titre' => 'Pack Classic', 'icone' => 'work', 'url' => 'classic-tableau-bord.php'],
    ['slug' => 'wellbeing', 'titre' => 'Pack Wellbeing', 'icone' => 'spa', 'url' => 'wellbeing-tableau-bord.php'],
];

render_head('Mon espace — NexRaiz', 'Connectez-vous à votre espace client NexRaiz pour suivre votre accompagnement.');
render_top_header('espace');
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto rounded-2xl bg-gradient-to-br from-[#0B192C] via-[#14304f] to-[#2563EB] px-space-lg py-space-2xl md:py-space-3xl flex flex-col items-center text-center gap-space-sm">
    <span class="inline-flex items-center px-space-sm py-1.5 rounded-full bg-white/10 text-white font-label-md text-label-md font-bold">Espace client</span>
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-white font-bold tracking-tight">Mon espace NexRaiz</h1>
    <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-white/80 max-w-max-width-reading">Suivez votre accompagnement, vos documents et vos rendez-vous en un seul endroit, où que vous soyez.</p>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1fr_1.2fr] gap-space-lg items-start">

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <div class="flex items-center gap-space-2xs bg-surface-container-low rounded-xl p-1" id="authTabs">
        <button type="button" data-tab="login" class="auth-tab flex-1 h-10 rounded-lg font-label-lg text-label-lg font-bold bg-surface-container-lowest text-on-surface shadow-sm">Se connecter</button>
        <button type="button" data-tab="signup" class="auth-tab flex-1 h-10 rounded-lg font-label-lg text-label-lg font-semibold text-secondary">Créer un compte</button>
      </div>

      <form id="loginForm" method="post" class="auth-panel flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <?php if ($erreurs): ?>
        <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
          <?php foreach ($erreurs as $e): ?>
          <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email</span>
          <input required type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="exemple@domaine.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mot de passe</span>
          <div class="relative flex items-center">
            <input required type="password" name="password" id="loginPassword" placeholder="••••••••" class="w-full h-11 px-space-sm pr-11 rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <button type="button" class="password-toggle absolute right-space-sm text-outline hover:text-on-surface" data-target="loginPassword" aria-label="Afficher le mot de passe"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
          </div>
        </label>
        <div class="flex items-center justify-between">
          <label class="flex items-center gap-space-xs">
            <input type="checkbox" class="w-4 h-4 accent-primary-container rounded">
            <span class="font-body-sm text-body-sm text-on-surface">Se souvenir de moi</span>
          </label>
          <a href="mot-de-passe-oublie.php" class="font-label-md text-label-md text-primary underline">Mot de passe oublié ?</a>
        </div>
        <button type="submit" class="h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity">
          <span>Se connecter</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <p class="flex items-center justify-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">lock</span>Connexion sécurisée et chiffrée</p>
      </form>

      <div id="signupPanel" class="auth-panel flex-col items-center text-center gap-space-md" style="display:none">
        <div class="w-14 h-14 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[28px]">inventory_2</span></div>
        <p class="font-body-md text-body-md text-secondary max-w-sm">La création de votre espace se fait directement au sein du parcours d'un pack. Choisissez d'abord le pack qui correspond à votre besoin.</p>
        <a href="index.php#accompagnements" class="h-12 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-95 transition-opacity">
          <span>Choisir mon pack</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </a>
      </div>
    </div>

    <div class="flex flex-col gap-space-md">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <?php foreach ($avantages as $a): ?>
        <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo $a['icone']; ?></span></div>
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($a['titre']); ?></h3>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($a['texte']); ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
          <span class="material-symbols-outlined text-[20px] text-primary">visibility</span>
          Découvrir un tableau de bord
        </h3>
        <p class="font-body-sm text-body-sm text-secondary">Pas encore de compte ? Explorez un aperçu de démonstration selon le pack qui vous intéresse.</p>
        <div class="grid grid-cols-2 gap-space-sm">
          <?php foreach ($demosTableauxDeBord as $d): ?>
          <a href="<?php echo htmlspecialchars($d['url']); ?>" class="flex items-center gap-space-xs px-space-sm h-12 rounded-xl border border-outline-variant hover:border-primary-container hover:bg-surface-container-low transition-colors">
            <span class="material-symbols-outlined text-[20px] text-primary"><?php echo $d['icone']; ?></span>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['titre']); ?></span>
          </a>
          <?php endforeach; ?>
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

  var tabs = document.querySelectorAll('.auth-tab');
  var loginForm = document.getElementById('loginForm');
  var signupPanel = document.getElementById('signupPanel');
  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      tabs.forEach(function(t) {
        t.classList.remove('bg-surface-container-lowest', 'text-on-surface', 'shadow-sm', 'font-bold');
        t.classList.add('text-secondary', 'font-semibold');
      });
      tab.classList.add('bg-surface-container-lowest', 'text-on-surface', 'shadow-sm', 'font-bold');
      tab.classList.remove('text-secondary', 'font-semibold');
      var isLogin = tab.getAttribute('data-tab') === 'login';
      loginForm.style.display = isLogin ? 'flex' : 'none';
      signupPanel.style.display = isLogin ? 'none' : 'flex';
    });
  });

})();
</script>

<?php render_footer(); ?>
