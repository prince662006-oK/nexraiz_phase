<?php
/**
 * NexRaiz — Onglet Suivi, Pack Assistance : pilotage des accompagnements au
 * niveau de l'action (actions_plan), pas au niveau du dossier — distinct de
 * consultant-dossiers-assistance.php (registre des dossiers) et de
 * consultant-dossier.php (fiche détaillée, où tout se modifie réellement :
 * cette page est volontairement en lecture seule, elle renvoie vers la
 * fiche pour toute écriture — pas de second formulaire qui dupliquerait
 * la logique déjà testée ailleurs).
 *
 * "Statut du suivi" (dérivé de actions_plan.statut + .proprietaire +
 * .echeance) est distinct du "statut du dossier" (dossiers.statut) —
 * affichés côte à côte, jamais confondus.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);
$condVisibilite = $voitTout ? '' : ' AND (ds.consultant_id = ? OR ds.consultant_id IS NULL)';
$paramVisibilite = $voitTout ? [] : [$consultantId];

$SEUIL_INACTIVITE_JOURS = 14; // seuil configurable pour l'alerte "dossier non mis à jour"

$inclureClotures = ($_GET['inclure_clotures'] ?? '') === '1';
$recherche = trim($_GET['q'] ?? '');
$statutFiltre = $_GET['statut'] ?? '';
$consultantFiltre = $_GET['consultant'] ?? '';
$echeanceFiltre = $_GET['echeance'] ?? '';
$prioriteFiltre = $_GET['priorite'] ?? '';
$tri = $_GET['tri'] ?? 'echeance';

// --- Dossiers visibles (actifs par défaut, cloturés sur demande) ---
$sqlDossiers = "SELECT ds.id, ds.numero, ds.statut AS dossier_statut, ds.consultant_id, ds.updated_at,
        u.nom, u.prenom, u.email, u.telephone,
        uc.prenom AS consultant_prenom, uc.nom AS consultant_nom,
        q.situation_type, q.urgence, q.documents_disponibles
     FROM dossiers ds
     JOIN users u ON u.id = ds.user_id
     LEFT JOIN users uc ON uc.id = ds.consultant_id
     LEFT JOIN qualifications q ON q.dossier_id = ds.id
     WHERE ds.pack_id = ?" . $condVisibilite . ($inclureClotures ? '' : " AND ds.statut != 'cloture'");
$stmt = db()->prepare($sqlDossiers);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$dossiersBruts = $stmt->fetchAll();
$dossierIds = array_column($dossiersBruts, 'id');

// --- Toutes les actions des dossiers visibles, en un seul aller-retour
// (pas de requête répétée par dossier). ---
$actionsParDossier = [];
if ($dossierIds) {
    $placeholders = implode(',', array_fill(0, count($dossierIds), '?'));
    $stmt = db()->prepare("SELECT id, dossier_id, titre, description, proprietaire, priorite, echeance, statut, created_at FROM actions_plan WHERE dossier_id IN ($placeholders) ORDER BY dossier_id, echeance ASC, id ASC");
    $stmt->execute($dossierIds);
    foreach ($stmt->fetchAll() as $a) {
        $actionsParDossier[$a['dossier_id']][] = $a;
    }
}

// --- Documents reçus par catégorie, par dossier (pour "document manquant bloquant"). ---
$documentsParDossier = [];
if ($dossierIds) {
    $placeholders = implode(',', array_fill(0, count($dossierIds), '?'));
    $stmt = db()->prepare("SELECT dossier_id, categorie FROM documents WHERE dossier_id IN ($placeholders) AND categorie IS NOT NULL");
    $stmt->execute($dossierIds);
    foreach ($stmt->fetchAll() as $d) {
        $documentsParDossier[$d['dossier_id']][$d['categorie']] = true;
    }
}

// --- Prochain rendez-vous par dossier (pour l'alerte "rendez-vous proche"). ---
$rdvParDossier = [];
if ($dossierIds) {
    $placeholders = implode(',', array_fill(0, count($dossierIds), '?'));
    $stmt = db()->prepare("SELECT dossier_id, MIN(date_heure) AS prochain FROM rendezvous WHERE dossier_id IN ($placeholders) AND date_heure >= NOW() AND statut IN ('planifie','confirme') GROUP BY dossier_id");
    $stmt->execute($dossierIds);
    foreach ($stmt->fetchAll() as $r) {
        $rdvParDossier[$r['dossier_id']] = $r['prochain'];
    }
}

/**
 * Déduit si le "propriétaire" texte libre d'une action désigne le client,
 * le consultant, ou un tiers externe (ex. "Avocat", "Médecin du travail" —
 * cohérent avec le module Orientation externe) — à partir du texte
 * réellement saisi, sans nouvelle colonne.
 */
