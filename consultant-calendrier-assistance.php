<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';
require_once __DIR__ . '/includes/consultant-calendrier.php';
require_once __DIR__ . '/includes/rendezvous.php';
require_once __DIR__ . '/includes/notifications.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);

$succes = '';
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer_rdv') {
    require_csrf();
    $dossierId = (int) ($_POST['dossier_id'] ?? 0);
    $jour = $_POST['jour'] ?? '';
    $heure = $_POST['heure'] ?? '';
    $format = in_array($_POST['format'] ?? '', ['ligne', 'presentiel', 'a_definir', 'hybride'], true) ? $_POST['format'] : 'ligne';

    $condVisibilite = $voitTout ? '' : ' AND (consultant_id = ? OR consultant_id IS NULL)';
    $paramVisibilite = $voitTout ? [] : [$consultantId];
    $stmt = db()->prepare("SELECT ds.id, ds.user_id, ds.numero FROM dossiers ds WHERE ds.id = ? AND ds.pack_id = ?{$condVisibilite}");
    $stmt->execute(array_merge([$dossierId, $packId], $paramVisibilite));
    $dossierCible = $stmt->fetch();

    if (!$dossierCible) {
        $erreur = 'Dossier introuvable ou non accessible.';
    } elseif ($jour === '' || $heure === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || !preg_match('/^\d{2}:\d{2}$/', $heure)) {
        $erreur = 'Merci de renseigner une date et une heure valides.';
    } elseif (creneau_est_pris($packId, $jour, $heure)) {
        $erreur = 'Ce créneau est déjà pris pour ce pack. Merci d\'en choisir un autre.';
    } else {
        $dateHeure = $jour . ' ' . $heure . ':00';
        db()->prepare('INSERT INTO rendezvous (dossier_id, date_heure, format, statut) VALUES (?, ?, ?, "confirme")')
            ->execute([$dossierId, $dateHeure, $format]);
        log_activite($dossierId, 'Rendez-vous planifié par le consultant pour le ' . date('d/m/Y', strtotime($jour)) . ' à ' . $heure);
        notify((int) $dossierCible['user_id'], 'rendez_vous', 'Rendez-vous planifié', 'Un rendez-vous a été programmé le ' . date('d/m/Y', strtotime($jour)) . ' à ' . $heure . ' — dossier ' . $dossierCible['numero'], 'tableau-bord.php');
        $succes = 'Rendez-vous planifié et confirmé.';
    }
}

$condVisibilite = $voitTout ? '' : ' AND (ds.consultant_id = ? OR ds.consultant_id IS NULL)';
$paramVisibilite = $voitTout ? [] : [$consultantId];
$stmt = db()->prepare(
    "SELECT ds.id, ds.numero, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ?{$condVisibilite} AND ds.statut != 'cloture' ORDER BY ds.updated_at DESC"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$dossiersPourCreation = array_map(fn ($d) => ['id' => (int) $d['id'], 'numero' => $d['numero'], 'client' => $d['prenom'] . ' ' . $d['nom']], $stmt->fetchAll());

render_head('Espace NexRaiz — Calendrier', 'Rendez-vous du Pack Assistance.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dashboard_sidebar($packId, 'calendrier'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-lg min-w-0">
        <a href="index.php" class="flex items-center gap-1.5 lg:hidden shrink-0">
          <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
        </a>
        <nav class="hidden md:flex items-center gap-space-md">
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
          <a href="consultant-dossiers-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
          <a href="consultant-diagnostic.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Diagnostic</a>
          <a href="consultant-suivi-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Suivi</a>
          <a href="consultant-comptes-rendus-assistance.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Comptes rendus</a>
          <a href="parametres.php" class="font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Paramètres</a>
        </nav>
      </div>
      <div class="flex items-center gap-space-md shrink-0">
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
          <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors ml-space-xs" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
        </div>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Calendrier</h1>
        <p class="font-body-sm text-body-sm text-secondary">Rendez-vous planifiés pour le Pack Assistance.</p>
      </div>
      <?php if ($succes): ?><div class="bg-green-50 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div><?php endif; ?>
      <?php if ($erreur): ?><div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreur); ?></div><?php endif; ?>
      <?php render_consultant_calendrier($packId, 'consultant-dossier.php', $dossiersPourCreation); ?>
    </main>
  </div>
</div>
</body>
</html>
