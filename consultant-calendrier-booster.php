<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';
require_once __DIR__ . '/includes/consultant-calendrier.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');

render_head('Espace NexRaiz — Calendrier', 'Sessions planifiées pour le Pack Booster.');
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'calendrier'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Calendrier</h1>
        <p class="font-body-sm text-body-sm text-secondary">Sessions planifiées pour le Pack Booster.</p>
      </div>
      <?php render_consultant_calendrier($packId, 'consultant-dossier-booster.php'); ?>
    </main>
  </div>
</div>
</body>
</html>
