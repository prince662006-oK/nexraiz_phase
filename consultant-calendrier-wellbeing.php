<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-wellbeing.php';
require_once __DIR__ . '/includes/consultant-calendrier.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('wellbeing');

render_head('Espace NexRaiz — Calendrier', 'Sessions planifiées pour le Pack Wellbeing.');
?>
<div class="flex w-full min-h-screen">

  <?php render_wellbeing_sidebar($packId, 'calendrier'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_wellbeing_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Calendrier</h1>
        <p class="font-body-sm text-body-sm text-secondary">Sessions planifiées pour le Pack Wellbeing.</p>
      </div>
      <?php render_consultant_calendrier($packId, 'consultant-diagnostic-wellbeing.php'); ?>
    </main>
  </div>
</div>
</body>
</html>
