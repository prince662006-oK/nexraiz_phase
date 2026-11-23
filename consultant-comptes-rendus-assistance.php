<?php
/**
 * NexRaiz — Comptes rendus structurés, Pack Assistance.
 * Tableau de bord + liste filtrable des comptes_rendus (cycle de vie
 * brouillon/à valider/validé/correction demandée/archivé). La page
 * compte-rendu.php (résumé auto-généré) et le bouton "Générer le compte
 * rendu" de consultant-dossier.php restent inchangés : ceci est un
 * document structuré distinct, additionnel.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';
require_once __DIR__ . '/includes/comptes-rendus.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);

$condVisibilite = $voitTout ? '' : ' AND (ds.consultant_id = ? OR ds.consultant_id IS NULL)';
$paramVisibilite = $voitTout ? [] : [$consultantId];

// --- Indicateurs (sur l'ensemble visible, sans tenir compte des filtres de la liste) ---
$stmt = db()->prepare(
    "SELECT cr.statut, COUNT(*) AS total
     FROM comptes_rendus cr
     JOIN dossiers ds ON ds.id = cr.dossier_id
     WHERE ds.pack_id = ?{$condVisibilite}
     GROUP BY cr.statut"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$parStatut = array_column($stmt->fetchAll(), 'total', 'statut');
$totalGlobal = array_sum($parStatut);

$stmt = db()->prepare(
    "SELECT COUNT(*) FROM comptes_rendus cr JOIN dossiers ds ON ds.id = cr.dossier_id
     WHERE ds.pack_id = ?{$condVisibilite} AND cr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$recents7j = (int) $stmt->fetchColumn();

// --- Filtres de la liste ---
$recherche = trim($_GET['q'] ?? '');
$filtreStatut = $_GET['statut'] ?? '';
$filtreType = $_GET['type'] ?? '';
$filtreConsultant = $voitTout ? (int) ($_GET['consultant'] ?? 0) : 0;
$filtrePeriode = $_GET['periode'] ?? '';
$tri = $_GET['tri'] ?? 'recent';

$conditions = ["ds.pack_id = ?"];
$params = [$packId];
if ($condVisibilite !== '') {
    $conditions[] = '(ds.consultant_id = ? OR ds.consultant_id IS NULL)';
    $params[] = $consultantId;
}
if ($recherche !== '') {
    $conditions[] = '(u.nom LIKE ? OR u.prenom LIKE ? OR ds.numero LIKE ? OR cr.reference LIKE ?)';
    $like = '%' . $recherche . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($filtreStatut, ['brouillon', 'a_valider', 'valide', 'correction_demandee', 'archive'], true)) {
    $conditions[] = 'cr.statut = ?';
    $params[] = $filtreStatut;
}
if (array_key_exists($filtreType, cr_types_labels())) {
    $conditions[] = 'cr.type_intervention = ?';
    $params[] = $filtreType;
}
if ($filtreConsultant > 0) {
    $conditions[] = 'ds.consultant_id = ?';
    $params[] = $filtreConsultant;
}
if ($filtrePeriode === '7j') {
    $conditions[] = 'cr.date_intervention >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($filtrePeriode === '30j') {
    $conditions[] = 'cr.date_intervention >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
}

$ordreSQL = match ($tri) {
    'ancien' => 'cr.date_intervention ASC',
    'client' => 'u.nom ASC, u.prenom ASC',
    default => 'cr.date_intervention DESC, cr.id DESC',
};

$sql = "SELECT cr.id, cr.reference, cr.type_intervention, cr.date_intervention, cr.statut, cr.created_at, cr.partage_le,
            ds.id AS dossier_id, ds.numero, u.nom, u.prenom, uc.prenom AS consultant_prenom, uc.nom AS consultant_nom
         FROM comptes_rendus cr
         JOIN dossiers ds ON ds.id = cr.dossier_id
         JOIN users u ON u.id = ds.user_id
         LEFT JOIN users uc ON uc.id = ds.consultant_id
         WHERE " . implode(' AND ', $conditions) . "
         ORDER BY {$ordreSQL}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$comptesRendus = $stmt->fetchAll();

$listeConsultants = $voitTout ? db()->query("SELECT id, nom, prenom FROM users WHERE role IN ('consultant', 'admin') ORDER BY prenom, nom")->fetchAll() : [];

$typesLabels = cr_types_labels();
$statutsLabels = cr_statuts_labels();

$parametresConserves = ['q' => $recherche, 'statut' => $filtreStatut, 'type' => $filtreType, 'consultant' => $filtreConsultant ?: '', 'periode' => $filtrePeriode, 'tri' => $tri];

render_head('Espace NexRaiz — Comptes rendus', 'Comptes rendus structurés des interventions, Pack Assistance.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dashboard_sidebar($packId, 'comptes'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-lg min-w-0">
        <a href="index.php" class="flex items-center gap-1.5 lg:hidden shrink-0">
          <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
        </a>
        <nav class="hidden md:flex items-center gap-space-md">
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
          <a href="consultant-dossiers-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Diagnostic</a>
          <a href="consultant-suivi-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Suivi</a>
          <a href="consultant-comptes-rendus-assistance.php" class="font-label-lg text-label-lg text-primary font-bold border-b-2 border-primary pb-1">Comptes rendus</a>
          <a href="parametres.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Paramètres</a>
        </nav>
      </div>
      <div class="flex items-center gap-space-xs shrink-0">
        <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
        <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div class="flex items-center justify-between gap-space-sm flex-wrap">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Comptes rendus</h1>
          <p class="font-body-sm text-body-sm text-secondary">Comptes rendus structurés d'intervention — distincts du résumé automatique du dossier.</p>
        </div>
        <a href="consultant-compte-rendu-form.php" class="h-10 px-space-md rounded-lg bg-primary text-white font-label-md text-label-md flex items-center gap-1 shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[16px]">add</span>Nouveau compte rendu</a>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-5 gap-space-sm">
        <?php
        $cartes = [
            ['cle' => '', 'label' => 'Total', 'valeur' => $totalGlobal, 'icone' => 'folder_copy', 'couleur' => 'text-primary'],
            ['cle' => 'brouillon', 'label' => 'Brouillons', 'valeur' => $parStatut['brouillon'] ?? 0, 'icone' => 'edit_note', 'couleur' => 'text-secondary'],
            ['cle' => 'a_valider', 'label' => 'À valider', 'valeur' => $parStatut['a_valider'] ?? 0, 'icone' => 'hourglass_top', 'couleur' => 'text-orange-600'],
            ['cle' => 'valide', 'label' => 'Validés', 'valeur' => $parStatut['valide'] ?? 0, 'icone' => 'task_alt', 'couleur' => 'text-green-600'],
            ['cle' => '', 'label' => '7 derniers jours', 'valeur' => $recents7j, 'icone' => 'schedule', 'couleur' => 'text-primary', 'lien_periode' => '7j'],
        ];
        foreach ($cartes as $carte):
            $lienQuery = $carte['cle'] !== '' ? ['statut' => $carte['cle']] : (isset($carte['lien_periode']) ? ['periode' => $carte['lien_periode']] : []);
        ?>
        <a href="consultant-comptes-rendus-assistance.php<?php echo $lienQuery ? '?' . http_build_query($lienQuery) : ''; ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-col gap-1 hover:shadow-md transition-shadow <?php echo ($filtreStatut === $carte['cle'] && $carte['cle'] !== '') ? 'ring-2 ring-primary' : ''; ?>">
          <span class="material-symbols-outlined text-[18px] <?php echo $carte['couleur']; ?>"><?php echo $carte['icone']; ?></span>
          <span class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo (int) $carte['valeur']; ?></span>
          <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($carte['label']); ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-wrap items-end gap-space-xs">
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[11px] text-secondary">Recherche</label>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Client, dossier, référence…" class="h-9 w-56 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
        </div>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[11px] text-secondary">Statut</label>
          <select name="statut" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">Tous</option>
            <?php foreach ($statutsLabels as $cle => [$label, ]): ?>
            <option value="<?php echo $cle; ?>" <?php echo $filtreStatut === $cle ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[11px] text-secondary">Type</label>
          <select name="type" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">Tous</option>
            <?php foreach ($typesLabels as $cle => $label): ?>
            <option value="<?php echo $cle; ?>" <?php echo $filtreType === $cle ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($voitTout): ?>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[11px] text-secondary">Consultant</label>
          <select name="consultant" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">Tous</option>
            <?php foreach ($listeConsultants as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $filtreConsultant === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[11px] text-secondary">Période</label>
          <select name="periode" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">Toutes</option>
            <option value="7j" <?php echo $filtrePeriode === '7j' ? 'selected' : ''; ?>>7 derniers jours</option>
            <option value="30j" <?php echo $filtrePeriode === '30j' ? 'selected' : ''; ?>>30 derniers jours</option>
          </select>
        </div>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[11px] text-secondary">Tri</label>
          <select name="tri" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="recent" <?php echo $tri === 'recent' ? 'selected' : ''; ?>>Plus récent</option>
            <option value="ancien" <?php echo $tri === 'ancien' ? 'selected' : ''; ?>>Plus ancien</option>
            <option value="client" <?php echo $tri === 'client' ? 'selected' : ''; ?>>Client (A→Z)</option>
          </select>
        </div>
        <button type="submit" class="h-9 px-space-md rounded-lg bg-primary text-white font-label-md text-[13px]">Filtrer</button>
        <?php if ($recherche || $filtreStatut || $filtreType || $filtreConsultant || $filtrePeriode || $tri !== 'recent'): ?>
        <a href="consultant-comptes-rendus-assistance.php" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[13px] text-secondary flex items-center">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <?php if (!$comptesRendus): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-md">Aucun compte rendu ne correspond à ces critères.</p>
        <?php endif; ?>
        <?php foreach ($comptesRendus as $c): [$labelStatut, $classeStatut] = $statutsLabels[$c['statut']]; ?>
        <a href="consultant-compte-rendu.php?id=<?php echo $c['id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:shadow-sm transition-shadow">
          <div class="flex items-center gap-space-xs min-w-0">
            <div class="w-9 h-9 rounded bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">description</span></div>
            <div class="min-w-0">
              <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($c['reference']); ?> — <?php echo htmlspecialchars($typesLabels[$c['type_intervention']] ?? $c['type_intervention']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom'] . ' · ' . $c['numero']); ?> · Intervention du <?php echo htmlspecialchars(date('d/m/Y', strtotime($c['date_intervention']))); ?><?php echo $voitTout && $c['consultant_prenom'] ? ' · ' . htmlspecialchars($c['consultant_prenom'] . ' ' . $c['consultant_nom']) : ''; ?></p>
            </div>
          </div>
          <span class="shrink-0 px-space-xs py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
