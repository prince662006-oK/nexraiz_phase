<?php
/**
 * NexRaiz — « Parcours client », Pack Booster : centre de pilotage de
 * l'accompagnement pour le consultant. Agrège les données déjà existantes
 * (dossiers, qualifications, rendez-vous, consentements, paiements,
 * actions_plan, documents, messages, activites) via
 * includes/consultant-booster.php::booster_parcours_dossiers() — aucune
 * nouvelle table, aucune donnée dupliquée.
 *
 * L'« étape » du parcours est calculée en lecture seule, avec exactement la
 * même chaîne que celle déjà affichée au client (booster-tableau-bord.php) :
 * il n'existe donc aucune action « déplacer ce dossier vers l'étape X » —
 * l'étape change uniquement en conséquence d'une vraie action métier
 * (qualification complétée, rendez-vous confirmé, paiement reçu...), chacune
 * effectuée via son propre module existant, jamais ici directement.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');

$tous = booster_parcours_dossiers($packId);

// --- Indicateurs (section 1) : sur l'ensemble des dossiers visibles par le
// consultant (même périmètre que consultant-dossiers-booster.php — tous les
// dossiers du pack, aucune restriction par consultant assigné dans le
// modèle actuel). Chaque dossier compte dans une seule catégorie
// d'intervention à la fois (voir booster_parcours_dossiers::$categorie). ---
$nbTotal = count($tous);
$nbEnCours = count(array_filter($tous, fn ($d) => $d['statut_dossier'] !== 'cloture'));
$nbAttenteIntervention = count(array_filter($tous, fn ($d) => $d['categorie'] === 'attente_intervention'));
$nbBloques = count(array_filter($tous, fn ($d) => $d['categorie'] === 'bloque'));
$nbTermines = count(array_filter($tous, fn ($d) => $d['statut_dossier'] === 'cloture'));

// --- Pipeline (section 2) : comptage réel par étape. ---
$etapesLabels = booster_parcours_etapes();
$compteEtapes = array_fill_keys(array_keys($etapesLabels), 0);
foreach ($tous as $d) { $compteEtapes[$d['etape']]++; }

// --- Mes priorités du jour (section 5) : dossiers où le consultant doit
// agir, jamais ceux qui attendent légitimement le client. ---
$prioritesJour = array_values(array_filter($tous, fn ($d) => $d['raisons_consultant']));
usort($prioritesJour, fn ($a, $b) => count($b['raisons_consultant']) <=> count($a['raisons_consultant']) ?: strtotime($a['derniere_activite']) <=> strtotime($b['derniere_activite']));
$prioritesJour = array_slice($prioritesJour, 0, 8);

// --- Filtres, recherche, tri (GET, lecture seule) ---
$vue = $_GET['vue'] ?? '';
$etapeFiltre = $_GET['etape'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$prioriteFiltre = $_GET['priorite'] ?? '';
$echeanceFiltre = $_GET['echeance'] ?? '';
$tri = $_GET['tri'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$parPage = 25;

$dossiersFiltres = array_values(array_filter($tous, function ($d) use ($vue, $etapeFiltre, $recherche, $prioriteFiltre, $echeanceFiltre) {
    if ($vue === 'en_cours' && $d['statut_dossier'] === 'cloture') { return false; }
    if ($vue === 'attente' && $d['categorie'] !== 'attente_intervention') { return false; }
    if ($vue === 'bloque' && $d['categorie'] !== 'bloque') { return false; }
    if ($vue === 'termine' && $d['statut_dossier'] !== 'cloture') { return false; }
    if ($etapeFiltre !== '' && $d['etape'] !== $etapeFiltre) { return false; }
    if ($recherche !== '' && stripos($d['client'] . ' ' . $d['numero'], $recherche) === false) { return false; }
    if ($prioriteFiltre === 'haute' && (!$d['prochaine_action'] || !$d['prochaine_action']['priorite_haute'])) { return false; }
    if ($echeanceFiltre === 'depassee' && !array_filter($d['raisons'], fn ($r) => str_contains($r['texte'], 'retard'))) { return false; }
    if ($echeanceFiltre === 'proche' && (!$d['prochaine_action'] || !$d['prochaine_action']['echeance'] || strtotime($d['prochaine_action']['echeance']) > strtotime('+3 days') || strtotime($d['prochaine_action']['echeance']) < strtotime('today'))) { return false; }
    return true;
}));

if ($tri === 'progression') {
    usort($dossiersFiltres, fn ($a, $b) => $b['progression'] <=> $a['progression']);
} elseif ($tri === 'echeance') {
    $echVal = fn ($d) => $d['prochaine_action']['echeance'] ?? null;
    usort($dossiersFiltres, fn ($a, $b) => ($echVal($a) ? strtotime($echVal($a)) : PHP_INT_MAX) <=> ($echVal($b) ? strtotime($echVal($b)) : PHP_INT_MAX));
} else {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($b['derniere_activite']) <=> strtotime($a['derniere_activite']));
}

$nbTotalFiltres = count($dossiersFiltres);
$nbPages = max(1, (int) ceil($nbTotalFiltres / $parPage));
$page = min($page, $nbPages);
$dossiersPage = array_slice($dossiersFiltres, ($page - 1) * $parPage, $parPage);

$lienVue = function (array $surcharge) use ($vue, $etapeFiltre, $recherche, $prioriteFiltre, $echeanceFiltre, $tri) {
    $params = array_filter(array_merge(['vue' => $vue, 'etape' => $etapeFiltre, 'q' => $recherche, 'priorite' => $prioriteFiltre, 'echeance' => $echeanceFiltre, 'tri' => $tri], $surcharge), fn ($v) => $v !== '');
    return 'consultant-parcours-booster.php' . ($params ? '?' . http_build_query($params) : '');
};

$etapeClasses = [
    'qualification'  => 'bg-surface-container-high text-secondary',
    'rdv'            => 'bg-orange-100 text-orange-700',
    'consentement'   => 'bg-orange-100 text-orange-700',
    'paiement'       => 'bg-orange-100 text-orange-700',
    'accompagnement' => 'bg-blue-100 text-blue-700',
    'cloture'        => 'bg-green-100 text-green-700',
];

function booster_parcours_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 3600) { return "il y a moins d'une heure"; }
    if ($diff < 86400) { return 'il y a ' . floor($diff / 3600) . 'h'; }
    $jours = (int) floor($diff / 86400);
    return 'il y a ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
}

render_head('Espace NexRaiz — Parcours client', "Centre de pilotage de l'accompagnement Pack Booster.");
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'parcours'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Parcours client</h1>
        <p class="font-body-sm text-body-sm text-secondary">Suivez l'avancement de chaque accompagnement, repérez les blocages et intervenez directement sur les dossiers.</p>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-5 gap-space-sm">
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => ''])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors <?php echo $vue === '' ? 'ring-2 ring-primary-container' : ''; ?>">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Total</span>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $nbTotal; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Clients accompagnés</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'en_cours'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors <?php echo $vue === 'en_cours' ? 'ring-2 ring-primary-container' : ''; ?>">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-blue-600 font-bold">En cours</span>
          <span class="font-headline-sm text-headline-sm font-bold text-blue-600"><?php echo $nbEnCours; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Parcours actifs</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'attente'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors <?php echo $vue === 'attente' ? 'ring-2 ring-primary-container' : ''; ?>">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-orange-600 font-bold">À traiter</span>
          <span class="font-headline-sm text-headline-sm font-bold text-orange-600"><?php echo $nbAttenteIntervention; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Attendent votre intervention</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'bloque'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors <?php echo $vue === 'bloque' ? 'ring-2 ring-primary-container' : ''; ?>">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-error font-bold">Bloqués</span>
          <span class="font-headline-sm text-headline-sm font-bold text-error"><?php echo $nbBloques; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Attention particulière</span>
        </a>
        <a href="<?php echo htmlspecialchars($lienVue(['vue' => 'termine'])); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-0.5 hover:bg-surface-container-low transition-colors <?php echo $vue === 'termine' ? 'ring-2 ring-primary-container' : ''; ?>">
          <span class="font-label-md text-[10px] uppercase tracking-wider text-green-600 font-bold">Terminés</span>
          <span class="font-headline-sm text-headline-sm font-bold text-green-600"><?php echo $nbTermines; ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Parcours clôturés</span>
        </a>
      </div>

      <!-- Pipeline -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Pipeline du parcours</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-space-xs">
          <?php foreach ($etapesLabels as $cle => $label): ?>
          <a href="<?php echo htmlspecialchars($lienVue(['etape' => $etapeFiltre === $cle ? '' : $cle])); ?>" class="rounded-xl border p-space-sm flex flex-col gap-0.5 transition-colors <?php echo $etapeFiltre === $cle ? 'border-primary-container bg-primary-container/5' : 'border-outline-variant/40 hover:bg-surface-container-low'; ?>">
            <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $compteEtapes[$cle]; ?></span>
            <span class="font-body-sm text-[11px] text-secondary leading-tight"><?php echo htmlspecialchars($label); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Mes priorités du jour -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-error/10 text-error flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">priority_high</span></span>Mes priorités du jour</h2>
        <?php if (!$prioritesJour): ?>
        <p class="font-body-sm text-body-sm text-secondary">Rien ne nécessite votre intervention pour le moment.</p>
        <?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-xs">
          <?php foreach ($prioritesJour as $d): $premiereRaison = $d['raisons_consultant'][0]; ?>
          <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
            <div class="flex items-center gap-space-xs min-w-0">
              <span class="w-9 h-9 rounded-full bg-error/10 text-error flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo htmlspecialchars($d['initiales']); ?></span>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['client']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($premiereRaison['texte']); ?><?php echo count($d['raisons_consultant']) > 1 ? ' +' . (count($d['raisons_consultant']) - 1) : ''; ?></p>
              </div>
            </div>
            <a href="<?php echo htmlspecialchars($premiereRaison['lien']); ?>" class="shrink-0 h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]"><?php echo htmlspecialchars($premiereRaison['icone']); ?></span>Traiter</a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Liste principale -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <form method="get" class="flex flex-wrap items-center gap-space-sm">
          <input type="hidden" name="vue" value="<?php echo htmlspecialchars($vue); ?>">
          <input type="hidden" name="etape" value="<?php echo htmlspecialchars($etapeFiltre); ?>">
          <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom ou numéro de dossier..." class="w-full h-10 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
          </div>
          <select name="priorite" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-[13px]">
            <option value="">Toute priorité</option>
            <option value="haute" <?php echo $prioriteFiltre === 'haute' ? 'selected' : ''; ?>>Priorité haute uniquement</option>
          </select>
          <select name="echeance" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-[13px]">
            <option value="">Toute échéance</option>
            <option value="proche" <?php echo $echeanceFiltre === 'proche' ? 'selected' : ''; ?>>Échéance proche (3 j)</option>
            <option value="depassee" <?php echo $echeanceFiltre === 'depassee' ? 'selected' : ''; ?>>Échéance dépassée</option>
          </select>
          <select name="tri" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface font-body-sm text-[13px]">
            <option value="" <?php echo $tri === '' ? 'selected' : ''; ?>>Activité récente</option>
            <option value="progression" <?php echo $tri === 'progression' ? 'selected' : ''; ?>>Progression</option>
            <option value="echeance" <?php echo $tri === 'echeance' ? 'selected' : ''; ?>>Échéance</option>
          </select>
          <button type="submit" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Filtrer</button>
          <?php if ($recherche || $prioriteFiltre || $echeanceFiltre || $tri || $etapeFiltre || $vue): ?>
          <a href="consultant-parcours-booster.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
          <?php endif; ?>
        </form>

        <div class="overflow-x-auto">
          <table class="w-full min-w-[1100px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Étape</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Progression</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Prochaine action</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Responsable</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière activité</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Situation</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dossiersPage as $d): $detailId = 'detail-' . $d['id']; ?>
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
                <td class="py-space-sm pr-space-sm">
                  <div class="flex items-center gap-1 w-24">
                    <div class="flex-1 h-1.5 rounded-full bg-surface-container-high overflow-hidden"><div class="h-full rounded-full bg-primary-container" style="width:<?php echo $d['progression']; ?>%"></div></div>
                    <span class="font-body-sm text-[10px] text-secondary shrink-0"><?php echo $d['progression']; ?>%</span>
                  </div>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface">
                  <?php if ($d['prochaine_action']): ?>
                  <?php echo htmlspecialchars($d['prochaine_action']['titre']); ?><?php if ($d['prochaine_action']['echeance']): ?><br><span class="text-[11px] text-secondary">Échéance <?php echo htmlspecialchars(date('d/m/Y', strtotime($d['prochaine_action']['echeance']))); ?></span><?php endif; ?>
                  <?php else: ?><span class="text-secondary">—</span><?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo $d['prochaine_action'] ? htmlspecialchars($d['prochaine_action']['responsable']) : '—'; ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars(booster_parcours_il_y_a($d['derniere_activite'])); ?></td>
                <td class="py-space-sm pr-space-sm">
                  <?php if ($d['categorie'] === 'attente_intervention'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-orange-100 text-orange-700">À traiter</span>
                  <?php elseif ($d['categorie'] === 'bloque'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-error-container/40 text-error">Bloqué</span>
                  <?php elseif ($d['statut_dossier'] === 'cloture'): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-green-100 text-green-700">Clôturé</span>
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
                      <p class="font-label-lg text-label-lg text-on-surface font-bold">Fiche parcours — <?php echo htmlspecialchars($d['client']); ?></p>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir le dossier complet</a>
                    </div>

                    <?php if ($d['raisons']): ?>
                    <div class="flex flex-col gap-0.5">
                      <p class="font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold">Prochaine intervention recommandée</p>
                      <?php foreach ($d['raisons'] as $r): ?>
                      <a href="<?php echo htmlspecialchars($r['lien']); ?>" class="flex items-center gap-1 font-body-sm text-[12px] <?php echo $r['qui'] === 'consultant' ? 'text-error' : 'text-secondary'; ?> hover:underline">
                        <span class="material-symbols-outlined text-[14px]"><?php echo htmlspecialchars($r['icone']); ?></span><?php echo htmlspecialchars($r['texte']); ?><?php echo $r['qui'] === 'client' ? ' (dépend du client)' : ''; ?>
                      </a>
                      <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="font-body-sm text-[12px] text-secondary">Aucune intervention nécessaire pour le moment.</p>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-xs">
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#plan" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">checklist</span>Actions</a>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#documents" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">folder_shared</span>Documents</a>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#session" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">event</span>Rendez-vous</a>
                      <a href="consultant-messages-booster.php?dossier=<?php echo $d['id']; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">mail</span>Messages</a>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#livrables" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">card_giftcard</span>Livrables</a>
                      <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>#notes" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[14px]">sticky_note_2</span>Notes internes</a>
                    </div>

                    <?php if ($d['notes_consultant']): ?>
                    <div class="bg-surface-container-low rounded-lg p-space-sm flex items-start gap-space-2xs">
                      <span class="material-symbols-outlined text-[14px] text-secondary mt-0.5">lock</span>
                      <p class="font-body-sm text-[11px] text-secondary italic">« <?php echo htmlspecialchars(mb_strlen($d['notes_consultant']) > 160 ? mb_substr($d['notes_consultant'], 0, 160) . '…' : $d['notes_consultant']); ?> »</p>
                    </div>
                    <?php endif; ?>
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
