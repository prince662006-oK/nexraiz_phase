<?php
/**
 * Modèles de documents téléchargeables, partagés par tous les packs (chaque
 * modèle peut être rattaché à un pack précis, ou visible de tous). Lecture
 * pour tout consultant/admin, ajout réservé aux administrateurs.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$estAdmin = in_array($consultant['role'], ['admin', 'super_admin'], true);

$categorieLabels = [
    'compte_rendu' => 'Compte rendu', 'courrier' => 'Courrier', 'rapport' => 'Rapport',
    'plan_action' => "Plan d'action", 'autre' => 'Autre',
];

$succes = '';
$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $estAdmin) {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'ajouter') {
        $titre = trim($_POST['titre'] ?? '');
        $categorie = array_key_exists($_POST['categorie'] ?? '', $categorieLabels) ? $_POST['categorie'] : null;
        if ($titre === '' || empty($_FILES['fichier']['name'])) {
            $erreurs[] = 'Merci de renseigner un titre et de choisir un fichier.';
        } else {
            $uploadsDir = __DIR__ . '/uploads/modeles';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
            $nomFichier = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['fichier']['name']));
            $cheminAbsolu = $uploadsDir . '/' . $nomFichier;
            if (move_uploaded_file($_FILES['fichier']['tmp_name'], $cheminAbsolu)) {
                $packChoisi = (($_POST['pack_id'] ?? '') !== '') ? (int) $_POST['pack_id'] : null;
                $stmt = db()->prepare('INSERT INTO modeles_documents (pack_id, categorie, titre, description, chemin, taille_ko, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$packChoisi, $categorie, $titre, trim($_POST['description'] ?? '') ?: null, 'uploads/modeles/' . $nomFichier, (int) round($_FILES['fichier']['size'] / 1024), $consultant['id']]);
                $succes = 'Modèle ajouté.';
            } else {
                $erreurs[] = "Impossible d'enregistrer le fichier.";
            }
        }
    } elseif ($action === 'supprimer') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM modeles_documents WHERE id = ?')->execute([$id]);
        $succes = 'Modèle supprimé.';
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs ORDER BY nom');
$packs = $stmt->fetchAll();
$packsParId = [];
foreach ($packs as $p) { $packsParId[$p['id']] = $p; }

$packFiltre = $_GET['pack'] ?? '';
$categorieFiltre = $_GET['categorie'] ?? '';
$rechercheModele = trim($_GET['q'] ?? '');
$sql = 'SELECT m.* FROM modeles_documents m WHERE 1=1';
$params = [];
if ($packFiltre !== '') {
    if ($packFiltre === 'global') {
        $sql .= ' AND m.pack_id IS NULL';
    } else {
        $sql .= ' AND m.pack_id = (SELECT id FROM packs WHERE slug = ?)';
        $params[] = $packFiltre;
    }
}
if (array_key_exists($categorieFiltre, $categorieLabels)) {
    $sql .= ' AND m.categorie = ?';
    $params[] = $categorieFiltre;
}
if ($rechercheModele !== '') {
    $sql .= ' AND (m.titre LIKE ? OR m.description LIKE ?)';
    $like = '%' . $rechercheModele . '%';
    array_push($params, $like, $like);
}
$sql .= ' ORDER BY m.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$modeles = $stmt->fetchAll();

render_head('Espace NexRaiz — Modèles de documents', 'Modèles de documents et outils téléchargeables pour les consultants NexRaiz.');
?>
<div class="flex w-full min-h-screen">

  <?php render_consultant_pack_sidebar($consultant, 'modeles'); ?>

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
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Modèles de documents</h1>
      <p class="font-body-sm text-body-sm text-secondary">Trames, modèles et outils téléchargeables pour votre accompagnement.</p>
    </div>

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
      <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
    </div>
    <?php endif; ?>
    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="flex items-center gap-space-sm flex-wrap">
      <form method="get" class="flex items-center gap-space-sm flex-wrap">
        <div class="relative">
          <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($rechercheModele); ?>" placeholder="Rechercher un modèle..." class="h-10 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
        </div>
        <select name="categorie" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Toutes les catégories</option>
          <?php foreach ($categorieLabels as $val => $label): ?>
          <option value="<?php echo $val; ?>" <?php echo $categorieFiltre === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="pack" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Tous les packs</option>
          <option value="global" <?php echo $packFiltre === 'global' ? 'selected' : ''; ?>>Général (tous packs)</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo htmlspecialchars($p['slug']); ?>" <?php echo $packFiltre === $p['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md">Filtrer</button>
      </form>
      <?php if ($packFiltre !== '' || $categorieFiltre !== '' || $rechercheModele !== ''): ?>
      <a href="modeles-documents.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
      <?php endif; ?>
    </div>

    <?php if ($estAdmin): ?>
    <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
      <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">upload_file</span>Ajouter un modèle</summary>
      <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-sm pt-space-sm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter">
        <input type="text" name="titre" required placeholder="Titre du modèle" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <select name="categorie" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Sans catégorie</option>
          <?php foreach ($categorieLabels as $val => $label): ?>
          <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="description" placeholder="Description (optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <select name="pack_id" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Général (tous packs)</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="file" name="fichier" required class="font-body-sm text-[12px] text-secondary">
        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">Ajouter</button>
      </form>
    </details>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-space-sm">
      <?php if (!$modeles): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg sm:col-span-2 lg:col-span-3">Aucun modèle disponible pour ces filtres.</p>
      <?php endif; ?>
      <?php foreach ($modeles as $m): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
        <div class="flex items-center justify-between">
          <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">description</span></div>
          <?php if ($m['categorie'] && isset($categorieLabels[$m['categorie']])): ?>
          <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-primary-container/10 text-primary"><?php echo htmlspecialchars($categorieLabels[$m['categorie']]); ?></span>
          <?php endif; ?>
        </div>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($m['titre']); ?></p>
        <?php if ($m['description']): ?><p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($m['description']); ?></p><?php endif; ?>
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($m['pack_id'] ? ($packsParId[$m['pack_id']]['nom'] ?? '') : 'Général'); ?> · <?php echo $m['taille_ko']; ?> Ko</p>
        <div class="flex items-center gap-space-xs pt-space-xs">
          <a href="<?php echo htmlspecialchars($m['chemin']); ?>" target="_blank" rel="noopener" class="flex-1 h-9 rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center justify-center gap-1"><span class="material-symbols-outlined text-[16px]">download</span>Télécharger</a>
          <?php if ($estAdmin): ?>
          <form method="post" onsubmit="return confirm('Supprimer ce modèle ?');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
            <button type="submit" class="w-9 h-9 rounded-lg border border-outline-variant text-error hover:bg-error-container/20 transition-colors flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">delete</span></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </main>
  </div>
</div>
</body>
</html>
