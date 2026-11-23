<?php
/**
 * NexRaiz — « Mes documents », Pack Classic. Sert à la fois d'étape 4 du
 * parcours d'inscription et de page permanente de gestion documentaire
 * (lien « Mes documents » du tableau de bord) — même fichier, comme avant
 * cette refonte.
 *
 * Dépôt, historique de versions, statuts réels (en_verification / valide /
 * a_corriger) posés par le consultant (voir consultant-corrections-classic.php),
 * stockage protégé (includes/documents-classic.php). Seul le CV est
 * obligatoire pour avancer dans le parcours.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents-classic.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];

$slots = classic_slots_documents();
$slotsObligatoires = array_keys(array_filter($slots, fn ($s) => $s['obligatoire']));
$extensionsAutorisees = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];
$tailleMaxOctets = 10 * 1024 * 1024;

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer') {
    require_csrf();
    $slotCible = $_POST['slot'] ?? '';
    $description = trim($_POST['description'] ?? '');
    if (!isset($slots[$slotCible])) {
        $erreurs[] = 'Type de document invalide.';
    } else {
        $messageErreur = classic_enregistrer_document($dossierId, $slotCible, 'fichier', $description);
        if ($messageErreur !== '') {
            $erreurs[] = $messageErreur;
        }
    }

    if (!$erreurs) {
        header('Location: classic-depot-documents.php?succes=1#bibliotheque');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_infos') {
    require_csrf();
    $stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
    $stmt->execute([$dossierId]);
    $donneesExistantes = json_decode((string) $stmt->fetchColumn(), true) ?: [];
    $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
    $messageLibre = trim($_POST['message_libre'] ?? '');
    $donneesExistantes['linkedin_url'] = $linkedinUrl;
    $donneesExistantes['message_libre'] = $messageLibre;
    db()->prepare('INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)')
        ->execute([$dossierId, json_encode($donneesExistantes, JSON_UNESCAPED_UNICODE)]);
    header('Location: classic-depot-documents.php?succes=1');
    exit;
}

// Avancement du parcours : ne vérifie que les slots réellement obligatoires
// (CV uniquement) avant de permettre de passer à l'étape suivante.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'continuer') {
    require_csrf();
    $documentsParSlotPourValidation = classic_documents_par_slot($dossierId);
    $manquants = array_filter($slotsObligatoires, fn ($s) => $documentsParSlotPourValidation[$s]['actuel'] === null);
    if ($manquants) {
        $erreurs[] = 'Merci de transmettre : ' . implode(', ', array_map(fn ($s) => $slots[$s]['label'], $manquants)) . '.';
    } else {
        update_dossier_step($dossierId, 5);
        header('Location: ' . step_url(5, 'classic'));
        exit;
    }
}

$succes = isset($_GET['succes']);

$documentsParSlot = classic_documents_par_slot($dossierId);
$statutsLabels = classic_statuts_documents();

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualificationDonnees = json_decode((string) $stmt->fetchColumn(), true) ?: [];
$linkedinExistant = $qualificationDonnees['linkedin_url'] ?? '';
$messageExistant = $qualificationDonnees['message_libre'] ?? '';

// --- Indicateurs, calculés sur les 4 slots réels (pas de double comptage :
// un slot avec plusieurs versions ne compte qu'une fois, sur sa version
// courante). ---
$compte = ['attendus' => count($slots), 'transmis' => 0, 'en_verification' => 0, 'valide' => 0, 'a_corriger' => 0];
foreach ($documentsParSlot as $d) {
    if ($d['actuel'] !== null) {
        $compte['transmis']++;
        $compte[$d['actuel']['statut']]++;
    }
}

$vue = $_GET['vue'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? '';

$slotsAffiches = array_filter(array_keys($slots), function ($slot) use ($vue, $recherche, $documentsParSlot) {
    $actuel = $documentsParSlot[$slot]['actuel'];
    if ($vue === 'a_fournir' && $actuel !== null) return false;
    if (in_array($vue, ['en_verification', 'valide', 'a_corriger'], true) && ($actuel === null || $actuel['statut'] !== $vue)) return false;
    if ($recherche !== '' && stripos($slots[$slot]['label'] . ' ' . ($actuel['nom_original'] ?? ''), $recherche) === false) return false;
    return true;
});
if ($tri === 'ancien') {
    usort($slotsAffiches, fn ($a, $b) => strtotime($documentsParSlot[$a]['actuel']['uploaded_at'] ?? '2000-01-01') - strtotime($documentsParSlot[$b]['actuel']['uploaded_at'] ?? '2000-01-01'));
} else {
    usort($slotsAffiches, fn ($a, $b) => strtotime($documentsParSlot[$b]['actuel']['uploaded_at'] ?? '2000-01-01') - strtotime($documentsParSlot[$a]['actuel']['uploaded_at'] ?? '2000-01-01'));
}

render_head('Pack Classic — Mes documents');
render_top_header();
?>
<style>
main{overflow-x:hidden;}
.reveal-up,.reveal-scale,.reveal-left,.reveal-right{opacity:0;transition:opacity .7s cubic-bezier(.22,.61,.36,1),transform .7s cubic-bezier(.22,.61,.36,1);will-change:opacity,transform;}
.reveal-up{transform:translateY(24px);}
.reveal-scale{transform:scale(.94);}
.reveal-left{transform:translateX(-24px);}
.reveal-right{transform:translateX(24px);}
.reveal-up.is-visible,.reveal-scale.is-visible,.reveal-left.is-visible,.reveal-right.is-visible{opacity:1;transform:translate(0,0) scale(1);}
@media (prefers-reduced-motion:reduce){.reveal-up,.reveal-scale,.reveal-left,.reveal-right{transition:none;opacity:1;transform:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
$totalEtapesClassic = count(pack_steps('classic'));
?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-[18px]">home</span>
      </a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="<?php echo htmlspecialchars(step_url(1, 'classic')); ?>" class="hover:text-primary transition-colors">Pack Classic</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Parcours client</span>
    </nav>

    <div class="reveal-up is-visible grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-center">
      <div class="flex flex-col gap-space-xs">
        <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-primary-container text-white font-label-md text-label-md font-bold">
          Étape 4 sur <?php echo $totalEtapesClassic; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Mes documents</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Transmettez et suivez vos pièces pour votre accompagnement Pack Classic</p>
      </div>

      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
        <img src="img_classic/dossier.png" alt="Candidate remettant son dossier de candidature à sa coach NexRaiz" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</section>
<?php render_stepper('classic', 4); ?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <div class="reveal-up flex items-start justify-between gap-space-sm flex-wrap">
      <p class="font-body-md text-body-md text-secondary max-w-max-width-reading">Déposez vos documents ci-dessous et suivez leur vérification par votre consultant. Seul le CV est obligatoire pour continuer — les autres pièces sont facultatives mais recommandées.</p>
      <a href="#bibliotheque" class="shrink-0 h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary hover:scale-[1.02] active:scale-[0.98] transition-all duration-200"><span class="material-symbols-outlined text-[18px]">upload_file</span>Déposer un document</a>
    </div>

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 border border-green-200 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
      <p class="font-body-sm text-body-sm text-on-surface">Document transmis avec succès. Votre consultant le vérifiera prochainement.</p>
    </div>
    <?php endif; ?>
    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 sm:grid-cols-5 gap-space-xs">
      <?php
      $cartes = [
          ['cle' => '', 'label' => 'Attendus', 'valeur' => $compte['attendus'], 'couleur' => 'text-on-surface', 'icone' => 'folder_copy', 'degrade' => 'from-[#1E3E62] to-[#0B192C]'],
          ['cle' => 'a_fournir', 'label' => 'À fournir', 'valeur' => $compte['attendus'] - $compte['transmis'], 'couleur' => 'text-orange-600', 'icone' => 'pending_actions', 'degrade' => 'from-[#F0A83E] to-[#824500]'],
          ['cle' => 'en_verification', 'label' => 'En vérification', 'valeur' => $compte['en_verification'], 'couleur' => 'text-orange-600', 'icone' => 'hourglass_top', 'degrade' => 'from-[#8FB2FF] to-[#2563EB]'],
          ['cle' => 'valide', 'label' => 'Validés', 'valeur' => $compte['valide'], 'couleur' => 'text-green-600', 'icone' => 'verified', 'degrade' => 'from-green-400 to-green-600'],
          ['cle' => 'a_corriger', 'label' => 'À corriger', 'valeur' => $compte['a_corriger'], 'couleur' => 'text-error', 'icone' => 'error', 'degrade' => 'from-red-400 to-error'],
      ];
      foreach ($cartes as $i => $c):
      ?>
      <a href="?vue=<?php echo $c['cle']; ?>#bibliotheque" class="reveal-up group bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-col gap-space-2xs hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300 <?php echo $vue === $c['cle'] && $c['cle'] !== '' ? 'ring-2 ring-primary' : ''; ?>" style="transition-delay:<?php echo $i * 70; ?>ms">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br <?php echo $c['degrade']; ?> text-white flex items-center justify-center group-hover:scale-110 transition-transform duration-300"><span class="material-symbols-outlined text-[16px]"><?php echo $c['icone']; ?></span></div>
        <span class="font-headline-sm text-headline-sm font-bold <?php echo $c['couleur']; ?>"><?php echo $c['valeur']; ?></span>
        <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($c['label']); ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <form method="get" class="flex items-center gap-space-xs flex-wrap bg-surface-container-lowest rounded-xl shadow-sm p-space-sm">
      <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Rechercher un document..." class="h-10 w-56 px-space-sm rounded-lg border border-outline-variant font-body-sm text-[13px]">
      <select name="vue" class="h-10 px-space-sm rounded-lg border border-outline-variant font-body-sm text-[13px]">
        <option value="">Tous les documents</option>
        <option value="a_fournir" <?php echo $vue === 'a_fournir' ? 'selected' : ''; ?>>À fournir</option>
        <option value="en_verification" <?php echo $vue === 'en_verification' ? 'selected' : ''; ?>>En cours de vérification</option>
        <option value="valide" <?php echo $vue === 'valide' ? 'selected' : ''; ?>>Validés</option>
        <option value="a_corriger" <?php echo $vue === 'a_corriger' ? 'selected' : ''; ?>>À corriger</option>
      </select>
      <select name="tri" class="h-10 px-space-sm rounded-lg border border-outline-variant font-body-sm text-[13px]">
        <option value="recent" <?php echo $tri !== 'ancien' ? 'selected' : ''; ?>>Dépôt le plus récent</option>
        <option value="ancien" <?php echo $tri === 'ancien' ? 'selected' : ''; ?>>Dépôt le plus ancien</option>
      </select>
      <button type="submit" class="h-10 px-space-md rounded-lg bg-primary-container text-white font-label-md text-[13px]">Filtrer</button>
      <?php if ($vue || $recherche || $tri): ?><a href="classic-depot-documents.php#bibliotheque" class="font-label-md text-[13px] text-primary">Réinitialiser</a><?php endif; ?>
    </form>

    <div id="bibliotheque" class="flex flex-col gap-space-sm scroll-mt-20">
      <?php if (!$slotsAffiches): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg bg-surface-container-lowest rounded-xl">Aucun document ne correspond à ces critères.</p>
      <?php endif; ?>

      <?php foreach ($slotsAffiches as $i => $slot):
        $info = $slots[$slot];
        $actuel = $documentsParSlot[$slot]['actuel'];
        $historique = $documentsParSlot[$slot]['historique'];
        [$iconeFichier, $couleurIcone] = $actuel ? classic_icone_fichier($actuel['nom_original']) : ['upload_file', 'text-secondary'];
      ?>
      <div class="reveal-up bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm hover:shadow-md transition-shadow duration-300" style="transition-delay:<?php echo min($i, 5) * 60; ?>ms">
        <div class="flex items-start justify-between gap-space-sm flex-wrap">
          <div class="flex items-center gap-space-sm min-w-0">
            <div class="w-11 h-11 rounded-full bg-primary-container/10 <?php echo $couleurIcone; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo $iconeFichier; ?></span></div>
            <div class="min-w-0">
              <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><?php echo htmlspecialchars($info['label']); ?><?php echo $info['obligatoire'] ? '<span class="text-error">*</span>' : '<span class="font-body-sm text-[11px] text-secondary font-normal">(facultatif)</span>'; ?></p>
              <p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($info['explication']); ?></p>
            </div>
          </div>
          <?php if ($actuel): [$labelStatut, $classeStatut] = $statutsLabels[$actuel['statut']]; ?>
          <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
          <?php else: ?>
          <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold bg-surface-container-high text-secondary">Non transmis</span>
          <?php endif; ?>
        </div>

        <?php if ($actuel): ?>
        <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm flex-wrap">
          <div class="min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($actuel['nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo $actuel['taille_ko']; ?> Ko · reçu le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($actuel['uploaded_at']))); ?><?php echo $actuel['valide_le'] ? ' · validé le ' . htmlspecialchars(date('d/m/Y', strtotime($actuel['valide_le']))) : ''; ?></p>
            <?php if ($actuel['description']): ?><p class="font-body-sm text-[12px] text-secondary italic mt-0.5">« <?php echo htmlspecialchars($actuel['description']); ?> »</p><?php endif; ?>
          </div>
          <a href="classic-document-telecharger.php?id=<?php echo $actuel['id']; ?>" target="_blank" rel="noopener" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir</a>
        </div>
        <?php endif; ?>

        <?php if ($actuel && $actuel['statut'] === 'a_corriger' && $actuel['motif_correction']): ?>
        <div class="bg-error-container/20 border border-error/20 rounded-lg p-space-sm flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-error text-[18px] mt-0.5">error</span>
          <div><p class="font-label-md text-[12px] text-error font-bold">Correction demandée</p><p class="font-body-sm text-[13px] text-on-surface">« <?php echo htmlspecialchars($actuel['motif_correction']); ?> »</p></div>
        </div>
        <?php endif; ?>

        <?php if ($historique): ?>
        <button type="button" onclick="var e=document.getElementById('hist-<?php echo $slot; ?>');e.classList.toggle('hidden');" class="self-start font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">history</span>Voir les <?php echo count($historique); ?> version(s) précédente(s)</button>
        <div id="hist-<?php echo $slot; ?>" class="hidden flex-col gap-space-xs">
          <?php foreach ($historique as $v): [$labelV, $classeV] = $statutsLabels[$v['statut']]; ?>
          <div class="flex items-center justify-between gap-space-xs border border-outline-variant/50 rounded-lg p-space-sm flex-wrap">
            <div class="min-w-0"><p class="font-body-sm text-[12px] text-on-surface truncate"><?php echo htmlspecialchars($v['nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary">Déposé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($v['uploaded_at']))); ?><?php echo $v['motif_correction'] ? ' — « ' . htmlspecialchars($v['motif_correction']) . ' »' : ''; ?></p></div>
            <div class="shrink-0 flex items-center gap-1">
              <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeV; ?>"><?php echo htmlspecialchars($labelV); ?></span>
              <a href="classic-document-telecharger.php?id=<?php echo $v['id']; ?>" target="_blank" rel="noopener" class="font-label-md text-[11px] text-primary">Ouvrir</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <button type="button" onclick="var z=document.getElementById('zone-<?php echo $slot; ?>');z.classList.toggle('hidden');" class="self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">upload_file</span><?php echo $actuel ? 'Remplacer le document' : 'Déposer ce document'; ?></button>

        <div id="zone-<?php echo $slot; ?>" class="hidden flex-col gap-space-sm pt-space-xs border-t border-outline-variant/40">
          <form method="post" enctype="multipart/form-data" class="upload-form flex flex-col gap-space-sm" data-slot="<?php echo $slot; ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="deposer">
            <input type="hidden" name="slot" value="<?php echo $slot; ?>">
            <label class="upload-zone cursor-pointer flex flex-col items-center justify-center gap-space-2xs p-space-lg rounded-xl border-2 border-dashed border-outline-variant hover:border-primary-container transition-colors text-center">
              <input type="file" name="fichier" class="hidden upload-input" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
              <span class="material-symbols-outlined text-[28px] text-primary">cloud_upload</span>
              <span class="upload-nom font-label-md text-label-md text-on-surface">Glissez-déposez votre fichier ou cliquez pour parcourir</span>
              <span class="font-body-sm text-[11px] text-secondary">PDF, DOC, DOCX, JPG ou PNG — 10 Mo maximum</span>
            </label>
            <textarea name="description" rows="2" placeholder="Description facultative (ex. : version mise à jour après mon dernier poste)..." class="p-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px] resize-none"></textarea>
            <button type="submit" class="upload-submit self-start h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1">Envoyer</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="reveal-up bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
      <h2 class="flex items-center gap-space-xs font-headline-md text-headline-md text-on-surface font-bold">
        <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">link</span></span>
        Informations complémentaires (facultatif)
      </h2>
      <form method="post" class="flex flex-col gap-space-sm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="deposer_infos">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-label-md text-on-surface font-semibold">Lien LinkedIn</span>
          <input type="text" name="linkedin_url" value="<?php echo htmlspecialchars($linkedinExistant); ?>" placeholder="linkedin.com/in/votre-profil" class="h-10 px-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px]">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-label-md text-on-surface font-semibold">Message libre</span>
          <textarea name="message_libre" rows="2" placeholder="Précisez ici toute information utile à l'analyse..." class="p-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px] resize-none"><?php echo htmlspecialchars($messageExistant); ?></textarea>
        </label>
        <button type="submit" class="self-start h-10 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface">Enregistrer</button>
      </form>
    </div>

    <div class="reveal-scale bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white rounded-xl p-space-lg shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-space-md">
      <div class="flex items-start gap-space-sm">
        <div class="w-11 h-11 rounded-full bg-white/10 text-[#F0A83E] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">shield</span></div>
        <div>
          <p class="font-label-lg text-label-lg text-white font-bold">Confidentialité garantie</p>
          <p class="font-body-sm text-body-sm text-white/70">Vos fichiers sont stockés hors de tout accès public et ne sont consultables que par vous et votre consultant.</p>
        </div>
      </div>
      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="continuer">
        <button type="submit" class="h-12 px-space-lg rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 shrink-0">Continuer <span class="material-symbols-outlined text-[18px]">arrow_forward</span></button>
      </form>
    </div>
  </div>
</section>

<script>
(function () {
  document.querySelectorAll('.upload-form').forEach(function (form) {
    var input = form.querySelector('.upload-input');
    var nomSpan = form.querySelector('.upload-nom');
    var zone = form.querySelector('.upload-zone');
    var bouton = form.querySelector('.upload-submit');

    input.addEventListener('change', function () {
      if (input.files && input.files[0]) {
        var f = input.files[0];
        nomSpan.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' Ko)';
      }
    });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('border-primary-container'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('border-primary-container'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('border-primary-container');
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change'));
      }
    });
    form.addEventListener('submit', function () {
      bouton.disabled = true;
      bouton.textContent = 'Envoi en cours...';
    });
  });

  // --- Animations au scroll ---
  var cibles = document.querySelectorAll('.reveal-up, .reveal-scale, .reveal-left, .reveal-right');
  if ('IntersectionObserver' in window) {
    var observateur = new IntersectionObserver(function (entrees) {
      entrees.forEach(function (entree) {
        if (entree.isIntersecting) {
          entree.target.classList.add('is-visible');
          observateur.unobserve(entree.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    cibles.forEach(function (el) { observateur.observe(el); });
  } else {
    cibles.forEach(function (el) { el.classList.add('is-visible'); });
  }
})();
</script>

<?php render_footer(); ?>
