<?php
/**
 * NexRaiz — Tableau de bord consultant, Pack Booster : vue d'ensemble
 * (jusqu'ici "Tableau de bord" renvoyait par erreur vers la liste des
 * dossiers — voir includes/consultant-booster.php). Indicateurs et listes
 * calculés en direct depuis les vraies tables, comme partout ailleurs dans
 * le projet.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');
$consultantId = (int) $consultant['id'];
$dossiersApercu = booster_dossiers_apercu($packId, $consultant);

$stmt = db()->prepare('SELECT COUNT(*) FROM dossiers WHERE pack_id = ?');
$stmt->execute([$packId]);
$nbDossiers = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM dossiers WHERE pack_id = ? AND statut != 'cloture'");
$stmt->execute([$packId]);
$nbEnCours = (int) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM dossiers WHERE pack_id = ? AND consultant_id = ?');
$stmt->execute([$packId, $consultantId]);
$nbMesDossiers = (int) $stmt->fetchColumn();

$nbMessagesNonLus = booster_messages_non_lus_total($packId);

$stmt = db()->prepare(
    "SELECT COUNT(*) FROM rendezvous rdv JOIN dossiers ds ON ds.id = rdv.dossier_id
     WHERE ds.pack_id = ? AND rdv.date_heure >= NOW() AND rdv.date_heure < DATE_ADD(NOW(), INTERVAL 7 DAY)"
);
$stmt->execute([$packId]);
$nbSessionsSemaine = (int) $stmt->fetchColumn();

$stats = [
    ['icone' => 'folder', 'couleur' => 'bg-primary-container/10 text-primary', 'valeur' => $nbDossiers, 'label' => 'Dossiers Pack Booster', 'sous' => $nbEnCours . ' en cours'],
    ['icone' => 'assignment_ind', 'couleur' => 'bg-blue-100 text-blue-700', 'valeur' => $nbMesDossiers, 'label' => 'Mes dossiers assignés', 'sous' => "qui m'ont été attribués"],
    ['icone' => 'mark_chat_unread', 'couleur' => 'bg-error/10 text-error', 'valeur' => $nbMessagesNonLus, 'label' => 'Messages non lus', 'sous' => 'à traiter', 'lien' => 'consultant-messages-booster.php'],
    ['icone' => 'event', 'couleur' => 'bg-green-100 text-green-700', 'valeur' => $nbSessionsSemaine, 'label' => 'Sessions cette semaine', 'sous' => '7 prochains jours'],
];

// --- Dossiers nécessitant une attention (messages non lus, aucune action définie). ---
$stmt = db()->prepare(
    'SELECT ds.id, u.nom, u.prenom,
        (SELECT COUNT(*) FROM messages m WHERE m.dossier_id = ds.id AND m.expediteur_type = "client" AND m.lu_par_consultant = 0) AS msgs_non_lus,
        (SELECT COUNT(*) FROM actions_plan a WHERE a.dossier_id = ds.id) AS nb_actions
     FROM dossiers ds
     JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ? AND ds.statut != "cloture"
     ORDER BY ds.updated_at DESC'
);
$stmt->execute([$packId]);
$dossiersActifs = $stmt->fetchAll();

$aTraiter = [];
foreach ($dossiersActifs as $d) {
    $raisons = [];
    if ((int) $d['msgs_non_lus'] > 0) {
        $raisons[] = $d['msgs_non_lus'] . ' message' . ($d['msgs_non_lus'] > 1 ? 's' : '') . ' non lu' . ($d['msgs_non_lus'] > 1 ? 's' : '');
    }
    if ((int) $d['nb_actions'] === 0) {
        $raisons[] = 'aucune action définie';
    }
    if ($raisons) {
        $aTraiter[] = [
            'id' => $d['id'],
            'nom' => $d['prenom'] . ' ' . $d['nom'],
            'initiales' => strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)),
            'raisons' => $raisons,
            'urgent' => (int) $d['msgs_non_lus'] > 0,
        ];
    }
}
usort($aTraiter, fn ($a, $b) => $b['urgent'] <=> $a['urgent']);
$aTraiter = array_slice($aTraiter, 0, 6);

// --- Prochaines sessions. ---
$stmt = db()->prepare(
    'SELECT rdv.date_heure, rdv.format, ds.id AS dossier_id, u.nom, u.prenom
     FROM rendezvous rdv
     JOIN dossiers ds ON ds.id = rdv.dossier_id
     JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ? AND rdv.date_heure >= NOW()
     ORDER BY rdv.date_heure ASC
     LIMIT 5'
);
$stmt->execute([$packId]);
$prochainesSessions = $stmt->fetchAll();
$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir'];

// --- Activité récente sur l'ensemble des dossiers Pack Booster. ---
$stmt = db()->prepare(
    'SELECT act.texte, act.created_at, u.nom, u.prenom, ds.id AS dossier_id
     FROM activites act
     JOIN dossiers ds ON ds.id = act.dossier_id
     JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ?
     ORDER BY act.id DESC
     LIMIT 8'
);
$stmt->execute([$packId]);
$activitesRecentes = $stmt->fetchAll();

function booster_tableau_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

$joursNoms = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
$moisNoms = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];
$aujourdhui = new DateTimeImmutable('today');
$dateAujourdhui = $joursNoms[(int) $aujourdhui->format('N')] . ' ' . $aujourdhui->format('j') . ' ' . $moisNoms[(int) $aujourdhui->format('n')] . ' ' . $aujourdhui->format('Y');

render_head('Espace NexRaiz — Tableau de bord', "Vue d'ensemble du Pack Booster pour les consultants NexRaiz.");
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'tableau'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Bonjour, <?php echo htmlspecialchars($consultant['prenom']); ?> 👋</h1>
        <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($dateAujourdhui); ?> · Vue d'ensemble du Pack Booster</p>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <?php foreach ($stats as $s): $tag = isset($s['lien']) ? 'a' : 'div'; ?>
        <<?php echo $tag; ?><?php echo isset($s['lien']) ? ' href="' . htmlspecialchars($s['lien']) . '"' : ''; ?> class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs<?php echo isset($s['lien']) ? ' hover:bg-surface-container-low transition-colors' : ''; ?>">
          <div class="w-11 h-11 rounded-full <?php echo $s['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $s['icone']; ?></span></div>
          <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo $s['valeur']; ?></p>
          <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($s['label']); ?></p>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($s['sous']); ?></p>
        </<?php echo $tag; ?>>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm items-start">

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-error/10 text-error flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">priority_high</span></span>Nécessite votre attention</h2>
          <?php if (!$aTraiter): ?>
          <p class="font-body-sm text-body-sm text-secondary">Rien à signaler pour le moment — tous les dossiers actifs sont à jour.</p>
          <?php endif; ?>
          <?php foreach ($aTraiter as $d): ?>
          <a href="consultant-dossier-booster.php?dossier=<?php echo $d['id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
            <div class="flex items-center gap-space-xs min-w-0">
              <span class="w-9 h-9 rounded-full <?php echo $d['urgent'] ? 'bg-error/10 text-error' : 'bg-primary-container/20 text-primary'; ?> flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo $d['initiales']; ?></span>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars(implode(' · ', $d['raisons'])); ?></p>
              </div>
            </div>
            <span class="material-symbols-outlined text-[18px] text-outline shrink-0">chevron_right</span>
          </a>
          <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">event</span></span>Prochaines sessions</h2>
          <?php if (!$prochainesSessions): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune session à venir pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($prochainesSessions as $r): ?>
          <a href="consultant-dossier-booster.php?dossier=<?php echo $r['dossier_id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
            <div class="flex items-center gap-space-xs min-w-0">
              <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">event</span></div>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($r['date_heure'])) . ' · ' . ($formatLabels[$r['format']] ?? '')); ?></p>
              </div>
            </div>
            <span class="material-symbols-outlined text-[18px] text-outline shrink-0">chevron_right</span>
          </a>
          <?php endforeach; ?>
          <a href="consultant-calendrier-booster.php" class="font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir le calendrier complet <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">history</span></span>Activité récente — Pack Booster</h2>
        <?php if (!$activitesRecentes): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
        <?php endif; ?>
        <?php foreach ($activitesRecentes as $a): ?>
        <div class="flex items-start gap-space-xs">
          <span class="w-1.5 h-1.5 rounded-full bg-primary-container mt-2 shrink-0"></span>
          <div class="flex-1 min-w-0">
            <p class="font-body-sm text-body-sm text-on-surface"><span class="font-semibold"><?php echo htmlspecialchars($a['prenom'] . ' ' . $a['nom']); ?></span> — <?php echo htmlspecialchars($a['texte']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(booster_tableau_il_y_a($a['created_at'])); ?></p>
          </div>
          <a href="consultant-dossier-booster.php?dossier=<?php echo $a['dossier_id']; ?>" class="font-label-md text-[11px] text-primary shrink-0">Ouvrir</a>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
        <a href="consultant-nouveau-dossier-booster.php" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex items-center gap-space-sm hover:bg-surface-container-low transition-colors">
          <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">add_circle</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Nouveau dossier</p><p class="font-body-sm text-[11px] text-secondary">Créer un dossier client</p></div>
        </a>
        <a href="consultant-reporting-booster.php?export=csv" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex items-center gap-space-sm hover:bg-surface-container-low transition-colors">
          <div class="w-11 h-11 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">ios_share</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Exporter un rapport</p><p class="font-body-sm text-[11px] text-secondary">Tous les dossiers en CSV</p></div>
        </a>
        <a href="consultant-reporting-booster.php" class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex items-center gap-space-sm hover:bg-surface-container-low transition-colors">
          <div class="w-11 h-11 rounded-full bg-green-100 text-green-700 flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">bar_chart</span></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Voir le reporting</p><p class="font-body-sm text-[11px] text-secondary">Indicateurs et filtres</p></div>
        </a>
      </div>

    </main>
  </div>
</div>
</body>
</html>
