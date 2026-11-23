<?php
/**
 * Parcours client Pack Wellbeing : avancement réel dans le tunnel (étapes
 * franchies), historique des rendez-vous et fil d'activité du dossier.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-wellbeing.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('wellbeing');
$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom, u.email
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
        header('Location: consultant-parcours-wellbeing.php?dossier=' . $premierId);
    } else {
        header('Location: consultant-dossiers-wellbeing.php');
    }
    exit;
}

$succes = '';
$erreurLivrable = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_livrable') {
    require_csrf();
    $titreLivrable = trim($_POST['titre_livrable'] ?? '');
    if ($titreLivrable === '' || !isset($_FILES['fichier_livrable']) || $_FILES['fichier_livrable']['error'] === UPLOAD_ERR_NO_FILE) {
        $erreurLivrable = 'Merci de renseigner un titre et un fichier pour le livrable.';
    } elseif ($_FILES['fichier_livrable']['error'] !== UPLOAD_ERR_OK) {
        $erreurLivrable = "Erreur lors de l'envoi du fichier.";
    } else {
        $extensionsAutorisees = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];
        $extension = strtolower(pathinfo($_FILES['fichier_livrable']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $extensionsAutorisees, true)) {
            $erreurLivrable = 'Format non accepté (PDF, DOC, DOCX, PNG, JPG uniquement).';
        } else {
            $livrableDir = __DIR__ . '/uploads/wellbeing/' . $dossierId . '/livrables';
            if (!is_dir($livrableDir)) {
                mkdir($livrableDir, 0755, true);
            }
            $nomFichier = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['fichier_livrable']['name']));
            $cheminAbsolu = $livrableDir . '/' . $nomFichier;
            if (move_uploaded_file($_FILES['fichier_livrable']['tmp_name'], $cheminAbsolu)) {
                $cheminRelatif = 'uploads/wellbeing/' . $dossierId . '/livrables/' . $nomFichier;
                $stmt = db()->prepare('INSERT INTO livrables (dossier_id, titre, nom_original, chemin, taille_ko, statut) VALUES (?, ?, ?, ?, ?, "en_preparation")');
                $stmt->execute([$dossierId, $titreLivrable, $_FILES['fichier_livrable']['name'], $cheminRelatif, (int) round($_FILES['fichier_livrable']['size'] / 1024)]);
                log_activite($dossierId, 'Livrable déposé (en préparation) : ' . $titreLivrable);
                $succes = "Livrable déposé. Il reste invisible pour le candidat tant que vous ne l'avez pas envoyé.";
            } else {
                $erreurLivrable = "Impossible d'enregistrer le fichier.";
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'envoyer_livrable') {
    require_csrf();
    $livrableId = (int) ($_POST['livrable_id'] ?? 0);
    if (marquer_livrable_disponible($livrableId, $dossierId)) {
        $succes = 'Livrable envoyé au candidat.';
    }
}

function wellbeing_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

$etapes = pack_steps('wellbeing');
$etapeActuelle = (int) $dossier['current_step'];

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = json_decode((string) $stmt->fetchColumn(), true) ?: [];

$stmt = db()->prepare('SELECT date_heure, format, statut FROM rendezvous WHERE dossier_id = ? ORDER BY date_heure DESC');
$stmt->execute([$dossierId]);
$rendezvous = $stmt->fetchAll();
$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir', 'hybride' => 'Hybride'];

$stmt = db()->prepare('SELECT texte, created_at FROM activites WHERE dossier_id = ? ORDER BY id DESC LIMIT 20');
$stmt->execute([$dossierId]);
$activites = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, titre, nom_original, chemin, taille_ko, statut, created_at FROM livrables WHERE dossier_id = ? ORDER BY created_at DESC');
$stmt->execute([$dossierId]);
$livrables = $stmt->fetchAll();

render_head('Espace NexRaiz — Parcours client', 'Avancement du parcours Pack Wellbeing pour ce client.');
?>
<div class="flex w-full min-h-screen">

  <?php render_wellbeing_sidebar($packId, 'parcours'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_wellbeing_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Parcours client</h1>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?> · Dossier <?php echo htmlspecialchars($dossier['numero']); ?></p>
        </div>
        <a href="consultant-diagnostic-wellbeing.php?dossier=<?php echo $dossierId; ?>" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1">Ouvrir le diagnostic <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Avancement du tunnel</h2>
        <div class="flex flex-col gap-space-xs">
          <?php foreach ($etapes as $num => $etape): $fait = $num < $etapeActuelle || ($num === $etapeActuelle && $dossier['statut'] === 'cloture'); $encours = $num === $etapeActuelle && $dossier['statut'] !== 'cloture'; ?>
          <div class="flex items-center gap-space-sm">
            <span class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 <?php echo $fait ? 'bg-primary-container text-white' : ($encours ? 'bg-orange-100 text-orange-700 border-2 border-orange-400' : 'bg-surface-container-high text-secondary'); ?>">
              <?php if ($fait): ?><span class="material-symbols-outlined text-[16px]">check</span><?php else: ?><span class="font-label-md text-[11px] font-bold"><?php echo $num; ?></span><?php endif; ?>
            </span>
            <span class="font-body-sm text-body-sm <?php echo $fait || $encours ? 'text-on-surface font-semibold' : 'text-secondary'; ?>"><?php echo htmlspecialchars($etape['label']); ?></span>
            <?php if ($encours): ?><span class="font-label-md text-[10px] px-space-xs py-0.5 rounded-full bg-orange-100 text-orange-700 font-bold">En cours</span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm items-start">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">psychology</span>Formulaire de situation</h2>
          <?php if (!$qualification): ?>
          <p class="font-body-sm text-body-sm text-secondary">Le formulaire de situation n'a pas encore été complété.</p>
          <?php else: ?>
          <p class="font-body-sm text-body-sm text-on-surface"><strong>Motif principal :</strong> <?php echo htmlspecialchars($qualification['motif_principal'] ?? 'Non renseigné'); ?></p>
          <?php if (!empty($qualification['niveau_stress'])): ?>
          <p class="font-body-sm text-body-sm text-on-surface"><strong>Niveau de stress déclaré :</strong> <?php echo (int) $qualification['niveau_stress']; ?>/5</p>
          <?php endif; ?>
          <?php if (!empty($qualification['situation_impactee']) && is_array($qualification['situation_impactee'])): ?>
          <p class="font-body-sm text-body-sm text-on-surface"><strong>Domaines impactés :</strong> <?php echo htmlspecialchars(implode(', ', $qualification['situation_impactee'])); ?></p>
          <?php endif; ?>
          <?php if (!empty($qualification['details'])): ?>
          <p class="font-body-sm text-[12px] text-secondary pt-space-xs border-t border-outline-variant/40"><?php echo nl2br(htmlspecialchars($qualification['details'])); ?></p>
          <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">event</span>Rendez-vous</h2>
          <?php if (!$rendezvous): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun rendez-vous planifié.</p>
          <?php endif; ?>
          <?php foreach ($rendezvous as $r): ?>
          <div class="flex items-center justify-between font-body-sm text-body-sm">
            <span class="text-on-surface"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($r['date_heure']))); ?> · <?php echo htmlspecialchars($formatLabels[$r['format']] ?? $r['format']); ?></span>
            <span class="font-label-md text-[10px] px-space-xs py-0.5 rounded-full bg-surface-container-high text-secondary font-bold"><?php echo htmlspecialchars($r['statut'] ?? 'planifié'); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">folder_special</span>Livrables</h2>

        <?php if ($succes): ?>
        <p class="font-body-sm text-body-sm text-green-700 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">check_circle</span><?php echo htmlspecialchars($succes); ?></p>
        <?php endif; ?>
        <?php if ($erreurLivrable): ?>
        <p class="font-body-sm text-body-sm text-error flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">error</span><?php echo htmlspecialchars($erreurLivrable); ?></p>
        <?php endif; ?>

        <?php if (!$livrables): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucun livrable pour le moment.</p>
        <?php else: ?>
        <div class="flex flex-col divide-y divide-outline-variant/30">
          <?php foreach ($livrables as $l): ?>
          <div class="flex items-center justify-between py-space-xs gap-space-sm">
            <div class="flex items-center gap-space-xs min-w-0">
              <span class="material-symbols-outlined text-[18px] text-primary shrink-0">description</span>
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary"><?php echo $l['statut'] === 'disponible' ? 'Envoyé le ' . htmlspecialchars(date('d/m/Y à H:i', strtotime($l['created_at']))) : 'Déposé le ' . htmlspecialchars(date('d/m/Y à H:i', strtotime($l['created_at']))) . ' — pas encore envoyé'; ?><?php echo $l['taille_ko'] ? ' · ' . (int) $l['taille_ko'] . ' Ko' : ''; ?></p>
              </div>
            </div>
            <?php if ($l['statut'] === 'disponible'): ?>
            <a href="<?php echo htmlspecialchars($l['chemin']); ?>" target="_blank" class="shrink-0 font-label-md text-label-md text-primary underline">Ouvrir</a>
            <?php else: ?>
            <div class="flex items-center gap-space-2xs shrink-0">
              <span class="font-label-md text-[11px] font-bold px-space-xs py-0.5 rounded-full bg-orange-100 text-orange-700">En préparation</span>
              <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="envoyer_livrable">
                <input type="hidden" name="livrable_id" value="<?php echo $l['id']; ?>">
                <button type="submit" class="h-8 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[14px]">send</span>Envoyer</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-space-sm pt-space-xs border-t border-outline-variant/40">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="ajouter_livrable">
          <label class="flex flex-col gap-space-2xs flex-1">
            <span class="font-label-md text-label-md text-secondary">Titre du livrable</span>
            <input required type="text" name="titre_livrable" placeholder="Ex. Plan d'action 30-60-90 jours" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          </label>
          <label class="flex flex-col gap-space-2xs flex-1">
            <span class="font-label-md text-label-md text-secondary">Fichier (PDF, DOC, DOCX, PNG, JPG)</span>
            <input required type="file" name="fichier_livrable" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="font-body-sm text-body-sm">
          </label>
          <button type="submit" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md shrink-0">Déposer</button>
        </form>
        <p class="font-body-sm text-[11px] text-secondary">Le fichier reste invisible pour le candidat tant que vous ne l'envoyez pas explicitement (bouton « Envoyer » ci-dessus).</p>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">history</span>Fil d'activité</h2>
        <?php if (!$activites): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
        <?php endif; ?>
        <?php foreach ($activites as $act): ?>
        <div class="flex items-center justify-between border-b border-outline-variant/30 last:border-0 py-space-2xs">
          <span class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[14px] text-primary">radio_button_checked</span><?php echo htmlspecialchars($act['texte']); ?></span>
          <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo htmlspecialchars(wellbeing_il_y_a($act['created_at'])); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

    </main>
  </div>
</div>
</body>
</html>
