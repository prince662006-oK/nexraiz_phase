<?php
/**
 * Tableau de bord du super administrateur NexRaiz : vue d'ensemble réelle,
 * tous packs confondus (dossiers, revenu, consultants), réservée au rôle
 * super_admin. Les administrateurs de pack (rattachés à un seul pack)
 * continuent d'utiliser le tableau de bord opérationnel de leur pack.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';

$superAdmin = require_role(['super_admin'], 'mon-espace.php');

$stmt = db()->query('SELECT id, slug, nom FROM packs ORDER BY nom');
$packs = $stmt->fetchAll();

// --- Indicateurs globaux, tous packs confondus. ---
$totalDossiers = (int) db()->query('SELECT COUNT(*) FROM dossiers')->fetchColumn();
$totalEnCours = (int) db()->query("SELECT COUNT(*) FROM dossiers WHERE statut = 'en_cours'")->fetchColumn();
$totalClotures = (int) db()->query("SELECT COUNT(*) FROM dossiers WHERE statut = 'cloture'")->fetchColumn();
$revenuTotal = (float) db()->query("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE statut = 'paye'")->fetchColumn();
$nbClientsActifs = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'client' AND actif = 1")->fetchColumn();
$nbConsultants = (int) db()->query("SELECT COUNT(*) FROM users WHERE role IN ('consultant', 'admin') AND actif = 1")->fetchColumn();

$stats = [
    ['icone' => 'folder', 'couleur' => 'bg-primary-container/10 text-primary', 'valeur' => number_format($totalDossiers, 0, ',', ' '), 'label' => 'Dossiers (tous packs)', 'delta' => $totalEnCours . ' en cours'],
    ['icone' => 'payments', 'couleur' => 'bg-green-100 text-green-700', 'valeur' => number_format($revenuTotal, 0, ',', ' ') . ' XOF', 'label' => 'Revenu encaissé', 'delta' => 'paiements confirmés'],
    ['icone' => 'group', 'couleur' => 'bg-blue-100 text-blue-700', 'valeur' => number_format($nbClientsActifs, 0, ',', ' '), 'label' => 'Clients actifs', 'delta' => 'tous packs'],
    ['icone' => 'support_agent', 'couleur' => 'bg-purple-100 text-purple-700', 'valeur' => number_format($nbConsultants, 0, ',', ' '), 'label' => 'Consultants & admins actifs', 'delta' => 'équipe NexRaiz'],
];

// --- Comparatif par pack : dossiers, statuts, revenu, consultants assignés. ---
$comparatifPacks = [];
foreach ($packs as $p) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM dossiers WHERE pack_id = ?');
    $stmt->execute([$p['id']]);
    $nbDossiers = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM dossiers WHERE pack_id = ? AND statut = 'en_cours'");
    $stmt->execute([$p['id']]);
    $nbEnCours = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM dossiers WHERE pack_id = ? AND statut = 'cloture'");
    $stmt->execute([$p['id']]);
    $nbClotures = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(pa.montant), 0) FROM paiements pa
         JOIN dossiers d ON d.id = pa.dossier_id
         WHERE d.pack_id = ? AND pa.statut = 'paye'"
    );
    $stmt->execute([$p['id']]);
    $revenu = (float) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE pack_id = ? AND role IN ('consultant', 'admin') AND actif = 1");
    $stmt->execute([$p['id']]);
    $nbConsultantsPack = (int) $stmt->fetchColumn();

    $comparatifPacks[] = [
        'nom' => $p['nom'], 'slug' => $p['slug'],
        'dossiers' => $nbDossiers, 'en_cours' => $nbEnCours, 'clotures' => $nbClotures,
        'revenu' => $revenu, 'consultants' => $nbConsultantsPack,
    ];
}

// --- Palette catégorielle fixe pour les graphiques (ordre jamais permuté). ---
$paletteCategorique = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

// --- Revenu encaissé par mois, 6 derniers mois (pour le graphique). ---
$moisLabels = ['01' => 'Jan', '02' => 'Fév', '03' => 'Mar', '04' => 'Avr', '05' => 'Mai', '06' => 'Juin', '07' => 'Juil', '08' => 'Août', '09' => 'Sept', '10' => 'Oct', '11' => 'Nov', '12' => 'Déc'];
$stmt = db()->query(
    "SELECT DATE_FORMAT(updated_at, '%Y-%m') AS mois, SUM(montant) AS total
     FROM paiements
     WHERE statut = 'paye' AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY mois"
);
$revenuParMoisBrut = [];
foreach ($stmt->fetchAll() as $r) {
    $revenuParMoisBrut[$r['mois']] = (float) $r['total'];
}
$revenuMensuel = [];
for ($i = 5; $i >= 0; $i--) {
    $cle = date('Y-m', strtotime("-{$i} months"));
    $revenuMensuel[] = ['label' => $moisLabels[substr($cle, 5, 2)], 'valeur' => $revenuParMoisBrut[$cle] ?? 0.0];
}

// --- Dossiers en cours par consultant, tous packs confondus (top 8). ---
$stmt = db()->query(
    "SELECT u.prenom, u.nom, COUNT(*) AS nb
     FROM dossiers d
     JOIN users u ON u.id = d.consultant_id
     WHERE d.statut = 'en_cours'
     GROUP BY d.consultant_id
     ORDER BY nb DESC
     LIMIT 8"
);
$chargeConsultants = [];
foreach ($stmt->fetchAll() as $c) {
    $chargeConsultants[] = ['label' => trim($c['prenom'] . ' ' . mb_substr($c['nom'], 0, 1) . '.'), 'valeur' => (int) $c['nb']];
}

// --- Activité récente, tous packs confondus. ---
$stmt = db()->query(
    "SELECT a.texte, a.created_at, ds.numero, pk.nom AS pack_nom
     FROM activites a
     JOIN dossiers ds ON ds.id = a.dossier_id
     JOIN packs pk ON pk.id = ds.pack_id
     ORDER BY a.id DESC LIMIT 15"
);
$activitesRecentes = $stmt->fetchAll();

function superadmin_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

$accesRapides = [
    ['icone' => 'folder_shared', 'titre' => 'Tous les dossiers', 'texte' => 'Vue transverse et affectation des consultants, tous packs confondus.', 'url' => 'admin-dossiers.php'],
    ['icone' => 'payments', 'titre' => 'Paiements', 'texte' => 'Suivi des paiements et confirmation des virements.', 'url' => 'admin-paiements.php'],
    ['icone' => 'event', 'titre' => 'Rendez-vous', 'texte' => 'Agenda de tous les rendez-vous, tous packs confondus.', 'url' => 'admin-rendezvous.php'],
    ['icone' => 'manage_accounts', 'titre' => 'Utilisateurs', 'texte' => 'Consultants, administrateurs, comptes et rôles.', 'url' => 'admin-utilisateurs.php'],
    ['icone' => 'folder_special', 'titre' => 'Ressources', 'texte' => 'Base de connaissances, guides et boîte à outils.', 'url' => 'ressources.php'],
    ['icone' => 'school', 'titre' => 'Formations & certifications', 'texte' => 'Catalogue de formations pour les équipes.', 'url' => 'formations.php'],
    ['icone' => 'description', 'titre' => 'Modèles de documents', 'texte' => 'Trames et modèles téléchargeables.', 'url' => 'modeles-documents.php'],
    ['icone' => 'campaign', 'titre' => 'Actualités', 'texte' => 'Annonces internes pour les équipes.', 'url' => 'actualites.php'],
];

render_head('Espace NexRaiz — Direction', 'Tableau de bord du super administrateur, vue tous packs confondus.');
?>
<div class="flex w-full min-h-screen">

  <?php render_admin_sidebar('dashboard'); ?>

  <div class="flex-1 flex flex-col min-w-0">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
    </a>
    <div class="flex items-center gap-space-sm shrink-0 ml-auto">
      <div class="flex items-center gap-space-xs">
        <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($superAdmin['prenom'], 0, 1) . mb_substr($superAdmin['nom'], 0, 1)); ?></div>
        <div class="hidden sm:flex flex-col leading-tight">
          <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($superAdmin['prenom'] . ' ' . $superAdmin['nom']); ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Super administrateur</span>
        </div>
      </div>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-max-width-content mx-auto w-full">
    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Tableau de bord — Direction NexRaiz</h1>
      <p class="font-body-sm text-body-sm text-secondary">Vue d'ensemble réelle de l'activité, tous packs confondus.</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
      <?php foreach ($stats as $s): ?>
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs">
        <div class="w-11 h-11 rounded-full <?php echo $s['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $s['icone']; ?></span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo $s['valeur']; ?></p>
        <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($s['label']); ?></p>
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($s['delta']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Comparatif par pack</h2>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/60">
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Pack</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dossiers</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">En cours</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Clôturés</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Revenu encaissé</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultants</th>
              <th class="w-8"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($comparatifPacks as $c): ?>
            <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
              <td class="py-space-sm pr-space-sm font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($c['nom']); ?></td>
              <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $c['dossiers']; ?></td>
              <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $c['en_cours']; ?></td>
              <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $c['clotures']; ?></td>
              <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo number_format($c['revenu'], 0, ',', ' '); ?> XOF</td>
              <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $c['consultants']; ?></td>
              <td class="py-space-sm text-right"><a href="admin-utilisateurs.php" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Gérer les utilisateurs de ce pack"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm">
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Dossiers par pack</h2>
        <div class="relative h-56"><canvas id="chartPacks" role="img" aria-label="Répartition des dossiers par pack"></canvas></div>
        <ul class="flex flex-col gap-1 pt-space-xs">
          <?php foreach ($comparatifPacks as $i => $c): ?>
          <li class="flex items-center gap-2 font-body-sm text-[12px] text-on-surface">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:<?php echo htmlspecialchars($paletteCategorique[$i % count($paletteCategorique)]); ?>"></span>
            <?php echo htmlspecialchars($c['nom']); ?>
            <span class="text-secondary ml-auto"><?php echo $c['dossiers']; ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="lg:col-span-2 bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Revenu encaissé — 6 derniers mois</h2>
        <div class="relative h-56"><canvas id="chartRevenu" role="img" aria-label="Revenu encaissé par mois sur les 6 derniers mois"></canvas></div>
      </div>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Dossiers en cours par consultant</h2>
      <?php if (!$chargeConsultants): ?>
      <p class="font-body-sm text-body-sm text-secondary">Aucun dossier en cours affecté pour le moment.</p>
      <?php else: ?>
      <div class="relative" style="height:<?php echo max(160, count($chargeConsultants) * 36); ?>px"><canvas id="chartConsultants" role="img" aria-label="Nombre de dossiers en cours par consultant"></canvas></div>
      <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm">
      <div class="lg:col-span-2 bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Activité récente (tous packs)</h2>
        <?php if (!$activitesRecentes): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
        <?php endif; ?>
        <?php foreach ($activitesRecentes as $act): ?>
        <div class="flex items-center justify-between gap-space-sm border-b border-outline-variant/30 last:border-0 py-space-2xs">
          <span class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-primary shrink-0">radio_button_checked</span><?php echo htmlspecialchars($act['texte']); ?><span class="text-secondary">— <?php echo htmlspecialchars($act['pack_nom']); ?> · <?php echo htmlspecialchars($act['numero']); ?></span></span>
          <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo htmlspecialchars(superadmin_il_y_a($act['created_at'])); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="flex flex-col gap-space-sm">
        <?php foreach ($accesRapides as $a): ?>
        <a href="<?php echo htmlspecialchars($a['url']); ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex items-center gap-space-sm hover:bg-surface-container-low transition-colors">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]"><?php echo $a['icone']; ?></span></div>
          <div class="flex-1 min-w-0"><p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($a['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($a['texte']); ?></p></div>
          <span class="material-symbols-outlined text-[18px] text-secondary shrink-0">chevron_right</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  </main>

  <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm max-w-max-width-content mx-auto w-full">
      <div class="flex items-center gap-1.5">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span>
      </div>
      <nav class="flex flex-wrap items-center gap-space-md">
        <a href="admin-utilisateurs.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Utilisateurs</a>
        <a href="ressources.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Ressources</a>
        <a href="contact.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Contact</a>
      </nav>
    </div>
  </footer>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
(function () {
  var paletteCategorique = <?php echo json_encode($paletteCategorique); ?>;
  var packsData = <?php echo json_encode(array_map(fn($c) => ['label' => $c['nom'], 'valeur' => $c['dossiers']], $comparatifPacks)); ?>;
  var revenuData = <?php echo json_encode($revenuMensuel); ?>;
  var consultantsData = <?php echo json_encode($chargeConsultants); ?>;

  var inkSecondary = '#52514e';
  var gridline = '#e1e0d9';
  Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', sans-serif";
  Chart.defaults.color = inkSecondary;

  new Chart(document.getElementById('chartPacks'), {
    type: 'doughnut',
    data: {
      labels: packsData.map(function (p) { return p.label; }),
      datasets: [{
        data: packsData.map(function (p) { return p.valeur; }),
        backgroundColor: packsData.map(function (p, i) { return paletteCategorique[i % paletteCategorique.length]; }),
        borderColor: '#fcfcfb',
        borderWidth: 2,
      }],
    },
    options: {
      cutout: '62%',
      plugins: { legend: { display: false } },
    },
  });

  new Chart(document.getElementById('chartRevenu'), {
    type: 'line',
    data: {
      labels: revenuData.map(function (r) { return r.label; }),
      datasets: [{
        label: 'Revenu encaissé (XOF)',
        data: revenuData.map(function (r) { return r.valeur; }),
        borderColor: '#2a78d6',
        backgroundColor: 'rgba(42, 120, 214, 0.12)',
        borderWidth: 2,
        pointRadius: 4,
        pointBackgroundColor: '#2a78d6',
        fill: true,
        tension: 0.3,
      }],
    },
    options: {
      scales: {
        y: { beginAtZero: true, grid: { color: gridline }, ticks: { callback: function (v) { return new Intl.NumberFormat('fr-FR').format(v); } } },
        x: { grid: { display: false } },
      },
      plugins: { legend: { display: false } },
    },
  });

  <?php if ($chargeConsultants): ?>
  new Chart(document.getElementById('chartConsultants'), {
    type: 'bar',
    data: {
      labels: consultantsData.map(function (c) { return c.label; }),
      datasets: [{
        label: 'Dossiers en cours',
        data: consultantsData.map(function (c) { return c.valeur; }),
        backgroundColor: '#6250d6',
        borderRadius: 4,
        maxBarThickness: 22,
      }],
    },
    options: {
      indexAxis: 'y',
      scales: {
        x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: gridline } },
        y: { grid: { display: false } },
      },
      plugins: { legend: { display: false } },
    },
  });
  <?php endif; ?>
})();
</script>
</body>
</html>
