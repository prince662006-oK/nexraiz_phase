<?php
/**
 * Fil d'actualités internes, partagé par tous les packs (une actualité peut
 * être rattachée à un pack précis, ou visible de tous). Lecture pour tout
 * consultant/admin, publication réservée aux administrateurs.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$estAdmin = in_array($consultant['role'], ['admin', 'super_admin'], true);

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $estAdmin) {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'ajouter') {
        $titre = trim($_POST['titre'] ?? '');
        $contenu = trim($_POST['contenu'] ?? '');
        $packChoisi = (($_POST['pack_id'] ?? '') !== '') ? (int) $_POST['pack_id'] : null;
        if ($titre !== '' && $contenu !== '') {
            $stmt = db()->prepare('INSERT INTO actualites (pack_id, titre, contenu, created_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$packChoisi, $titre, $contenu, $consultant['id']]);
            $succes = 'Actualité publiée.';
        }
    } elseif ($action === 'supprimer') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM actualites WHERE id = ?')->execute([$id]);
        $succes = 'Actualité supprimée.';
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs ORDER BY nom');
$packs = $stmt->fetchAll();
$packsParId = [];
foreach ($packs as $p) { $packsParId[$p['id']] = $p; }

$packFiltre = $_GET['pack'] ?? '';
$sql = "SELECT a.*, u.nom AS auteur_nom, u.prenom AS auteur_prenom FROM actualites a LEFT JOIN users u ON u.id = a.created_by WHERE 1=1";
$params = [];
if ($packFiltre !== '') {
    if ($packFiltre === 'global') {
        $sql .= ' AND a.pack_id IS NULL';
    } else {
        $sql .= ' AND a.pack_id = (SELECT id FROM packs WHERE slug = ?)';
        $params[] = $packFiltre;
    }
}
$sql .= ' ORDER BY a.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$actualites = $stmt->fetchAll();

render_head('Espace NexRaiz — Actualités', 'Actualités internes NexRaiz pour les consultants et administrateurs.');
?>
<div class="flex w-full min-h-screen">

  <?php render_consultant_pack_sidebar($consultant, 'actualites'); ?>

  <div class="flex-1 flex flex-col min-w-0">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
    </a>
    <div class="flex items-center gap-space-sm shrink-0 ml-auto">
      <a href="mon-espace.php" class="font-label-md text-label-md text-secondary hover:text-on-surface transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour à mon espace</a>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-max-width-content mx-auto w-full">
    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Actualités</h1>
      <p class="font-body-sm text-body-sm text-secondary">Informations et annonces internes NexRaiz pour les équipes consultants.</p>
    </div>

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
      <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
    </div>
    <?php endif; ?>

    <div class="flex items-center gap-space-sm flex-wrap">
      <form method="get" class="flex items-center gap-space-sm">
        <select name="pack" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Tous les packs</option>
          <option value="global" <?php echo $packFiltre === 'global' ? 'selected' : ''; ?>>Général (tous packs)</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo htmlspecialchars($p['slug']); ?>" <?php echo $packFiltre === $p['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($packFiltre !== ''): ?>
      <a href="actualites.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
      <?php endif; ?>
    </div>

    <?php if ($estAdmin): ?>
    <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
      <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">campaign</span>Publier une actualité</summary>
      <form method="post" class="flex flex-col gap-space-sm pt-space-sm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter">
        <input type="text" name="titre" required placeholder="Titre de l'actualité" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <select name="pack_id" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Général (tous packs)</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
        <textarea name="contenu" required rows="4" placeholder="Contenu de l'actualité..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">Publier</button>
      </form>
    </details>
    <?php endif; ?>

    <div class="flex flex-col gap-space-sm">
      <?php if (!$actualites): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucune actualité pour le moment.</p>
      <?php endif; ?>
      <?php foreach ($actualites as $a): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
        <div class="flex items-start justify-between gap-space-sm">
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($a['titre']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary">
              <?php echo htmlspecialchars($a['pack_id'] ? ($packsParId[$a['pack_id']]['nom'] ?? '') : 'Général'); ?>
              · <?php echo htmlspecialchars(date('d/m/Y', strtotime($a['created_at']))); ?>
              <?php if ($a['auteur_nom']): ?> · par <?php echo htmlspecialchars($a['auteur_prenom'] . ' ' . $a['auteur_nom']); ?><?php endif; ?>
            </p>
          </div>
          <?php if ($estAdmin): ?>
          <form method="post" onsubmit="return confirm('Supprimer cette actualité ?');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
            <button type="submit" class="w-8 h-8 rounded-lg text-error hover:bg-error-container/20 transition-colors flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]">delete</span></button>
          </form>
          <?php endif; ?>
        </div>
        <p class="font-body-sm text-body-sm text-on-surface whitespace-pre-wrap pt-space-xs border-t border-outline-variant/60"><?php echo nl2br(htmlspecialchars($a['contenu'])); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </main>
  </div>
</div>
</body>
</html>
