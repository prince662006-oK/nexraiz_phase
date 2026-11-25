<?php
/**
 * Centre de référence du consultant NexRaiz : le « playbook » qui rassemble
 * ce qu'il faut savoir pour bien accompagner un candidat ou un client
 * (cadrage, scripts d'entretien, grilles de lecture, FAQ interne...) —
 * partagé par tous les packs (chaque ressource peut être rattachée à un
 * pack précis, ou visible de tous). Organisé par catégorie avec une mise en
 * avant des ressources du pack du consultant connecté. Lecture pour tout
 * consultant/admin, gestion (ajout/modification/archivage/suppression)
 * réservée aux administrateurs. Une ressource est un texte, un fichier
 * joint (stockage privé + passerelle sécurisée, voir
 * ressource-telecharger.php), ou les deux — jamais les deux absents.
 *
 * Distincte de « Outils & modèles » (modeles-documents.php) : ici, des
 * guides et documents de référence à consulter, pas des trames à remplir.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';
require_once __DIR__ . '/includes/ressources.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
require_permission_section($consultant, 'ressources', 'mon-espace.php');
$estAdmin = in_array($consultant['role'], ['admin', 'super_admin'], true);
$categoriesValides = ['base_connaissances', 'guide', 'outil', 'faq'];

$succes = '';
$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $estAdmin) {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'ajouter') {
        $titre = trim($_POST['titre'] ?? '');
        $contenu = trim($_POST['contenu'] ?? '');
        $categorie = in_array($_POST['categorie'] ?? '', $categoriesValides, true) ? $_POST['categorie'] : 'base_connaissances';
        $packChoisi = (($_POST['pack_id'] ?? '') !== '') ? (int) $_POST['pack_id'] : null;
        $aUnFichier = isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($titre === '') {
            $erreurs[] = 'Le titre est obligatoire.';
        } elseif ($contenu === '' && !$aUnFichier) {
            $erreurs[] = 'Merci de renseigner un contenu ou de joindre un fichier.';
        } else {
            $fichierInfos = null;
            if ($aUnFichier) {
                $resultat = ressource_enregistrer_fichier($_FILES['fichier']);
                if (is_string($resultat)) {
                    $erreurs[] = $resultat;
                } else {
                    $fichierInfos = $resultat;
                }
            }
            if (!$erreurs) {
                $stmt = db()->prepare(
                    'INSERT INTO ressources (pack_id, categorie, titre, contenu, fichier_chemin, fichier_nom_original, fichier_extension, fichier_taille_ko, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $packChoisi, $categorie, $titre, $contenu !== '' ? $contenu : null,
                    $fichierInfos['chemin'] ?? null, $fichierInfos['nom'] ?? null, $fichierInfos['extension'] ?? null, $fichierInfos['taille_ko'] ?? null,
                    $consultant['id'],
                ]);
                $succes = 'Ressource ajoutée.';
            }
        }
    } elseif ($action === 'supprimer') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM ressources WHERE id = ?')->execute([$id]);
        $succes = 'Ressource supprimée.';
    } elseif ($action === 'archiver') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE ressources SET statut = 'archive' WHERE id = ?")->execute([$id]);
        $succes = 'Ressource archivée — elle ne sera plus visible des consultants.';
    } elseif ($action === 'republier') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE ressources SET statut = 'publie' WHERE id = ?")->execute([$id]);
        $succes = 'Ressource republiée.';
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs ORDER BY nom');
$packs = $stmt->fetchAll();
$packsParId = [];
foreach ($packs as $p) { $packsParId[$p['id']] = $p; }

$packFiltre = $_GET['pack'] ?? '';
$categorieFiltre = $_GET['categorie'] ?? '';
$typeFiltre = $_GET['type'] ?? '';
$rechercheRessource = trim($_GET['q'] ?? '');
$vueArchivees = $estAdmin && ($_GET['vue'] ?? '') === 'archivees';

$sql = "SELECT r.*, u.nom AS auteur_nom, u.prenom AS auteur_prenom FROM ressources r LEFT JOIN users u ON u.id = r.created_by WHERE r.statut = ?";
$params = [$vueArchivees ? 'archive' : 'publie'];
if ($packFiltre !== '') {
    if ($packFiltre === 'global') {
        $sql .= ' AND r.pack_id IS NULL';
    } else {
        $sql .= ' AND r.pack_id = (SELECT id FROM packs WHERE slug = ?)';
        $params[] = $packFiltre;
    }
}
if (in_array($categorieFiltre, $categoriesValides, true)) {
    $sql .= ' AND r.categorie = ?';
    $params[] = $categorieFiltre;
}
if ($typeFiltre === 'texte') {
    $sql .= ' AND r.fichier_chemin IS NULL';
} elseif ($typeFiltre !== '') {
    $sql .= ' AND r.fichier_extension = ?';
    $params[] = $typeFiltre;
}
if ($rechercheRessource !== '') {
    $sql .= ' AND (r.titre LIKE ? OR r.contenu LIKE ?)';
    $like = '%' . $rechercheRessource . '%';
    array_push($params, $like, $like);
}
$sql .= ' ORDER BY r.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$ressources = $stmt->fetchAll();

// Types de fichiers réellement présents (pour ne proposer que des filtres
// qui donneront un résultat) — calculé sur les ressources publiées.
$stmt = db()->query("SELECT DISTINCT fichier_extension FROM ressources WHERE statut = 'publie' AND fichier_extension IS NOT NULL ORDER BY fichier_extension");
$typesDisponibles = array_column($stmt->fetchAll(), 'fichier_extension');

$categorieLabels = [
    'base_connaissances' => ['Base de connaissances', 'bg-blue-100 text-blue-700', 'menu_book'],
    'guide' => ['Guides & procédures', 'bg-purple-100 text-purple-700', 'map'],
    'outil' => ['Boîte à outils', 'bg-orange-100 text-orange-700', 'construction'],
    'faq' => ['FAQ interne', 'bg-green-100 text-green-700', 'help'],
];

// --- Aucun filtre actif : seule situation où la mise en avant « Recommandé
// pour votre pack » a du sens (sinon elle se superposerait aux résultats
// filtrés par le consultant). ---
$aucunFiltreActif = $rechercheRessource === '' && $categorieFiltre === '' && $typeFiltre === '' && $packFiltre === '';
$recommandees = [];
if ($aucunFiltreActif && !$vueArchivees && !empty($consultant['pack_id'])) {
    $stmt = db()->prepare(
        "SELECT r.*, u.nom AS auteur_nom, u.prenom AS auteur_prenom FROM ressources r LEFT JOIN users u ON u.id = r.created_by
         WHERE r.statut = 'publie' AND r.pack_id = ? ORDER BY r.created_at DESC LIMIT 4"
    );
    $stmt->execute([(int) $consultant['pack_id']]);
    $recommandees = $stmt->fetchAll();
}
$idsRecommandees = array_column($recommandees, 'id');

// --- Regroupement par catégorie : une section par catégorie réellement
// représentée dans les résultats, jamais une section vide. ---
$parCategorie = array_fill_keys(array_keys($categorieLabels), []);
foreach ($ressources as $r) { $parCategorie[$r['categorie']][] = $r; }

$nbCategoriesRepresentees = count(array_filter($parCategorie));

/**
 * Rendu d'une carte ressource — utilisé à la fois dans la mise en avant
 * « Recommandé pour votre pack » et dans les sections par catégorie,
 * jamais dupliqué.
 */
