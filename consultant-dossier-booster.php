<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';
require_once __DIR__ . '/includes/documents.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');
$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom, u.email, u.telephone
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();

if (!$dossier) {
    $stmt = db()->prepare('SELECT id FROM dossiers WHERE pack_id = ? ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$packId]);
    $premierId = $stmt->fetchColumn();
    if ($premierId) {
        header('Location: consultant-dossier-booster.php?dossier=' . $premierId);
    } else {
        header('Location: consultant-dossiers-booster.php');
    }
    exit;
}

// Le premier consultant qui ouvre un dossier non assigné en devient responsable.
if ($dossier['consultant_id'] === null) {
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND consultant_id IS NULL');
    $stmt->execute([$consultant['id'], $dossierId]);
    $dossier['consultant_id'] = $consultant['id'];
}

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'changer_statut') {
        $nouveauStatut = $_POST['statut'] ?? '';
        if (in_array($nouveauStatut, ['en_cours', 'en_attente', 'cloture'], true)) {
            db()->prepare('UPDATE dossiers SET statut = ? WHERE id = ?')->execute([$nouveauStatut, $dossierId]);
            log_activite($dossierId, 'Statut du dossier changé : ' . $nouveauStatut);
            notify((int) $dossier['user_id'], 'statut_dossier', 'Votre dossier a été mis à jour', 'Nouveau statut : ' . $nouveauStatut, 'booster-tableau-bord.php');
        }
    } elseif ($action === 'ajouter_action') {
        $titre = trim($_POST['titre_action'] ?? '');
        if ($titre !== '') {
            $stmt = db()->prepare('INSERT INTO actions_plan (dossier_id, titre, proprietaire, echeance, statut) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$dossierId, $titre, trim($_POST['proprietaire_action'] ?? '') ?: 'Consultant', $_POST['echeance_action'] ?: null, 'a_faire']);
            log_activite($dossierId, 'Action ajoutée au plan : ' . $titre);
            notify((int) $dossier['user_id'], 'action_plan', 'Nouvelle action à réaliser', $titre, 'booster-mes-actions.php');
        }
    } elseif ($action === 'changer_statut_action') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $nouveauStatutAction = $_POST['nouveau_statut'] ?? '';
        if (in_array($nouveauStatutAction, ['a_faire', 'en_cours', 'termine', 'a_venir'], true)) {
            $stmt = db()->prepare('UPDATE actions_plan SET statut = ? WHERE id = ? AND dossier_id = ?');
            $stmt->execute([$nouveauStatutAction, $actionId, $dossierId]);
        }
    } elseif ($action === 'valider_action_plan') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $stmt = db()->prepare("SELECT titre FROM actions_plan WHERE id = ? AND dossier_id = ? AND statut = 'a_verifier'");
        $stmt->execute([$actionId, $dossierId]);
        $titreActionValidee = $stmt->fetchColumn();
        $stmt = db()->prepare("UPDATE actions_plan SET statut = 'termine', motif_correction = NULL, statut_maj_le = NOW(), statut_maj_par = 'consultant' WHERE id = ? AND dossier_id = ? AND statut = 'a_verifier'");
        $stmt->execute([$actionId, $dossierId]);
        if ($stmt->rowCount() > 0) {
            log_activite($dossierId, 'Action validée par le coach');
            notify((int) $dossier['user_id'], 'action_plan', 'Action validée par votre coach', $titreActionValidee ?: null, 'booster-mes-actions.php');
            $succes = 'Action validée.';
        }
    } elseif ($action === 'renvoyer_action_plan_correction') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $motif = trim($_POST['motif_correction'] ?? '');
        if ($motif === '') {
            $succes = '';
        } else {
            $stmt = db()->prepare("SELECT titre FROM actions_plan WHERE id = ? AND dossier_id = ? AND statut = 'a_verifier'");
            $stmt->execute([$actionId, $dossierId]);
            $titreActionCorrigee = $stmt->fetchColumn();
            $stmt = db()->prepare("UPDATE actions_plan SET statut = 'a_faire', motif_correction = ?, statut_maj_le = NOW(), statut_maj_par = 'consultant' WHERE id = ? AND dossier_id = ? AND statut = 'a_verifier'");
            $stmt->execute([$motif, $actionId, $dossierId]);
            if ($stmt->rowCount() > 0) {
                log_activite($dossierId, 'Action renvoyée en correction au client');
                notify((int) $dossier['user_id'], 'action_plan', 'Correction demandée sur une action', $titreActionCorrigee ?: null, 'booster-mes-actions.php');
                $succes = 'Action renvoyée au client avec votre commentaire.';
            }
        }
    } elseif ($action === 'ajouter_recommandation') {
        $categorie = $_POST['categorie_recommandation'] ?? '';
        $texte = trim($_POST['texte_recommandation'] ?? '');
        if ($texte !== '' && in_array($categorie, ['point_fort', 'axe_progression', 'recommandation'], true)) {
            $stmt = db()->prepare('INSERT INTO dossier_recommandations (dossier_id, categorie, texte) VALUES (?, ?, ?)');
            $stmt->execute([$dossierId, $categorie, $texte]);
            log_activite($dossierId, 'Recommandation ajoutée');
        }
    } elseif ($action === 'planifier_session') {
        $jour = $_POST['jour_session'] ?? '';
        $heure = $_POST['heure_session'] ?? '';
        if ($jour !== '' && $heure !== '') {
            $stmt = db()->prepare('INSERT INTO rendezvous (dossier_id, date_heure, format, statut) VALUES (?, ?, ?, ?)');
            $stmt->execute([$dossierId, $jour . ' ' . $heure . ':00', $_POST['format_session'] ?? 'ligne', 'confirme']);
            log_activite($dossierId, 'Prochaine session programmée');
            $succes = 'Session programmée.';
        }
    } elseif ($action === 'enregistrer_notes') {
        db()->prepare('UPDATE dossiers SET notes_consultant = ? WHERE id = ?')->execute([trim($_POST['notes_consultant'] ?? ''), $dossierId]);
        log_activite($dossierId, 'Dossier mis à jour par le consultant');
        $succes = 'Dossier mis à jour.';
    } elseif ($action === 'generer_livrables') {
        // Génère un rapport toujours à jour (reconstruit depuis les données
        // du dossier à chaque consultation, voir compte-rendu.php) : le
        // clic est lui-même l'action délibérée d'envoi, donc "disponible"
        // directement — pas de brouillon intermédiaire à confirmer.
        booster_generer_livrables($dossierId, (int) $dossier['user_id']);
        $succes = 'Livrables générés et transmis au client.';
    } elseif ($action === 'envoyer_plan') {
        $stmt = db()->prepare('SELECT titre, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC');
        $stmt->execute([$dossierId]);
        $lignes = array_map(fn ($a) => '- ' . $a['titre'] . ($a['echeance'] ? ' (échéance : ' . date('d/m/Y', strtotime($a['echeance'])) . ')' : ''), $stmt->fetchAll());
        $corps = "Voici votre plan d'action personnalisé :\n\n" . ($lignes ? implode("\n", $lignes) : 'Aucune action pour le moment.');
        $stmt = db()->prepare(
            'INSERT INTO messages (dossier_id, expediteur_id, expediteur_type, corps, lu_par_client, lu_par_consultant)
             VALUES (?, ?, ?, ?, 0, 1)'
        );
        $stmt->execute([$dossierId, $consultant['id'], 'consultant', $corps]);
        log_activite($dossierId, "Plan d'action envoyé au client");
        $succes = 'Plan envoyé au client.';
    } elseif ($action === 'valider_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if (nx_valider_document($documentId, $dossierId, (int) $consultant['id'], 'booster-mes-documents.php')) {
            $succes = 'Document validé.';
        }
    } elseif ($action === 'corriger_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $motif = trim($_POST['motif_correction'] ?? '');
        if ($motif !== '' && nx_corriger_document($documentId, $dossierId, $motif, 'booster-mes-documents.php')) {
            $succes = 'Correction demandée au client.';
        }
    }

    header('Location: consultant-dossier-booster.php?dossier=' . $dossierId);
    exit;
}

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = json_decode((string) $stmt->fetchColumn(), true) ?: [];

