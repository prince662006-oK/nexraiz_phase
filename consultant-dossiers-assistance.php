<?php
/**
 * NexRaiz — Registre des dossiers, Pack Assistance. Visibilité : un
 * consultant voit ses dossiers + les non-assignés (nécessaire pour pouvoir
 * se les approprier — voir consultant-dossier.php) ; un admin/super_admin
 * voit tout le pack. Même règle que consultant-diagnostic.php, pour que
 * les deux pages restent cohérentes.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';
require_once __DIR__ . '/includes/payments.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);

$stmt = db()->prepare("SELECT id, nom, prenom FROM users WHERE role IN ('consultant', 'admin') ORDER BY prenom, nom");
$stmt->execute();
$consultantsDisponibles = $stmt->fetchAll();
$idsConsultantsValides = array_map(fn ($c) => (int) $c['id'], $consultantsDisponibles);

// Paramètres de recherche/filtre conservés pour la redirection post-POST
// (évite la resoumission de formulaire au rechargement, et garde le
// consultant sur sa vue/recherche en cours).
$parametresConserves = [
    'q' => $_GET['q'] ?? '', 'vue' => $_GET['vue'] ?? '', 'statut' => $_GET['statut'] ?? '',
    'urgence' => $_GET['urgence'] ?? '', 'paiement' => $_GET['paiement'] ?? '', 'periode' => $_GET['periode'] ?? '',
    'tri' => $_GET['tri'] ?? '',
];
$requeteConservee = http_build_query(array_filter($parametresConserves, fn ($v) => $v !== ''));

$succes = '';
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassigner') {
    require_csrf();
    $dossierId = (int) ($_POST['dossier_id'] ?? 0);
    $nouveauConsultantIdBrut = trim($_POST['nouveau_consultant_id'] ?? '');
    $nouveauConsultantId = $nouveauConsultantIdBrut !== '' ? (int) $nouveauConsultantIdBrut : null;

    $stmt = db()->prepare('SELECT consultant_id FROM dossiers WHERE id = ? AND pack_id = ?');
    $stmt->execute([$dossierId, $packId]);
    $ancienConsultantId = $stmt->fetchColumn();

    // Un consultant (non-admin) ne peut réassigner que ses propres dossiers
    // ou un dossier non assigné — jamais le dossier d'un collègue.
    $autorise = $voitTout || $ancienConsultantId === false || $ancienConsultantId === null || (int) $ancienConsultantId === $consultantId;
    // La cible doit être un consultant/admin réel, ou vide (non assigné).
    $cibleValide = $nouveauConsultantId === null || in_array($nouveauConsultantId, $idsConsultantsValides, true);

    if (!$autorise) {
        $erreur = "Vous n'êtes pas autorisé à réassigner ce dossier (déjà pris en charge par un autre consultant).";
    } elseif (!$cibleValide) {
        $erreur = 'Consultant sélectionné invalide.';
    } elseif ($ancienConsultantId === false) {
        $erreur = 'Dossier introuvable.';
    } else {
        $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND pack_id = ?');
        $stmt->execute([$nouveauConsultantId, $dossierId, $packId]);
        $nomNouveau = $nouveauConsultantId ? array_values(array_filter($consultantsDisponibles, fn ($c) => (int) $c['id'] === $nouveauConsultantId)) : [];
        $libelleNouveau = $nomNouveau ? $nomNouveau[0]['prenom'] . ' ' . $nomNouveau[0]['nom'] : 'Non assigné';
        log_activite($dossierId, 'Dossier réassigné à : ' . $libelleNouveau);
        $succes = 'Consultant réassigné.';
    }
    header('Location: consultant-dossiers-assistance.php?' . $requeteConservee . ($requeteConservee ? '&' : '') . 'succes=' . urlencode($succes) . '&erreur=' . urlencode($erreur));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmer_virement') {
    require_csrf();
    $paiementId = (int) ($_POST['paiement_id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT p.id FROM paiements p JOIN dossiers d ON d.id = p.dossier_id
         WHERE p.id = ? AND d.pack_id = ? AND p.methode = "virement" AND p.statut = "en_attente"'
    );
    $stmt->execute([$paiementId, $packId]);
    if ($stmt->fetchColumn()) {
        marquer_paiement_paye($paiementId); // trace déjà "Paiement confirmé" via log_activite()
        $succes = 'Virement confirmé : le paiement a été marqué comme reçu.';
    } else {
        $erreur = 'Ce paiement est introuvable ou déjà traité.';
    }
    header('Location: consultant-dossiers-assistance.php?' . $requeteConservee . ($requeteConservee ? '&' : '') . 'succes=' . urlencode($succes) . '&erreur=' . urlencode($erreur));
    exit;
}

if (isset($_GET['succes']) && $_GET['succes'] !== '') $succes = $_GET['succes'];
if (isset($_GET['erreur']) && $_GET['erreur'] !== '') $erreur = $_GET['erreur'];

$dossiersApercuBrut = assistance_dossiers_apercu($packId, $consultant);
$dossiersApercu = array_values(array_filter($dossiersApercuBrut, fn ($d) => $voitTout || $d['est_mienne'] || $d['consultant_id'] === null));

$recherche = trim($_GET['q'] ?? '');
$vue = $_GET['vue'] ?? '';
$statutFiltre = $_GET['statut'] ?? '';
$urgenceFiltre = $_GET['urgence'] ?? '';
$paiementFiltre = $_GET['paiement'] ?? '';
$periodeFiltre = $_GET['periode'] ?? '';
$tri = $_GET['tri'] ?? '';
$vuesLabels = [
    'nouveaux'   => 'Nouveaux dossiers',
    'a_valider'  => 'Diagnostics à valider',
    'preparation' => 'En préparation',
    'a_traiter'  => 'À traiter',
];

$stmt = db()->prepare('SELECT dossier_id, id, statut, methode FROM paiements p1 WHERE p1.id = (SELECT MAX(p2.id) FROM paiements p2 WHERE p2.dossier_id = p1.dossier_id)');
$stmt->execute();
$paiementParDossier = [];
foreach ($stmt->fetchAll() as $p) {
    $paiementParDossier[$p['dossier_id']] = $p;
}
$paiementLabels = ['paye' => ['Payé', 'text-green-700'], 'en_attente' => ['En attente', 'text-orange-600'], 'echoue' => ['Échoué', 'text-error'], 'rembourse' => ['Remboursé', 'text-secondary']];

$urgenceOrdre = ['critique' => 0, 'eleve' => 1, 'moyen' => 2, 'faible' => 3, '' => 4];

// Les 4 vues exactement comme décrites par le cahier des charges (aucune ne
// doit se chevaucher avec une autre pour un même dossier) :
// - nouveaux      : qualification pas encore remplie
// - a_valider     : qualification faite, diagnostic pas encore validé
// - preparation   : statut calculé = "Préparation"
// - a_traiter     : dossier actif sans aucune action au plan
$dossiersFiltres = array_values(array_filter($dossiersApercu, function ($d) use ($vue, $recherche, $statutFiltre, $urgenceFiltre, $paiementFiltre, $periodeFiltre, $paiementParDossier) {
    if ($vue === 'nouveaux' && $d['a_qualification']) return false;
    if ($vue === 'a_valider' && !($d['a_qualification'] && !$d['diagnostic_valide'])) return false;
    if ($vue === 'preparation' && $d['statut_label'] !== 'Préparation') return false;
    if ($vue === 'a_traiter' && !$d['a_traiter']) return false;
    if ($recherche !== '' && stripos($d['client'] . ' ' . $d['numero'] . ' ' . $d['email'] . ' ' . $d['telephone'], $recherche) === false) return false;
    if ($statutFiltre !== '' && $d['statut_label'] !== $statutFiltre) return false;
    if ($urgenceFiltre !== '') {
        $urgenceBrute = array_search($d['urgence_label'], ['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique']) ?: '';
        if ($urgenceBrute !== $urgenceFiltre) return false;
    }
    if ($paiementFiltre !== '') {
        $statutPaiement = $paiementParDossier[$d['id']]['statut'] ?? 'aucun';
        if ($statutPaiement !== $paiementFiltre) return false;
    }
    if ($periodeFiltre === '7j' && strtotime($d['created_at']) < strtotime('-7 days')) return false;
    if ($periodeFiltre === '30j' && strtotime($d['created_at']) < strtotime('-30 days')) return false;
    return true;
}));

// Compteurs exacts par vue (sur l'ensemble visible, indépendamment des
// filtres additionnels/recherche en cours).
$compteursVues = ['nouveaux' => 0, 'a_valider' => 0, 'preparation' => 0, 'a_traiter' => 0];
foreach ($dossiersApercu as $d) {
    if (!$d['a_qualification']) $compteursVues['nouveaux']++;
    if ($d['a_qualification'] && !$d['diagnostic_valide']) $compteursVues['a_valider']++;
    if ($d['statut_label'] === 'Préparation') $compteursVues['preparation']++;
    if ($d['a_traiter']) $compteursVues['a_traiter']++;
}

if ($tri === 'urgence') {
    usort($dossiersFiltres, function ($a, $b) use ($urgenceOrdre) {
        $ua = array_search($a['urgence_label'], ['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique']) ?: '';
        $ub = array_search($b['urgence_label'], ['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique']) ?: '';
        return ($urgenceOrdre[$ua] ?? 4) <=> ($urgenceOrdre[$ub] ?? 4);
    });
} elseif ($tri === 'activite') {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($b['maj']) <=> strtotime($a['maj']));
} elseif ($tri === 'ancien') {
    usort($dossiersFiltres, fn ($a, $b) => strtotime($a['created_at']) <=> strtotime($b['created_at']));
}

render_head('Espace NexRaiz — Dossiers Assistance', 'Gestion des dossiers candidats du Pack Assistance.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dashboard_sidebar($packId, 'dossiers'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-lg min-w-0">
        <a href="index.php" class="flex items-center gap-1.5 lg:hidden shrink-0">
          <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
        </a>
        <nav class="hidden md:flex items-center gap-space-md">
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
          <a href="consultant-dossiers-assistance.php" class="font-label-lg text-label-lg text-primary font-bold border-b-2 border-primary pb-1">Dossiers</a>
          <a href="consultant-dossiers-assistance.php?vue=a_valider" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Diagnostic</a>
          <a href="consultant-suivi-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Suivi</a>
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

      <div class="flex items-center justify-between flex-wrap gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Dossiers Pack Assistance</h1>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo $vue !== '' ? htmlspecialchars($vuesLabels[$vue] ?? '') . ' — ' : ''; ?>Situation, urgence, documents et consultant assigné pour chaque dossier.</p>
        </div>
      </div>

      <?php if ($succes): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
      </div>
      <?php endif; ?>
      <?php if ($erreur): ?>
      <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($erreur); ?></p>
      </div>
      <?php endif; ?>

      <!-- Onglets de vue avec compteurs exacts -->
      <div class="flex items-center gap-space-xs flex-wrap">
        <a href="consultant-dossiers-assistance.php" class="px-space-sm h-9 flex items-center rounded-full font-label-md text-[12px] <?php echo $vue === '' ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low'; ?> transition-colors">Tous (<?php echo count($dossiersApercu); ?>)</a>
        <?php foreach ($vuesLabels as $cleVue => $labelVue): ?>
        <a href="consultant-dossiers-assistance.php?vue=<?php echo $cleVue; ?>" class="px-space-sm h-9 flex items-center rounded-full font-label-md text-[12px] <?php echo $vue === $cleVue ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low'; ?> transition-colors"><?php echo htmlspecialchars($labelVue); ?> (<?php echo $compteursVues[$cleVue]; ?>)</a>
        <?php endforeach; ?>
      </div>

      <form method="get" class="flex items-center gap-space-xs flex-wrap">
        <?php if ($vue !== ''): ?><input type="hidden" name="vue" value="<?php echo htmlspecialchars($vue); ?>"><?php endif; ?>
        <div class="relative flex-1 min-w-[200px] max-w-md">
          <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" id="champRechercheDossier" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom, numéro, email ou téléphone..." class="w-full h-11 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
        </div>
        <select name="statut" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Tous les statuts</option>
          <?php foreach (['Nouveau', 'En cours', 'Analyse', 'Préparation', 'Clôturé'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $statutFiltre === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
          <?php endforeach; ?>
        </select>
        <select name="urgence" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Toute urgence</option>
          <?php foreach (['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique'] as $val => $lbl): ?>
          <option value="<?php echo $val; ?>" <?php echo $urgenceFiltre === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
          <?php endforeach; ?>
        </select>
        <select name="paiement" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Tout paiement</option>
          <option value="paye" <?php echo $paiementFiltre === 'paye' ? 'selected' : ''; ?>>Payé</option>
          <option value="en_attente" <?php echo $paiementFiltre === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
        </select>
        <select name="periode" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Toute période</option>
          <option value="7j" <?php echo $periodeFiltre === '7j' ? 'selected' : ''; ?>>7 derniers jours</option>
          <option value="30j" <?php echo $periodeFiltre === '30j' ? 'selected' : ''; ?>>30 derniers jours</option>
        </select>
        <select name="tri" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <option value="">Tri par défaut</option>
          <option value="urgence" <?php echo $tri === 'urgence' ? 'selected' : ''; ?>>Urgence</option>
          <option value="activite" <?php echo $tri === 'activite' ? 'selected' : ''; ?>>Dernière activité</option>
          <option value="ancien" <?php echo $tri === 'ancien' ? 'selected' : ''; ?>>Plus anciens d'abord</option>
        </select>
        <button type="submit" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Filtrer</button>
        <?php if ($vue !== '' || $recherche !== '' || $statutFiltre !== '' || $urgenceFiltre !== '' || $paiementFiltre !== '' || $periodeFiltre !== '' || $tri !== ''): ?>
        <a href="consultant-dossiers-assistance.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[900px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Situation</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Urgence</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultant assigné</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Paiement</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière mise à jour</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dossiersFiltres as $d): ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors <?php echo $d['urgence_label'] === 'Critique' ? 'bg-error-container/10' : ''; ?>">
                <td class="py-space-sm pr-space-sm">
                  <span class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo $d['initiales']; ?></span>
                    <span class="flex flex-col">
                      <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['client']); ?></span>
                      <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['numero']); ?></span>
                    </span>
                  </span>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['situation']); ?></td>
                <td class="py-space-sm pr-space-sm"><span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface font-semibold"><span class="w-2 h-2 rounded-full <?php echo $d['urgence_dot']; ?>"></span><?php echo htmlspecialchars($d['urgence_label']); ?></span></td>
                <td class="py-space-sm pr-space-sm">
                  <?php $peutReassigner = $voitTout || $d['consultant_id'] === null || $d['est_mienne']; ?>
                  <?php if ($peutReassigner): ?>
                  <form method="post" class="flex items-center gap-1">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reassigner">
                    <input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                    <select name="nouveau_consultant_id" onchange="this.form.submit()" class="h-9 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                      <option value="">Non assigné</option>
                      <?php foreach ($consultantsDisponibles as $c): ?>
                      <option value="<?php echo $c['id']; ?>" <?php echo $d['consultant_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                  <?php else: ?>
                  <span class="font-body-sm text-body-sm text-on-surface"><?php echo $d['consultant_id'] ? htmlspecialchars((array_values(array_filter($consultantsDisponibles, fn ($c) => (int) $c['id'] === $d['consultant_id']))[0]['prenom'] ?? '—') . ' ' . (array_values(array_filter($consultantsDisponibles, fn ($c) => (int) $c['id'] === $d['consultant_id']))[0]['nom'] ?? '')) : 'Non assigné'; ?></span>
                  <?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $d['statut_classe']; ?>"><?php echo htmlspecialchars($d['statut_label']); ?></span></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px]">
                  <?php $paiement = $paiementParDossier[$d['id']] ?? null; [$paiementLabel, $paiementClass] = $paiement ? ($paiementLabels[$paiement['statut']] ?? ['—', 'text-secondary']) : ['Aucun paiement', 'text-secondary']; ?>
                  <span class="font-semibold <?php echo $paiementClass; ?>"><?php echo htmlspecialchars($paiementLabel); ?></span>
                  <?php if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente'): ?>
                  <form method="post" class="inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="confirmer_virement">
                    <input type="hidden" name="paiement_id" value="<?php echo $paiement['id']; ?>">
                    <button type="submit" class="ml-1 px-space-2xs py-0.5 rounded-full border border-outline-variant text-[10px] font-bold text-primary hover:bg-primary-container/10 transition-colors" title="Confirmer la réception du virement" onclick="return confirm('Confirmer la réception de ce virement ?');">Confirmer</button>
                  </form>
                  <?php endif; ?>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['maj']))); ?></td>
                <td class="py-space-sm text-right"><a href="consultant-dossier.php?dossier=<?php echo $d['id']; ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le dossier"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$dossiersFiltres): ?>
              <tr><td colspan="8" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à cette recherche.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="font-body-sm text-[12px] text-secondary"><?php echo count($dossiersFiltres); ?> dossier<?php echo count($dossiersFiltres) > 1 ? 's' : ''; ?> au total</p>
      </div>

    </main>
  </div>
</div>

<script>
if (window.location.hash === '#recherche') {
  var champ = document.getElementById('champRechercheDossier');
  if (champ) { champ.focus(); champ.select(); }
}
</script>
</body>
</html>
