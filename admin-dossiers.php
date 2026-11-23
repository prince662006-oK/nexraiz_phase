<?php
/**
 * Vue transverse de tous les dossiers, tous packs confondus (ou limité au
 * pack de rattachement pour un administrateur de pack) : recherche, filtre,
 * et affectation centralisée d'un consultant à un dossier.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';

$admin = require_role(['admin', 'super_admin'], 'mon-espace.php');
$estSuperAdmin = $admin['role'] === 'super_admin';

/**
 * Statut détaillé (parmi les 11 recommandés par le cahier des charges),
 * dérivé des données existantes sans modifier le schéma : dossiers.statut
 * (en_cours/en_attente/cloture) + current_step restent la source de vérité
 * fonctionnelle, ce calcul n'en est qu'une lecture plus lisible.
 */
function nx_statut_detail(array $d): array
{
    if ($d['statut'] === 'cloture') {
        return ['label' => 'Accompagnement terminé', 'couleur' => 'bg-surface-container-high text-secondary'];
    }
    if ($d['statut'] === 'en_attente') {
        return ['label' => 'Dossier suspendu ou annulé', 'couleur' => 'bg-error-container text-error'];
    }
    if ((int) $d['current_step'] <= 1) {
        return ['label' => 'Inscription commencée', 'couleur' => 'bg-surface-container-high text-secondary'];
    }
    if ($d['dernier_paiement_statut'] === 'en_attente' && $d['dernier_paiement_methode'] === 'virement') {
        return ['label' => 'Paiement à confirmer', 'couleur' => 'bg-orange-100 text-orange-700'];
    }
    if ($d['dernier_paiement_statut'] !== 'paye') {
        if ((int) $d['current_step'] <= 2) {
            return ['label' => 'Qualification à compléter', 'couleur' => 'bg-blue-100 text-blue-700'];
        }
        return ['label' => 'En attente de paiement', 'couleur' => 'bg-orange-100 text-orange-700'];
    }
    if (empty($d['consultant_id'])) {
        return ['label' => 'Consultant à affecter', 'couleur' => 'bg-error-container text-error'];
    }
    if ((int) $d['nb_livrables'] > 0) {
        return ['label' => 'Livrable disponible', 'couleur' => 'bg-green-100 text-green-700'];
    }
    if ((int) $d['nb_rdv'] > 0) {
        return ['label' => 'Accompagnement en cours', 'couleur' => 'bg-primary-container/10 text-primary'];
    }
    return ['label' => 'Rendez-vous planifié', 'couleur' => 'bg-blue-100 text-blue-700'];
}

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'affecter_consultant') {
        $dossierId = (int) ($_POST['dossier_id'] ?? 0);
        $consultantId = $_POST['consultant_id'] !== '' ? (int) $_POST['consultant_id'] : null;

        // Un administrateur de pack ne peut affecter que sur les dossiers de son pack.
        $stmt = db()->prepare('SELECT pack_id, user_id, numero FROM dossiers WHERE id = ?');
        $stmt->execute([$dossierId]);
        $cible = $stmt->fetch();

        if ($cible && ($estSuperAdmin || (int) $cible['pack_id'] === (int) $admin['pack_id'])) {
            db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ?')->execute([$consultantId, $dossierId]);
            log_activite($dossierId, $consultantId ? 'Consultant affecté par l\'administration' : 'Consultant retiré par l\'administration');
            if ($consultantId) {
                notify((int) $cible['user_id'], 'statut_dossier', 'Un consultant vous a été affecté', 'Votre dossier ' . $cible['numero'] . ' a maintenant un consultant dédié.', null);
            }
            $succes = 'Affectation mise à jour.';
        }
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs WHERE slug != \'particulier\' ORDER BY nom');
$packs = $stmt->fetchAll();

$packFiltre = $_GET['pack'] ?? '';
$statutFiltre = $_GET['statut'] ?? '';
$recherche = trim($_GET['q'] ?? '');

$conditions = [];
$params = [];

if (!$estSuperAdmin) {
    $conditions[] = 'ds.pack_id = ?';
    $params[] = $admin['pack_id'];
} elseif ($packFiltre !== '') {
    $conditions[] = 'pk.slug = ?';
    $params[] = $packFiltre;
}

if ($statutFiltre !== '') {
    $conditions[] = 'ds.statut = ?';
    $params[] = $statutFiltre;
}