$stmt = db()->prepare('SELECT id, titre, proprietaire, echeance, statut, motif_correction FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC, id ASC');
$stmt->execute([$dossierId]);
$planAction = $stmt->fetchAll();
$actionStatutLabels = ['a_faire' => ['À faire', 'bg-surface-container-high text-secondary'], 'en_cours' => ['En cours', 'bg-blue-100 text-blue-700'], 'a_venir' => ['À venir', 'bg-surface-container-low text-secondary'], 'a_verifier' => ['En attente de validation', 'bg-orange-100 text-orange-700'], 'termine' => ['Terminé', 'bg-green-100 text-green-700']];

/**
 * Icône indicative selon le contenu de l'action (mots-clés) — purement
 * décoratif, pour repérer visuellement le type d'action dans le plan.
 */
function booster_icone_action(string $titre): string
{
    $t = mb_strtolower($titre, 'UTF-8');
    return match (true) {
        str_contains($t, 'projet') || str_contains($t, 'objectif') => 'flag',
        str_contains($t, 'bilan') || str_contains($t, 'compétence') => 'assessment',
        str_contains($t, 'reconversion') || str_contains($t, 'piste') => 'search',
        str_contains($t, 'formation') || str_contains($t, 'certification') => 'school',
        str_contains($t, 'pitch') || str_contains($t, 'entretien') => 'mic',
        str_contains($t, 'communication') || str_contains($t, 'assertiv') => 'forum',
        str_contains($t, 'poste') || str_contains($t, 'prise de poste') => 'work',
        default => 'task_alt',
    };
}

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY date_heure DESC LIMIT 1');
$stmt->execute([$dossierId]);
$rdv = $stmt->fetch();
$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir', 'hybride' => 'Hybride'];

