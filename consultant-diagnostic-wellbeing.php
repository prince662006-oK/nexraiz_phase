<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-wellbeing.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('wellbeing');
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
        header('Location: consultant-diagnostic-wellbeing.php?dossier=' . $premierId);
    } else {
        header('Location: consultant-dossiers-wellbeing.php');
    }
    exit;
}

// Le premier consultant qui ouvre un dossier non assigné en devient responsable.
if ($dossier['consultant_id'] === null) {
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND consultant_id IS NULL');
    $stmt->execute([$consultant['id'], $dossierId]);
    $dossier['consultant_id'] = $consultant['id'];
}

$typesEvaluation = wellbeing_types_evaluation();

$succes = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'lancer_evaluation') {
        $type = $_POST['type_evaluation'] ?? '';
        if (isset($typesEvaluation[$type])) {
            $labels = $typesEvaluation[$type]['dimensions'];
            $valeurs = array_map(fn ($v) => max(0, min(100, (int) $v)), $_POST['dimensions'] ?? []);
            $dimensions = [];
            foreach ($labels as $i => $label) {
                $dimensions[] = ['label' => $label, 'valeur' => $valeurs[$i] ?? 0];
            }
            $score = $dimensions ? (int) round(array_sum(array_column($dimensions, 'valeur')) / count($dimensions)) : 0;

            $stmt = db()->prepare(
                'INSERT INTO wellbeing_evaluations (dossier_id, type, score, dimensions_json, created_by) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), dimensions_json = VALUES(dimensions_json), created_by = VALUES(created_by)'
            );
            $stmt->execute([$dossierId, $type, $score, json_encode($dimensions, JSON_UNESCAPED_UNICODE), $consultant['id']]);
            log_activite($dossierId, 'Évaluation "' . $typesEvaluation[$type]['titre'] . '" enregistrée (score ' . $score . '/100)');
            $succes = 'Évaluation enregistrée.';
        }
    } elseif ($action === 'enregistrer_notes') {
        db()->prepare('UPDATE dossiers SET notes_consultant = ? WHERE id = ?')->execute([trim($_POST['notes_consultant'] ?? ''), $dossierId]);
        log_activite($dossierId, 'Observations consultant mises à jour');
        $succes = 'Analyse enregistrée.';
    } elseif ($action === 'ajouter_vigilance') {
        $texte = trim($_POST['texte_vigilance'] ?? '');
        $gravite = ($_POST['gravite_vigilance'] ?? '') === 'eleve' ? 'eleve' : 'moyen';
        $couleur = $gravite === 'eleve' ? 'bg-red-500' : 'bg-orange-500';
        if ($texte !== '') {
            $stmt = db()->prepare('INSERT INTO dossier_points_vigilance (dossier_id, titre, texte, icone, couleur) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$dossierId, mb_substr($texte, 0, 150), $texte, 'warning', $couleur]);
            log_activite($dossierId, 'Point de vigilance ajouté');
        }
    } elseif ($action === 'supprimer_vigilance') {
        db()->prepare('DELETE FROM dossier_points_vigilance WHERE id = ? AND dossier_id = ?')->execute([(int) ($_POST['vigilance_id'] ?? 0), $dossierId]);
    } elseif ($action === 'toggle_orientation') {
        $type = $_POST['type_orientation'] ?? '';
        if (in_array($type, ['medecin_travail', 'psychologue'], true)) {
            $recommande = !empty($_POST['recommande']) ? 1 : 0;
            $stmt = db()->prepare(
                'INSERT INTO dossier_orientations (dossier_id, type, recommande) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE recommande = VALUES(recommande)'
            );
            $stmt->execute([$dossierId, $type, $recommande]);
        }
    } elseif ($action === 'ajouter_action') {
        $titre = trim($_POST['titre_action'] ?? '');
        if ($titre !== '') {
            $stmt = db()->prepare('INSERT INTO actions_plan (dossier_id, titre, proprietaire, echeance, statut) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$dossierId, $titre, trim($_POST['proprietaire_action'] ?? '') ?: 'Client', $_POST['echeance_action'] ?: null, 'a_faire']);
            log_activite($dossierId, 'Action ajoutée au plan Wellbeing : ' . $titre);
        }
    } elseif ($action === 'changer_statut_action') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $nouveauStatutAction = $_POST['nouveau_statut'] ?? '';
        if (in_array($nouveauStatutAction, ['a_faire', 'en_cours', 'termine', 'a_venir'], true)) {
            db()->prepare('UPDATE actions_plan SET statut = ? WHERE id = ? AND dossier_id = ?')->execute([$nouveauStatutAction, $actionId, $dossierId]);
        }
    } elseif ($action === 'generer_plan') {
        $stmt = db()->prepare('SELECT type, dimensions_json FROM wellbeing_evaluations WHERE dossier_id = ?');
        $stmt->execute([$dossierId]);
        $stmtExiste = db()->prepare('SELECT COUNT(*) FROM actions_plan WHERE dossier_id = ? AND titre = ?');
        $stmtInsert = db()->prepare('INSERT INTO actions_plan (dossier_id, titre, proprietaire, statut) VALUES (?, ?, ?, ?)');
        $nbAjoutees = 0;
        foreach ($stmt->fetchAll() as $ev) {
            $dimensions = json_decode((string) $ev['dimensions_json'], true) ?: [];
            foreach ($dimensions as $d) {
                if ((int) $d['valeur'] < 55) {
                    $titreAction = 'Travailler sur : ' . $d['label'] . ' (score ' . $d['valeur'] . '/100)';
                    $stmtExiste->execute([$dossierId, $titreAction]);
                    if ((int) $stmtExiste->fetchColumn() === 0) {
                        $stmtInsert->execute([$dossierId, $titreAction, 'Client', 'a_faire']);
                        $nbAjoutees++;
                    }
                }
            }
        }
        log_activite($dossierId, "Plan d'action Wellbeing généré depuis le diagnostic");
        $succes = $nbAjoutees > 0 ? $nbAjoutees . ' action(s) ajoutée(s) au plan à partir du diagnostic.' : "Aucune nouvelle action à ajouter (aucun point sous le seuil de vigilance, ou plan déjà à jour).";
    }

    if ($action !== 'generer_plan' && $action !== 'enregistrer_notes' && !$succes) {
        header('Location: consultant-diagnostic-wellbeing.php?dossier=' . $dossierId);
        exit;
    }

    if ($action === 'enregistrer_notes') {
        // Pas de redirection pour cette action (le message de succès doit rester affiché) :
        // on recharge le dossier pour refléter la mise à jour qui vient d'être enregistrée.
        $stmt = db()->prepare('SELECT * FROM dossiers WHERE id = ?');
        $stmt->execute([$dossierId]);
        $dossier = array_merge($dossier, $stmt->fetch());
    }
}

