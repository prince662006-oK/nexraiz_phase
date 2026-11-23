<?php
/**
 * NexRaiz — « Mes actions à réaliser », Pack Booster. Centre de suivi des
 * tâches du client sur `actions_plan` (table partagée avec les autres
 * packs — même mécanisme que Pack Classic, voir classic-mes-actions.php et
 * consultant-corrections-classic.php, repris ici sans dupliquer la logique
 * métier). Cycle de statut : a_faire/en_cours/a_venir (le client agit) ->
 * a_verifier (déclaré terminé par le client, jamais "termine" directement)
 * -> termine (validé par le coach, voir consultant-dossier-booster.php) ou
 * retour à a_faire avec un motif si le coach demande une correction.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');
$dossierId = (int) $dossier['id'];

$coach = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    $coach = $stmt->fetch();
}

$succes = '';
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $actionId = (int) ($_POST['action_id'] ?? 0);

    // L'action doit appartenir au dossier du client connecté — jamais une
    // confiance aveugle dans l'identifiant transmis par le formulaire.
    $stmt = db()->prepare('SELECT * FROM actions_plan WHERE id = ? AND dossier_id = ?');
    $stmt->execute([$actionId, $dossierId]);
    $actionCible = $stmt->fetch();

    if (!$actionCible) {
        $erreur = 'Action introuvable.';
    } elseif ($action === 'demarrer_action') {
        if ($actionCible['statut'] === 'a_faire' || $actionCible['statut'] === 'a_venir') {
            db()->prepare("UPDATE actions_plan SET statut = 'en_cours', statut_maj_le = NOW(), statut_maj_par = 'client' WHERE id = ?")->execute([$actionId]);
            $succes = 'Action démarrée.';
        } else {
            $erreur = "Cette action ne peut pas être démarrée dans son état actuel.";
        }
    } elseif ($action === 'terminer_action') {
        if (in_array($actionCible['statut'], ['a_faire', 'en_cours', 'a_venir'], true)) {
            db()->prepare("UPDATE actions_plan SET statut = 'a_verifier', motif_correction = NULL, statut_maj_le = NOW(), statut_maj_par = 'client' WHERE id = ?")->execute([$actionId]);
            log_activite($dossierId, 'Action déclarée terminée par le client : ' . $actionCible['titre']);
            $succes = 'Action soumise à votre coach pour vérification.';
        } else {
            $erreur = "Cette action ne peut pas être marquée terminée dans son état actuel.";
        }
    } else {
        $erreur = 'Action non reconnue.';
    }

    if ($erreur === '') {
        $parametresConserves = array_merge($_GET, ['succes' => $succes]);
        unset($parametresConserves['action'], $parametresConserves['action_id']);
        header('Location: booster-mes-actions.php?' . http_build_query($parametresConserves));
        exit;
    }
}
if (isset($_GET['succes']) && $_GET['succes'] !== '') { $succes = $_GET['succes']; }

$stmt = db()->prepare('SELECT * FROM actions_plan WHERE dossier_id = ? ORDER BY (echeance IS NULL), echeance ASC, id DESC');
$stmt->execute([$dossierId]);
$toutesActions = $stmt->fetchAll();

$aujourdhui = strtotime('today');
foreach ($toutesActions as &$a) {
    $a['en_retard'] = in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true) && $a['echeance'] && strtotime($a['echeance']) < $aujourdhui;
    $a['echeance_proche'] = !$a['en_retard'] && in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true) && $a['echeance'] && strtotime($a['echeance']) <= strtotime('+3 days') && strtotime($a['echeance']) >= $aujourdhui;

    // Ressource liée, détectée par mots-clés dans le titre/la description —
    // aucune colonne ne lie une action à un document ou un rendez-vous dans
    // le modèle actuel ; le Pack Booster n'a pas de pages dédiées séparées
    // (contrairement au Pack Classic), les sections vivent dans le tableau
    // de bord — on pointe donc vers ses ancres réelles.
    $texteAnalyse = mb_strtolower($a['titre'] . ' ' . ($a['description'] ?? ''), 'UTF-8');
    if (preg_match('/livrable|rapport|bilan|plan d.action|consulter.*document.*corrig|t[eé]l[eé]charg/u', $texteAnalyse)) {
        $a['ressource_liee'] = ['label' => 'Voir mes livrables', 'url' => 'booster-tableau-bord.php#mes-livrables', 'icone' => 'card_giftcard'];
    } elseif (preg_match('/document|cv\b|lettre|fichier|pi[eè]ce|justificatif|scan|photo|diplome|diplôme/u', $texteAnalyse)) {
        $a['ressource_liee'] = ['label' => 'Déposer un document', 'url' => 'booster-mes-documents.php', 'icone' => 'upload_file'];
    } elseif (preg_match('/rendez.vous|rdv\b|entretien|session|cr[eé]neau|visio|appel/u', $texteAnalyse)) {
        $a['ressource_liee'] = ['label' => 'Voir mes rendez-vous', 'url' => 'booster-tableau-bord.php#rendez-vous', 'icone' => 'event'];
    } else {
        $a['ressource_liee'] = null;
    }
}
unset($a);

// --- Indicateurs : calculés sur les données réelles, jamais codés en dur —
// même source que le tableau de bord (comptage sur in_array('a_faire','en_cours')
// pour "actions à réaliser") afin que les deux pages restent cohérentes. ---
$compte = ['total' => count($toutesActions), 'a_faire' => 0, 'en_cours' => 0, 'a_venir' => 0, 'a_verifier' => 0, 'termine' => 0, 'en_retard' => 0];
foreach ($toutesActions as $a) {
    $compte[$a['statut']]++;
    if ($a['en_retard']) { $compte['en_retard']++; }
}
$nbOuvertes = $compte['a_faire'] + $compte['en_cours'] + $compte['a_venir'];

// --- Filtres et tri (GET, aucune action destructrice) ---
$filtre = $_GET['filtre'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? '';

$actionsRecherchees = array_values(array_filter($toutesActions, function ($a) use ($recherche) {
    if ($recherche !== '' && stripos($a['titre'] . ' ' . ($a['description'] ?? ''), $recherche) === false) return false;
    return true;
}));

// Compteurs des onglets : sur la recherche en cours mais indépendants du
// filtre actif, pour que chaque onglet affiche son propre total.
$compteOnglets = ['' => count($actionsRecherchees), 'ouvertes' => 0, 'a_verifier' => 0, 'termine' => 0];
foreach ($actionsRecherchees as $a) {
    if (in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true)) { $compteOnglets['ouvertes']++; }
    elseif ($a['statut'] === 'a_verifier') { $compteOnglets['a_verifier']++; }
    elseif ($a['statut'] === 'termine') { $compteOnglets['termine']++; }
}

$actionsAffichees = array_values(array_filter($actionsRecherchees, function ($a) use ($filtre) {
    if ($filtre === 'ouvertes') return in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true);
    if ($filtre === 'a_verifier') return $a['statut'] === 'a_verifier';
    if ($filtre === 'termine') return $a['statut'] === 'termine';
    return true;
}));

if ($tri === 'echeance') {
    usort($actionsAffichees, fn ($a, $b) => ($a['echeance'] && $b['echeance']) ? strtotime($a['echeance']) - strtotime($b['echeance']) : ($a['echeance'] ? -1 : ($b['echeance'] ? 1 : $b['id'] - $a['id'])));
} elseif ($tri === 'priorite') {
    usort($actionsAffichees, function ($a, $b) {
        $diff = ($b['priorite'] === 'haute') - ($a['priorite'] === 'haute');
        if ($diff !== 0) return $diff;
        if ($a['echeance'] && $b['echeance']) return strtotime($a['echeance']) - strtotime($b['echeance']);
        return $a['echeance'] ? -1 : ($b['echeance'] ? 1 : $b['id'] - $a['id']);
    });
} else {
    usort($actionsAffichees, fn ($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
}

$lienFiltre = function (string $f) use ($recherche, $tri) {
    $params = [];
    if ($recherche !== '') { $params['q'] = $recherche; }
    if ($tri !== '') { $params['tri'] = $tri; }
    if ($f !== '') { $params['filtre'] = $f; }
    return 'booster-mes-actions.php' . ($params ? '?' . http_build_query($params) : '');
};

// Hiérarchie (pour « Ma priorité » uniquement) : en retard -> échéance proche -> priorité haute -> le reste par échéance.
$rang = fn ($a) => $a['en_retard'] ? 0 : ($a['echeance_proche'] ? 1 : ($a['statut'] === 'a_verifier' ? 3 : ($a['statut'] === 'termine' ? 4 : 2)));

// --- Ma priorité : la toute première action éligible dans le tri ci-dessus
// (même logique, pas une seconde règle inventée) — null si aucune ne
// nécessite d'action du client. ---
$actionsEligibles = array_values(array_filter($toutesActions, fn ($a) => in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true)));
usort($actionsEligibles, function ($a, $b) use ($rang) {
    $diff = $rang($a) - $rang($b);
    if ($diff !== 0) return $diff;
    $diffPriorite = ($b['priorite'] === 'haute') - ($a['priorite'] === 'haute');
    if ($diffPriorite !== 0) return $diffPriorite;
    if ($a['echeance'] && $b['echeance']) return strtotime($a['echeance']) - strtotime($b['echeance']);
    return $a['echeance'] ? -1 : ($b['echeance'] ? 1 : 0);
});
$maPriorite = $actionsEligibles[0] ?? null;

$statutLabels = [
    'a_faire'    => ['À faire', 'bg-orange-100 text-orange-700'],
    'en_cours'   => ['En cours', 'bg-on-surface text-white'],
    'a_venir'    => ['Prévu', 'bg-green-100 text-green-700'],
    'a_verifier' => ['En attente de validation', 'bg-blue-100 text-blue-700'],
    'termine'    => ['Terminée', 'bg-green-100 text-green-700'],
];

// --- Bandeau « Dossier à jour » : à jour du point de vue du client dès
// qu'il n'a plus aucune action a_faire/en_cours/a_venir à sa charge (les
// actions en attente de validation par le coach ne sont pas de son fait).
// Le pourcentage compte aussi les actions soumises (a_verifier) comme
// « faites » par le client, même si le coach ne les a pas encore validées. ---
$dossierAJour = $nbOuvertes === 0;
$partClientFaite = $compte['total'] > 0 ? (int) round((($compte['termine'] + $compte['a_verifier']) / $compte['total']) * 100) : 100;

function booster_action_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 3600) return "il y a moins d'une heure";
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    $jours = (int) floor($diff / 86400);
    return 'il y a ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
}

render_head('Pack Booster — Mes actions à réaliser');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-lg">
  <div class="max-w-max-width-content mx-auto flex items-start justify-between gap-space-sm flex-wrap">
    <div>
      <a href="booster-tableau-bord.php" class="font-label-md text-label-md text-secondary hover:text-primary transition-colors flex items-center gap-1 mb-space-2xs"><span class="material-symbols-outlined text-[16px]">arrow_back</span>Tableau de bord</a>
      <p class="font-label-md text-[11px] uppercase tracking-wider text-primary font-bold">Suivi d'avancement</p>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mes actions à réaliser</h1>
      <p class="font-body-sm text-body-sm text-secondary max-w-xl">Ce que vous devez faire pour avancer dans votre accompagnement Pack Booster, et où en est votre coach.</p>
    </div>
    <span class="shrink-0 px-space-sm py-1.5 rounded-full font-label-md text-[12px] font-bold flex items-center gap-1 <?php echo $dossierAJour ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'; ?>">
      <span class="material-symbols-outlined text-[15px]"><?php echo $dossierAJour ? 'check_circle' : 'schedule'; ?></span>
      Dossier <?php echo $partClientFaite; ?>% à jour
    </span>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-2 sm:grid-cols-4 gap-space-sm">
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <div class="flex items-center justify-between">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Total</span>
        <span class="w-7 h-7 rounded-lg bg-surface-container-low text-on-surface flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">list_alt</span></span>
      </div>
      <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $compte['total']; ?></span>
      <span class="font-body-sm text-[12px] text-secondary">Actions au total</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <div class="flex items-center justify-between">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-orange-600 font-bold">À faire</span>
        <span class="w-7 h-7 rounded-lg bg-orange-100 text-orange-600 flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">schedule</span></span>
      </div>
      <span class="font-headline-sm text-headline-sm font-bold text-orange-600"><?php echo $nbOuvertes; ?></span>
      <span class="font-body-sm text-[12px] text-secondary">À réaliser</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <div class="flex items-center justify-between">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-indigo-600 font-bold">En revue</span>
        <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">person</span></span>
      </div>
      <span class="font-headline-sm text-headline-sm font-bold text-indigo-600"><?php echo $compte['a_verifier']; ?></span>
      <span class="font-body-sm text-[12px] text-secondary">En validation coach</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <div class="flex items-center justify-between">
        <span class="font-label-md text-[10px] uppercase tracking-wider text-green-600 font-bold">Validé</span>
        <span class="w-7 h-7 rounded-lg bg-green-100 text-green-600 flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">check_circle</span></span>
      </div>
      <span class="font-headline-sm text-headline-sm font-bold text-green-600"><?php echo $compte['termine']; ?></span>
      <span class="font-body-sm text-[12px] text-secondary">Terminées</span>
    </div>
  </div>
</section>

<?php if ($maPriorite): ?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto bg-gradient-to-br from-primary-container/10 to-transparent border border-primary-container/20 rounded-xl shadow-sm p-space-lg flex items-start gap-space-sm">
    <div class="w-11 h-11 rounded-full bg-primary-container text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo $maPriorite['en_retard'] ? 'priority_high' : 'flag'; ?></span></div>
    <div class="flex-1 min-w-0">
      <p class="font-label-md text-[11px] uppercase tracking-wider text-primary font-bold">Ma priorité</p>
      <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($maPriorite['titre']); ?><?php echo $maPriorite['en_retard'] ? ' — en retard' : ($maPriorite['echeance'] ? ' — avant le ' . date('d/m/Y', strtotime($maPriorite['echeance'])) : ''); ?></p>
    </div>
    <a href="#action-<?php echo $maPriorite['id']; ?>" class="shrink-0 h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center gap-1 hover:bg-primary transition-colors">Voir <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
  </div>
</section>
<?php elseif ($compte['a_verifier'] > 0): ?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto bg-blue-50 border border-blue-200 rounded-xl p-space-lg flex items-start gap-space-sm">
    <div class="w-11 h-11 rounded-full bg-blue-600 text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">hourglass_top</span></div>
    <div class="flex-1 min-w-0">
      <p class="font-label-md text-[11px] uppercase tracking-wider text-blue-700 font-bold">Ma priorité</p>
      <p class="font-label-lg text-label-lg text-on-surface font-semibold">Aucune action de votre côté pour le moment — votre coach doit vérifier <?php echo $compte['a_verifier']; ?> action<?php echo $compte['a_verifier'] > 1 ? 's' : ''; ?> que vous avez soumise<?php echo $compte['a_verifier'] > 1 ? 's' : ''; ?>.</p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <?php if ($succes): ?><div class="bg-green-50 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div><?php endif; ?>
    <?php if ($erreur): ?><div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreur); ?></div><?php endif; ?>

    <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-wrap items-center gap-space-sm">
      <input type="hidden" name="filtre" value="<?php echo htmlspecialchars($filtre); ?>">
      <div class="relative flex-1 min-w-[220px]">
        <span class="material-symbols-outlined text-[16px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Rechercher par mot-clé, thème ou livrable..." class="w-full h-9 pl-7 pr-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
      </div>
      <div class="flex items-center flex-wrap gap-1 bg-surface p-1 rounded-lg border border-outline-variant/40">
        <a href="<?php echo htmlspecialchars($lienFiltre('')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === '' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Toutes (<?php echo $compteOnglets['']; ?>)</a>
        <a href="<?php echo htmlspecialchars($lienFiltre('ouvertes')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'ouvertes' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">À réaliser (<?php echo $compteOnglets['ouvertes']; ?>)</a>
        <a href="<?php echo htmlspecialchars($lienFiltre('a_verifier')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'a_verifier' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">En attente (<?php echo $compteOnglets['a_verifier']; ?>)</a>
        <a href="<?php echo htmlspecialchars($lienFiltre('termine')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'termine' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Terminées (<?php echo $compteOnglets['termine']; ?>)</a>
      </div>
      <div class="flex items-center gap-1">
        <label class="font-body-sm text-[12px] text-secondary">Trier par</label>
        <select name="tri" onchange="this.form.submit()" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
          <option value="" <?php echo $tri === '' ? 'selected' : ''; ?>>Plus récentes</option>
          <option value="echeance" <?php echo $tri === 'echeance' ? 'selected' : ''; ?>>Échéance proche</option>
          <option value="priorite" <?php echo $tri === 'priorite' ? 'selected' : ''; ?>>Priorité haute</option>
        </select>
      </div>
      <button type="submit" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-secondary">Rechercher</button>
      <?php if ($recherche || $filtre || $tri): ?>
      <a href="booster-mes-actions.php" class="h-9 px-space-sm rounded-lg font-label-md text-[12px] text-secondary flex items-center">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <div class="flex flex-col gap-space-sm">
      <?php if (!$toutesActions): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-2xl flex flex-col items-center text-center gap-space-sm">
        <div class="relative w-16 h-16 rounded-2xl bg-primary-container/15 flex items-center justify-center">
          <span class="material-symbols-outlined text-[30px] text-primary">check</span>
          <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-primary-container border-2 border-surface-container-lowest"></span>
        </div>
        <div>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Aucune action requise pour le moment</p>
          <p class="font-body-sm text-body-sm text-secondary max-w-sm mx-auto mt-space-2xs">Votre coach vous assignera de nouvelles missions et livrables au fil de vos prochaines séances d'accompagnement. Tout est parfaitement en ordre de votre côté !</p>
        </div>
        <span class="px-space-sm py-1 rounded-full bg-green-100 text-green-700 font-label-md text-[11px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check_circle</span>Votre dossier d'accompagnement est à jour</span>

        <div class="w-full max-w-sm bg-surface rounded-xl border border-outline-variant/40 p-space-sm flex items-center gap-space-sm text-left mt-space-xs">
          <div class="w-10 h-10 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold shrink-0"><?php echo $coach ? htmlspecialchars(mb_strtoupper(mb_substr($coach['prenom'], 0, 1) . mb_substr($coach['nom'], 0, 1), 'UTF-8')) : 'PB'; ?></div>
          <div class="flex-1 min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-bold truncate"><?php echo $coach ? htmlspecialchars($coach['prenom'] . ' ' . $coach['nom']) : 'Coach Dédié'; ?></p>
            <p class="font-body-sm text-[11px] text-primary">Coach Pack Booster</p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo $coach ? 'Disponible pour répondre à vos questions' : 'Un coach vous sera assigné prochainement'; ?></p>
          </div>
          <?php if ($coach): ?>
          <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="shrink-0 font-label-md text-[12px] text-primary font-bold flex items-center">Écrire<span class="material-symbols-outlined text-[16px]">chevron_right</span></a>
          <?php endif; ?>
        </div>

        <div class="w-full max-w-sm bg-surface-container-low rounded-lg px-space-sm py-space-xs flex items-start gap-space-2xs text-left">
          <span class="material-symbols-outlined text-[16px] text-secondary mt-0.5">notifications_active</span>
          <p class="font-body-sm text-[11px] text-secondary"><strong class="text-on-surface">Notification en temps réel :</strong> dès qu'une nouvelle mission vous est assignée par votre coach, vous recevrez une alerte directe.</p>
        </div>
      </div>
      <?php elseif (!$actionsAffichees): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucune action ne correspond à ces critères.</p>
      <?php elseif ($compte['total'] === $compte['termine']): ?>
      <div class="bg-green-50 border border-green-200 rounded-xl p-space-md flex items-center gap-space-sm">
        <span class="material-symbols-outlined text-[24px] text-green-700">celebration</span>
        <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Toutes vos actions sont terminées</p><p class="font-body-sm text-body-sm text-secondary">Votre coach vous informera dès qu'une nouvelle action sera nécessaire.</p></div>
      </div>
      <?php endif; ?>

      <?php foreach ($actionsAffichees as $a):
        [$labelStatut, $classeStatut] = $statutLabels[$a['statut']];
        $peutAgir = in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true);
      ?>
      <div id="action-<?php echo $a['id']; ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-space-xs scroll-mt-20 <?php echo $a['en_retard'] ? 'border-l-4 border-error' : ''; ?>">
        <div class="flex items-start justify-between gap-space-sm flex-wrap">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1 flex-wrap font-body-sm text-[11px] text-secondary">
              <?php if ($a['en_retard']): ?><span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-error text-white">En retard</span><?php endif; ?>
              <?php if ($a['echeance_proche']): ?><span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-orange-100 text-orange-700">Échéance proche</span><?php endif; ?>
              <?php if ($a['en_retard'] || $a['echeance_proche']): ?>·<?php endif; ?>
              <span>Priorité <?php echo $a['priorite'] === 'haute' ? 'haute' : 'normale'; ?></span>
            </div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($a['titre']); ?></p>
            <?php if ($a['description']): ?><p class="font-body-sm text-[13px] text-secondary mt-0.5 whitespace-pre-line"><?php echo htmlspecialchars($a['description']); ?></p><?php endif; ?>
          </div>
          <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
        </div>

        <div class="flex items-center gap-space-md flex-wrap font-body-sm text-[12px] text-secondary">
          <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">calendar_today</span><?php echo $a['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : 'Sans échéance'; ?></span>
          <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">person</span><?php echo $a['statut'] === 'a_verifier' ? 'En attente de votre coach' : ($a['statut'] === 'termine' ? 'Terminée' : 'À vous de jouer'); ?></span>
          <?php if ($a['statut_maj_le']): ?><span class="italic">Mis à jour <?php echo booster_action_il_y_a($a['statut_maj_le']); ?></span><?php endif; ?>
        </div>

        <?php if ($a['statut'] === 'a_verifier'): ?>
        <p class="font-body-sm text-[12px] text-blue-700 bg-blue-50 rounded-lg px-space-sm py-space-2xs flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">hourglass_top</span>Vous avez déclaré cette action terminée — votre coach doit encore la valider.</p>
        <?php elseif ($a['motif_correction']): ?>
        <p class="font-body-sm text-[12px] text-orange-700 bg-orange-50 rounded-lg px-space-sm py-space-2xs flex items-start gap-1"><span class="material-symbols-outlined text-[14px] mt-0.5">info</span>Votre coach a demandé une correction : « <?php echo htmlspecialchars($a['motif_correction']); ?> »</p>
        <?php endif; ?>

        <?php if ($peutAgir): ?>
        <div class="flex items-center gap-space-xs flex-wrap pt-space-2xs">
          <?php if ($a['statut'] !== 'en_cours'): ?>
          <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="demarrer_action"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
            <button type="submit" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">play_arrow</span>Démarrer</button>
          </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Confirmer que cette action est terminée ? Elle sera soumise à votre coach pour vérification.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="terminer_action"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
            <button type="submit" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>J'ai terminé</button>
          </form>
          <?php if ($a['ressource_liee']): ?>
          <a href="<?php echo htmlspecialchars($a['ressource_liee']['url']); ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]"><?php echo $a['ressource_liee']['icone']; ?></span><?php echo htmlspecialchars($a['ressource_liee']['label']); ?></a>
          <?php endif; ?>
          <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">chat</span>Demander une précision</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($toutesActions): ?>
    <div class="bg-primary-container/5 border border-primary-container/20 rounded-xl p-space-lg flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-sm">
      <div class="flex items-start gap-space-sm">
        <span class="material-symbols-outlined text-[20px] text-primary mt-0.5">support_agent</span>
        <div>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Besoin d'aide pour finaliser une tâche ?</p>
          <p class="font-body-sm text-[13px] text-secondary">Votre coach est disponible par messagerie.</p>
        </div>
      </div>
      <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1">Contacter mon coach</a>
    </div>
    <?php endif; ?>
  </div>
</section>

</main>
<?php render_footer(); ?>