$stmt = db()->prepare("SELECT titre, echeance FROM actions_plan WHERE dossier_id = ? AND statut != 'termine' ORDER BY echeance ASC LIMIT 4");
$stmt->execute([$dossierId]);
$prochainesEtapes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT texte, created_at FROM activites WHERE dossier_id = ? ORDER BY id DESC LIMIT 5');
$stmt->execute([$dossierId]);
$activitesRecentes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT titre, chemin, created_at FROM livrables WHERE dossier_id = ? ORDER BY created_at DESC');
$stmt->execute([$dossierId]);
$documentsGeneres = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, nom_original, categorie, chemin, taille_ko, uploaded_at, statut, motif_correction FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$dossierId]);
$documentsClient = $stmt->fetchAll();
$documentStatutsLabels = nx_documents_statuts();

$stmt = db()->prepare('SELECT categorie, texte FROM dossier_recommandations WHERE dossier_id = ? ORDER BY id DESC');
$stmt->execute([$dossierId]);
$recommandationsBrutes = $stmt->fetchAll();
$pointsForts = array_column(array_filter($recommandationsBrutes, fn ($r) => $r['categorie'] === 'point_fort'), 'texte');
$axesProgression = array_column(array_filter($recommandationsBrutes, fn ($r) => $r['categorie'] === 'axe_progression'), 'texte');
$recommandations = array_column(array_filter($recommandationsBrutes, fn ($r) => $r['categorie'] === 'recommandation'), 'texte');

function booster_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