function assistance_type_proprietaire(?string $proprietaire, string $nomClient): string
{
    $p = mb_strtolower(trim((string) $proprietaire), 'UTF-8');
    if ($p === '' || str_contains($p, 'consultant')) return 'consultant';
    if (str_contains($p, 'client') || str_contains($p, mb_strtolower($nomClient, 'UTF-8'))) return 'client';
    return 'tiers';
}

/**
 * Statut de suivi dérivé pour un dossier, à partir de sa prochaine action
 * ouverte (ou de l'absence d'action) — distinct du statut du dossier lui-même.
 */
function assistance_statut_suivi(array $actions, string $nomClient): array
{
    $ouvertes = array_values(array_filter($actions, fn ($a) => $a['statut'] !== 'termine'));
    if (!$actions) {
        return ['label' => 'Aucune action définie', 'classe' => 'bg-error-container text-error', 'cle' => 'sans_action'];
    }
    if (!$ouvertes) {
        return ['label' => 'Toutes actions terminées', 'classe' => 'bg-green-100 text-green-700', 'cle' => 'termine'];
    }
    $reference = $ouvertes[0]; // déjà trié par échéance ASC
    $enRetard = $reference['echeance'] && strtotime($reference['echeance']) < strtotime('today');
    if ($enRetard) {
        return ['label' => 'En retard', 'classe' => 'bg-error-container text-error', 'cle' => 'en_retard'];
    }
    $typeProprietaire = assistance_type_proprietaire($reference['proprietaire'], $nomClient);
    if ($reference['statut'] === 'a_venir') {
        return ['label' => 'À planifier', 'classe' => 'bg-surface-container-high text-secondary', 'cle' => 'a_planifier'];
    }
    if ($typeProprietaire === 'client') {
        return ['label' => 'En attente du client', 'classe' => 'bg-orange-100 text-orange-700', 'cle' => 'attente_client'];
    }
    if ($typeProprietaire === 'tiers') {
        return ['label' => 'En attente d\'un tiers', 'classe' => 'bg-purple-100 text-purple-700', 'cle' => 'attente_tiers'];
    }
    return ['label' => 'À effectuer', 'classe' => 'bg-blue-100 text-blue-700', 'cle' => 'a_effectuer'];
}

$statutDossierLabels = ['en_cours' => ['En cours', 'bg-blue-100 text-blue-700'], 'en_attente' => ['En attente', 'bg-orange-100 text-orange-700'], 'cloture' => ['Clôturé', 'bg-green-100 text-green-700']];

$suivis = [];
$alertes = ['en_retard' => [], 'aujourdhui' => [], 'attente_client' => [], 'document_manquant' => [], 'rdv_proche' => [], 'sans_action' => [], 'inactif' => []];

foreach ($dossiersBruts as $d) {
    $nomClient = $d['prenom'] . ' ' . $d['nom'];
    $actions = $actionsParDossier[$d['id']] ?? [];
    $ouvertes = array_values(array_filter($actions, fn ($a) => $a['statut'] !== 'termine'));
    $terminees = array_values(array_filter($actions, fn ($a) => $a['statut'] === 'termine'));

    $derniereAction = $terminees ? end($terminees) : null;
    $prochaineAction = $ouvertes[0] ?? null;
    $statutSuivi = assistance_statut_suivi($actions, $nomClient);

    $documentsAttendus = is_array(json_decode((string) ($d['documents_disponibles'] ?? ''), true)) ? json_decode((string) $d['documents_disponibles'], true) : [];
    $documentsManquants = array_values(array_filter($documentsAttendus, fn ($c) => empty($documentsParDossier[$d['id']][$c])));

    $prochainRdv = $rdvParDossier[$d['id']] ?? null;
    $joursInactivite = (int) floor((time() - strtotime($d['updated_at'])) / 86400);

    $suivi = [
        'dossier_id' => $d['id'], 'numero' => $d['numero'], 'client' => $nomClient,
        'email' => $d['email'], 'telephone' => $d['telephone'],
        'consultant' => $d['consultant_prenom'] ? $d['consultant_prenom'] . ' ' . $d['consultant_nom'] : 'Non assigné',
        'dossier_statut' => $d['dossier_statut'],
        'derniere_action' => $derniereAction ? $derniereAction['titre'] : null,
        'prochaine_action' => $prochaineAction ? $prochaineAction['titre'] : null,
        'prochaine_echeance' => $prochaineAction['echeance'] ?? null,
        'priorite' => $prochaineAction['priorite'] ?? 'normale',
        'statut_suivi' => $statutSuivi,
        'maj' => $d['updated_at'],
        'nb_actions_ouvertes' => count($ouvertes),
        'documents_manquants' => $documentsManquants,
        'prochain_rdv' => $prochainRdv,
    ];
    $suivis[] = $suivi;

    if ($statutSuivi['cle'] === 'en_retard') $alertes['en_retard'][] = $suivi;
    if ($prochaineAction && $prochaineAction['echeance'] === date('Y-m-d')) $alertes['aujourdhui'][] = $suivi;
    if ($statutSuivi['cle'] === 'attente_client') $alertes['attente_client'][] = $suivi;
    if ($documentsManquants && $d['dossier_statut'] !== 'cloture') $alertes['document_manquant'][] = $suivi;
    if ($prochainRdv && strtotime($prochainRdv) < strtotime('+3 days')) $alertes['rdv_proche'][] = $suivi;
    if ($statutSuivi['cle'] === 'sans_action' && $d['dossier_statut'] !== 'cloture') $alertes['sans_action'][] = $suivi;
    if ($d['dossier_statut'] !== 'cloture' && $joursInactivite >= $SEUIL_INACTIVITE_JOURS) $alertes['inactif'][] = $suivi;
}

