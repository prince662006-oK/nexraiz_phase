<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/password-reset.php';

if ($utilisateurDejaConnecte = current_user()) {
    redirect_to_dashboard($utilisateurDejaConnecte);
}

$envoye = false;
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Merci de saisir une adresse email valide.';
    } else {
        request_password_reset($email);
        $envoye = true;
    }
}

render_head('Mot de passe oublié — NexRaiz', 'Réinitialisez votre mot de passe NexRaiz.');
render_top_header('espace');
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto rounded-2xl bg-gradient-to-br from-[#0B192C] via-[#14304f] to-[#2563EB] px-space-lg py-space-2xl flex flex-col items-center text-center gap-space-sm">
    <span class="inline-flex items-center px-space-sm py-1.5 rounded-full bg-white/10 text-white font-label-md text-label-md font-bold">Espace client</span>
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-white font-bold tracking-tight">Mot de passe oublié</h1>
    <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-white/80 max-w-max-width-reading">Indiquez votre email : si un compte NexRaiz y est associé, vous recevrez un lien pour choisir un nouveau mot de passe.</p>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-sm mx-auto bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">

    <?php if ($envoye): ?>
    <div class="flex flex-col items-center text-center gap-space-sm">
      <div class="w-14 h-14 rounded-full bg-green-100 text-green-700 flex items-center justify-center"><span class="material-symbols-outlined text-[28px]">mark_email_read</span></div>
      <p class="font-body-md text-body-md text-on-surface">Si un compte existe avec cette adresse, un email contenant un lien de réinitialisation valable 1 heure vient de vous être envoyé.</p>
      <a href="mon-espace.php" class="font-label-lg text-label-lg text-primary underline">Retour à la connexion</a>
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
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email</span>
        <input required type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="exemple@domaine.com" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
      </label>
      <button type="submit" class="h-12 rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity">
        <span>Envoyer le lien de réinitialisation</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </button>
      <a href="mon-espace.php" class="font-label-md text-label-md text-secondary text-center hover:text-primary transition-colors">Retour à la connexion</a>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php render_footer(); ?>
