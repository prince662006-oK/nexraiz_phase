<?php
/**
 * NexRaiz — Centre de pilotage des analyses, Pack Classic (espace
 * consultant). Le traitement détaillé (corrections, livrables, plan
 * d'action) reste dans consultant-corrections-classic.php : cette page ne
 * fait qu'orienter le consultant vers les bons dossiers, à partir d'un
 * statut d'analyse unifié calculé une seule fois dans
 * includes/consultant-classic.php (classic_dossiers_apercu()).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);

$dossiersTous = classic_dossiers_apercu($packId, $consultant);
// Visibilité : un consultant ne voit que ses dossiers + les non assignés
// (même règle que le Pack Assistance — nécessaire pour l'appropriation au
// premier accès, voir consultant-corrections-classic.php) ; un admin voit
// tout. Appliqué ici localement : classic_dossiers_apercu() est partagée
// par 8 autres pages dont le comportement ne doit pas changer.
$dossiers = $voitTout ? $dossiersTous : array_values(array_filter($dossiersTous, fn ($d) => $d['est_mienne'] || $d['consultant_id'] === null));

$idsVisibles = array_column($dossiers, 'id');
$nbDocuments = 0;
if ($idsVisibles) {
    $placeholders = implode(',', array_fill(0, count($idsVisibles), '?'));
    $stmt = db()->prepare("SELECT COUNT(*) FROM documents WHERE dossier_id IN ($placeholders)");
    $stmt->execute($idsVisibles);
    $nbDocuments = (int) $stmt->fetchColumn();
}

$nbDossiers = count($dossiers);
$nbEnCours = count(array_filter($dossiers, fn ($d) => $d['est_en_cours']));
$nbPrets = count(array_filter($dossiers, fn ($d) => $d['est_prete']));

$stats = [
    ['icone' => 'folder', 'couleur' => 'bg-primary-container/10 text-primary', 'valeur' => $nbDossiers, 'label' => 'Dossiers candidats', 'delta' => 'Dossiers que vous pouvez traiter', 'url' => 'consultant-analyse-classic.php'],
    ['icone' => 'description', 'couleur' => 'bg-blue-100 text-blue-700', 'valeur' => $nbDocuments, 'label' => 'Documents reçus', 'delta' => 'Fichiers déposés (toutes versions)', 'url' => null],
    ['icone' => 'schedule', 'couleur' => 'bg-orange-100 text-orange-600', 'valeur' => $nbEnCours, 'label' => 'Analyses en cours', 'delta' => 'À analyser ou en cours de correction', 'url' => 'consultant-analyse-classic.php?vue=en_cours'],
    ['icone' => 'check_circle', 'couleur' => 'bg-purple-100 text-purple-700', 'valeur' => $nbPrets, 'label' => 'Prêts à publier', 'delta' => 'Correction écrite, en attente d\'envoi', 'url' => 'consultant-analyse-classic.php?vue=pret'],
];

$statutsLabels = classic_statuts_analyse_labels();

// --- Filtres réels, tous calculés à partir des données ci-dessus. ---
$vuesSidebar = [
    'mes'       => ['icone' => 'assignment_ind', 'label' => 'Mes analyses',     'champ' => 'est_mienne'],
    'en_cours'  => ['icone' => 'hourglass_top',   'label' => 'Analyses en cours', 'champ' => 'est_en_cours'],
    'pret'      => ['icone' => 'check_circle',    'label' => 'Analyses prêtes',   'champ' => 'est_prete'],
    'a_valider' => ['icone' => 'task_alt',        'label' => 'À valider',         'champ' => 'est_a_valider'],
    'archivees' => ['icone' => 'archive',         'label' => 'Archivées',         'champ' => 'est_cloturee'],
];
$vue = $_GET['vue'] ?? '';
if (!isset($vuesSidebar[$vue])) {
    $vue = '';
}
$posteFiltre = trim($_GET['poste'] ?? '');
$sourceFiltre = trim($_GET['source'] ?? '');
$moisFiltre = trim($_GET['mois'] ?? '');
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? 'recent';
$type = in_array($_GET['type'] ?? '', ['cv', 'lettre', 'linkedin'], true) ? $_GET['type'] : '';

$postesDisponibles = array_values(array_unique(array_map(fn ($d) => $d['poste'], $dossiers)));
sort($postesDisponibles);
$sourcesDisponibles = array_values(array_unique(array_map(fn ($d) => $d['source'], $dossiers)));
sort($sourcesDisponibles);
$moisDisponibles = array_values(array_unique(array_map(fn ($d) => $d['mois'], $dossiers)));
rsort($moisDisponibles);
$moisNomsCourts = [1 => 'Janv.', 2 => 'Févr.', 3 => 'Mars', 4 => 'Avr.', 5 => 'Mai', 6 => 'Juin', 7 => 'Juil.', 8 => 'Août', 9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Déc.'];

$dossiersFiltres = array_values(array_filter($dossiers, function ($d) use ($vue, $vuesSidebar, $posteFiltre, $sourceFiltre, $moisFiltre, $recherche, $type) {
    if ($vue !== '' && empty($d[$vuesSidebar[$vue]['champ']])) {
        return false;
    }
    if ($posteFiltre !== '' && $d['poste'] !== $posteFiltre) {
        return false;
    }
    if ($sourceFiltre !== '' && $d['source'] !== $sourceFiltre) {
        return false;
    }
    if ($moisFiltre !== '' && $d['mois'] !== $moisFiltre) {
        return false;
    }
    if ($recherche !== '' && stripos($d['nom'] . ' ' . $d['numero'], $recherche) === false) {
        return false;
    }
    // Vue spécialisée par type de document : on ne garde que les dossiers où
    // ce document a réellement quelque chose à montrer (reçu, même
    // incomplet par ailleurs) — un dossier sans CV n'a pas sa place dans la
    // vue "Analyse du CV".
    if ($type === 'cv' && !$d['cv']) return false;
    if ($type === 'lettre' && !$d['lettre']) return false;
    if ($type === 'linkedin' && !$d['linkedin']) return false;
    return true;
}));

if ($tri === 'ancien') {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));
} elseif ($tri === 'maj') {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($b['updated_at']) - strtotime($a['updated_at']));
} else {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
}

/** Échappe une valeur contre l'injection de formule tableur (=, +, -, @ en tête de cellule). */
function nx_csv_safe(string $valeur): string
{
    return preg_match('/^[=+\-@]/', $valeur) ? "'" . $valeur : $valeur;
}

