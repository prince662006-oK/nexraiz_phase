<?php
/**
 * NexRaiz — Formations & certifications (consultant), partagé par tous les
 * packs. Catalogue de formations (table `formations`, lecture pour tout
 * consultant/admin, gestion réservée aux administrateurs), inscription et
 * progression personnelles (formation_inscriptions/formation_lecons*,
 * voir includes/formations.php), certifications professionnelles externes
 * déclarées par le consultant (certifications_externes) avec validation
 * administrative séparée.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';
require_once __DIR__ . '/includes/formations.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
require_permission_section($consultant, 'formations', 'mon-espace.php');
$userId = (int) $consultant['id'];
$estAdmin = in_array($consultant['role'], ['admin', 'super_admin'], true);

$succes = '';
$erreurCertification = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter' && $estAdmin) {
        $titre = trim($_POST['titre'] ?? '');
        $packChoisi = (($_POST['pack_id'] ?? '') !== '') ? (int) $_POST['pack_id'] : null;
        if ($titre !== '') {
            $stmt = db()->prepare('INSERT INTO formations (pack_id, titre, organisme, duree, lien, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $packChoisi, $titre,
                trim($_POST['organisme'] ?? '') ?: null,
                trim($_POST['duree'] ?? '') ?: null,
                trim($_POST['lien'] ?? '') ?: null,
                trim($_POST['description'] ?? '') ?: null,
                $consultant['id'],
            ]);
            $succes = 'Formation ajoutée.';
        }
    } elseif ($action === 'supprimer' && $estAdmin) {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM formations WHERE id = ?')->execute([$id]);
        $succes = 'Formation supprimée.';
    } elseif ($action === 'ajouter_lecon' && $estAdmin) {
        $formationId = (int) ($_POST['formation_id'] ?? 0);
        $titreLecon = trim($_POST['titre_lecon'] ?? '');
        if ($formationId > 0 && $titreLecon !== '') {
            $stmt = db()->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM formation_lecons WHERE formation_id = ?');
            $stmt->execute([$formationId]);
            $ordre = (int) $stmt->fetchColumn();
            db()->prepare('INSERT INTO formation_lecons (formation_id, titre, description, ressource_lien, ordre) VALUES (?, ?, ?, ?, ?)')
                ->execute([$formationId, $titreLecon, trim($_POST['description_lecon'] ?? '') ?: null, trim($_POST['lien_lecon'] ?? '') ?: null, $ordre]);
            $succes = 'Leçon ajoutée.';
        }
    } elseif ($action === 'supprimer_lecon' && $estAdmin) {
        db()->prepare('DELETE FROM formation_lecons WHERE id = ?')->execute([(int) ($_POST['lecon_id'] ?? 0)]);
        $succes = 'Leçon supprimée.';
    } elseif ($action === 'ajouter_certification') {
        $erreurCertification = certification_enregistrer($userId, $_POST);
        if ($erreurCertification === '') {
            $succes = 'Certification ajoutée — en attente de validation.';
        }
    } elseif ($action === 'supprimer_certification') {
        $certId = (int) ($_POST['certification_id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM certifications_externes WHERE id = ? AND user_id = ? AND statut = 'en_attente'");
        $stmt->execute([$certId, $userId]);
        $succes = $stmt->rowCount() > 0 ? 'Certification supprimée.' : '';
    } elseif ($action === 'valider_certification' && $estAdmin) {
        if (certification_statuer((int) ($_POST['certification_id'] ?? 0), true, '', $userId)) {
            $succes = 'Certification validée.';
        }
    } elseif ($action === 'refuser_certification' && $estAdmin) {
        if (certification_statuer((int) ($_POST['certification_id'] ?? 0), false, trim($_POST['motif_refus'] ?? ''), $userId)) {
            $succes = 'Certification refusée.';
        }
    }

    if ($erreurCertification === '') {
        header('Location: formations.php?succes=' . urlencode($succes) . (isset($_GET['pack']) ? '&pack=' . urlencode($_GET['pack']) : ''));
        exit;
    }
}
if (isset($_GET['succes']) && $_GET['succes'] !== '') { $succes = $_GET['succes']; }

$stmt = db()->query('SELECT id, slug, nom FROM packs ORDER BY nom');
$packs = $stmt->fetchAll();
$packsParId = [];
foreach ($packs as $p) { $packsParId[$p['id']] = $p; }

$packFiltre = $_GET['pack'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$sql = 'SELECT f.* FROM formations f WHERE 1=1';
$params = [];
if ($packFiltre !== '') {
    if ($packFiltre === 'global') {
        $sql .= ' AND f.pack_id IS NULL';
    } else {
        $sql .= ' AND f.pack_id = (SELECT id FROM packs WHERE slug = ?)';
        $params[] = $packFiltre;
    }
}
if ($recherche !== '') {
    $sql .= ' AND f.titre LIKE ?';
    $params[] = '%' . $recherche . '%';
}
$sql .= ' ORDER BY f.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$formationsCatalogue = $stmt->fetchAll();

// --- Toutes les formations (hors filtres) + mes inscriptions, pour les
// indicateurs et "Mes formations" / "Reprendre ma formation". ---
$stmt = db()->query('SELECT id FROM formations');
$nbFormationsDisponibles = count($stmt->fetchAll());

$stmt = db()->prepare('SELECT fi.*, f.titre, f.description, f.pack_id FROM formation_inscriptions fi JOIN formations f ON f.id = fi.formation_id WHERE fi.user_id = ? ORDER BY fi.commence_le DESC');
$stmt->execute([$userId]);
$mesInscriptions = $stmt->fetchAll();

$progressions = [];
$progressionsCalculables = [];
foreach ($mesInscriptions as $ins) {
    $p = formation_progression((int) $ins['formation_id'], (int) $ins['id']);
    $progressions[$ins['id']] = $p;
    if ($p !== null && $ins['statut'] !== 'termine') { $progressionsCalculables[] = $p; }
}
$progressionMoyenne = $progressionsCalculables ? (int) round(array_sum($progressionsCalculables) / count($progressionsCalculables)) : null;

$mesEnCours = array_values(array_filter($mesInscriptions, fn ($i) => $i['statut'] === 'en_cours'));
$mesTerminees = array_values(array_filter($mesInscriptions, fn ($i) => $i['statut'] === 'termine'));
$idsInscrits = array_column($mesInscriptions, 'formation_id');

$stmt = db()->prepare('SELECT * FROM certifications_externes WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$mesCertifications = $stmt->fetchAll();
$nbCertValidees = count(array_filter($mesCertifications, fn ($c) => $c['statut'] === 'validee'));
$nbCertEnAttente = count(array_filter($mesCertifications, fn ($c) => $c['statut'] === 'en_attente'));

$certificationsAdmin = [];
if ($estAdmin) {
    $stmt = db()->query(
        "SELECT c.*, u.nom AS demandeur_nom, u.prenom AS demandeur_prenom FROM certifications_externes c JOIN users u ON u.id = c.user_id
         WHERE c.statut = 'en_attente' ORDER BY c.created_at ASC"
    );
    $certificationsAdmin = $stmt->fetchAll();
}

$certStatutLabels = ['en_attente' => ['En attente', 'bg-orange-100 text-orange-700'], 'validee' => ['Validée', 'bg-green-100 text-green-700'], 'refusee' => ['Refusée', 'bg-error-container/40 text-error']];

render_head('Espace NexRaiz — Formations & certifications', 'Catalogue de formations et certifications pour les consultants NexRaiz.');
?>
<div class="flex w-full min-h-screen">

  <?php render_consultant_pack_sidebar($consultant, 'formations'); ?>

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
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Formations &amp; certifications</h1>
      <p class="font-body-sm text-body-sm text-secondary">Développez vos compétences : formations recommandées et suivi de vos certifications professionnelles.</p>
    </div>

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm"><span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p></div>
    <?php endif; ?>

    <!-- A. Vue d'ensemble -->
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-space-sm">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Catalogue</span>
        <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $nbFormationsDisponibles; ?></span>
        <span class="font-body-sm text-[11px] text-secondary">Formations disponibles</span>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-blue-600 font-bold">En cours</span>
        <span class="font-headline-sm text-headline-sm font-bold text-blue-600"><?php echo count($mesEnCours); ?></span>
        <span class="font-body-sm text-[11px] text-secondary">Mes formations en cours</span>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-green-600 font-bold">Terminées</span>
        <span class="font-headline-sm text-headline-sm font-bold text-green-600"><?php echo count($mesTerminees); ?></span>
        <span class="font-body-sm text-[11px] text-secondary">Formations terminées</span>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Progression</span>
        <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $progressionMoyenne !== null ? $progressionMoyenne . '%' : '—'; ?></span>
        <span class="font-body-sm text-[11px] text-secondary"><?php echo $progressionMoyenne !== null ? 'Progression moyenne' : 'Non calculable pour le moment'; ?></span>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-purple-600 font-bold">Certifications</span>
        <span class="font-headline-sm text-headline-sm font-bold text-purple-600"><?php echo $nbCertValidees; ?></span>
        <span class="font-body-sm text-[11px] text-secondary">Obtenues et validées</span>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-orange-600 font-bold">En attente</span>
        <span class="font-headline-sm text-headline-sm font-bold text-orange-600"><?php echo $nbCertEnAttente; ?></span>
        <span class="font-body-sm text-[11px] text-secondary">Certifications à valider</span>
      </div>
    </div>

    <!-- Reprendre ma formation -->
    <?php if ($mesEnCours): ?>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Reprendre ma formation</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <?php foreach ($mesEnCours as $ins): $p = $progressions[$ins['id']]; ?>
        <a href="formation-detail.php?id=<?php echo $ins['formation_id']; ?>" class="border border-outline-variant/40 rounded-xl p-space-sm flex flex-col gap-space-2xs hover:bg-surface-container-low transition-colors">
          <p class="font-label-md text-label-md text-on-surface font-bold truncate"><?php echo htmlspecialchars($ins['titre']); ?></p>
          <?php if ($p !== null): ?>
          <div class="w-full h-1.5 rounded-full bg-surface-container-high overflow-hidden"><div class="h-full rounded-full bg-primary-container" style="width:<?php echo $p; ?>%"></div></div>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo $p; ?>% terminé</p>
          <?php else: ?>
          <p class="font-body-sm text-[11px] text-secondary">Commencée le <?php echo htmlspecialchars(date('d/m/Y', strtotime($ins['commence_le']))); ?></p>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- B. Catalogue -->
    <div class="flex flex-col gap-space-sm">
      <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Catalogue des formations</h2>
      <form method="get" class="flex items-center gap-space-sm flex-wrap">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Rechercher un titre..." class="w-full h-10 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
        </div>
        <select name="pack" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Tous les packs</option>
          <option value="global" <?php echo $packFiltre === 'global' ? 'selected' : ''; ?>>Général (tous packs)</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo htmlspecialchars($p['slug']); ?>" <?php echo $packFiltre === $p['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Filtrer</button>
        <?php if ($packFiltre !== '' || $recherche !== ''): ?>
        <a href="formations.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
        <?php endif; ?>
      </form>

      <?php if ($estAdmin): ?>
      <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">add_circle</span>Ajouter une formation</summary>
        <form method="post" class="flex flex-col gap-space-sm pt-space-sm">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="ajouter">
          <input type="text" name="titre" required placeholder="Titre de la formation" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <input type="text" name="organisme" placeholder="Organisme (optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <input type="text" name="duree" placeholder="Durée (ex: 3 jours, optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <input type="url" name="lien" placeholder="Lien (optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <select name="pack_id" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
              <option value="">Général (tous packs)</option>
              <?php foreach ($packs as $p): ?>
              <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <textarea name="description" rows="3" placeholder="Description (optionnel)" class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
          <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">Ajouter</button>
        </form>
      </details>
      <?php endif; ?>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-space-sm">
        <?php if (!$formationsCatalogue): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg sm:col-span-2 lg:col-span-3">Aucune formation ne correspond à ces critères.</p>
        <?php endif; ?>
        <?php foreach ($formationsCatalogue as $f):
          $statutPerso = in_array($f['id'], $idsInscrits)
            ? (($mesInscriptions[array_search($f['id'], array_column($mesInscriptions, 'formation_id'))]['statut'] ?? '') === 'termine' ? 'termine' : 'en_cours')
            : null;
        ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
          <div class="flex items-start justify-between gap-space-xs">
            <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">school</span></div>
            <?php if ($statutPerso === 'termine'): ?><span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-green-100 text-green-700">Terminée</span>
            <?php elseif ($statutPerso === 'en_cours'): ?><span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-blue-100 text-blue-700">En cours</span>
            <?php endif; ?>
          </div>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($f['titre']); ?></p>
          <?php if ($f['description']): ?><p class="font-body-sm text-[12px] text-secondary line-clamp-2"><?php echo htmlspecialchars($f['description']); ?></p><?php endif; ?>
          <p class="font-body-sm text-[11px] text-secondary flex flex-wrap gap-x-1">
            <?php echo htmlspecialchars($f['pack_id'] ? ($packsParId[$f['pack_id']]['nom'] ?? '') : 'Général'); ?>
            <?php if ($f['organisme']): ?>· <?php echo htmlspecialchars($f['organisme']); ?><?php endif; ?>
            <?php if ($f['duree']): ?>· <?php echo htmlspecialchars($f['duree']); ?><?php endif; ?>
          </p>
          <div class="flex items-center gap-space-xs pt-space-xs">
            <a href="formation-detail.php?id=<?php echo $f['id']; ?>" class="flex-1 h-9 rounded-lg bg-primary-container text-white font-label-md text-label-md hover:bg-primary transition-colors flex items-center justify-center gap-1"><span class="material-symbols-outlined text-[16px]">arrow_forward</span><?php echo $statutPerso ? 'Reprendre' : 'Découvrir'; ?></a>
            <?php if ($estAdmin): ?>
            <form method="post" onsubmit="return confirm('Supprimer cette formation ?');">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="supprimer">
              <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
              <button type="submit" class="w-9 h-9 rounded-lg border border-outline-variant text-error hover:bg-error-container/20 transition-colors flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">delete</span></button>
            </form>
            <?php endif; ?>
          </div>
          <?php if ($estAdmin): ?>
          <details class="pt-space-2xs">
            <summary class="font-label-md text-[11px] text-primary cursor-pointer">Gérer les leçons</summary>
            <div class="flex flex-col gap-space-2xs pt-space-xs">
              <?php foreach (formation_lecons((int) $f['id']) as $l): ?>
              <div class="flex items-center justify-between gap-space-xs">
                <span class="font-body-sm text-[11px] text-on-surface truncate"><?php echo htmlspecialchars($l['titre']); ?></span>
                <form method="post" onsubmit="return confirm('Supprimer cette leçon ?');">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="supprimer_lecon"><input type="hidden" name="lecon_id" value="<?php echo $l['id']; ?>">
                  <button type="submit" class="text-error"><span class="material-symbols-outlined text-[14px]">delete</span></button>
                </form>
              </div>
              <?php endforeach; ?>
              <form method="post" class="flex flex-col gap-space-2xs pt-space-2xs border-t border-outline-variant/30">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="ajouter_lecon"><input type="hidden" name="formation_id" value="<?php echo $f['id']; ?>">
                <input type="text" name="titre_lecon" required placeholder="Titre de la leçon" class="h-8 px-space-xs rounded-md border border-outline-variant font-body-sm text-[11px]">
                <input type="url" name="lien_lecon" placeholder="Lien de la ressource (optionnel)" class="h-8 px-space-xs rounded-md border border-outline-variant font-body-sm text-[11px]">
                <button type="submit" class="h-8 rounded-md bg-on-surface text-white font-label-md text-[11px]">Ajouter la leçon</button>
              </form>
            </div>
          </details>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- E. Mes certifications -->
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Mes certifications</h2>

      <?php if (!$mesCertifications): ?>
      <p class="font-body-sm text-body-sm text-secondary">Aucune certification enregistrée pour le moment.</p>
      <?php endif; ?>

      <?php foreach ($mesCertifications as $c): [$label, $classe] = $certStatutLabels[$c['statut']]; ?>
      <div class="flex items-center justify-between gap-space-sm border-b border-outline-variant/30 py-space-sm last:border-0 flex-wrap">
        <div class="min-w-0">
          <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($c['nom']); ?></p>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($c['organisme'] ?: 'Organisme non précisé'); ?> · Obtenue le <?php echo htmlspecialchars(date('d/m/Y', strtotime($c['date_obtention']))); ?><?php echo $c['date_expiration'] ? ' · Expire le ' . htmlspecialchars(date('d/m/Y', strtotime($c['date_expiration']))) : ''; ?></p>
          <?php if ($c['statut'] === 'refusee' && $c['motif_refus']): ?><p class="font-body-sm text-[11px] text-error">Motif : « <?php echo htmlspecialchars($c['motif_refus']); ?> »</p><?php endif; ?>
        </div>
        <div class="flex items-center gap-space-xs shrink-0">
          <span class="px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $classe; ?>"><?php echo htmlspecialchars($label); ?></span>
          <?php if ($c['justificatif_chemin']): ?>
          <a href="certification-telecharger.php?id=<?php echo $c['id']; ?>" target="_blank" rel="noopener" class="h-8 px-space-xs rounded-md border border-outline-variant font-label-md text-[11px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">description</span>Justificatif</a>
          <?php endif; ?>
          <?php if ($c['lien_verification']): ?>
          <a href="<?php echo htmlspecialchars($c['lien_verification']); ?>" target="_blank" rel="noopener" class="h-8 w-8 rounded-md border border-outline-variant flex items-center justify-center text-on-surface"><span class="material-symbols-outlined text-[14px]">open_in_new</span></a>
          <?php endif; ?>
          <?php if ($c['statut'] === 'en_attente'): ?>
          <form method="post" onsubmit="return confirm('Supprimer cette certification ?');">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="supprimer_certification"><input type="hidden" name="certification_id" value="<?php echo $c['id']; ?>">
            <button type="submit" class="h-8 w-8 rounded-md border border-outline-variant text-error flex items-center justify-center"><span class="material-symbols-outlined text-[14px]">delete</span></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <details class="pt-space-xs">
        <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">add_circle</span>Ajouter une certification</summary>
        <?php if ($erreurCertification): ?>
        <p class="font-body-sm text-[12px] text-error pt-space-xs"><?php echo htmlspecialchars($erreurCertification); ?></p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-sm pt-space-sm">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="ajouter_certification">
          <input type="text" name="nom" required placeholder="Nom de la certification" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <input type="text" name="organisme" placeholder="Organisme émetteur (optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <input type="text" name="identifiant" placeholder="Identifiant de certification (optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <label class="flex flex-col gap-0.5"><span class="font-body-sm text-[11px] text-secondary">Date d'obtention</span><input type="date" name="date_obtention" required class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary"></label>
            <label class="flex flex-col gap-0.5"><span class="font-body-sm text-[11px] text-secondary">Date d'expiration (optionnel)</span><input type="date" name="date_expiration" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary"></label>
          </div>
          <input type="url" name="lien_verification" placeholder="Lien de vérification (optionnel)" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <label class="flex flex-col gap-0.5"><span class="font-body-sm text-[11px] text-secondary">Justificatif (PDF, JPG ou PNG — 10 Mo max, optionnel)</span><input type="file" name="justificatif" accept=".pdf,.jpg,.jpeg,.png" class="font-body-sm text-[12px]"></label>
          <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">Ajouter</button>
        </form>
      </details>
    </div>

    <!-- Admin : certifications à valider -->
    <?php if ($estAdmin && $certificationsAdmin): ?>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-orange-100 text-orange-700 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">verified</span></span>Certifications à valider</h2>
      <?php foreach ($certificationsAdmin as $c): $zoneId = 'refus-' . $c['id']; ?>
      <div class="flex flex-col gap-space-xs border-b border-outline-variant/30 py-space-sm last:border-0">
        <div class="flex items-center justify-between gap-space-sm flex-wrap">
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($c['demandeur_prenom'] . ' ' . $c['demandeur_nom']); ?></p>
          </div>
        </div>
        <p class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($c['nom']); ?> · <?php echo htmlspecialchars($c['organisme'] ?: 'Organisme non précisé'); ?> · Obtenue le <?php echo htmlspecialchars(date('d/m/Y', strtotime($c['date_obtention']))); ?></p>
        <div class="flex items-center gap-space-xs flex-wrap">
          <?php if ($c['justificatif_chemin']): ?>
          <a href="certification-telecharger.php?id=<?php echo $c['id']; ?>" target="_blank" rel="noopener" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">description</span>Voir le justificatif</a>
          <?php else: ?>
          <span class="font-body-sm text-[11px] text-secondary">Aucun justificatif joint</span>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Valider cette certification ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="valider_certification"><input type="hidden" name="certification_id" value="<?php echo $c['id']; ?>">
            <button type="submit" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>Valider</button>
          </form>
          <button type="button" onclick="document.getElementById('<?php echo $zoneId; ?>').classList.toggle('hidden');" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-error">Refuser</button>
        </div>
        <div id="<?php echo $zoneId; ?>" class="hidden flex-col gap-space-2xs">
          <form method="post" class="flex items-center gap-space-2xs">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="refuser_certification"><input type="hidden" name="certification_id" value="<?php echo $c['id']; ?>">
            <input required type="text" name="motif_refus" placeholder="Motif du refus..." class="flex-1 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">
            <button type="submit" class="h-9 px-space-sm rounded-lg bg-error text-white font-label-md text-[12px]">Confirmer le refus</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </main>
  </div>
</div>
</body>
</html>