if ($recherche !== '') {
    $conditions[] = '(ds.numero LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)';
    $like = '%' . $recherche . '%';
    array_push($params, $like, $like, $like, $like);
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stmt = db()->prepare(
    "SELECT ds.id, ds.numero, ds.statut, ds.current_step, ds.consultant_id, ds.created_at, ds.updated_at,
            u.nom, u.prenom, u.email,
            pk.slug AS pack_slug, pk.nom AS pack_nom,
            c.nom AS consultant_nom, c.prenom AS consultant_prenom,
            (SELECT statut FROM paiements p WHERE p.dossier_id = ds.id ORDER BY p.id DESC LIMIT 1) AS dernier_paiement_statut,
            (SELECT methode FROM paiements p WHERE p.dossier_id = ds.id ORDER BY p.id DESC LIMIT 1) AS dernier_paiement_methode,
            (SELECT COUNT(*) FROM livrables l WHERE l.dossier_id = ds.id AND l.statut = 'disponible') AS nb_livrables,
            (SELECT COUNT(*) FROM rendezvous r WHERE r.dossier_id = ds.id) AS nb_rdv
     FROM dossiers ds
     JOIN users u ON u.id = ds.user_id
     JOIN packs pk ON pk.id = ds.pack_id
     LEFT JOIN users c ON c.id = ds.consultant_id
     {$where}
     ORDER BY ds.updated_at DESC
     LIMIT 300"
);
$stmt->execute($params);
$dossiers = $stmt->fetchAll();

$stmt = db()->prepare("SELECT id, nom, prenom, pack_id FROM users WHERE role IN ('consultant', 'admin') AND actif = 1 ORDER BY prenom, nom");
$stmt->execute();
$tousConsultants = $stmt->fetchAll();

render_head('Tous les dossiers — Administration NexRaiz', 'Vue transverse de tous les dossiers NexRaiz.');
?>
<div class="flex w-full min-h-screen">

  <?php render_admin_sidebar('dossiers'); ?>

  <div class="flex-1 flex flex-col min-w-0">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
    </a>
    <div class="flex items-center gap-space-sm shrink-0 ml-auto">
      <?php render_notification_bell((int) $admin['id']); ?>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-max-width-content mx-auto w-full">
    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Tous les dossiers</h1>
      <p class="font-body-sm text-body-sm text-secondary"><?php echo count($dossiers); ?> dossier(s) <?php echo $estSuperAdmin ? 'tous packs confondus' : 'pour votre pack'; ?>.</p>
    </div>

    <?php if ($succes): ?>
    <div class="bg-green-100 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div>
    <?php endif; ?>

    <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-wrap items-end gap-space-sm">
      <label class="flex flex-col gap-space-2xs flex-1 min-w-[180px]">
        <span class="font-label-md text-label-md text-secondary">Recherche</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Numéro, nom, email..." class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
      </label>
      <?php if ($estSuperAdmin): ?>
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-secondary">Pack</span>
        <select name="pack" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Tous</option>
          <?php foreach ($packs as $p): ?>
          <option value="<?php echo htmlspecialchars($p['slug']); ?>" <?php echo $packFiltre === $p['slug'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label class="flex flex-col gap-space-2xs">
        <span class="font-label-md text-label-md text-secondary">Statut</span>
        <select name="statut" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Tous</option>
          <option value="en_cours" <?php echo $statutFiltre === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
          <option value="en_attente" <?php echo $statutFiltre === 'en_attente' ? 'selected' : ''; ?>>Suspendu / en attente</option>
          <option value="cloture" <?php echo $statutFiltre === 'cloture' ? 'selected' : ''; ?>>Clôturé</option>
        </select>
      </label>
      <button type="submit" class="h-10 px-space-md rounded-lg bg-primary-container text-white font-label-md text-label-md">Filtrer</button>
      <?php if ($recherche || $packFiltre || $statutFiltre): ?>
      <a href="admin-dossiers.php" class="h-10 px-space-md rounded-lg border border-outline-variant flex items-center font-label-md text-label-md text-secondary">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm overflow-x-auto">
      <table class="w-full min-w-[1000px] border-collapse">
        <thead>
          <tr class="border-b border-outline-variant/60 text-left">
            <th class="p-space-sm font-label-md text-label-md text-secondary">Dossier</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Candidat</th>
            <?php if ($estSuperAdmin): ?><th class="p-space-sm font-label-md text-label-md text-secondary">Pack</th><?php endif; ?>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Statut</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Consultant</th>
            <th class="p-space-sm font-label-md text-label-md text-secondary">Mis à jour</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dossiers as $d): $statutDetail = nx_statut_detail($d); ?>
          <tr class="border-b border-outline-variant/30 last:border-0 align-top">
            <td class="p-space-sm font-label-md text-label-md text-on-surface font-semibold whitespace-nowrap"><?php echo htmlspecialchars($d['numero']); ?></td>
            <td class="p-space-sm">
              <p class="font-body-sm text-body-sm text-on-surface font-semibold"><?php echo htmlspecialchars($d['prenom'] . ' ' . $d['nom']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['email']); ?></p>
            </td>
            <?php if ($estSuperAdmin): ?><td class="p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($d['pack_nom']); ?></td><?php endif; ?>
            <td class="p-space-sm"><span class="inline-flex px-space-xs py-1 rounded-full font-label-md text-[11px] font-bold <?php echo $statutDetail['couleur']; ?>"><?php echo htmlspecialchars($statutDetail['label']); ?></span></td>
            <td class="p-space-sm min-w-[220px]">
              <form method="post" class="flex items-center gap-space-2xs">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="affecter_consultant">
                <input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                <select name="consultant_id" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] flex-1">
                  <option value="">— Non affecté —</option>
                  <?php foreach ($tousConsultants as $c): ?>
                  <?php if (!$estSuperAdmin && (int) $c['pack_id'] !== (int) $admin['pack_id']) continue; ?>
                  <option value="<?php echo $c['id']; ?>" <?php echo (int) $d['consultant_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="h-9 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface hover:bg-surface-container-low transition-colors">OK</button>
              </form>
            </td>
            <td class="p-space-sm font-body-sm text-[12px] text-secondary whitespace-nowrap"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($d['updated_at']))); ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$dossiers): ?>
          <tr><td colspan="6" class="p-space-lg text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à ces critères.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  </div>
</div>
</body>
</html>
