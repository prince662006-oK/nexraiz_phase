<?php
/**
 * NexRaiz — « Pack Booster », espace consultant : centre opérationnel de
 * gestion des prestations. Complémentaire à consultant-parcours-booster.php
 * (qui montre la progression globale du parcours client) : ici, l'angle est
 * le travail concret du consultant — dossiers à prendre en charge,
 * documents à vérifier, tâches à exécuter, livrables à préparer.
 *
 * Même source de données que « Parcours client »
 * (includes/consultant-booster.php::booster_parcours_dossiers()) — aucun
 * second système de progression, aucune table dupliquée. Seule la lecture
 * et le regroupement diffèrent (file de travail par type d'intervention au
 * lieu d'étape du parcours).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');
$consultantId = (int) $consultant['id'];

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $dossierIdPost = (int) ($_POST['dossier_id'] ?? 0);

    if ($action === 'prendre_en_charge') {
        if (booster_prendre_en_charge($dossierIdPost, $packId, $consultantId)) {
            $succes = 'Dossier pris en charge.';
        }
    } elseif ($action === 'generer_livrables') {
        $stmt = db()->prepare('SELECT user_id FROM dossiers WHERE id = ? AND pack_id = ?');
        $stmt->execute([$dossierIdPost, $packId]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            booster_generer_livrables($dossierIdPost, (int) $userId);
            $succes = 'Livrables générés et transmis au client.';
        }
    }

    header('Location: consultant-pack-booster.php?succes=' . urlencode($succes));
    exit;
}
if (isset($_GET['succes']) && $_GET['succes'] !== '') { $succes = $_GET['succes']; }

$tous = booster_parcours_dossiers($packId);

// --- Indicateurs (section 1) : définitions explicites, sur le même
// périmètre que « Parcours client » (tous les dossiers Pack Booster). ---
$nbActifs = count(array_filter($tous, fn ($d) => $d['statut_dossier'] !== 'cloture'));
$nbAPrendreEnCharge = count(array_filter($tous, fn ($d) => $d['prise_en_charge'] && $d['statut_dossier'] !== 'cloture'));
$nbPrestationsEnCours = count(array_filter($tous, fn ($d) => $d['etape'] === 'accompagnement'));
$nbActionsOperationnelles = array_sum(array_map(fn ($d) => $d['nb_actions_a_verifier'] + $d['nb_actions_consultant_ouvertes'], $tous));
$nbDocumentsAVerifier = array_sum(array_map(fn ($d) => $d['nb_documents_a_verifier'], $tous));
$nbLivrablesAPreparer = count(array_filter($tous, fn ($d) => $d['livrable_a_preparer']));
$nbTerminees = count(array_filter($tous, fn ($d) => $d['statut_dossier'] === 'cloture'));

// --- Espace de travail (sections 4-5) : regroupe les raisons déjà calculées
// par booster_parcours_dossiers() (même source que « Parcours client »,
// jamais un second calcul de priorité). ---
$queueLabels = [
    'person_add'         => ['Dossiers à prendre en charge', 'person_add'],
    'mail'                => ['Demandes client à traiter', 'mail'],
    'fact_check'          => ['Documents à vérifier', 'fact_check'],
    'task_alt'            => ['Actions soumises à valider', 'task_alt'],
    'event_available'     => ['Rendez-vous à confirmer', 'event_available'],
    'event_upcoming'      => ['Rendez-vous à préparer', 'event_upcoming'],
    'description'         => ['Livrables à préparer', 'description'],
    'payments'            => ['Virements à confirmer', 'payments'],
    'hourglass_disabled'  => ['Dossiers sans activité récente', 'hourglass_disabled'],
];
$queues = array_fill_keys(array_keys($queueLabels), []);
foreach ($tous as $d) {
    foreach ($d['raisons_consultant'] as $r) {
        if (isset($queues[$r['icone']])) {
            $queues[$r['icone']][] = ['dossier' => $d, 'raison' => $r];
        }
    }
}

// --- Prestations prêtes à finaliser : accompagnement en cours, plus
// aucune action ouverte ni document en vérification, au moins un livrable
// déjà généré — candidates réelles à la clôture, jamais automatique. ---
$aFinaliser = array_values(array_filter($tous, fn ($d) => $d['etape'] === 'accompagnement' && $d['nb_actions_a_verifier'] === 0 && $d['nb_documents_a_verifier'] === 0 && $d['nb_livrables'] > 0 && $d['prochaine_action'] === null));

// --- Filtres, recherche, tri, pagination (section 2) ---
$vue = $_GET['vue'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$parPage = 25;

$dossiersFiltres = array_values(array_filter($tous, function ($d) use ($vue, $recherche) {
    if ($vue === 'a_prendre_en_charge' && !($d['prise_en_charge'] && $d['statut_dossier'] !== 'cloture')) { return false; }
    if ($vue === 'en_cours' && $d['statut_dossier'] === 'cloture') { return false; }
    if ($vue === 'attente_client' && $d['categorie'] !== 'bloque') { return false; }
    if ($vue === 'attente_consultant' && $d['categorie'] !== 'attente_intervention') { return false; }
    if ($vue === 'documents_a_verifier' && $d['nb_documents_a_verifier'] === 0) { return false; }
    if ($vue === 'livrables_a_produire' && !$d['livrable_a_preparer']) { return false; }
    if ($vue === 'termines' && $d['statut_dossier'] !== 'cloture') { return false; }
    if ($recherche !== '' && stripos($d['client'] . ' ' . $d['numero'], $recherche) === false) { return false; }
    return true;
}));

if ($tri === 'echeance') {
    $echVal = fn ($d) => $d['prochaine_action']['echeance'] ?? null;
    usort($dossiersFiltres, fn ($a, $b) => ($echVal($a) ? strtotime($echVal($a)) : PHP_INT_MAX) <=> ($echVal($b) ? strtotime($echVal($b)) : PHP_INT_MAX));
} elseif ($tri === 'debut') {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
} else {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($b['derniere_activite']) <=> strtotime($a['derniere_activite']));
}

$nbTotalFiltres = count($dossiersFiltres);
$nbPages = max(1, (int) ceil($nbTotalFiltres / $parPage));
$page = min($page, $nbPages);
$dossiersPage = array_slice($dossiersFiltres, ($page - 1) * $parPage, $parPage);

$lienVue = function (array $surcharge) use ($vue, $recherche, $tri) {
    $params = array_filter(array_merge(['vue' => $vue, 'q' => $recherche, 'tri' => $tri], $surcharge), fn ($v) => $v !== '');
    return 'consultant-pack-booster.php' . ($params ? '?' . http_build_query($params) : '');
};

$etapesLabels = booster_parcours_etapes();
$etapeClasses = [
    'qualification'  => 'bg-surface-container-high text-secondary',
    'rdv'            => 'bg-orange-100 text-orange-700',
    'consentement'   => 'bg-orange-100 text-orange-700',
    'paiement'       => 'bg-orange-100 text-orange-700',
    'accompagnement' => 'bg-blue-100 text-blue-700',
    'cloture'        => 'bg-green-100 text-green-700',
];

function booster_pack_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 3600) { return "il y a moins d'une heure"; }
    if ($diff < 86400) { return 'il y a ' . floor($diff / 3600) . 'h'; }
    $jours = (int) floor($diff / 86400);
    return 'il y a ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
}

render_head('Espace NexRaiz — Pack Booster', 'Centre opérationnel de gestion des prestations Pack Booster.');
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'pack'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Pack Booster</h1>
        <p class="font-body-sm text-body-sm text-secondary">Gérez les prestations, les dossiers, les documents à traiter et les livrables à produire.</p>
      </div>

      <?php if ($succes): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
      </div>
      <?php endif; ?>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'en_cours'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Actifs</span>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $nbActifs; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Dossiers Booster actifs</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'a_prendre_en_charge'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-error font-bold">À prendre en charge</span>
          <span class="font-headline-sm text-headline-sm font-bold text-error"><?php echo $nbAPrendreEnCharge; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Non assignés</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => ''])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-blue-600 font-bold">En cours</span>
          <span class="font-headline-sm text-headline-sm font-bold text-blue-600"><?php echo $nbPrestationsEnCours; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Prestations en cours</span>
        </a>
        <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-orange-600 font-bold">Opérationnel</span>
          <span class="font-headline-sm text-headline-sm font-bold text-orange-600"><?php echo $nbActionsOperationnelles; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Actions en attente</span>
        </div>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'documents_a_verifier'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-indigo-600 font-bold">Documents</span>
          <span class="font-headline-sm text-headline-sm font-bold text-indigo-600"><?php echo $nbDocumentsAVerifier; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">À vérifier</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'livrables_a_produire'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-purple-600 font-bold">Livrables</span>
          <span class="font-headline-sm text-headline-sm font-bold text-purple-600"><?php echo $nbLivrablesAPreparer; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">À préparer</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'termines'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-green-600 font-bold">Terminées</span>
          <span class="font-headline-sm text-headline-sm font-bold text-green-600"><?php echo $nbTerminees; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Prestations terminées</span>
        </a>
      </div>

      <!-- Espace de travail -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">work</span></span>Espace de travail</h2>
        <?php $queuesNonVides = array_filter($queues, fn ($q) => $q); ?>
        <?php if (!$queuesNonVides): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune tâche opérationnelle en attente — tout est à jour.</p>
        <?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm">
          <?php foreach ($queueLabels as $icone => [$label, $iconeAffichee]): $items = $queues[$icone]; if (!$items) { continue; } ?>
          <div class="border border-outline-variant/40 rounded-xl p-space-sm flex flex-col gap-space-2xs">
            <p class="font-label-md text-label-md text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-primary"><?php echo htmlspecialchars($iconeAffichee); ?></span><?php echo htmlspecialchars($label); ?><span class="ml-auto font-body-sm text-[11px] text-secondary font-normal"><?php echo count($items); ?></span></p>
            <?php foreach (array_slice($items, 0, 4) as $it): $d = $it['dossier']; $r = $it['raison']; ?>
            <div class="flex items-center justify-between gap-space-xs">
              <span class="font-body-sm text-[12px] text-on-surface truncate"><?php echo htmlspecialchars($d['client']); ?></span>
              <?php if ($icone === 'person_add'): ?>
              <form method="post" class="shrink-0"><?php echo csrf_field(); ?><input type="hidden" name="action" value="prendre_en_charge"><input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                <button type="submit" class="h-7 px-space-xs rounded-md bg-on-surface text-white font-label-md text-[11px]">Prendre en charge</button>
              </form>
              <?php elseif ($icone === 'description'): ?>
              <form method="post" class="shrink-0" onsubmit="return confirm('Générer le livrable pour ce dossier ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generer_livrables"><input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                <button type="submit" class="h-7 px-space-xs rounded-md bg-on-surface text-white font-label-md text-[11px]">Générer</button>
              </form>
              <?php else: ?>
              <a href="<?php echo htmlspecialchars($r['lien']); ?>" class="shrink-0 h-7 px-space-xs rounded-md border border-outline-variant font-label-md text-[11px] text-on-surface">Traiter</a>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($items) > 4): ?><p class="font-body-sm text-[11px] text-secondary">+ <?php echo count($items) - 4; ?> autre(s)</p><?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php if ($aFinaliser): ?>
          <div class="border border-outline-variant/40 rounded-xl p-space-sm flex flex-col gap-space-2xs">
            <p class="font-label-md text-label-md text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-green-600">task_alt</span>Prestations prêtes à finaliser<span class="ml-auto font-body-sm text-[11px] text-secondary font-normal"><?php echo count($aFinaliser); ?></span></p>
            <?php foreach (array_slice($aFinaliser, 0, 4) as $d): ?>
            <div class="flex items-center justify-between gap-space-xs">
              <span class="font-body-sm text-[12px] text-on-surface truncate"><?php echo htmlspecialchars($d['client']); ?></span>
              <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>" class="shrink-0 h-7 px-space-xs rounded-md border border-outline-variant font-label-md text-[11px] text-on-surface">Ouvrir</a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Liste des dossiers -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <form method="get" class="flex flex-wrap items-center gap-space-sm">
          <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Client ou numéro de dossier..." class="w-full h-10 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
          </div>
          <select name="vue" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-[13px]">
            <option value="">Tous les dossiers</option>
            <option value="a_prendre_en_charge" <?php echo $vue === 'a_prendre_en_charge' ? 'selected' : ''; ?>>À prendre en charge</option>
            <option value="en_cours" <?php echo $vue === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
            <option value="attente_client" <?php echo $vue === 'attente_client' ? 'selected' : ''; ?>>En attente du client</option>
            <option value="attente_consultant" <?php echo $vue === 'attente_consultant' ? 'selected' : ''; ?>>En attente du consultant</option>
            <option value="documents_a_verifier" <?php echo $vue === 'documents_a_verifier' ? 'selected' : ''; ?>>Documents à vérifier</option>
            <option value="livrables_a_produire" <?php echo $vue === 'livrables_a_produire' ? 'selected' : ''; ?>>Livrables à produire</option>
            <option value="termines" <?php echo $vue === 'termines' ? 'selected' : ''; ?>>Terminés</option>
          </select>
          <select name="tri" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-[13px]">
            <option value="" <?php echo $tri === '' ? 'selected' : ''; ?>>Activité récente</option>
            <option value="echeance" <?php echo $tri === 'echeance' ? 'selected' : ''; ?>>Échéance</option>
            <option value="debut" <?php echo $tri === 'debut' ? 'selected' : ''; ?>>Date de début</option>
          </select>
          <button type="submit" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Filtrer</button>
          <?php if ($recherche || $vue || $tri): ?>
          <a href="consultant-pack-booster.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
          <?php endif; ?>
        </form>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[1150px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dossier</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Début</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière activité</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Prochaine action</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Livrables</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Situation</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dossiersPage as $d): $detailId = 'opdetail-' . $d['id']; ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors cursor-pointer" onclick="document.getElementById('<?php echo $detailId; ?>').classList.toggle('hidden');">
                <td class="py-space-sm pr-space-sm">
                  <span class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo htmlspecialchars($d['initiales']); ?></span>
                    <span class="flex flex-col">
                      <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['client']); ?></span>
                      <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['numero']); ?> · <?php echo htmlspecialchars($d['objectif']); ?></span>
                    </span>
                  </span>
                </td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $etapeClasses[$d['etape']]; ?>"><?php echo htmlspecialchars($etapesLabels[$d['etape']]); ?></span></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))); ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars(booster_pack_il_y_a($d['derniere_activite'])); ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface">
                  <?php if ($d['prochaine_action']): ?><?php echo htmlspecialchars($d['prochaine_action']['titre']); ?><?php else: ?><span class="text-secondary">—</span><?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px]">
                  <?php if ($d['nb_livrables'] > 0): ?><span class="text-green-700 font-semibold"><?php echo $d['nb_livrables']; ?> généré(s)</span>
                  <?php elseif ($d['livrable_a_preparer']): ?><span class="text-orange-600 font-semibold">À préparer</span>
                  <?php else: ?><span class="text-secondary">—</span><?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm">
                  <?php if ($d['prise_en_charge'] && $d['statut_dossier'] !== 'cloture'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-error-container/40 text-error">Non assigné</span>
                  <?php elseif ($d['categorie'] === 'attente_intervention'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-orange-100 text-orange-700">À traiter</span>
                  <?php elseif ($d['categorie'] === 'bloque'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-surface-container-high text-secondary">Attente client</span>
                  <?php elseif ($d['statut_dossier'] === 'cloture'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-green-100 text-green-700">Terminé</span>
                  <?php else: ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-surface-container-high text-secondary">OK</span>
                  <?php endif; ?>
                </td>
                <td class="py-space-sm text-right"><span class="material-symbols-outlined text-[18px] text-outline">expand_more</span></td>
              </tr>
              <tr id="<?php echo $detailId; ?>" class="hidden">
                <td colspan="8" class="py-space-sm pr-space-sm">
                  <div class="bg-surface rounded-xl border border-outline-variant/40 p-space-md flex flex-col gap-space-sm" onclick="event.stopPropagation();">
                    <div class="flex items-center justify-between flex-wrap gap-space-xs">
                      <p class="font-label-lg text-label-lg text-on-surface font-bold">Fiche opérationnelle — <?php echo htmlspecialchars($d['client']); ?></p>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir le dossier</a>
                    </div>

                    <?php if ($d['raisons']): ?>
                    <div class="flex flex-col gap-0.5">
                      <?php foreach ($d['raisons'] as $r): ?>
                      <a href="<?php echo htmlspecialchars($r['lien']); ?>" class="flex items-center gap-1 font-body-sm text-[12px] <?php echo $r['qui'] === 'consultant' ? 'text-error' : 'text-secondary'; ?> hover:underline">
                        <span class="material-symbols-outlined text-[14px]"><?php echo htmlspecialchars($r['icone']); ?></span><?php echo htmlspecialchars($r['texte']); ?>
                      </a>
                      <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="font-body-sm text-[12px] text-secondary">Rien à traiter pour ce dossier pour le moment.</p>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-xs">
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#plan" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">checklist</span>Actions</a>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#documents" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">folder_shared</span>Documents</a>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#session" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">event</span>Rendez-vous</a>
                      <a href="consultant-messages-booster.php?dossier=<?php echo $d['id']; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">mail</span>Messages</a>
                    </div>

                    <div class="flex items-center gap-space-xs flex-wrap">
                      <?php if ($d['prise_en_charge'] && $d['statut_dossier'] !== 'cloture'): ?>
                      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="prendre_en_charge"><input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                        <button type="submit" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">person_add</span>Prendre en charge</button>
                      </form>
                      <?php endif; ?>
                      <?php if ($d['livrable_a_preparer']): ?>
                      <form method="post" onsubmit="return confirm('Générer le livrable pour ce dossier ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generer_livrables"><input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                        <button type="submit" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">description</span>Générer le livrable</button>
                      </form>
                      <?php elseif ($d['nb_livrables'] > 0): ?>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#livrables" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">card_giftcard</span>Voir les livrables (<?php echo $d['nb_livrables']; ?>)</a>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$dossiersPage): ?>
              <tr><td colspan="8" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à ces critères.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="flex items-center justify-between flex-wrap gap-space-xs">
          <p class="font-body-sm text-[12px] text-secondary"><?php echo $nbTotalFiltres; ?> dossier<?php echo $nbTotalFiltres > 1 ? 's' : ''; ?> au total<?php echo $nbPages > 1 ? ' · page ' . $page . ' / ' . $nbPages : ''; ?></p>
          <?php if ($nbPages > 1): ?>
          <div class="flex items-center gap-1">
            <?php if ($page > 1): ?><a href="<?php echo htmlspecialchars($lienVue(['page' => (string) ($page - 1)])); ?>" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center">Précédent</a><?php endif; ?>
            <?php if ($page < $nbPages): ?><a href="<?php echo htmlspecialchars($lienVue(['page' => (string) ($page + 1)])); ?>" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center">Suivant</a><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </main>
  </div>
</div>
</body>
</html>