function ressources_rendre_carte(array $r, array $categorieLabels, array $packsParId, bool $estAdmin): void
{
    [$catLabel, $catClass] = $categorieLabels[$r['categorie']];
    [$icone, $couleurIcone] = $r['fichier_nom_original'] ? nx_icone_fichier($r['fichier_nom_original']) : ['description', 'text-secondary'];
    ?>
    <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
      <summary class="cursor-pointer flex items-start justify-between gap-space-sm">
        <div class="min-w-0">
          <div class="flex items-center gap-1 flex-wrap mb-space-2xs">
            <span class="inline-flex px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $catClass; ?>"><?php echo htmlspecialchars($catLabel); ?></span>
            <?php if ($r['fichier_extension']): ?><span class="inline-flex px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-surface-container-high text-secondary"><?php echo htmlspecialchars(strtoupper($r['fichier_extension'])); ?></span><?php endif; ?>
          </div>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($r['titre']); ?></p>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($r['pack_id'] ? ($packsParId[$r['pack_id']]['nom'] ?? '') : 'Général'); ?> · Mis à jour le <?php echo htmlspecialchars(date('d/m/Y', strtotime($r['created_at']))); ?><?php if ($r['auteur_prenom']): ?> · Source : <?php echo htmlspecialchars($r['auteur_prenom'] . ' ' . $r['auteur_nom']); ?><?php endif; ?></p>
        </div>
      </summary>

      <?php if ($r['contenu']): ?>
      <p class="font-body-sm text-body-sm text-on-surface whitespace-pre-wrap pt-space-xs border-t border-outline-variant/60"><?php echo nl2br(htmlspecialchars($r['contenu'])); ?></p>
      <?php endif; ?>

      <?php if ($r['fichier_chemin']): ?>
      <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm <?php echo $r['contenu'] ? '' : 'mt-space-xs'; ?>">
        <div class="flex items-center gap-space-xs min-w-0">
          <span class="material-symbols-outlined text-[20px] <?php echo $couleurIcone; ?> shrink-0"><?php echo $icone; ?></span>
          <div class="min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['fichier_nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo $r['fichier_taille_ko']; ?> Ko</p></div>
        </div>
        <a href="ressource-telecharger.php?id=<?php echo $r['id']; ?>" target="_blank" rel="noopener" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]"><?php echo $r['fichier_extension'] === 'pdf' ? 'visibility' : 'download'; ?></span><?php echo $r['fichier_extension'] === 'pdf' ? 'Aperçu' : 'Télécharger'; ?></a>
      </div>
      <?php elseif (!$r['contenu']): ?>
      <p class="font-body-sm text-[12px] text-secondary italic pt-space-xs border-t border-outline-variant/60">Aucun fichier associé à cette ressource.</p>
      <?php endif; ?>

      <?php if ($estAdmin): ?>
      <div class="flex items-center gap-space-sm self-end pt-space-xs">
        <?php if ($r['statut'] === 'archive'): ?>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="republier"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
          <button type="submit" class="font-label-md text-[11px] text-primary hover:underline">Republier</button>
        </form>
        <?php else: ?>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="archiver"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
          <button type="submit" class="font-label-md text-[11px] text-secondary hover:underline">Archiver</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Supprimer définitivement cette ressource ?');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="supprimer">
          <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
          <button type="submit" class="font-label-md text-[11px] text-error hover:underline">Supprimer</button>
        </form>
      </div>
      <?php endif; ?>
    </details>
    <?php
}