// --- Indicateurs (sur l'ensemble visible, avant filtres additionnels). ---
$indicateurs = [
    ['cle' => '', 'icone' => 'folder_shared', 'couleur' => 'bg-primary-container/10 text-primary', 'valeur' => count($suivis), 'label' => 'Dossiers suivis'],
    ['cle' => 'a_effectuer', 'icone' => 'pending_actions', 'couleur' => 'bg-blue-100 text-blue-700', 'valeur' => count(array_filter($suivis, fn ($s) => $s['statut_suivi']['cle'] === 'a_effectuer')), 'label' => 'Actions à effectuer'],
    ['cle' => 'en_retard', 'icone' => 'schedule', 'couleur' => 'bg-error-container text-error', 'valeur' => count($alertes['en_retard']), 'label' => 'Actions en retard'],
    ['cle' => 'attente_client', 'icone' => 'hourglass_top', 'couleur' => 'bg-orange-100 text-orange-700', 'valeur' => count($alertes['attente_client']), 'label' => 'En attente du client'],
    ['cle' => 'attente_tiers', 'icone' => 'groups', 'couleur' => 'bg-purple-100 text-purple-700', 'valeur' => count(array_filter($suivis, fn ($s) => $s['statut_suivi']['cle'] === 'attente_tiers')), 'label' => "En attente d'un tiers"],
    ['cle' => 'termine', 'icone' => 'task_alt', 'couleur' => 'bg-green-100 text-green-700', 'valeur' => count(array_filter($suivis, fn ($s) => $s['statut_suivi']['cle'] === 'termine')), 'label' => 'Accompagnements à jour'],
];

// --- Filtres combinables + recherche + tri. ---
$consultantsListe = [];
foreach ($suivis as $s) { if ($s['consultant'] !== 'Non assigné') $consultantsListe[$s['consultant']] = true; }

$suivisFiltres = array_values(array_filter($suivis, function ($s) use ($recherche, $statutFiltre, $consultantFiltre, $echeanceFiltre, $prioriteFiltre) {
    if ($recherche !== '' && stripos($s['client'] . ' ' . $s['numero'], $recherche) === false) return false;
    if ($statutFiltre !== '' && $s['statut_suivi']['cle'] !== $statutFiltre) return false;
    if ($consultantFiltre !== '' && $s['consultant'] !== $consultantFiltre) return false;
    if ($prioriteFiltre !== '' && $s['priorite'] !== $prioriteFiltre) return false;
    if ($echeanceFiltre === '7j' && (!$s['prochaine_echeance'] || strtotime($s['prochaine_echeance']) > strtotime('+7 days'))) return false;
    if ($echeanceFiltre === 'depassee' && (!$s['prochaine_echeance'] || strtotime($s['prochaine_echeance']) >= strtotime('today'))) return false;
    return true;
}));

