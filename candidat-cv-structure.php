<?php
/**
 * NexRaiz — Vue candidat, en lecture seule, du CV construit par étapes
 * (CV Builder structuré, Pack Classic). Calquée sur correction-document.php
 * (même vérification d'accès, même bandeau « Traité par NexRaiz») — jamais
 * de contrôle d'édition ici, toute modification passe par le consultant via
 * consultant-cv-builder.php.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cv-builder-classic.php';

$user = require_login('mon-espace.php');
$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare('SELECT ds.*, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ?');
$stmt->execute([$dossierId]);
$dossier = $stmt->fetch();

$autorise = $dossier && ((int) $dossier['user_id'] === (int) $user['id'] || in_array($user['role'], ['consultant', 'admin'], true));
if (!$dossier || !$autorise) {
    header('Location: mon-espace.php');
    exit;
}

$cv = classic_cv_builder_recuperer($dossierId);
$experiences = $cv ? classic_cv_builder_experiences($dossierId) : [];
$formations = $cv ? classic_cv_builder_formations($dossierId) : [];

render_head('Mon CV, étape par étape — NexRaiz');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-reading mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Mon CV, étape par étape</span>
    </nav>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <div class="flex flex-wrap items-start justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mon CV, étape par étape</h1>
          <p class="font-body-sm text-body-sm text-secondary">Dossier <?php echo htmlspecialchars($dossier['numero']); ?> · <?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p>
        </div>
        <?php if ($cv && $cv['layout']): ?>
        <span class="inline-flex items-center gap-1 px-space-sm py-1.5 rounded-full bg-primary-container/10 text-primary font-label-md text-label-md font-bold shrink-0">
          <span class="material-symbols-outlined text-[16px]">verified</span>Traité par NexRaiz
        </span>
        <?php endif; ?>
      </div>

      <?php if (!$cv || !$cv['layout']): ?>
      <p class="font-body-sm text-body-sm text-secondary">Ce document est encore en cours de préparation par votre consultant.</p>
      <?php else: ?>
      <p class="font-body-sm text-[11px] text-secondary">Dernière mise à jour le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($cv['updated_at']))); ?> — lecture seule : toute correction se fait avec votre consultant.</p>
      <div class="rounded-xl border border-outline-variant/60 p-space-md flex flex-col gap-space-md">
        <?php echo cv_builder_rendre_recap_html($cv, $experiences, $formations); ?>
      </div>
      <?php endif; ?>

      <a href="mon-espace.php" class="self-start h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors mt-space-xs">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Retour à mon espace</span>
      </a>
    </div>
  </div>
</section>
<?php render_footer(); ?>