$orientationCarriere = [
    ['icone' => 'apartment', 'titre' => 'Évolution interne', 'texte' => "Explorer les opportunités au sein de l'entreprise."],
    ['icone' => 'search', 'titre' => 'Reconversion', 'texte' => 'Identifier de nouveaux métiers et secteurs.'],
    ['icone' => 'school', 'titre' => 'Formation', 'texte' => 'Développer de nouvelles compétences clés.'],
    ['icone' => 'groups', 'titre' => 'Leadership', 'texte' => 'Renforcer les compétences managériales.'],
    ['icone' => 'mic', 'titre' => 'Coaching entretien', 'texte' => 'Se préparer aux entretiens et négociations.'],
];

// Sujets de préparation de session : génériques mais adaptés à l'objectif réel du client.
$sujetsParObjectif = [
    'Clarifier mon projet' => ['Faire le point sur les objectifs', 'Clarifier le projet cible', 'Planifier les prochaines actions'],
    'Faire un bilan de compétences' => ['Faire le point sur le bilan de compétences', 'Identifier les compétences clés', 'Planifier les prochaines actions'],
    'Préparer une reconversion' => ['Valider le projet cible et motivations', 'Définir les premières pistes de reconversion', 'Planifier les prochaines actions'],
];
$sujetsSession = $sujetsParObjectif[$qualification['objectif_principal'] ?? ''] ?? ['Faire le point sur la situation actuelle', 'Valider les objectifs', 'Planifier les prochaines actions'];

$statutDossierLabels = ['en_cours' => ['En cours', 'bg-blue-100 text-blue-700'], 'en_attente' => ['En attente', 'bg-orange-100 text-orange-700'], 'cloture' => ['Clôturé', 'bg-green-100 text-green-700']];
[$statutLabel, $statutClass] = $statutDossierLabels[$dossier['statut']] ?? ['—', 'bg-surface-container-high text-secondary'];
$pourcentage = min(100, (int) round(((int) $dossier['current_step']) / 8 * 100));