if ($tri === 'maj') {
    usort($suivisFiltres, fn ($a, $b) => strtotime($b['maj']) <=> strtotime($a['maj']));
} elseif ($tri === 'urgence') {
    $ordre = ['critique' => 0, 'eleve' => 1, 'moyen' => 2, 'faible' => 3, '' => 4];
    // Urgence non chargée ici par défaut (pas nécessaire aux indicateurs) —
    // tri par retard puis échéance en repli cohérent.
    usort($suivisFiltres, fn ($a, $b) => ($a['statut_suivi']['cle'] === 'en_retard' ? 0 : 1) <=> ($b['statut_suivi']['cle'] === 'en_retard' ? 0 : 1));
} else {
    usort($suivisFiltres, function ($a, $b) {
        $ea = $a['prochaine_echeance'] ? strtotime($a['prochaine_echeance']) : PHP_INT_MAX;
        $eb = $b['prochaine_echeance'] ? strtotime($b['prochaine_echeance']) : PHP_INT_MAX;
        return $ea <=> $eb;
    });
}

$nbAlertes = count($alertes['en_retard']) + count($alertes['aujourdhui']) + count($alertes['document_manquant']) + count($alertes['rdv_proche']) + count($alertes['sans_action']) + count($alertes['inactif']);

render_head('Espace NexRaiz — Suivi', 'Pilotage des accompagnements, Pack Assistance.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dashboard_sidebar($packId, 'suivi'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-lg min-w-0">
        <a href="index.php" class="flex items-center gap-1.5 lg:hidden shrink-0">
          <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
        </a>
        <nav class="hidden md:flex items-center gap-space-md">
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
          <a href="consultant-dossiers-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
          <a href="consultant-dossiers-assistance.php?vue=a_valider" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Diagnostic</a>
          <a href="consultant-suivi-assistance.php" class="font-label-lg text-label-lg text-primary font-bold border-b-2 border-primary pb-1">Suivi</a>
          <a href="consultant-comptes-rendus-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Comptes rendus</a>
          <a href="parametres.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Paramètres</a>
        </nav>
      </div>
      <div class="flex items-center gap-space-xs shrink-0">
        <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
        <span class="hidden sm:inline font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
        <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Suivi des accompagnements</h1>
        <p class="font-body-sm text-body-sm text-secondary">Qui accompagner, quelle action, pour quand, et ce qui bloque.</p>
      </div>

      <!-- Indicateurs cliquables -->
      <div class="grid grid-cols-2 lg:grid-cols-6 gap-space-sm">
        <?php foreach ($indicateurs as $i): ?>
        <a href="?statut=<?php echo $i['cle']; ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md transition-shadow <?php echo $statutFiltre === $i['cle'] && $i['cle'] !== '' ? 'ring-2 ring-primary-container' : ''; ?>">
          <div class="w-10 h-10 rounded-full <?php echo $i['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo $i['icone']; ?></span></div>
          <p class="font-headline-md text-headline-md text-on-surface font-bold"><?php echo $i['valeur']; ?></p>
          <p class="font-label-md text-label-md text-on-surface font-semibold leading-tight"><?php echo htmlspecialchars($i['label']); ?></p>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Alertes -->
      <?php if ($nbAlertes > 0): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-error/10 text-error flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">priority_high</span></span>Nécessite votre attention (<?php echo $nbAlertes; ?>)</h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-xs">
          <?php
          $typesAlertes = [
              'en_retard' => ['icone' => 'schedule', 'texte' => 'Échéance dépassée'],
              'aujourdhui' => ['icone' => 'today', 'texte' => "Échéance aujourd'hui"],
              'attente_client' => ['icone' => 'hourglass_top', 'texte' => 'Réponse du client attendue'],
              'document_manquant' => ['icone' => 'description', 'texte' => 'Document manquant'],
              'rdv_proche' => ['icone' => 'event', 'texte' => 'Rendez-vous proche'],
              'sans_action' => ['icone' => 'playlist_add', 'texte' => 'Aucune prochaine action définie'],
              'inactif' => ['icone' => 'history_toggle_off', 'texte' => "Non mis à jour depuis $SEUIL_INACTIVITE_JOURS jours ou plus"],
          ];
          foreach ($typesAlertes as $cle => $info):
            foreach (array_slice($alertes[$cle], 0, 4) as $suivi):
          ?>
          <a href="consultant-dossier.php?dossier=<?php echo $suivi['dossier_id']; ?>#plan" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
            <div class="flex items-center gap-space-xs min-w-0">
              <div class="w-9 h-9 rounded-full bg-error/10 text-error flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $info['icone']; ?></span></div>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($info['texte']); ?> — <?php echo htmlspecialchars($suivi['client']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($suivi['numero']); ?></p>
              </div>
            </div>
            <span class="material-symbols-outlined text-[18px] text-outline shrink-0">chevron_right</span>
          </a>
          <?php endforeach; endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Recherche et filtres -->
      <form method="get" class="flex items-center gap-space-xs flex-wrap">
        <div class="relative flex-1 min-w-[200px] max-w-md">
          <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom ou numéro de dossier..." class="w-full h-11 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
        </div>
        <select name="statut" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Tout statut de suivi</option>
          <?php foreach (['a_planifier' => 'À planifier', 'a_effectuer' => 'À effectuer', 'attente_client' => 'En attente du client', 'attente_tiers' => "En attente d'un tiers", 'en_retard' => 'En retard', 'termine' => 'Terminé', 'sans_action' => 'Sans action'] as $val => $lbl): ?>
          <option value="<?php echo $val; ?>" <?php echo $statutFiltre === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($voitTout && $consultantsListe): ?>
        <select name="consultant" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Tout consultant</option>
          <?php foreach (array_keys($consultantsListe) as $nomC): ?>
          <option value="<?php echo htmlspecialchars($nomC); ?>" <?php echo $consultantFiltre === $nomC ? 'selected' : ''; ?>><?php echo htmlspecialchars($nomC); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="echeance" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Toute échéance</option>
          <option value="depassee" <?php echo $echeanceFiltre === 'depassee' ? 'selected' : ''; ?>>Dépassée</option>
          <option value="7j" <?php echo $echeanceFiltre === '7j' ? 'selected' : ''; ?>>Dans les 7 jours</option>
        </select>
        <select name="priorite" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Toute priorité</option>
          <option value="haute" <?php echo $prioriteFiltre === 'haute' ? 'selected' : ''; ?>>Haute</option>
          <option value="normale" <?php echo $prioriteFiltre === 'normale' ? 'selected' : ''; ?>>Normale</option>
        </select>
        <select name="tri" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="echeance" <?php echo $tri === 'echeance' ? 'selected' : ''; ?>>Tri : échéance</option>
          <option value="maj" <?php echo $tri === 'maj' ? 'selected' : ''; ?>>Tri : dernière mise à jour</option>
          <option value="urgence" <?php echo $tri === 'urgence' ? 'selected' : ''; ?>>Tri : retards d'abord</option>
        </select>
        <label class="flex items-center gap-1 font-body-sm text-[12px] text-secondary">
          <input type="checkbox" name="inclure_clotures" value="1" <?php echo $inclureClotures ? 'checked' : ''; ?> onchange="this.form.submit()"> Inclure les clôturés
        </label>
        <button type="submit" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Filtrer</button>
        <?php if ($recherche || $statutFiltre || $consultantFiltre || $echeanceFiltre || $prioriteFiltre): ?>
        <a href="consultant-suivi-assistance.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
        <?php endif; ?>
      </form>

      <!-- Tableau de suivi -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[1000px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultant</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière action</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Prochaine action</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Échéance</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut du suivi</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut dossier</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière M.A.J.</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($suivisFiltres as $s): [$dStatutLabel, $dStatutClass] = $statutDossierLabels[$s['dossier_statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors <?php echo $s['statut_suivi']['cle'] === 'en_retard' ? 'bg-error-container/10' : ''; ?>">
                <td class="py-space-sm pr-space-sm">
                  <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($s['client']); ?></p>
                  <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($s['numero']); ?></p>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($s['consultant']); ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo $s['derniere_action'] ? htmlspecialchars($s['derniere_action']) : '—'; ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface">
                  <?php echo $s['prochaine_action'] ? htmlspecialchars($s['prochaine_action']) : '—'; ?>
                  <?php if ($s['priorite'] === 'haute'): ?><span class="ml-1 px-1 rounded-full bg-error-container text-error font-label-md text-[9px] font-bold">Prioritaire</span><?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] <?php echo $s['statut_suivi']['cle'] === 'en_retard' ? 'text-error font-bold' : 'text-secondary'; ?>"><?php echo $s['prochaine_echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($s['prochaine_echeance']))) : '—'; ?></td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $s['statut_suivi']['classe']; ?>"><?php echo htmlspecialchars($s['statut_suivi']['label']); ?></span></td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $dStatutClass; ?>"><?php echo htmlspecialchars($dStatutLabel); ?></span></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars(date('d/m/Y', strtotime($s['maj']))); ?></td>
                <td class="py-space-sm text-right"><a href="consultant-dossier.php?dossier=<?php echo $s['dossier_id']; ?>#plan" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le dossier"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$suivisFiltres): ?>
              <tr><td colspan="9" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun suivi ne correspond à cette recherche.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="font-body-sm text-[12px] text-secondary"><?php echo count($suivisFiltres); ?> dossier<?php echo count($suivisFiltres) > 1 ? 's' : ''; ?> suivi<?php echo count($suivisFiltres) > 1 ? 's' : ''; ?></p>
      </div>

    </main>
  </div>
</div>
</body>
</html>
