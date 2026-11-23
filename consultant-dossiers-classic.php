<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/payments.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassigner') {
    require_csrf();
    $dossierId = (int) ($_POST['dossier_id'] ?? 0);
    $nouveauConsultantId = $_POST['nouveau_consultant_id'] !== '' ? (int) $_POST['nouveau_consultant_id'] : null;
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND pack_id = ?');
    $stmt->execute([$nouveauConsultantId, $dossierId, $packId]);
    $succes = 'Consultant réassigné.';
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
        marquer_paiement_paye($paiementId);
        $succes = 'Virement confirmé : le paiement a été marqué comme reçu.';
    }
}

$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

$stmt = db()->prepare("SELECT id, nom, prenom FROM users WHERE role IN ('consultant', 'admin') ORDER BY prenom, nom");
$stmt->execute();
$consultantsDisponibles = $stmt->fetchAll();

$recherche = trim($_GET['q'] ?? '');

$sql = "SELECT ds.id, ds.numero, ds.statut, ds.consultant_id, ds.created_at,
               u.nom, u.prenom, u.email, u.telephone,
               c.nom AS consultant_nom, c.prenom AS consultant_prenom
        FROM dossiers ds
        JOIN users u ON u.id = ds.user_id
        LEFT JOIN users c ON c.id = ds.consultant_id
        WHERE ds.pack_id = ?";