render_head('Espace NexRaiz — Suivi, plan d\'action et accompagnement', 'Piloter les prochaines étapes du parcours Booster.');
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'pack'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="consultant-dossiers-booster.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <a href="consultant-dossiers-booster.php" class="hover:text-primary transition-colors">Mes clients</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <a href="consultant-dossiers-booster.php" class="hover:text-primary transition-colors">Dossier client</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Pack Booster</span>
      </nav>

      <?php if ($succes): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
      </div>
      <?php endif; ?>

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Espace NexRaiz : Suivi, plan d'action et accompagnement</h1>
        <p class="font-body-sm text-body-sm text-secondary">Piloter les prochaines étapes du parcours Booster</p>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-wrap items-center gap-space-lg">
        <div class="flex items-center gap-space-xs">
          <div class="w-11 h-11 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-lg text-label-lg font-bold"><?php echo strtoupper(mb_substr($dossier['prenom'], 0, 1) . mb_substr($dossier['nom'], 0, 1)); ?></div>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p>
            <?php if (!empty($qualification['poste_actuel'])): ?>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($qualification['poste_actuel']); ?></p>
            <?php endif; ?>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($dossier['email']); ?><?php echo !empty($qualification['ville']) ? ' · ' . htmlspecialchars($qualification['ville']) : ''; ?></p>
          </div>
        </div>
        <div><p class="font-body-sm text-[11px] text-secondary">Entreprise</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($qualification['entreprise'] ?? 'Non renseignée'); ?></p></div>
        <div><p class="font-body-sm text-[11px] text-secondary">Date de démarrage</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y', strtotime($dossier['created_at']))); ?></p></div>
        <div><p class="font-body-sm text-[11px] text-secondary">Consultant</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></p></div>
        <div class="ml-auto flex flex-col items-end gap-space-2xs min-w-[9rem]">
          <form method="post" class="flex items-center gap-1">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="changer_statut">
            <span class="text-secondary font-label-md text-label-md">Statut du dossier</span>
            <select name="statut" onchange="this.form.submit()" class="w-auto shrink-0 whitespace-nowrap px-space-xs py-0.5 rounded-full <?php echo $statutClass; ?> font-bold text-[11px] border-0 focus:outline-none">
              <option value="en_cours" <?php echo $dossier['statut'] === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
              <option value="en_attente" <?php echo $dossier['statut'] === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
              <option value="cloture" <?php echo $dossier['statut'] === 'cloture' ? 'selected' : ''; ?>>Clôturé</option>
            </select>
          </form>
          <div class="w-full h-1.5 rounded-full bg-surface-container-low overflow-hidden"><div class="h-full rounded-full bg-primary-container" style="width:<?php echo $pourcentage; ?>%"></div></div>
          <span class="font-body-sm text-[11px] text-secondary">Étape <?php echo (int) $dossier['current_step']; ?> sur 8 · <?php echo $pourcentage; ?> %</span>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[1.7fr_1fr] gap-space-sm items-start">

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="plan">
          <div class="flex items-center justify-between">
            <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">explore</span>Plan d'action personnalisé</h2>
          </div>
          <?php if (!$planAction): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune action pour le moment.</p>
          <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse">
              <thead>
                <tr class="border-b border-outline-variant/60">
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Action</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Propriétaire</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Échéance</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($planAction as $a): [$label, $class] = $actionStatutLabels[$a['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
                <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                  <td class="py-space-sm pr-space-sm">
                    <span class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary"><?php echo booster_icone_action($a['titre']); ?></span><span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></span></span>
                  </td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary">
                    <?php if (!empty($a['proprietaire'])): $motsProprietaire = preg_split('/\s+/', trim($a['proprietaire'])); $initiales = strtoupper(mb_substr($motsProprietaire[0], 0, 1) . mb_substr(end($motsProprietaire), 0, 1)); ?>
                    <span class="inline-flex items-center gap-1"><span class="w-5 h-5 rounded-full bg-primary-container/15 text-primary flex items-center justify-center font-label-md text-[9px] font-bold shrink-0"><?php echo htmlspecialchars($initiales); ?></span><?php echo htmlspecialchars($a['proprietaire']); ?></span>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo $a['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : '—'; ?></td>
                  <td class="py-space-sm pr-space-sm">
                    <?php if ($a['statut'] === 'a_verifier'): ?>
                    <div class="flex flex-col gap-space-2xs">
                      <span class="self-start px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $class; ?>"><?php echo $label; ?></span>
                      <div class="flex items-center gap-1">
                        <form method="post" onsubmit="return confirm('Valider cette action comme terminée ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="valider_action_plan"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                          <button type="submit" class="h-7 px-space-xs rounded-lg bg-green-600 text-white font-label-md text-[10px] flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">check</span>Valider</button>
                        </form>
                        <button type="button" onclick="var f=document.getElementById('motifAction-<?php echo $a['id']; ?>');f.classList.toggle('hidden');f.classList.toggle('flex');" class="h-7 px-space-xs rounded-lg border border-outline-variant font-label-md text-[10px] text-on-surface">Corriger</button>
                      </div>
                      <form method="post" id="motifAction-<?php echo $a['id']; ?>" class="hidden flex-col gap-space-2xs pt-space-2xs">
                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="renvoyer_action_plan_correction"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                        <textarea name="motif_correction" rows="2" placeholder="Ce qui doit être corrigé..." class="w-44 p-space-2xs rounded-lg border border-outline-variant font-body-sm text-[11px]"></textarea>
                        <button type="submit" class="self-start h-7 px-space-xs rounded-lg bg-orange-600 text-white font-label-md text-[10px]">Envoyer</button>
                      </form>
                    </div>
                    <?php else: ?>
                    <form method="post">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="changer_statut_action">
                      <input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                      <select name="nouveau_statut" onchange="this.form.submit()" class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold border-0 <?php echo $class; ?>">
                        <?php foreach ($actionStatutLabels as $val => [$lbl, ]): if ($val === 'a_verifier') continue; ?>
                        <option value="<?php echo $val; ?>" <?php echo $a['statut'] === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                    <?php endif; ?>
                    <?php if ($a['motif_correction'] && $a['statut'] !== 'a_verifier'): ?>
                    <p class="font-body-sm text-[10px] text-orange-700 mt-0.5">Motif envoyé : « <?php echo htmlspecialchars(mb_strimwidth($a['motif_correction'], 0, 40, '…')); ?> »</p>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
          <form method="post" class="flex flex-col sm:flex-row gap-space-xs pt-space-xs border-t border-outline-variant/60">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="ajouter_action">
            <input type="text" name="titre_action" placeholder="Nouvelle action..." class="flex-1 h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
            <input type="text" name="proprietaire_action" placeholder="Propriétaire" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary w-32">
            <input type="date" name="echeance_action" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
            <button type="submit" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center justify-center gap-1 shrink-0"><span class="material-symbols-outlined text-[14px]">add</span>Ajouter</button>
          </form>
        </div>

        <div class="flex flex-col gap-space-sm">
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">diversity_3</span>Orientation carrière</h2>
            <?php foreach ($orientationCarriere as $o): ?>
            <div class="flex items-center gap-space-xs py-space-xs -mx-space-xs px-space-xs">
              <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $o['icone']; ?></span></div>
              <div class="flex-1"><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($o['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($o['texte']); ?></p></div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="session">
            <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">event_note</span>Préparation des sessions</h2>
            <div class="flex items-center justify-between"><span class="font-label-md text-label-md text-on-surface font-semibold">Prochaine session</span><span class="font-body-sm text-body-sm text-secondary"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) : 'Non planifiée'; ?></span></div>
            <p class="font-label-md text-label-md text-on-surface font-bold pt-space-xs">Sujets à aborder</p>
            <?php foreach ($sujetsSession as $s): ?>
            <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-green-600 mt-0.5">check</span><?php echo htmlspecialchars($s); ?></span>
            <?php endforeach; ?>
            <button type="button" class="edit-toggle h-10 rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors mt-space-xs" data-target="edit-session">Programmer une session</button>
            <form method="post" id="edit-session" class="hidden flex-col gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="planifier_session">
              <div class="grid grid-cols-2 gap-space-xs">
                <input type="date" name="jour_session" required class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <input type="time" name="heure_session" required class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              </div>
              <select name="format_session" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <option value="ligne">En ligne</option>
                <option value="presentiel">En présentiel</option>
                <option value="hybride">Hybride</option>
              </select>
              <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>Programmer</button>
            </form>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">event_upcoming</span>Prochaines étapes</h2>
          <?php if (!$prochainesEtapes): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune échéance à venir.</p>
          <?php endif; ?>
          <?php foreach ($prochainesEtapes as $e): ?>
          <div class="flex items-center justify-between">
            <span class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-green-600">check</span><?php echo htmlspecialchars($e['titre']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo $e['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($e['echeance']))) : '—'; ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">monitor_heart</span>Activité récente</h2>
          <?php if (!$activitesRecentes): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($activitesRecentes as $act): ?>
          <div class="flex items-center justify-between">
            <span class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-primary">radio_button_checked</span><?php echo htmlspecialchars($act['texte']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo htmlspecialchars(booster_il_y_a($act['created_at'])); ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="documents">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">folder_shared</span>Documents transmis par le client</h2>
          <?php if (!$documentsClient): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun document transmis pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($documentsClient as $d): [$icone, $couleur] = nx_icone_fichier($d['nom_original']); [$labelStatutDoc, $classeStatutDoc] = $documentStatutsLabels[$d['statut']]; $zoneCorrId = 'corr-doc-' . $d['id']; ?>
          <div class="flex flex-col gap-space-2xs border-b border-outline-variant/30 pb-space-xs last:border-0 last:pb-0">
            <a href="document-telecharger.php?id=<?php echo $d['id']; ?>" target="_blank" rel="noopener" class="flex items-center gap-space-xs hover:bg-surface-container-low rounded-lg -mx-space-2xs px-space-2xs py-space-2xs transition-colors">
              <span class="material-symbols-outlined text-[16px] <?php echo $couleur; ?> shrink-0"><?php echo $icone; ?></span>
              <div class="flex-1 min-w-0">
                <p class="font-body-sm text-[12px] text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary"><?php echo $d['categorie'] ? htmlspecialchars($d['categorie']) . ' · ' : ''; ?><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['uploaded_at']))); ?></p>
              </div>
              <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeStatutDoc; ?>"><?php echo htmlspecialchars($labelStatutDoc); ?></span>
            </a>
            <?php if ($d['statut'] === 'a_corriger' && $d['motif_correction']): ?>
            <p class="font-body-sm text-[11px] text-error pl-space-lg">Motif envoyé : « <?php echo htmlspecialchars($d['motif_correction']); ?> »</p>
            <?php endif; ?>
            <?php if ($d['statut'] !== 'a_corriger'): ?>
            <div class="flex items-center gap-space-xs pl-space-lg">
              <?php if ($d['statut'] === 'en_verification'): ?>
              <form method="post" onsubmit="return confirm('Valider ce document ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="valider_document"><input type="hidden" name="document_id" value="<?php echo $d['id']; ?>">
                <button type="submit" class="h-7 px-space-xs rounded-md border border-outline-variant font-label-md text-[11px] text-green-700 flex items-center gap-0.5"><span class="material-symbols-outlined text-[13px]">check</span>Valider</button>
              </form>
              <?php endif; ?>
              <button type="button" onclick="document.getElementById('<?php echo $zoneCorrId; ?>').classList.toggle('hidden');" class="h-7 px-space-xs rounded-md border border-outline-variant font-label-md text-[11px] text-error">Demander une correction</button>
            </div>
            <div id="<?php echo $zoneCorrId; ?>" class="hidden flex-col gap-space-2xs pl-space-lg">
              <form method="post" class="flex items-center gap-space-2xs">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="corriger_document">
                <input type="hidden" name="document_id" value="<?php echo $d['id']; ?>">
                <input required type="text" name="motif_correction" placeholder="Motif de la correction..." class="flex-1 h-8 px-space-xs rounded-md border border-outline-variant font-body-sm text-[11px]">
                <button type="submit" class="h-8 px-space-xs rounded-md bg-error text-white font-label-md text-[11px]">Envoyer</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="livrables">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">description</span>Documents générés</h2>
          <?php if (!$documentsGeneres): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun document généré pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($documentsGeneres as $d): ?>
          <div class="flex items-center justify-between">
            <span class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($d['titre']); ?></span>
            <a href="<?php echo htmlspecialchars($d['chemin']); ?>" target="_blank" rel="noopener" class="text-primary shrink-0"><span class="material-symbols-outlined text-[18px]">open_in_new</span></a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20" id="recommandations">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">tips_and_updates</span>Recommandations consultant</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-space-md">
          <div class="flex flex-col gap-space-2xs">
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Points forts à valoriser</p>
            <?php if (!$pointsForts): ?><p class="font-body-sm text-[12px] text-secondary">Aucun point fort renseigné.</p><?php endif; ?>
            <?php foreach ($pointsForts as $p): ?>
            <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-green-600 mt-0.5">check_circle</span><?php echo htmlspecialchars($p); ?></span>
            <?php endforeach; ?>
            <form method="post" class="flex gap-1 pt-space-2xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_recommandation">
              <input type="hidden" name="categorie_recommandation" value="point_fort">
              <input type="text" name="texte_recommandation" placeholder="Ajouter..." class="flex-1 h-8 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[11px] focus:outline-none focus:border-primary">
              <button type="submit" class="w-8 h-8 rounded-lg bg-primary-container text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[14px]">add</span></button>
            </form>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Axes de progression</p>
            <?php if (!$axesProgression): ?><p class="font-body-sm text-[12px] text-secondary">Aucun axe renseigné.</p><?php endif; ?>
            <?php foreach ($axesProgression as $a): ?>
            <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-orange-500 mt-0.5">warning</span><?php echo htmlspecialchars($a); ?></span>
            <?php endforeach; ?>
            <form method="post" class="flex gap-1 pt-space-2xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_recommandation">
              <input type="hidden" name="categorie_recommandation" value="axe_progression">
              <input type="text" name="texte_recommandation" placeholder="Ajouter..." class="flex-1 h-8 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[11px] focus:outline-none focus:border-primary">
              <button type="submit" class="w-8 h-8 rounded-lg bg-primary-container text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[14px]">add</span></button>
            </form>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Recommandations</p>
            <?php if (!$recommandations): ?><p class="font-body-sm text-[12px] text-secondary">Aucune recommandation renseignée.</p><?php endif; ?>
            <?php foreach ($recommandations as $r): ?>
            <span class="flex items-start gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-primary mt-0.5">radio_button_checked</span><?php echo htmlspecialchars($r); ?></span>
            <?php endforeach; ?>
            <form method="post" class="flex gap-1 pt-space-2xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_recommandation">
              <input type="hidden" name="categorie_recommandation" value="recommandation">
              <input type="text" name="texte_recommandation" placeholder="Ajouter..." class="flex-1 h-8 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[11px] focus:outline-none focus:border-primary">
              <button type="submit" class="w-8 h-8 rounded-lg bg-primary-container text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[14px]">add</span></button>
            </form>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs scroll-mt-20" id="notes">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">sticky_note_2</span>Notes internes</h2>
        <p class="font-body-sm text-[11px] text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">lock</span>Visibles uniquement par l'équipe NexRaiz — jamais affichées au client.</p>
        <form method="post" id="notesForm" class="flex flex-col gap-space-xs">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="enregistrer_notes">
          <textarea name="notes_consultant" rows="4" placeholder="Observations, points de vigilance, contexte à partager avec l'équipe..." class="w-full p-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px] resize-none"><?php echo htmlspecialchars($dossier['notes_consultant'] ?? ''); ?></textarea>
        </form>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="envoyer_plan">
          <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[18px]">send</span>Envoyer le plan au client</button>
        </form>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generer_livrables">
          <button type="submit" class="w-full h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">description</span>Générer les livrables</button>
        </form>
        <a href="#session" class="h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">event</span>Programmer la prochaine session</a>
        <button type="submit" form="notesForm" class="h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[18px]">edit</span>Mettre à jour le dossier</button>
      </div>

    </main>

    <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
      <div class="flex flex-col gap-space-md">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-space-md">
          <div class="col-span-2 md:col-span-1 flex flex-col gap-space-xs">
            <span class="flex items-center gap-1.5"><span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span></span>
            <p class="font-body-sm text-[11px] text-inverse-on-surface/70">NexRaiz accompagne les professionnels à chaque étape de leur évolution.</p>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <h4 class="font-label-md text-[11px] uppercase tracking-wider text-inverse-primary font-bold">Navigation</h4>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="index.php">Accueil</a>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="consultant-dossiers-booster.php">Mes clients</a>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <h4 class="font-label-md text-[11px] uppercase tracking-wider text-inverse-primary font-bold">Support</h4>
            <a class="font-body-sm text-[12px] text-inverse-on-surface/70 hover:text-white transition-colors" href="contact.php">Contact</a>
          </div>
        </div>
        <div class="pt-space-sm border-t border-white/10 font-body-sm text-[11px] text-inverse-on-surface/60 text-center">© <?php echo date('Y'); ?> NexRaiz – Tous droits réservés</div>
      </div>
    </footer>
  </div>
</div>
<script>
(function() {
  document.querySelectorAll('.edit-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var el = document.getElementById(btn.getAttribute('data-target'));
      if (el) { el.classList.toggle('hidden'); el.classList.toggle('flex'); }
    });
  });
})();
</script>
</body>
</html>
