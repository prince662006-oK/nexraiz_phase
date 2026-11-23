<?php
require __DIR__ . '/includes/bootstrap.php';

$utilisateur = require_login('mon-espace.php');

if (isset($_GET['marquer_lu'])) {
    marquer_notifications_lues((int) $utilisateur['id']);
    header('Location: notifications.php');
    exit;
}

$notifications = notifications_recentes((int) $utilisateur['id'], 50);
marquer_notifications_lues((int) $utilisateur['id']);

render_head('Notifications — NexRaiz', 'Vos notifications NexRaiz.');
render_top_header('espace');
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Notifications</h1>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm divide-y divide-outline-variant/20 max-w-2xl">
      <?php if (!$notifications): ?>
      <p class="px-space-lg py-space-xl font-body-md text-body-md text-secondary text-center">Vous n'avez aucune notification pour le moment.</p>
      <?php else: ?>
      <?php foreach ($notifications as $n): ?>
      <a href="<?php echo htmlspecialchars($n['lien'] ?: '#'); ?>" class="flex flex-col gap-space-2xs px-space-lg py-space-md hover:bg-surface-container-low transition-colors">
        <span class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($n['titre']); ?></span>
        <?php if ($n['corps']): ?><span class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($n['corps']); ?></span><?php endif; ?>
        <span class="font-body-sm text-[11px] text-outline"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($n['created_at']))); ?></span>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php render_footer(); ?>
