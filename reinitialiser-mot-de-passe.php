<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/password-reset.php';

if ($utilisateurDejaConnecte = current_user()) {
    redirect_to_dashboard($utilisateurDejaConnecte);
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$resetValide = $token !== '' && find_valid_reset($token) !== null;
$succes = false;
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (!$resetValide) {
        $erreurs[] = 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.';
    } elseif (mb_strlen($password) < 8) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
    } else {
        $succes = consume_password_reset($token, $password);
        if (!$succes) {
            $erreurs[] = 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.';
        }
    }
}

render_head('Réinitialiser mon mot de passe — NexRaiz', 'Choisissez un nouveau mot de passe pour votre compte NexRaiz.');
render_top_header('espace');
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto rounded-2xl bg-gradient-to-br from-[#0B192C] via-[#14304f] to-[#2563EB] px-space-lg py-space-2xl flex flex-col items-center text-center gap-space-sm">
    <span class="inline-flex items-center px-space-sm py-1.5 rounded-full bg-white/10 text-white font-label-md text-label-md font-bold">Espace client</span>
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-white font-bold tracking-tight">Nouveau mot de passe</h1>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-sm mx-auto bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">

    <?php if ($succes): ?>
    <div class="flex flex-col items-center text-center gap-space-sm">
      <div class="w-14 h-14 rounded-full bg-green-100 text-green-700 flex items-center justify-center"><span class="material-symbols-outlined text-[28px]">check_circle</span></div>
      <p class="font-body-md text-body-md text-on-surface">Votre mot de passe a été mis à jour. Vous pouvez maintenant vous connecter.</p>
      <a href="mon-espace.php" class="h-12 px-space-lg rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity">Se connecter</a>
    </div>

    <?php elseif (!$resetValide): ?>
    <div class="flex flex-col items-center text-center gap-space-sm">
      <div class="w-14 h-14 rounded-full bg-error-container text-error flex items-center justify-center"><span class="material-symbols-outlined text-[28px]">error</span></div>
      <p class="font-body-md text-body-md text-on-surface">Ce lien de réinitialisation est invalide ou a expiré.</p>
      <a href="mot-de-passe-oublie.php" class="font-label-lg text-label-lg text-primary underline">Demander un nouveau lien</a>
    </div>

    <?php else: ?>
    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="flex flex-col gap-space-md">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nouveau mot de passe</span>
        <input required minlength="8" type="password" name="password" placeholder="8 caractères minimum" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Confirmer le mot de passe</span>
        <input required minlength="8" type="password" name="password_confirm" placeholder="8 caractères minimum" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>
      <button type="submit" class="h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity">
        <span>Mettre à jour le mot de passe</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </button>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php render_footer(); ?>