render_head('Espace NexRaiz — Ressources', 'Le centre de référence des consultants NexRaiz : guides, procédures et boîte à outils.');
?>
<div class="flex w-full min-h-screen">

  <?php render_consultant_pack_sidebar($consultant, 'ressources'); ?>

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
    <div class="flex items-start justify-between gap-space-sm flex-wrap">
      <div>
        <p class="font-label-md text-[11px] uppercase tracking-wider text-primary font-bold">Centre de référence consultant</p>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Ressources</h1>
        <p class="font-body-sm text-body-sm text-secondary max-w-xl">Tout ce qu'il faut savoir pour bien accompagner un candidat ou un client : cadrage, scripts d'entretien, grilles de lecture et réponses aux questions courantes.</p>
        <?php if (!$vueArchivees): ?>
        <p class="font-body-sm text-[11px] text-secondary pt-space-2xs"><?php echo count($ressources); ?> ressource<?php echo count($ressources) > 1 ? 's' : ''; ?> · <?php echo $nbCategoriesRepresentees; ?> catégorie<?php echo $nbCategoriesRepresentees > 1 ? 's' : ''; ?></p>
        <?php endif; ?>
      </div>
      <?php if ($estAdmin): ?>
      <a href="ressources.php?vue=<?php echo $vueArchivees ? '' : 'archivees'; ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">archive</span><?php echo $vueArchivees ? 'Voir les ressources publiées' : 'Voir les ressources archivées'; ?></a>
      <?php endif; ?>
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
    <?php if ($vueArchivees): ?>
    <div class="flex items-start gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-secondary mt-0.5">archive</span>
      <p class="font-body-sm text-body-sm text-on-surface">Vous consultez les ressources archivées — invisibles pour les consultants non administrateurs.</p>
    </div>
    <?php endif; ?>

    <form method="get" class="flex items-center gap-space-sm flex-wrap">
      <?php if ($vueArchivees): ?><input type="hidden" name="vue" value="archivees"><?php endif; ?>
      <div class="relative">
        <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($rechercheRessource); ?>" placeholder="Rechercher par mot-clé..." class="h-10 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
      </div>
      <select name="categorie" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <option value="">Toutes les catégories</option>
        <option value="base_connaissances" <?php echo $categorieFiltre === 'base_connaissances' ? 'selected' : ''; ?>>Base de connaissances</option>
        <option value="guide" <?php echo $categorieFiltre === 'guide' ? 'selected' : ''; ?>>Guides &amp; procédures</option>
        <option value="outil" <?php echo $categorieFiltre === 'outil' ? 'selected' : ''; ?>>Boîte à outils</option>
        <option value="faq" <?php echo $categorieFiltre === 'faq' ? 'selected' : ''; ?>>FAQ interne</option>
      </select>
      <?php if ($typesDisponibles): ?>
      <select name="type" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <option value="">Tous les types</option>
        <option value="texte" <?php echo $typeFiltre === 'texte' ? 'selected' : ''; ?>>Contenu texte uniquement</option>
        <?php foreach ($typesDisponibles as $t): ?>
        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $typeFiltre === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars(strtoupper($t)); ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <select name="pack" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <option value="">Tous les packs</option>
        <option value="global" <?php echo $packFiltre === 'global' ? 'selected' : ''; ?>>Général (tous packs)</option>
        <?php foreach ($packs as $p): ?>
        <option value="<?php echo htmlspecialchars($p['slug']); ?>" <?php echo $packFiltre === $p['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md">Filtrer</button>
      <?php if ($packFiltre !== '' || $categorieFiltre !== '' || $typeFiltre !== '' || $rechercheRessource !== ''): ?>
      <a href="ressources.php<?php echo $vueArchivees ? '?vue=archivees' : ''; ?>" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
      <?php endif; ?>
    </form>

    <?php if ($recommandees): ?>
    <div class="flex flex-col gap-space-sm">
      <div class="flex items-center gap-space-xs">
        <span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">star</span></span>
        <div>
          <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Recommandé pour votre pack</h2>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($packsParId[$consultant['pack_id']]['nom'] ?? ''); ?></p>
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
        <?php foreach ($recommandees as $r) { ressources_rendre_carte($r, $categorieLabels, $packsParId, $estAdmin); } ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($estAdmin && !$vueArchivees): ?>
    <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
      <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">add_circle</span>Ajouter une ressource</summary>
      <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-sm pt-space-sm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ajouter">
        <input type="text" name="titre" required placeholder="Titre de la ressource" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <select name="categorie" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="base_connaissances">Base de connaissances</option>
            <option value="guide">Guide &amp; procédure</option>
            <option value="outil">Boîte à outils</option>
            <option value="faq">FAQ interne</option>
          </select>
          <select name="pack_id" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Général (tous packs)</option>
            <?php foreach ($packs as $p): ?>
            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <textarea name="contenu" rows="4" placeholder="Contenu texte (optionnel si un fichier est joint)..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
        <label class="flex flex-col gap-0.5"><span class="font-body-sm text-[11px] text-secondary">Fichier joint (PDF, Word, Excel, PowerPoint, JPG ou PNG — 20 Mo max, optionnel)</span><input type="file" name="fichier" class="font-body-sm text-[12px] text-secondary"></label>
        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">Publier</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if (!$ressources): ?>
    <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg"><?php echo $vueArchivees ? 'Aucune ressource archivée.' : 'Aucune ressource ne correspond à ces filtres.'; ?></p>
    <?php endif; ?>

    <?php foreach ($categorieLabels as $cle => [$catLabel, $catClass, $catIcone]):
      $liste = $parCategorie[$cle];
      if (!$liste) { continue; }
    ?>
    <div class="flex flex-col gap-space-sm">
      <div class="flex items-center gap-space-xs">
        <span class="w-9 h-9 rounded-full <?php echo $catClass; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $catIcone; ?></span></span>
        <h2 class="font-label-lg text-label-lg text-on-surface font-bold flex-1"><?php echo htmlspecialchars($catLabel); ?><span class="font-body-sm text-[12px] text-secondary font-normal"> · <?php echo count($liste); ?></span></h2>
      </div>
      <?php if ($cle === 'faq'): ?>
      <div class="flex items-start gap-space-xs bg-primary-container/5 border border-primary-container/20 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">support_agent</span>
        <p class="font-body-sm text-body-sm text-on-surface">Vous ne trouvez pas de réponse à votre question ? <a href="admin-messages.php" class="text-primary font-semibold hover:underline">Contactez un administrateur via la messagerie interne</a>.</p>
      </div>
      <?php endif; ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
        <?php foreach ($liste as $r) { ressources_rendre_carte($r, $categorieLabels, $packsParId, $estAdmin); } ?>
      </div>
    </div>
    <?php endforeach; ?>
  </main>
  </div>
</div>
</body>
</html>