$stmt = db()->prepare('SELECT type, score, dimensions_json, updated_at FROM wellbeing_evaluations WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$evaluationsBrutes = [];
foreach ($stmt->fetchAll() as $e) {
    $evaluationsBrutes[$e['type']] = $e;
}

$evaluations = [];
foreach ($typesEvaluation as $type => $meta) {
    $brut = $evaluationsBrutes[$type] ?? null;
    $dimensions = $brut ? (json_decode((string) $brut['dimensions_json'], true) ?: []) : array_map(fn ($l) => ['label' => $l, 'valeur' => null], $meta['dimensions']);
    $score = $brut ? (int) $brut['score'] : null;
    $badge = $score !== null ? wellbeing_badge_score($score) : ['label' => 'Non évalué', 'classe' => 'bg-surface-container-high text-secondary', 'couleur_texte' => 'text-secondary', 'couleur_point' => 'bg-outline-variant'];
    $evaluations[$type] = ['titre' => $meta['titre'], 'icone' => $meta['icone'], 'dimensions' => $dimensions, 'score' => $score, 'badge' => $badge, 'maj' => $brut['updated_at'] ?? null];
}

$axesLabels = array_map(fn ($ev) => $ev['titre'], $evaluations);
$axesClient = array_map(fn ($ev) => $ev['score'] ?? 0, $evaluations);
$aAuMoinsUneEvaluation = (bool) array_filter($axesClient);

function radar_points_wb(array $valeurs, float $cx, float $cy, float $rayon): string
{
    $n = count($valeurs);
    $pts = [];
    foreach (array_values($valeurs) as $i => $v) {
        $angle = deg2rad(-90 + $i * (360 / $n));
        $r = ($v / 100) * $rayon;
        $pts[] = round($cx + $r * cos($angle), 1) . ',' . round($cy + $r * sin($angle), 1);
    }
    return implode(' ', $pts);
}
function radar_label_point_wb(int $i, int $n, float $cx, float $cy, float $rayon): array
{
    $angle = deg2rad(-90 + $i * (360 / $n));
    return [round($cx + $rayon * cos($angle), 1), round($cy + $rayon * sin($angle), 1)];
}
$cx = 100; $cy = 100; $rayon = 78;

$stmt = db()->prepare('SELECT id, titre, texte, couleur FROM dossier_points_vigilance WHERE dossier_id = ? ORDER BY id DESC');
$stmt->execute([$dossierId]);
$pointsVigilance = $stmt->fetchAll();

$stmt = db()->prepare('SELECT type, recommande FROM dossier_orientations WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$orientations = ['medecin_travail' => false, 'psychologue' => false];
foreach ($stmt->fetchAll() as $o) {
    $orientations[$o['type']] = (bool) $o['recommande'];
}
$orientationLabels = ['medecin_travail' => 'Médecin du travail', 'psychologue' => 'Psychologue / coach bien-être'];

$stmt = db()->prepare('SELECT id, titre, proprietaire, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY (statut = "termine"), echeance ASC, id ASC');
$stmt->execute([$dossierId]);
$planAction = $stmt->fetchAll();
$actionStatutLabels = ['a_faire' => ['À faire', 'bg-surface-container-high text-secondary'], 'en_cours' => ['En cours', 'bg-blue-100 text-blue-700'], 'termine' => ['Terminé', 'bg-green-100 text-green-700'], 'a_venir' => ['À venir', 'bg-surface-container-low text-secondary']];

$statutDossier = wellbeing_statut_dossier(array_merge($dossier, ['a_qualification' => true, 'nb_evaluations' => count($evaluationsBrutes)]));

render_head('Espace NexRaiz — Diagnostic et évaluations Wellbeing', 'Piloter les évaluations et le diagnostic initial du client.');
?>
<div class="flex w-full min-h-screen">

  <?php render_wellbeing_sidebar($packId, 'diagnostic'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_wellbeing_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Espace NexRaiz : Diagnostic et évaluations Wellbeing</h1>
          <p class="font-body-sm text-body-sm text-secondary">Piloter les évaluations et le diagnostic initial du client.</p>
        </div>
        <a href="consultant-dossiers-wellbeing.php" class="inline-flex self-start px-space-sm py-1.5 rounded-full bg-surface-container-low font-label-md text-label-md text-secondary font-bold shrink-0 hover:bg-surface-container-high transition-colors">Voir mes clients</a>
      </div>

      <?php if ($succes): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
      </div>
      <?php endif; ?>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-wrap items-center gap-space-lg">
        <div class="flex items-center gap-space-xs">
          <div class="w-11 h-11 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-lg text-label-lg font-bold"><?php echo htmlspecialchars(mb_substr($dossier['prenom'], 0, 1) . mb_substr($dossier['nom'], 0, 1)); ?></div>
          <div><p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($dossier['numero']); ?></p></div>
        </div>
        <div class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-secondary">person</span><div><p class="font-body-sm text-[11px] text-secondary">Consultant</p><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></p></div></div>
        <div class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-secondary">calendar_today</span><div><p class="font-body-sm text-[11px] text-secondary">Date de démarrage</p><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y', strtotime($dossier['created_at']))); ?></p></div></div>
        <div class="ml-auto flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-secondary">radio_button_checked</span><div><p class="font-body-sm text-[11px] text-secondary">Statut du dossier</p><span class="inline-flex px-space-xs py-0.5 rounded-full <?php echo $statutDossier['classe']; ?> font-label-md text-[11px] font-bold"><?php echo htmlspecialchars($statutDossier['label']); ?></span></div></div>
      </div>

      <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">assignment</span>Lancer / mettre à jour une évaluation</summary>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-space-sm pt-space-sm">
          <?php foreach ($typesEvaluation as $type => $meta): ?>
          <form method="post" class="flex flex-col gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="lancer_evaluation">
            <input type="hidden" name="type_evaluation" value="<?php echo $type; ?>">
            <p class="flex items-center gap-1 font-label-md text-label-md text-on-surface font-bold"><span class="material-symbols-outlined text-[16px] text-primary"><?php echo $meta['icone']; ?></span><?php echo htmlspecialchars($meta['titre']); ?></p>
            <?php foreach ($meta['dimensions'] as $i => $label): $valeurActuelle = $evaluations[$type]['dimensions'][$i]['valeur'] ?? ''; ?>
            <label class="flex items-center justify-between gap-space-xs font-body-sm text-[12px] text-on-surface">
              <span class="flex-1"><?php echo htmlspecialchars($label); ?></span>
              <input type="number" min="0" max="100" name="dimensions[]" value="<?php echo htmlspecialchars((string) $valeurActuelle); ?>" required class="w-16 h-8 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] text-center focus:outline-none focus:border-primary">
            </label>
            <?php endforeach; ?>
            <button type="submit" class="h-9 rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center justify-center gap-1 mt-space-2xs">Enregistrer</button>
          </form>
          <?php endforeach; ?>
        </div>
      </details>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
        <?php foreach ($evaluations as $ev): ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <div class="flex items-start justify-between gap-space-xs">
            <span class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]"><?php echo $ev['icone']; ?></span></span><?php echo htmlspecialchars($ev['titre']); ?></span>
            <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $ev['badge']['classe']; ?>"><?php echo htmlspecialchars($ev['badge']['label']); ?></span>
          </div>
          <div class="flex items-center justify-between">
            <div><p class="font-body-sm text-[11px] text-secondary">Score global</p><p class="font-headline-lg text-headline-lg <?php echo $ev['badge']['couleur_texte']; ?> font-bold"><?php echo $ev['score'] ?? '—'; ?><?php if ($ev['score'] !== null): ?><span class="font-body-sm text-[12px] text-secondary font-normal">/100</span><?php endif; ?></p></div>
            <div class="text-right"><p class="font-body-sm text-[11px] text-secondary">Niveau</p><div class="flex gap-0.5">
              <?php $etoiles = $ev['score'] !== null ? (int) round($ev['score'] / 20) : 0; ?>
              <?php for ($i = 1; $i <= 5; $i++): ?><span class="material-symbols-outlined text-[16px] <?php echo $i <= $etoiles ? ($ev['score'] < 55 ? 'text-error' : 'text-primary') : 'text-outline-variant'; ?>">star</span><?php endfor; ?>
            </div></div>
          </div>
          <div class="flex flex-col gap-space-2xs">
            <p class="font-label-md text-label-md text-on-surface font-bold">Dimensions évaluées</p>
            <?php foreach ($ev['dimensions'] as $d): $couleurPoint = $d['valeur'] === null ? 'bg-outline-variant' : ($d['valeur'] >= 65 ? 'bg-green-500' : ($d['valeur'] >= 55 ? 'bg-orange-500' : 'bg-red-500')); ?>
            <div class="flex items-center justify-between font-body-sm text-[12px]"><span class="text-on-surface"><?php echo htmlspecialchars($d['label']); ?></span><span class="flex items-center gap-1 text-secondary"><?php echo $d['valeur'] ?? '—'; ?><span class="w-1.5 h-1.5 rounded-full <?php echo $couleurPoint; ?>"></span></span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col items-center gap-space-sm">
          <h2 class="self-start font-headline-sm text-headline-sm text-on-surface font-bold">Synthèse des scores</h2>
          <?php if ($aAuMoinsUneEvaluation): ?>
          <svg viewBox="0 0 200 220" class="w-full max-w-[260px]">
            <?php foreach ([25, 50, 75, 100] as $niveau): ?>
            <polygon points="<?php echo radar_points_wb(array_fill(0, count($axesClient), $niveau), $cx, $cy, $rayon); ?>" fill="none" stroke="#c3c6d7" stroke-width="1"></polygon>
            <?php endforeach; ?>
            <?php foreach (array_values($axesLabels) as $i => $label):
              [$lx, $ly] = radar_label_point_wb($i, count($axesLabels), $cx, $cy, $rayon + 26);
              $anchor = $lx < $cx - 5 ? 'end' : ($lx > $cx + 5 ? 'start' : 'middle');
            ?>
            <line x1="<?php echo $cx; ?>" y1="<?php echo $cy; ?>" x2="<?php echo radar_label_point_wb($i, count($axesLabels), $cx, $cy, $rayon)[0]; ?>" y2="<?php echo radar_label_point_wb($i, count($axesLabels), $cx, $cy, $rayon)[1]; ?>" stroke="#c3c6d7" stroke-width="1"></line>
            <text x="<?php echo $lx; ?>" y="<?php echo $ly; ?>" text-anchor="<?php echo $anchor; ?>" font-size="9" fill="#525f75"><?php echo htmlspecialchars($label); ?></text>
            <?php endforeach; ?>
            <polygon points="<?php echo radar_points_wb($axesClient, $cx, $cy, $rayon); ?>" fill="#14304f22" stroke="#14304f" stroke-width="2"></polygon>
          </svg>
          <span class="flex items-center gap-1 font-body-sm text-[11px] text-secondary"><span class="w-4 h-0.5 bg-[#14304f]"></span>Client</span>
          <?php else: ?>
          <p class="font-body-sm text-body-sm text-secondary py-space-lg text-center">Aucune évaluation réalisée pour l'instant.</p>
          <?php endif; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <div class="flex items-center justify-between"><h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Observations consultant</h2></div>
          <form method="post" class="flex flex-col gap-space-xs">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="enregistrer_notes">
            <textarea name="notes_consultant" rows="7" placeholder="Vos observations sur ce client..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none leading-relaxed"><?php echo htmlspecialchars($dossier['notes_consultant'] ?? ''); ?></textarea>
            <button type="submit" class="self-start h-9 px-space-md rounded-lg border border-outline-variant font-label-md text-[12px] font-bold text-on-surface hover:bg-surface-container-low transition-colors">Enregistrer</button>
          </form>
          <p class="flex items-center gap-1 font-body-sm text-[11px] text-secondary pt-space-xs border-t border-outline-variant/40"><span class="material-symbols-outlined text-[14px]">schedule</span>Dernière mise à jour : <?php echo htmlspecialchars(date('d/m/Y', strtotime($dossier['updated_at']))); ?></p>
        </div>

        <div class="flex flex-col gap-space-sm">
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">shield</span>Points de vigilance</h2>
            <?php if (!$pointsVigilance): ?><p class="font-body-sm text-[12px] text-secondary">Aucun point de vigilance identifié.</p><?php endif; ?>
            <?php foreach ($pointsVigilance as $p): ?>
            <div class="flex items-center gap-space-xs group">
              <span class="w-2 h-2 rounded-full <?php echo htmlspecialchars($p['couleur']); ?> shrink-0"></span>
              <span class="flex-1 font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($p['texte']); ?></span>
              <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="supprimer_vigilance"><input type="hidden" name="vigilance_id" value="<?php echo $p['id']; ?>"><button type="submit" class="text-outline-variant hover:text-error transition-colors"><span class="material-symbols-outlined text-[14px]">close</span></button></form>
            </div>
            <?php endforeach; ?>
            <form method="post" class="flex gap-1 pt-space-xs border-t border-outline-variant/40">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_vigilance">
              <select name="gravite_vigilance" class="h-8 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[11px] focus:outline-none focus:border-primary">
                <option value="eleve">Élevé</option>
                <option value="moyen" selected>Moyen</option>
              </select>
              <input type="text" name="texte_vigilance" placeholder="Ajouter un point..." class="flex-1 h-8 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[11px] focus:outline-none focus:border-primary">
              <button type="submit" class="w-8 h-8 rounded-lg bg-primary-container text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[14px]">add</span></button>
            </form>
          </div>
          <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
            <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">favorite</span>Orientation externe si nécessaire</h2>
            <p class="font-body-sm text-body-sm text-secondary">Selon les résultats, une orientation vers un professionnel externe peut être recommandée.</p>
            <?php foreach ($orientationLabels as $type => $label): ?>
            <form method="post" class="flex items-center justify-between gap-space-xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="toggle_orientation">
              <input type="hidden" name="type_orientation" value="<?php echo $type; ?>">
              <input type="hidden" name="recommande" value="<?php echo $orientations[$type] ? '0' : '1'; ?>">
              <span class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($label); ?></span>
              <button type="submit" class="px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $orientations[$type] ? 'bg-primary-container/10 text-primary' : 'bg-surface-container-high text-secondary'; ?>"><?php echo $orientations[$type] ? 'Recommandé' : 'Non recommandé'; ?></button>
            </form>
            <?php endforeach; ?>
            <a href="ressources.php?pack=wellbeing" class="h-10 rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface flex items-center justify-center gap-1 hover:bg-surface-container-low transition-colors">Voir les partenaires <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">eco</span>Plan d'action Wellbeing</h2>
        <?php if (!$planAction): ?><p class="font-body-sm text-[12px] text-secondary">Aucune action pour le moment. Utilisez "Créer le plan d'action Wellbeing" ci-dessous pour générer des actions à partir du diagnostic.</p><?php endif; ?>
        <?php foreach ($planAction as $a): ?>
        <div class="flex items-center gap-space-sm py-space-2xs border-b border-outline-variant/30 last:border-0">
          <span class="flex-1 font-body-sm text-body-sm text-on-surface <?php echo $a['statut'] === 'termine' ? 'line-through text-secondary' : ''; ?>"><?php echo htmlspecialchars($a['titre']); ?></span>
          <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($a['proprietaire'] ?? ''); ?></span>
          <form method="post" class="flex items-center gap-1">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="changer_statut_action">
            <input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
            <select name="nouveau_statut" onchange="this.form.submit()" class="h-8 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[11px] focus:outline-none focus:border-primary">
              <?php foreach ($actionStatutLabels as $val => [$label, $class]): ?>
              <option value="<?php echo $val; ?>" <?php echo $a['statut'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <?php endforeach; ?>
        <form method="post" class="flex flex-wrap gap-1 pt-space-xs">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="ajouter_action">
          <input type="text" name="titre_action" placeholder="Nouvelle action..." class="flex-1 h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <input type="date" name="echeance_action" class="h-9 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <button type="submit" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center justify-center gap-1"><span class="material-symbols-outlined text-[14px]">add</span>Ajouter</button>
        </form>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generer_plan">
          <button type="submit" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[18px]">eco</span>Créer le plan d'action Wellbeing</button>
        </form>
        <a href="#" onclick="document.querySelector('details').open = true; document.querySelector('details').scrollIntoView({behavior:'smooth'}); return false;" class="h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">assignment</span>Lancer une évaluation</a>
        <a href="compte-rendu.php?dossier=<?php echo $dossierId; ?>" class="h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">description</span>Voir le compte rendu client</a>
      </div>

    </main>

    <footer class="w-full bg-surface-container-lowest border-t border-outline-variant/50 px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-xs font-body-sm text-[12px] text-secondary max-w-max-width-content mx-auto w-full">
        <span>© <?php echo date('Y'); ?> NexRaiz – Tous droits réservés</span>
        <span class="flex items-center gap-1">Fait avec 💚 pour votre bien-être au travail</span>
      </div>
    </footer>
  </div>
</div>
</body>
</html>
