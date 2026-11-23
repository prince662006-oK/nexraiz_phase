<?php
/**
 * NexRaiz — « Déposer des documents » (Pack Classic, module Actions
 * rapides). Distinct de consultant-importer-dossier-classic.php (import CSV
 * en masse de candidats) : ici, le consultant dépose un ou plusieurs
 * fichiers (CV, lettre, offre, portfolio) et les rattache à UN dossier
 * précis, avec une analyse IA informative du CV déposé.
 *
 * Réutilise entièrement le mécanisme existant de includes/documents-classic.php
 * (slots, validations MIME/taille, stockage protégé) — aucune seconde
 * logique de dépôt de fichier.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/documents-classic.php';
require_once __DIR__ . '/includes/extraction.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');
$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

$dossierId = (int) ($_GET['dossier'] ?? 0);
$dossier = null;
if ($dossierId > 0) {
    $stmt = db()->prepare('SELECT ds.*, u.nom, u.prenom, u.email FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?');
    $stmt->execute([$dossierId, $packId]);
    $dossier = $stmt->fetch();
    if (!$dossier) {
        header('Location: consultant-deposer-documents-classic.php');
        exit;
    }
}

$slots = classic_slots_documents();
$resultatsDepot = [];

if ($dossier && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_documents') {
    require_csrf();
    $nbReussis = 0;
    foreach (array_keys($slots) as $slot) {
        $champ = 'fichier_' . $slot;
        if (empty($_FILES[$champ]) || $_FILES[$champ]['error'] === UPLOAD_ERR_NO_FILE) {
            continue; // Ce slot n'a pas été rempli dans cette opération — normal, pas une erreur.
        }
        $messageErreur = classic_enregistrer_document($dossierId, $slot, $champ);
        $resultatsDepot[] = [
            'slot' => $slot,
            'label' => $slots[$slot]['label'],
            'nom_fichier' => $_FILES[$champ]['name'] ?? '',
            'ok' => $messageErreur === '',
            'message' => $messageErreur,
        ];
        if ($messageErreur === '') {
            $nbReussis++;
        }
    }
    if ($nbReussis > 0) {
        log_activite($dossierId, $nbReussis . ' document(s) déposé(s) par le consultant ' . $consultant['prenom'] . ' ' . $consultant['nom'] . ' via le module de dépôt.');
    }
    if (!$resultatsDepot) {
        $resultatsDepot[] = ['slot' => null, 'label' => '', 'nom_fichier' => '', 'ok' => false, 'message' => 'Aucun fichier sélectionné.'];
    }
}

// --- Recherche de dossier (étape 1, si aucun dossier n'est encore choisi). ---
$resultatsRecherche = [];
$termeRecherche = trim($_GET['q'] ?? '');
if (!$dossier && $termeRecherche !== '') {
    $like = '%' . $termeRecherche . '%';
    $stmt = db()->prepare(
        'SELECT ds.id, ds.numero, ds.statut, u.nom, u.prenom, u.email
         FROM dossiers ds JOIN users u ON u.id = ds.user_id
         WHERE ds.pack_id = ? AND (u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR ds.numero LIKE ?)
         ORDER BY ds.updated_at DESC LIMIT 20'
    );
    $stmt->execute([$packId, $like, $like, $like, $like]);
    $resultatsRecherche = $stmt->fetchAll();
}

$documentsParSlot = $dossier ? classic_documents_par_slot($dossierId) : [];

render_head('Espace NexRaiz — Déposer des documents', 'Rattacher des documents à un dossier candidat, Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <nav class="hidden md:flex items-center gap-space-sm">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
        <a href="consultant-analyse-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Analyses</a>
        <a href="consultant-corrections-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Corrections</a>
        <a href="parametres.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Paramètres</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <div class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
      <span class="hidden sm:inline font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiersApercu, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="consultant-analyse-classic.php" class="hover:text-primary transition-colors">Dossiers Pack Classic</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Déposer des documents</span>
    </nav>

    <?php if (!$dossier): ?>
    <!-- ===== Étape 1 : rechercher le dossier cible ===== -->
    <div class="max-w-2xl w-full flex flex-col gap-space-md">
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Déposer des documents</h1>
      <p class="font-body-sm text-body-sm text-secondary">Recherchez le candidat auquel rattacher les documents (nom, prénom, email ou numéro de dossier).</p>

      <form method="get" class="flex items-center gap-space-xs">
        <input type="text" name="q" value="<?php echo htmlspecialchars($termeRecherche); ?>" autofocus placeholder="Ex. : Awa Diop, awa@example.com, PC-2026-..." class="flex-1 h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        <button type="submit" class="h-11 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">search</span>Rechercher</button>
      </form>

      <?php if ($termeRecherche !== ''): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
        <?php if (!$resultatsRecherche): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucun dossier Pack Classic trouvé pour « <?php echo htmlspecialchars($termeRecherche); ?> ».</p>
        <a href="consultant-nouveau-dossier-classic.php" class="self-start h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">add_circle</span>Créer un nouveau dossier</a>
        <?php endif; ?>
        <?php foreach ($resultatsRecherche as $r): ?>
        <a href="consultant-deposer-documents-classic.php?dossier=<?php echo $r['id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($r['numero'] . ' · ' . $r['email']); ?></p>
          </div>
          <span class="material-symbols-outlined text-[18px] text-outline shrink-0">chevron_right</span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ===== Étape 2 : déposer les documents pour ce dossier ===== -->
    <div class="max-w-3xl w-full flex flex-col gap-space-md">
      <div class="flex items-center justify-between flex-wrap gap-space-xs">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Déposer des documents</h1>
          <p class="font-body-sm text-body-sm text-secondary">Pour <strong><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></strong> · <?php echo htmlspecialchars($dossier['numero']); ?></p>
        </div>
        <a href="consultant-deposer-documents-classic.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">search</span>Changer de dossier</a>
      </div>

      <?php if ($resultatsDepot): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
        <p class="font-label-lg text-label-lg text-on-surface font-bold">Résultat du dépôt</p>
        <?php foreach ($resultatsDepot as $r): ?>
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[18px] <?php echo $r['ok'] ? 'text-green-700' : 'text-error'; ?>"><?php echo $r['ok'] ? 'check_circle' : 'error'; ?></span>
          <p class="font-body-sm text-[12px] text-on-surface"><?php echo $r['label'] ? htmlspecialchars($r['label']) . ' — ' : ''; ?><?php echo $r['ok'] ? htmlspecialchars($r['nom_fichier']) . ' déposé avec succès.' : htmlspecialchars($r['message']); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="deposer_documents">
        <p class="font-body-sm text-[11px] text-secondary">Remplissez un ou plusieurs champs ci-dessous puis cliquez une seule fois sur « Déposer » — chaque fichier rempli sera importé dans la même opération. Formats acceptés : PDF, DOC, DOCX, JPG, PNG (10 Mo max par fichier).</p>
        <?php foreach ($slots as $slot => $info): ?>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($info['label']); ?><?php echo $info['obligatoire'] ? ' <span class="text-error">*</span>' : ' <span class="font-body-sm text-[11px] text-secondary font-normal">(facultatif)</span>'; ?></span>
          <input type="file" name="fichier_<?php echo $slot; ?>" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="font-body-sm text-[12px] text-secondary">
        </label>
        <?php endforeach; ?>
        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span class="material-symbols-outlined text-[18px]">upload_file</span>
          <span>Déposer les documents sélectionnés</span>
        </button>
      </form>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Documents déjà déposés</h2>
        <?php foreach ($slots as $slot => $info):
          $actuel = $documentsParSlot[$slot]['actuel'] ?? null;
        ?>
        <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm flex-wrap">
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($info['label']); ?></p>
            <?php if ($actuel): ?>
            <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($actuel['nom_original']); ?> · reçu le <?php echo htmlspecialchars(date('d/m/Y', strtotime($actuel['uploaded_at']))); ?></p>
            <?php else: ?>
            <p class="font-body-sm text-[11px] text-secondary">Non transmis.</p>
            <?php endif; ?>
          </div>
          <?php if ($actuel): ?>
          <div class="flex items-center gap-1 shrink-0">
            <a href="classic-document-telecharger.php?id=<?php echo $actuel['id']; ?>" target="_blank" rel="noopener" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir</a>
            <?php if ($slot === 'cv'): ?>
            <button type="button" class="btn-analyser-cv h-8 px-space-sm rounded-lg border border-primary-container text-primary font-label-md text-[11px] flex items-center gap-1" data-document-id="<?php echo $actuel['id']; ?>"><span class="material-symbols-outlined text-[14px]">auto_awesome</span>Analyser avec l'IA</button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="resultatAnalyseIa" class="hidden bg-primary-container/5 border border-primary-container/30 rounded-xl shadow-sm p-space-lg flex-col gap-space-sm">
        <p class="font-label-lg text-label-lg text-primary font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">auto_awesome</span>Analyse IA du CV</p>
        <p class="font-body-sm text-[11px] text-secondary">Purement informatif, à vérifier — ne remplace jamais les réponses du candidat à son propre formulaire de qualification.</p>
        <div id="contenuAnalyseIa" class="font-body-sm text-[13px] text-on-surface flex flex-col gap-space-xs"></div>
      </div>
    </div>
    <?php endif; ?>

  </main>
  </div>

  <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm max-w-max-width-content mx-auto w-full">
      <div class="flex items-center gap-1.5">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span>
      </div>
      <nav class="flex flex-wrap items-center gap-space-md">
        <a href="contact.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Contact</a>
      </nav>
    </div>
  </footer>
</div>
<?php if ($dossier): ?>
<script>
(function() {
  var csrfInput = document.querySelector('input[name="_csrf"]');
  document.querySelectorAll('.btn-analyser-cv').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var zone = document.getElementById('resultatAnalyseIa');
      var contenu = document.getElementById('contenuAnalyseIa');
      zone.classList.remove('hidden');
      zone.classList.add('flex');
      contenu.innerHTML = '<p class="text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[16px] animate-spin">progress_activity</span>Analyse en cours…</p>';
      zone.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

      var donnees = new FormData();
      donnees.append('_csrf', csrfInput ? csrfInput.value : '');
      donnees.append('document_id', btn.getAttribute('data-document-id'));
      fetch('analyser-cv-depot-ia.php', { method: 'POST', body: donnees })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) {
            contenu.innerHTML = '<p class="text-error">' + data.error.replace(/</g, '&lt;') + '</p>';
            return;
          }
          var html = '';
          if (data.poste_detecte) html += '<p><strong>Poste détecté :</strong> ' + data.poste_detecte.replace(/</g, '&lt;') + '</p>';
          if (data.experience_resumee) html += '<p><strong>Expérience :</strong> ' + data.experience_resumee.replace(/</g, '&lt;') + '</p>';
          if (data.competences && data.competences.length) html += '<p><strong>Compétences relevées :</strong> ' + data.competences.map(function(c){return c.replace(/</g,'&lt;');}).join(', ') + '</p>';
          if (data.resume) html += '<p><strong>Résumé :</strong> ' + data.resume.replace(/</g, '&lt;') + '</p>';
          if (data.informations_manquantes && data.informations_manquantes.length) {
            html += '<p class="text-orange-700"><strong>À vérifier :</strong> ' + data.informations_manquantes.map(function(c){return c.replace(/</g,'&lt;');}).join(' · ') + '</p>';
          }
          contenu.innerHTML = html || '<p class="text-secondary">Aucune information exploitable détectée.</p>';
        })
        .catch(function() {
          contenu.innerHTML = '<p class="text-error">Erreur réseau, merci de réessayer.</p>';
        });
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
