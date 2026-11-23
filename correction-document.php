<?php
/**
 * Document corrigé (CV ou lettre de motivation) consultable par le candidat
 * (et son consultant), Pack Classic. Affiche la version corrigée la plus
 * récente de la table `corrections`, avec le tampon « Traité par NexRaiz ».
 * Généré dynamiquement comme compte-rendu.php, pas un fichier statique.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$user = require_login('mon-espace.php');
$dossierId = (int) ($_GET['dossier'] ?? 0);
$typeDocument = $_GET['type'] ?? 'cv';
if (!in_array($typeDocument, ['cv', 'lettre'], true)) {
    $typeDocument = 'cv';
}

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     WHERE ds.id = ?'
);
$stmt->execute([$dossierId]);
$dossier = $stmt->fetch();

$autorise = $dossier && ((int) $dossier['user_id'] === (int) $user['id'] || in_array($user['role'], ['consultant', 'admin'], true));
if (!$dossier || !$autorise) {
    header('Location: mon-espace.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT contenu_initial, contenu_corrige, created_at FROM corrections
     WHERE dossier_id = ? AND document_type = ? ORDER BY created_at DESC LIMIT 1'
);
$stmt->execute([$dossierId, $typeDocument]);
$correction = $stmt->fetch();

$labels = ['cv' => 'CV corrigé', 'lettre' => 'Lettre de motivation corrigée'];

render_head($labels[$typeDocument] . ' — Dossier ' . htmlspecialchars($dossier['numero']));
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-reading mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold"><?php echo htmlspecialchars($labels[$typeDocument]); ?></span>
    </nav>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <div class="flex flex-wrap items-start justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo htmlspecialchars($labels[$typeDocument]); ?></h1>
          <p class="font-body-sm text-body-sm text-secondary">Dossier <?php echo htmlspecialchars($dossier['numero']); ?> · <?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p>
        </div>
        <?php if ($correction): ?>
        <span class="inline-flex items-center gap-1 px-space-sm py-1.5 rounded-full bg-primary-container/10 text-primary font-label-md text-label-md font-bold shrink-0">
          <span class="material-symbols-outlined text-[16px]">verified</span>
          Traité par NexRaiz
        </span>
        <?php endif; ?>
      </div>

      <?php if (!$correction): ?>
      <p class="font-body-sm text-body-sm text-secondary">Ce document est encore en cours de préparation par votre consultant.</p>
      <?php else: ?>
      <p class="font-body-sm text-[11px] text-secondary">Dernière mise à jour le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($correction['created_at']))); ?></p>
      <div class="rounded-xl border border-outline-variant/60 p-space-md whitespace-pre-wrap font-body-md text-body-md text-on-surface">
<?php echo nl2br(htmlspecialchars($correction['contenu_corrige'])); ?>
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