$params = [$packId];
if ($recherche !== '') {
    $sql .= ' AND (u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR ds.numero LIKE ?)';
    $like = '%' . $recherche . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY ds.updated_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$dossiersBruts = $stmt->fetchAll();

$stmt = db()->prepare('SELECT dossier_id, MIN(date_heure) AS prochain FROM rendezvous rdv JOIN dossiers ds ON ds.id = rdv.dossier_id WHERE ds.pack_id = ? AND rdv.date_heure >= NOW() GROUP BY dossier_id');
$stmt->execute([$packId]);
$rdvParDossier = [];
foreach ($stmt->fetchAll() as $r) {
    $rdvParDossier[$r['dossier_id']] = $r['prochain'];
}

$stmt = db()->prepare('SELECT dossier_id, id, statut, methode FROM paiements p1 WHERE p1.id = (SELECT MAX(p2.id) FROM paiements p2 WHERE p2.dossier_id = p1.dossier_id)');
$stmt->execute();
$paiementParDossier = [];
foreach ($stmt->fetchAll() as $p) {
    $paiementParDossier[$p['dossier_id']] = $p;
}

$statutLabels = ['en_cours' => ['En cours', 'bg-orange-100 text-orange-700'], 'en_attente' => ['En attente', 'bg-surface-container-high text-secondary'], 'cloture' => ['Clôturé', 'bg-green-100 text-green-700']];
$paiementLabels = ['paye' => ['Payé', 'text-green-700'], 'en_attente' => ['En attente', 'text-orange-600'], 'echoue' => ['Échoué', 'text-error'], 'rembourse' => ['Remboursé', 'text-secondary']];

render_head('Espace NexRaiz — Dossiers', 'Gestion des dossiers candidats du Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <nav class="hidden md:flex items-center gap-space-2xs">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">home</span>Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center gap-1 rounded-xl bg-surface-container-low text-primary font-label-lg text-label-lg font-bold border-b-2 border-primary"><span class="material-symbols-outlined text-[18px]">folder</span>Dossiers</a>
        <a href="consultant-analyse-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">bar_chart</span>Analyses</a>
        <a href="consultant-corrections-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">fact_check</span>Corrections</a>
        <a href="consultant-entretiens-classic.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">groups</span>Entretiens</a>
        <a href="parametres.php" class="px-space-sm h-9 flex items-center gap-1 font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors"><span class="material-symbols-outlined text-[18px]">settings</span>Paramètres</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <div class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
      <div class="hidden sm:flex flex-col leading-tight">
        <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
        <span class="font-body-sm text-[11px] text-secondary"><?php echo $consultant['role'] === 'admin' ? 'Administrateur' : 'Consultant'; ?></span>
      </div>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiersApercu, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

    <div class="flex items-center justify-between flex-wrap gap-space-sm">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Dossiers Pack Classic</h1>
        <p class="font-body-sm text-body-sm text-secondary">Coordonnées, paiement, rendez-vous et consultant assigné pour chaque candidat.</p>
      </div>
      <a href="consultant-nouveau-dossier-classic.php" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1"><span class="material-symbols-outlined text-[18px]">add_circle</span>Nouveau dossier</a>
    </div>

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
      <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
    </div>
    <?php endif; ?>

    <form method="get" class="flex items-center gap-space-sm max-w-md">
      <div class="relative flex-1">
        <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom, email ou numéro de dossier..." class="w-full h-11 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
      </div>
      <button type="submit" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Rechercher</button>
      <?php if ($recherche !== ''): ?>
      <a href="consultant-dossiers-classic.php" class="h-11 px-space-sm rounded-xl text-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">close</span></a>
      <?php endif; ?>
    </form>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[900px] border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/60">
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Candidat</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Contact</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Créé le</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultant assigné</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Paiement</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Prochain RDV</th>
              <th class="w-8"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dossiersBruts as $d):
              [$statutLabel, $statutClass] = $statutLabels[$d['statut']] ?? ['—', 'bg-surface-container-high text-secondary'];
              $paiement = $paiementParDossier[$d['id']] ?? null;
              [$paiementLabel, $paiementClass] = $paiement ? ($paiementLabels[$paiement['statut']] ?? ['—', 'text-secondary']) : ['Aucun paiement', 'text-secondary'];
              $virementAConfirmer = $paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente';
              $prochainRdv = $rdvParDossier[$d['id']] ?? null;
            ?>
            <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
              <td class="py-space-sm pr-space-sm">
                <span class="flex items-center gap-space-xs">
                  <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)); ?></span>
                  <span class="flex flex-col">
                    <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($d['prenom'] . ' ' . $d['nom']); ?></span>
                    <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($d['numero']); ?></span>
                  </span>
                </span>
              </td>
              <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface">
                <div><?php echo htmlspecialchars($d['email']); ?></div>
                <?php if ($d['telephone']): ?><div class="text-secondary"><?php echo htmlspecialchars($d['telephone']); ?></div><?php endif; ?>
              </td>
              <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))); ?></td>
              <td class="py-space-sm pr-space-sm">
                <form method="post" class="flex items-center gap-1">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="reassigner">
                  <input type="hidden" name="dossier_id" value="<?php echo $d['id']; ?>">
                  <select name="nouveau_consultant_id" onchange="this.form.submit()" class="h-9 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                    <option value="">Non assigné</option>
                    <?php foreach ($consultantsDisponibles as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo (int) $d['consultant_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td class="py-space-sm pr-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $statutClass; ?>"><?php echo htmlspecialchars($statutLabel); ?></span></td>
              <td class="py-space-sm pr-space-sm font-body-sm text-[12px]">
                <span class="font-semibold <?php echo $paiementClass; ?>"><?php echo htmlspecialchars($paiementLabel); ?></span>
                <?php if ($virementAConfirmer): ?>
                <form method="post" class="inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="confirmer_virement">
                  <input type="hidden" name="paiement_id" value="<?php echo $paiement['id']; ?>">
                  <button type="submit" class="ml-1 px-space-2xs py-0.5 rounded-full border border-outline-variant text-[10px] font-bold text-primary hover:bg-primary-container/10 transition-colors" title="Confirmer la réception du virement">Confirmer</button>
                </form>
                <?php endif; ?>
              </td>
              <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo $prochainRdv ? htmlspecialchars(date('d/m/Y H:i', strtotime($prochainRdv))) : '—'; ?></td>
              <td class="py-space-sm text-right"><a href="consultant-corrections-classic.php?dossier=<?php echo $d['id']; ?>" class="w-7 h-7 rounded-full hover:bg-surface-container-high flex items-center justify-center text-secondary" title="Ouvrir le dossier"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$dossiersBruts): ?>
            <tr><td colspan="8" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun dossier ne correspond à cette recherche.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="font-body-sm text-[12px] text-secondary"><?php echo count($dossiersBruts); ?> dossier<?php echo count($dossiersBruts) > 1 ? 's' : ''; ?> <?php echo $recherche !== '' ? 'trouvé' . (count($dossiersBruts) > 1 ? 's' : '') . ' pour « ' . htmlspecialchars($recherche) . ' »' : 'au total'; ?></p>
    </div>

  </main>
  </div>

  <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm max-w-max-width-content mx-auto w-full">
      <div class="flex items-center gap-1.5">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span>
      </div>
      <nav class="flex flex-wrap items-center gap-space-md">
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">À propos</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">CGU</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Confidentialité</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Mentions légales</a>
        <a href="contact.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Contact</a>
      </nav>
    </div>
  </footer>
</div>
</body>
</html>