// --- Export CSV de la vue filtrée (avant tout affichage HTML). ---
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dossiers-classic-' . date('Y-m-d') . '.csv"');
    $sortie = fopen('php://output', 'w');
    fputs($sortie, "\xEF\xBB\xBF"); // BOM pour Excel
    fputcsv($sortie, ['Nom', 'Numéro de dossier', 'Poste visé', 'CV', 'Lettre', 'LinkedIn', 'Statut', 'Source', 'Créé le'], ';');
    foreach ($dossiersFiltres as $d) {
        fputcsv($sortie, [
            nx_csv_safe($d['nom']),
            $d['numero'],
            nx_csv_safe($d['poste']),
            $d['cv'] ? 'Oui' : 'Non',
            $d['lettre'] ? 'Oui' : 'Non',
            $d['linkedin'] ? 'Oui' : 'Non',
            $d['statut'],
            nx_csv_safe($d['source']),
            date('d/m/Y', strtotime($d['created_at'])),
        ], ';');
    }
    fclose($sortie);
    exit;
}

$parPage = 10;
$totalFiltre = count($dossiersFiltres);
$totalPages = max(1, (int) ceil($totalFiltre / $parPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$dossiersPage = array_slice($dossiersFiltres, ($page - 1) * $parPage, $parPage);

function nx_url_filtre(array $overrides = []): string
{
    $base = ['vue' => $_GET['vue'] ?? '', 'type' => $_GET['type'] ?? '', 'poste' => $_GET['poste'] ?? '', 'source' => $_GET['source'] ?? '', 'mois' => $_GET['mois'] ?? '', 'q' => $_GET['q'] ?? '', 'tri' => $_GET['tri'] ?? '', 'page' => $_GET['page'] ?? ''];
    $params = array_merge($base, $overrides);

    if ((int) ($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }
    $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

    return 'consultant-analyse-classic.php' . ($params ? '?' . http_build_query($params) : '');
}

$modules = [
    ['cle' => 'cv', 'icone' => 'description', 'couleur' => 'bg-primary-container text-white', 'titre' => 'Analyse du CV', 'texte' => 'Dossiers avec un CV reçu, par étape réelle d\'analyse.', 'url' => nx_url_filtre(['type' => 'cv', 'vue' => '', 'page' => ''])],
    ['cle' => 'lettre', 'icone' => 'mail', 'couleur' => 'bg-green-600 text-white', 'titre' => 'Analyse de la lettre', 'texte' => 'Dossiers avec une lettre reçue, par étape réelle d\'analyse.', 'url' => nx_url_filtre(['type' => 'lettre', 'vue' => '', 'page' => ''])],
    ['cle' => 'linkedin', 'icone' => 'work_history', 'couleur' => 'bg-blue-600 text-white', 'titre' => 'Analyse LinkedIn', 'texte' => 'Dossiers avec un profil LinkedIn renseigné.', 'url' => nx_url_filtre(['type' => 'linkedin', 'vue' => '', 'page' => ''])],
    ['cle' => '', 'icone' => 'groups', 'couleur' => 'bg-purple-600 text-white', 'titre' => 'Préparation entretien', 'texte' => "Identifiez les points clés et préparez vos entretiens.", 'url' => 'consultant-entretiens-classic.php'],
];

// --- "Priorités du jour" : listes réelles, jamais une urgence inventée —
// aucune échéance n'existe sur un dossier Pack Classic, donc aucun retard
// n'est affiché ici (voir audit). ---
$prioriteDocsIncomplets = array_values(array_filter($dossiers, fn ($d) => $d['statut_cle'] === 'document_incomplet'));
$prioritePretsAPublier = array_values(array_filter($dossiers, fn ($d) => $d['statut_cle'] === 'pret_a_publier'));
$prioriteAValider = array_values(array_filter($dossiers, fn ($d) => $d['est_a_valider']));

// --- Répartition réelle des dossiers actuellement affichés (pas une barre
// de progression arbitraire, et pas la progression d'un dossier unique). ---
$repartition = ['document_incomplet' => 0, 'a_analyser' => 0, 'analyse_en_cours' => 0, 'pret_a_publier' => 0, 'publie' => 0, 'cloture' => 0];
foreach ($dossiersFiltres as $d) {
    $repartition[$d['statut_cle']]++;
}

// --- Légende des annotations consultant (3 catégories réelles, jamais 6
// "critères" inventés) — compte réel sur les dossiers visibles. ---
$annotationsParCategorie = ['contenu' => 0, 'competence' => 0, 'style' => 0];
if ($idsVisibles) {
    $placeholders = implode(',', array_fill(0, count($idsVisibles), '?'));
    $stmt = db()->prepare("SELECT categorie, COUNT(*) AS nb FROM correction_annotations WHERE dossier_id IN ($placeholders) GROUP BY categorie");
    $stmt->execute($idsVisibles);
    foreach ($stmt->fetchAll() as $row) {
        $annotationsParCategorie[$row['categorie']] = (int) $row['nb'];
    }
}

render_head('Espace NexRaiz — Analyse', 'Analyse des candidatures Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <nav class="hidden md:flex items-center gap-space-2xs">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">home</span>Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">folder</span>Dossiers</a>
        <a href="consultant-analyse-classic.php" class="px-space-sm h-9 flex items-center gap-1 rounded-xl bg-surface-container-low text-primary font-label-lg text-label-lg font-bold border-b-2 border-primary"><span class="material-symbols-outlined text-[18px]">bar_chart</span>Analyses</a>
        <a href="consultant-corrections-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">fact_check</span>Corrections</a>
        <a href="consultant-entretiens-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">groups</span>Entretiens</a>
        <a href="parametres.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">settings</span>Paramètres</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <div class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
      <div class="hidden sm:flex flex-col leading-tight">
        <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
        <span class="font-body-sm text-[11px] text-secondary"><?php echo $consultant['role'] === 'admin' ? 'Administrateur' : 'Consultant'; ?></span>
      </div>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiers, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div class="flex items-start justify-between gap-space-sm flex-wrap">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Analyses — Pack Classic</h1>
          <p class="font-body-sm text-body-sm text-secondary">Centre de pilotage des candidatures à traiter. <?php echo $voitTout ? 'Vue administrateur : tous les dossiers du pack.' : 'Vos dossiers, et les dossiers non encore attribués.'; ?></p>
        </div>
        <a href="<?php echo htmlspecialchars(nx_url_filtre(['export' => 'csv'])); ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[16px]">download</span>Exporter la vue (CSV)</a>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <?php foreach ($stats as $s): $contenu = '
          <div class="w-11 h-11 rounded-full ' . $s['couleur'] . ' flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">' . $s['icone'] . '</span></div>
          <p class="font-headline-lg text-headline-lg text-on-surface font-bold">' . $s['valeur'] . '</p>
          <p class="font-label-lg text-label-lg text-on-surface font-semibold">' . htmlspecialchars($s['label']) . '</p>
          <p class="font-body-sm text-[11px] text-secondary">' . htmlspecialchars($s['delta']) . '</p>
        '; ?>
        <?php if ($s['url']): ?>
        <a href="<?php echo htmlspecialchars($s['url']); ?>" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs hover:shadow-md transition-shadow"><?php echo $contenu; ?></a>
        <?php else: ?>
        <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs"><?php echo $contenu; ?></div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($prioriteDocsIncomplets || $prioritePretsAPublier || $prioriteAValider): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Priorités du jour</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-space-sm">
          <?php
          $blocs = [
              ['titre' => 'Documents manquants', 'icone' => 'folder_off', 'couleur' => 'text-red-700', 'liste' => $prioriteDocsIncomplets],
              ['titre' => 'Prêts à publier', 'icone' => 'check_circle', 'couleur' => 'text-purple-700', 'liste' => $prioritePretsAPublier],
              ['titre' => 'En attente de votre validation', 'icone' => 'task_alt', 'couleur' => 'text-blue-700', 'liste' => $prioriteAValider],
          ];
          foreach ($blocs as $bloc): if (!$bloc['liste']) continue;
          ?>
          <div class="flex flex-col gap-space-2xs">
            <p class="flex items-center gap-1 font-label-md text-label-md font-bold <?php echo $bloc['couleur']; ?>"><span class="material-symbols-outlined text-[16px]"><?php echo $bloc['icone']; ?></span><?php echo htmlspecialchars($bloc['titre']); ?> (<?php echo count($bloc['liste']); ?>)</p>
            <?php foreach (array_slice($bloc['liste'], 0, 4) as $d): ?>
            <a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>" class="font-body-sm text-[12px] text-on-surface hover:text-primary hover:underline truncate"><?php echo htmlspecialchars($d['nom']); ?> · <?php echo htmlspecialchars($d['numero']); ?></a>
            <?php endforeach; ?>
            <?php if (count($bloc['liste']) > 4): ?><span class="font-body-sm text-[11px] text-secondary">+ <?php echo count($bloc['liste']) - 4; ?> autre(s)</span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center justify-between flex-wrap gap-space-xs">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">
            Dossiers <?php echo $type !== '' ? '— ' . ['cv' => 'Analyse du CV', 'lettre' => 'Analyse de la lettre', 'linkedin' => 'Analyse LinkedIn'][$type] : ($vue !== '' ? htmlspecialchars($vuesSidebar[$vue]['label']) : 'récents'); ?>
            <span class="font-body-sm text-[12px] text-secondary font-normal">(<?php echo $totalFiltre; ?> résultat<?php echo $totalFiltre > 1 ? 's' : ''; ?>)</span>
          </h2>
          <?php if ($vue !== '' || $posteFiltre !== '' || $sourceFiltre !== '' || $moisFiltre !== '' || $recherche !== '' || $type !== ''): ?>
          <a href="consultant-analyse-classic.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">close</span>Réinitialiser les filtres</a>
          <?php endif; ?>
        </div>

        <form method="get" class="flex flex-wrap items-end gap-space-xs">
          <?php if ($vue !== ''): ?><input type="hidden" name="vue" value="<?php echo htmlspecialchars($vue); ?>"><?php endif; ?>
          <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>"><?php endif; ?>
          <div class="relative flex-1 min-w-[180px]">
            <span class="material-symbols-outlined text-[16px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom du candidat ou numéro de dossier..." class="w-full h-9 pl-space-2xl pr-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]" style="padding-left:2rem">
          </div>
          <select name="tri" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="recent" <?php echo $tri !== 'ancien' && $tri !== 'maj' ? 'selected' : ''; ?>>Création la plus récente</option>
            <option value="ancien" <?php echo $tri === 'ancien' ? 'selected' : ''; ?>>Création la plus ancienne</option>
            <option value="maj" <?php echo $tri === 'maj' ? 'selected' : ''; ?>>Dernière activité</option>
          </select>
          <button type="submit" class="h-9 px-space-md rounded-lg bg-on-surface text-white font-label-md text-[13px]">Rechercher</button>
        </form>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[720px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Candidat</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Poste visé</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Créé le</th>
                <th class="text-center font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">CV</th>
                <th class="text-center font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Lettre</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dossiersPage as $d): [$statutLabel, $statutClasse] = $statutsLabels[$d['statut_cle']]; ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                <td class="py-space-sm pr-space-sm">
                  <a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>" class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo $d['initiales']; ?></span>
                    <span class="min-w-0">
                      <span class="block font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom']); ?></span>
                      <span class="block font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['numero']); ?></span>
                    </span>
                    <?php if ($d['messages_non_lus'] > 0): ?>
                    <span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold shrink-0" title="<?php echo $d['messages_non_lus']; ?> nouveau(x) message(s)"><?php echo $d['messages_non_lus']; ?></span>
                    <?php endif; ?>
                  </a>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['poste']); ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))); ?></td>
                <td class="py-space-sm pr-space-sm text-center"><span class="material-symbols-outlined text-[18px] <?php echo $d['cv'] ? 'text-green-600' : 'text-error'; ?>"><?php echo $d['cv'] ? 'check_circle' : 'do_not_disturb_on'; ?></span></td>
                <td class="py-space-sm pr-space-sm text-center"><span class="material-symbols-outlined text-[18px] <?php echo $d['lettre'] ? 'text-green-600' : 'text-error'; ?>"><?php echo $d['lettre'] ? 'check_circle' : 'do_not_disturb_on'; ?></span></td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $statutClasse; ?>"><?php echo htmlspecialchars($statutLabel); ?></span></td>
                <td class="py-space-sm">
                  <?php if ($d['statut_cle'] === 'document_incomplet'): ?>
                  <a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>" class="font-label-md text-[12px] text-on-surface hover:text-primary flex items-center gap-1">Voir les documents manquants <span class="material-symbols-outlined text-[14px]">chevron_right</span></a>
                  <?php elseif (in_array($d['statut_cle'], ['a_analyser', 'analyse_en_cours'], true)):
                    // Ouvre l'onglet du document qui a encore besoin de travail ; le CV est prioritaire si les deux en ont besoin.
                    $ongletSuggere = in_array($d['statut_cv'], ['a_analyser', 'en_cours'], true) ? 'cv' : 'lettre';
                  ?>
                  <a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>&onglet=<?php echo $ongletSuggere; ?>" class="font-label-md text-[12px] text-primary hover:underline flex items-center gap-1">Analyser <span class="material-symbols-outlined text-[14px]">chevron_right</span></a>
                  <?php elseif ($d['statut_cle'] === 'pret_a_publier'): ?>
                  <a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>#livrables" class="font-label-md text-[12px] text-purple-700 hover:underline flex items-center gap-1">Vérifier et envoyer <span class="material-symbols-outlined text-[14px]">chevron_right</span></a>
                  <?php else: ?>
                  <a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>" class="font-label-md text-[12px] text-secondary hover:text-primary flex items-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[14px]">chevron_right</span></a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$dossiersPage): ?>
              <tr><td colspan="7" class="py-space-lg text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à ces critères.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="flex items-center justify-between pt-space-xs">
          <span class="font-body-sm text-[12px] text-secondary"><?php echo count($dossiersPage); ?> dossier<?php echo count($dossiersPage) > 1 ? 's' : ''; ?> affiché<?php echo count($dossiersPage) > 1 ? 's' : ''; ?> sur <?php echo $totalFiltre; ?></span>
          <span class="flex items-center gap-space-xs font-body-sm text-[12px] text-secondary">Page <?php echo $page; ?> / <?php echo $totalPages; ?>
            <?php if ($page > 1): ?>
            <a href="<?php echo htmlspecialchars(nx_url_filtre(['page' => $page - 1])); ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-low flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">chevron_left</span></a>
            <?php else: ?>
            <span class="w-7 h-7 rounded-full flex items-center justify-center text-outline-variant"><span class="material-symbols-outlined text-[16px]">chevron_left</span></span>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo htmlspecialchars(nx_url_filtre(['page' => $page + 1])); ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-low flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">chevron_right</span></a>
            <?php else: ?>
            <span class="w-7 h-7 rounded-full flex items-center justify-center text-outline-variant"><span class="material-symbols-outlined text-[16px]">chevron_right</span></span>
            <?php endif; ?>
          </span>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <?php foreach ($modules as $m): ?>
        <a href="<?php echo htmlspecialchars($m['url']); ?>" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col items-center text-center gap-space-sm hover:shadow-md transition-shadow <?php echo $type === $m['cle'] && $type !== '' ? 'ring-2 ring-primary' : ''; ?>">
          <div class="w-14 h-14 rounded-full <?php echo $m['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[26px]"><?php echo $m['icone']; ?></span></div>
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($m['titre']); ?></h3>
          <p class="font-body-sm text-[12px] text-secondary flex-1"><?php echo htmlspecialchars($m['texte']); ?></p>
        </a>
        <?php endforeach; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Répartition des dossiers affichés</h2>
          <p class="font-body-sm text-[12px] text-secondary">Résume l'état des <?php echo $totalFiltre; ?> dossier(s) de la vue actuelle — pas la progression d'un dossier en particulier.</p>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-space-sm">
          <?php foreach ($statutsLabels as $cle => [$label, $classe]): ?>
          <div class="rounded-xl border border-outline-variant/50 p-space-sm flex flex-col gap-0.5">
            <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $repartition[$cle]; ?></span>
            <span class="inline-flex self-start px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classe; ?>"><?php echo htmlspecialchars($label); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Annotations consultant</h2>
          <p class="font-body-sm text-[12px] text-secondary">Nombre réel d'annotations déjà enregistrées sur vos dossiers, par catégorie (voir l'onglet Corrections de chaque dossier).</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
          <?php
          $categoriesAnnotations = [
              'contenu' => ['icone' => 'layers', 'label' => 'Amélioration de contenu'],
              'competence' => ['icone' => 'key', 'label' => 'Compétence ajoutée'],
              'style' => ['icone' => 'grid_view', 'label' => 'Style et clarté'],
          ];
          foreach ($categoriesAnnotations as $cle => $info):
          ?>
          <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex items-center gap-space-sm">
            <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]"><?php echo $info['icone']; ?></span></div>
            <div>
              <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $annotationsParCategorie[$cle]; ?></p>
              <p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($info['label']); ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </main>
  </div>

  <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm max-w-max-width-content mx-auto w-full">
      <div class="flex items-center gap-1.5">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span>
      </div>
      <nav class="flex flex-wrap items-center gap-space-md">
        <a href="contact.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Contact</a>
      </nav>
    </div>
  </footer>
</div>
</body>
</html>
