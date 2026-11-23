<?php
/**
 * NexRaiz — Tableau de bord consultant, Pack Assistance. Centre de
 * pilotage quotidien : dossiers assignés, priorités du jour, rendez-vous,
 * activité récente. Tout est recalculé depuis les vraies tables à chaque
 * chargement — rien n'est stocké en dur ni inventé.
 *
 * Visibilité : un consultant (rôle "consultant") voit par défaut ses
 * propres dossiers + les dossiers non encore assignés (nécessaire pour
 * pouvoir se les approprier, logique déjà en place dans tout le projet —
 * voir consultant-dossier.php). Un admin/super_admin voit tout le pack,
 * comme partout ailleurs dans l'espace interne.
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

// --- 6 cartes statistiques ---
$stmt = db()->prepare("SELECT COUNT(*) FROM dossiers ds WHERE ds.pack_id = ? AND ds.consultant_id = ?");
$stmt->execute([$packId, $consultantId]);
$nbDossiersAssignes = (int) $stmt->fetchColumn();

$dossiersApercu = assistance_dossiers_apercu($packId, $consultant);
$dossiersVisibles = array_filter($dossiersApercu, fn ($d) => $voitTout || $d['est_mienne'] || $d['consultant_id'] === null);
$nbATraiter = count(array_filter($dossiersVisibles, fn ($d) => $d['a_traiter']));

$stmt = db()->prepare(
    "SELECT COUNT(*) FROM actions_plan ap JOIN dossiers ds ON ds.id = ap.dossier_id
     WHERE ds.pack_id = ? AND ap.statut != 'termine' AND ap.echeance IS NOT NULL
       AND ap.echeance <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)$condVisibilite"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$nbActionsUrgentes = (int) $stmt->fetchColumn();

// "Documents à examiner" : documents reçus sur des dossiers dont le
// diagnostic n'est pas encore validé (même logique que assistance_rappels(),
// réutilisée ici car aucun champ "statut de relecture" n'existe sur
// `documents` — c'est le meilleur signal réel disponible).
$stmt = db()->prepare(
    "SELECT COUNT(DISTINCT doc.id) FROM documents doc
     JOIN dossiers ds ON ds.id = doc.dossier_id
     LEFT JOIN qualifications q ON q.dossier_id = ds.id
     WHERE ds.pack_id = ? AND (q.diagnostic_valide_le IS NULL OR q.dossier_id IS NULL)$condVisibilite"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$nbDocumentsAExaminer = (int) $stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT COUNT(*) FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id
     WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0$condVisibilite"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$nbMessagesNonLus = (int) $stmt->fetchColumn();

// "Livrables à finaliser" : pour le Pack Assistance, le compte rendu est
// publié directement (pas d'état "en préparation" dans ce pack — voir
// consultant-dossier.php, action "generer_compte_rendu"). Le signal réel
// équivalent est : dossiers dont le diagnostic est validé mais qui n'ont
// encore aucun livrable — le compte rendu reste à produire.
$stmt = db()->prepare(
    "SELECT COUNT(DISTINCT ds.id) FROM dossiers ds
     JOIN qualifications q ON q.dossier_id = ds.id
     LEFT JOIN livrables l ON l.dossier_id = ds.id
     WHERE ds.pack_id = ? AND ds.statut != 'cloture' AND q.diagnostic_valide_le IS NOT NULL AND l.id IS NULL$condVisibilite"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$nbLivrablesAFinaliser = (int) $stmt->fetchColumn();

$stats = [
    ['icone' => 'folder_shared', 'valeur' => $nbDossiersAssignes, 'label' => 'Dossiers assignés', 'lien' => 'consultant-dossiers-assistance.php', 'couleur' => 'bg-primary-container/10 text-primary'],
    ['icone' => 'pending_actions', 'valeur' => $nbATraiter, 'label' => 'Dossiers à traiter', 'lien' => 'consultant-dossiers-assistance.php?vue=a_traiter', 'couleur' => 'bg-orange-100 text-orange-700'],
    ['icone' => 'schedule', 'valeur' => $nbActionsUrgentes, 'label' => 'Actions urgentes', 'lien' => '#priorites', 'couleur' => 'bg-error-container text-error'],
    ['icone' => 'description', 'valeur' => $nbDocumentsAExaminer, 'label' => 'Documents à examiner', 'lien' => 'consultant-dossiers-assistance.php?vue=a_valider', 'couleur' => 'bg-blue-100 text-blue-700'],
    ['icone' => 'mark_chat_unread', 'valeur' => $nbMessagesNonLus, 'label' => 'Messages non lus', 'lien' => 'consultant-messages-assistance.php', 'couleur' => 'bg-purple-100 text-purple-700'],
    ['icone' => 'summarize', 'valeur' => $nbLivrablesAFinaliser, 'label' => 'Livrables à finaliser', 'lien' => '#priorites', 'couleur' => 'bg-green-100 text-green-700'],
];

// --- Mes priorités du jour : fusion de plusieurs signaux réels, triés par urgence ---
$priorites = [];

$stmt = db()->prepare(
    "SELECT ap.id, ap.titre, ap.echeance, ds.id AS dossier_id, ds.numero, u.prenom, u.nom
     FROM actions_plan ap JOIN dossiers ds ON ds.id = ap.dossier_id JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ? AND ap.statut != 'termine' AND ap.echeance IS NOT NULL
       AND ap.echeance <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)$condVisibilite
     ORDER BY ap.echeance ASC LIMIT 6"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
foreach ($stmt->fetchAll() as $a) {
    $enRetard = strtotime($a['echeance']) < strtotime('today');
    $priorites[] = [
        'type' => 'Action ' . ($enRetard ? 'en retard' : 'à échéance proche'),
        'icone' => 'task_alt',
        'client' => $a['prenom'] . ' ' . $a['nom'],
        'numero' => $a['numero'],
        'detail' => $a['titre'],
        'echeance' => date('d/m/Y', strtotime($a['echeance'])),
        'priorite' => $enRetard ? 'haute' : 'moyenne',
        'lien' => 'consultant-dossier.php?dossier=' . $a['dossier_id'],
        'bouton' => 'Ouvrir le dossier',
    ];
}

$stmt = db()->prepare(
    "SELECT ds.id AS dossier_id, ds.numero, u.prenom, u.nom, COUNT(*) AS nb
     FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0$condVisibilite
     GROUP BY ds.id ORDER BY nb DESC LIMIT 5"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
foreach ($stmt->fetchAll() as $m) {
    $priorites[] = [
        'type' => 'Client en attente de réponse',
        'icone' => 'chat',
        'client' => $m['prenom'] . ' ' . $m['nom'],
        'numero' => $m['numero'],
        'detail' => $m['nb'] . ' message' . ($m['nb'] > 1 ? 's' : '') . ' non lu' . ($m['nb'] > 1 ? 's' : ''),
        'echeance' => null,
        'priorite' => 'haute',
        'lien' => 'consultant-messages-assistance.php?dossier=' . $m['dossier_id'],
        'bouton' => 'Répondre au client',
    ];
}

$stmt = db()->prepare(
    "SELECT ds.id AS dossier_id, ds.numero, u.prenom, u.nom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     JOIN qualifications q ON q.dossier_id = ds.id
     LEFT JOIN livrables l ON l.dossier_id = ds.id
     WHERE ds.pack_id = ? AND ds.statut != 'cloture' AND q.diagnostic_valide_le IS NOT NULL AND l.id IS NULL$condVisibilite
     GROUP BY ds.id LIMIT 5"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
foreach ($stmt->fetchAll() as $l) {
    $priorites[] = [
        'type' => 'Livrable à finaliser',
        'icone' => 'summarize',
        'client' => $l['prenom'] . ' ' . $l['nom'],
        'numero' => $l['numero'],
        'detail' => 'Diagnostic validé, compte rendu pas encore généré',
        'echeance' => null,
        'priorite' => 'moyenne',
        'lien' => 'consultant-dossier.php?dossier=' . $l['dossier_id'],
        'bouton' => 'Finaliser le livrable',
    ];
}

$stmt = db()->prepare(
    "SELECT ds.id AS dossier_id, ds.numero, u.prenom, u.nom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     JOIN qualifications q ON q.dossier_id = ds.id
     WHERE ds.pack_id = ? AND ds.statut != 'cloture' AND q.diagnostic_valide_le IS NULL
       AND q.created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)$condVisibilite
     LIMIT 5"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
foreach ($stmt->fetchAll() as $d) {
    $priorites[] = [
        'type' => 'Diagnostic en retard',
        'icone' => 'monitor_heart',
        'client' => $d['prenom'] . ' ' . $d['nom'],
        'numero' => $d['numero'],
        'detail' => 'Qualifié depuis plus de 2 jours, diagnostic pas encore validé',
        'echeance' => null,
        'priorite' => 'haute',
        'lien' => 'consultant-dossier.php?dossier=' . $d['dossier_id'],
        'bouton' => 'Examiner le document',
    ];
}

$ordrePriorite = ['haute' => 0, 'moyenne' => 1, 'basse' => 2];
usort($priorites, fn ($a, $b) => $ordrePriorite[$a['priorite']] <=> $ordrePriorite[$b['priorite']]);
$priorites = array_slice($priorites, 0, 8);

// --- Rendez-vous à venir ---
$stmt = db()->prepare(
    "SELECT rdv.date_heure, rdv.format, rdv.statut, ds.id AS dossier_id, ds.numero, u.prenom, u.nom
     FROM rendezvous rdv JOIN dossiers ds ON ds.id = rdv.dossier_id JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ? AND rdv.date_heure >= NOW() AND rdv.statut IN ('planifie', 'confirme')$condVisibilite
     ORDER BY rdv.date_heure ASC LIMIT 5"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$rdvAVenir = $stmt->fetchAll();
$formatLabels = ['ligne' => 'Visioconférence', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir', 'hybride' => 'Hybride'];
$rdvStatutLabels = ['planifie' => ['Planifié', 'bg-blue-100 text-blue-700'], 'confirme' => ['Confirmé', 'bg-green-100 text-green-700']];

// --- Activité récente (vue interne consultant : pas de filtre de
// confidentialité ici, contrairement au tableau de bord client — ces
// événements sont destinés aux consultants eux-mêmes). ---
$stmt = db()->prepare(
    "SELECT act.texte, act.created_at, ds.id AS dossier_id, ds.numero, u.prenom, u.nom
     FROM activites act JOIN dossiers ds ON ds.id = act.dossier_id JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ?$condVisibilite
     ORDER BY act.id DESC LIMIT 10"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$activitesRecentes = $stmt->fetchAll();

function assistance_tableau_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

// --- Dossiers récents (aperçu — le filtrage complet se fait sur
// consultant-dossiers-assistance.php) ---
$aAfficher = max(5, (int) ($_GET['affiches'] ?? 8));
$dossiersAffiches = array_slice(array_values($dossiersVisibles), 0, $aAfficher);

$joursNoms = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
$moisNoms = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$aujourdhui = new DateTimeImmutable('today');
$dateAujourdhui = $joursNoms[(int) $aujourdhui->format('N')] . ' ' . $aujourdhui->format('j') . ' ' . $moisNoms[(int) $aujourdhui->format('n')] . ' ' . $aujourdhui->format('Y');

$modules = [
    ['icone' => 'search', 'titre' => 'Diagnostic initial', 'texte' => "Clarifiez la situation, identifiez les enjeux et les objectifs du client.", 'lien' => 'Lancer le diagnostic', 'url' => 'consultant-dossiers-assistance.php?vue=nouveaux'],
    ['icone' => 'shield', 'titre' => 'Analyse des risques et urgences', 'texte' => 'Évaluez les risques juridiques et déterminez le niveau d\'urgence.', 'lien' => "Accéder à l'analyse", 'url' => 'consultant-dossiers-assistance.php?vue=a_valider'],
    ['icone' => 'folder', 'titre' => 'Organisation des preuves', 'texte' => 'Structurez et vérifiez les documents clés disponibles.', 'lien' => 'Gérer les preuves', 'url' => 'consultant-dossiers-assistance.php'],
    ['icone' => 'forum', 'titre' => 'Préparation des échanges', 'texte' => 'Anticipez les questions, préparez les arguments et interlocuteurs.', 'lien' => 'Préparer les échanges', 'url' => 'consultant-dossiers-assistance.php?vue=preparation'],
];

render_head('Espace NexRaiz — Tableau de bord', 'Pilotage interne des dossiers Pack Assistance.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dashboard_sidebar($packId, 'tableau'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-lg min-w-0">
        <a href="index.php" class="flex items-center gap-1.5 lg:hidden shrink-0">
          <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
        </a>
        <nav class="hidden md:flex items-center gap-space-md">
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-primary font-bold border-b-2 border-primary pb-1">Tableau de bord</a>
          <a href="consultant-dossiers-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
          <a href="consultant-dossiers-assistance.php?vue=a_valider" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Diagnostic</a>
          <a href="consultant-suivi-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Suivi</a>
          <a href="consultant-comptes-rendus-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Comptes rendus</a>
          <a href="parametres.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Paramètres</a>
        </nav>
      </div>
      <div class="flex items-center gap-space-sm shrink-0">
        <?php render_notification_bell($consultantId); ?>
        <div class="flex items-center gap-space-xs">
          <a href="parametres.php" class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold" title="Mon profil"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></a>
          <div class="hidden sm:flex flex-col leading-tight">
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo $consultant['role'] === 'admin' ? 'Administrateur' : 'Consultant'; ?></span>
          </div>
          <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors ml-space-xs" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
        </div>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <!-- En-tête personnalisé -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Bonjour, <?php echo htmlspecialchars($consultant['prenom']); ?> !</h1>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($dateAujourdhui); ?> · Voici un aperçu de vos dossiers et des actions à traiter aujourd'hui.</p>
        </div>
        <a href="consultant-comptes-rendus-assistance.php" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors shrink-0">
          <span class="material-symbols-outlined text-[18px]">description</span>
          <span>Exporter un rapport</span>
        </a>
      </div>

      <!-- 6 cartes statistiques -->
      <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-space-sm">
        <?php foreach ($stats as $s): ?>
        <a href="<?php echo htmlspecialchars($s['lien']); ?>" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md transition-shadow">
          <div class="w-10 h-10 rounded-full <?php echo $s['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo $s['icone']; ?></span></div>
          <p class="font-headline-md text-headline-md text-on-surface font-bold"><?php echo $s['valeur']; ?></p>
          <p class="font-label-md text-label-md text-on-surface font-semibold leading-tight"><?php echo htmlspecialchars($s['label']); ?></p>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Mes priorités du jour -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="priorites">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-error/10 text-error flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">priority_high</span></span>Mes priorités du jour</h2>
        <?php if (!$priorites): ?>
        <div class="flex flex-col items-center text-center gap-space-xs py-space-lg">
          <span class="material-symbols-outlined text-[36px] text-green-600">task_alt</span>
          <p class="font-body-sm text-body-sm text-secondary">Rien d'urgent pour le moment — tous vos dossiers actifs sont à jour.</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-xs">
          <?php foreach ($priorites as $p): ?>
          <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
            <div class="flex items-center gap-space-xs min-w-0">
              <div class="w-9 h-9 rounded-full <?php echo $p['priorite'] === 'haute' ? 'bg-error/10 text-error' : 'bg-orange-100 text-orange-700'; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $p['icone']; ?></span></div>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($p['type']); ?> — <?php echo htmlspecialchars($p['client']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($p['numero']); ?> · <?php echo htmlspecialchars($p['detail']); ?><?php echo $p['echeance'] ? ' · ' . htmlspecialchars($p['echeance']) : ''; ?></p>
              </div>
            </div>
            <a href="<?php echo htmlspecialchars($p['lien']); ?>" class="shrink-0 h-8 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[11px] font-bold flex items-center gap-1 hover:bg-primary transition-colors"><?php echo htmlspecialchars($p['bouton']); ?></a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-space-sm items-start">

        <!-- Mes dossiers récents -->
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm xl:col-span-2">
          <div class="flex items-center justify-between flex-wrap gap-space-xs">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Mes dossiers récents</h2>
            <a href="consultant-dossiers-assistance.php" class="font-label-md text-label-md text-primary flex items-center gap-1">Voir tous les dossiers <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
          </div>
          <div class="flex flex-col sm:flex-row gap-space-xs">
            <div class="relative flex-1">
              <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
              <input type="text" id="filtreRecherche" placeholder="Rechercher un client ou un dossier..." class="w-full h-10 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
            </div>
            <select id="filtreStatut" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <option value="">Tous les statuts</option>
              <option value="Nouveau">Nouveau</option>
              <option value="En cours">En cours</option>
              <option value="Analyse">Analyse</option>
              <option value="Préparation">Préparation</option>
              <option value="Clôturé">Clôturé</option>
            </select>
          </div>
          <?php if (!$dossiersAffiches): ?>
          <p class="font-body-sm text-body-sm text-secondary text-center py-space-md">Aucun dossier pour le moment.</p>
          <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] border-collapse" id="tableDossiers">
              <thead>
                <tr class="border-b border-outline-variant/60">
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Client</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Situation</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Urgence</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Documents</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dernière activité</th>
                  <th class="w-8"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dossiersAffiches as $d): ?>
                <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors" data-client="<?php echo htmlspecialchars(mb_strtolower($d['client'] . ' ' . $d['numero'])); ?>" data-statut="<?php echo htmlspecialchars($d['statut_label']); ?>">
                  <td class="py-space-sm pr-space-sm">
                    <a href="consultant-dossier.php?dossier=<?php echo $d['id']; ?>" class="flex items-center gap-space-xs">
                      <div class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo $d['initiales']; ?></div>
                      <div><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['client']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['numero']); ?></p></div>
                    </a>
                  </td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['situation']); ?></td>
                  <td class="py-space-sm pr-space-sm"><span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface"><span class="w-2 h-2 rounded-full <?php echo $d['urgence_dot']; ?>"></span><?php echo htmlspecialchars($d['urgence_label']); ?></span></td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $d['nb_documents']; ?>/<?php echo $d['nb_documents_attendus'] ?: '—'; ?></td>
                  <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $d['statut_classe']; ?>"><?php echo htmlspecialchars($d['statut_label']); ?></span></td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars(assistance_tableau_il_y_a($d['maj'])); ?></td>
                  <td class="py-space-sm text-right"><a href="consultant-dossier.php?dossier=<?php echo $d['id']; ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le dossier"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p id="aucunResultat" class="hidden font-body-sm text-body-sm text-secondary text-center py-space-md">Aucun dossier ne correspond à votre recherche.</p>
          </div>
          <?php if (count($dossiersVisibles) > count($dossiersAffiches)): ?>
          <a href="?affiches=<?php echo $aAfficher + 10; ?>" class="self-center flex items-center gap-1 font-label-md text-label-md text-primary pt-space-xs">Charger plus de dossiers <span class="material-symbols-outlined text-[16px]">expand_more</span></a>
          <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- Rendez-vous à venir -->
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">event</span></span>Rendez-vous à venir</h2>
          <?php if (!$rdvAVenir): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun rendez-vous à venir pour le moment.</p>
          <?php else: ?>
          <?php foreach ($rdvAVenir as $r): [$labelStatut, $classeStatut] = $rdvStatutLabels[$r['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
          <a href="consultant-dossier.php?dossier=<?php echo $r['dossier_id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
            <div class="flex items-center gap-space-xs min-w-0">
              <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">event</span></div>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($r['date_heure'])) . ' · ' . ($formatLabels[$r['format']] ?? '')); ?></p>
              </div>
            </div>
            <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeStatut; ?>"><?php echo $labelStatut; ?></span>
          </a>
          <?php endforeach; ?>
          <a href="consultant-calendrier-assistance.php" class="font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir le calendrier complet <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm items-start">
        <!-- Activité récente -->
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">history</span></span>Activité récente</h2>
          <?php if (!$activitesRecentes): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($activitesRecentes as $a): ?>
          <div class="flex items-start gap-space-xs">
            <span class="w-1.5 h-1.5 rounded-full bg-primary-container mt-2 shrink-0"></span>
            <div class="flex-1 min-w-0">
              <p class="font-body-sm text-body-sm text-on-surface"><span class="font-semibold"><?php echo htmlspecialchars($a['prenom'] . ' ' . $a['nom']); ?></span> — <?php echo htmlspecialchars($a['texte']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(assistance_tableau_il_y_a($a['created_at'])); ?></p>
            </div>
            <a href="consultant-dossier.php?dossier=<?php echo $a['dossier_id']; ?>" class="font-label-md text-[11px] text-primary shrink-0">Ouvrir</a>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Raccourcis d'accès rapide -->
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Accès rapide</h2>
          <div class="grid grid-cols-2 gap-space-xs">
            <a href="consultant-dossiers-assistance.php" class="flex items-center gap-space-xs p-space-sm rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">folder</span><span class="font-label-md text-label-md text-on-surface">Mes dossiers</span></a>
            <a href="consultant-dossiers-assistance.php?vue=a_valider" class="flex items-center gap-space-xs p-space-sm rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">description</span><span class="font-label-md text-label-md text-on-surface">Documents</span></a>
            <a href="consultant-messages-assistance.php" class="flex items-center gap-space-xs p-space-sm rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">chat</span><span class="font-label-md text-label-md text-on-surface">Messagerie</span></a>
            <a href="consultant-dossiers-assistance.php" class="flex items-center gap-space-xs p-space-sm rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">summarize</span><span class="font-label-md text-label-md text-on-surface">Livrables</span></a>
            <a href="consultant-calendrier-assistance.php" class="flex items-center gap-space-xs p-space-sm rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">calendar_month</span><span class="font-label-md text-label-md text-on-surface">Rendez-vous</span></a>
            <a href="notifications.php" class="flex items-center gap-space-xs p-space-sm rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">notifications</span><span class="font-label-md text-label-md text-on-surface">Notifications</span></a>
          </div>
        </div>
      </div>

      <!-- Modules du diagnostic (raccourcis fonctionnels réels, conservés) -->
      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Modules du diagnostic</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm">
          <?php foreach ($modules as $m): ?>
          <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
            <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $m['icone']; ?></span></div>
            <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo htmlspecialchars($m['titre']); ?></h3>
            <p class="font-body-sm text-body-sm text-secondary flex-1"><?php echo htmlspecialchars($m['texte']); ?></p>
            <a href="<?php echo htmlspecialchars($m['url']); ?>" class="font-label-md text-label-md text-primary flex items-center gap-1"><?php echo htmlspecialchars($m['lien']); ?> <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </main>
  </div>
</div>

<script>
(function () {
  var champRecherche = document.getElementById('filtreRecherche');
  var champStatut = document.getElementById('filtreStatut');
  var lignes = document.querySelectorAll('#tableDossiers tbody tr');
  var aucunResultat = document.getElementById('aucunResultat');

  function appliquerFiltres() {
    var recherche = (champRecherche.value || '').toLowerCase().trim();
    var statut = champStatut.value;
    var visibles = 0;
    lignes.forEach(function (ligne) {
      var correspondRecherche = recherche === '' || ligne.dataset.client.indexOf(recherche) !== -1;
      var correspondStatut = statut === '' || ligne.dataset.statut === statut;
      var visible = correspondRecherche && correspondStatut;
      ligne.classList.toggle('hidden', !visible);
      if (visible) visibles++;
    });
    if (aucunResultat) aucunResultat.classList.toggle('hidden', visibles > 0);
  }

  if (champRecherche) champRecherche.addEventListener('input', appliquerFiltres);
  if (champStatut) champStatut.addEventListener('change', appliquerFiltres);
})();
</script>
</body>
</html>
