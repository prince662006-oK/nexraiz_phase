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
</body>
</html>
