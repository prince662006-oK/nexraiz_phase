<?php
/**
 * NexRaiz — CV Builder structuré (Pack Classic) : les 6 étapes de
 * construction du CV (Infos personnelles, Ce que tu cherches, Expériences,
 * Formation, Compétences et langues, Ton CV). Troisième outil d'édition du
 * CV, additif à l'atelier/Studio (table `corrections`) et à l'éditeur
 * Canvas (table `canvas_editions`) — n'écrit jamais dans ces deux tables.
 * Le PDF final publié ici devient la version courante du même livrable
 * « CV corrigé » que les deux autres outils (voir includes/livrables-classic.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/documents-classic.php';
require_once __DIR__ . '/includes/livrables-classic.php';
require_once __DIR__ . '/includes/cv-builder-classic.php';
require_once __DIR__ . '/includes/pdf-cv-builder.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$dossierId = (int) ($_GET['dossier'] ?? 0);
$stmt = db()->prepare('SELECT ds.*, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?');
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    header('Location: consultant-analyse-classic.php');
    exit;
}
if ($dossier['consultant_id'] === null) {
    db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ?')->execute([$consultant['id'], $dossierId]);
}

$cv = classic_cv_builder_recuperer($dossierId);
if (!$cv || !$cv['layout']) {
    header('Location: consultant-cv-builder-choix.php?dossier=' . $dossierId);
    exit;
}

$erreurPublication = '';
$succesPublication = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'sauver_infos_personnelles':
            classic_cv_builder_sauver_infos_personnelles($dossierId, $_POST);
            classic_cv_builder_avancer_etape($dossierId, 1);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=1');
            exit;
        case 'sauver_recherche':
            classic_cv_builder_sauver_recherche($dossierId, $_POST);
            classic_cv_builder_avancer_etape($dossierId, 2);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=2');
            exit;
        case 'ajouter_experience':
            classic_cv_builder_ajouter_experience($dossierId, $_POST);
            classic_cv_builder_avancer_etape($dossierId, 3);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=3');
            exit;
        case 'modifier_experience':
            classic_cv_builder_modifier_experience((int) ($_POST['id'] ?? 0), $dossierId, $_POST);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=3');
            exit;
        case 'supprimer_experience':
            classic_cv_builder_supprimer_experience((int) ($_POST['id'] ?? 0), $dossierId);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=3');
            exit;
        case 'ajouter_formation':
            classic_cv_builder_ajouter_formation($dossierId, $_POST);
            classic_cv_builder_avancer_etape($dossierId, 4);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=4');
            exit;
        case 'modifier_formation':
            classic_cv_builder_modifier_formation((int) ($_POST['id'] ?? 0), $dossierId, $_POST);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=4');
            exit;
        case 'supprimer_formation':
            classic_cv_builder_supprimer_formation((int) ($_POST['id'] ?? 0), $dossierId);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=4');
            exit;
        case 'sauver_competences_langues':
            classic_cv_builder_sauver_competences_langues(
                $dossierId,
                array_filter(array_map('trim', explode(',', $_POST['competences'] ?? ''))),
                array_filter(array_map('trim', explode(',', $_POST['langues'] ?? '')))
            );
            classic_cv_builder_avancer_etape($dossierId, 5);
            header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=5');
            exit;
        case 'deposer_photo':
            $erreurPhoto = classic_enregistrer_document($dossierId, 'photo', 'fichier_photo');
            if ($erreurPhoto === '') {
                $photoActuelle = classic_documents_par_slot($dossierId)['photo']['actuel'] ?? null;
                if ($photoActuelle) {
                    classic_cv_builder_photo_definir($dossierId, (int) $photoActuelle['id']);
                }
            } else {
                $erreurPublication = $erreurPhoto;
            }
            classic_cv_builder_avancer_etape($dossierId, 6);
            if ($erreurPublication === '') {
                header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=6');
                exit;
            }
            break;
        case 'publier_cv':
            $pdfGenere = generer_pdf_cv_structure($dossierId, $dossier['prenom'], $dossier['nom']);
            if (!$pdfGenere) {
                $erreurPublication = 'Rien à publier : complétez au moins une section du CV avant de le publier.';
            } else {
                $nouveauFichier = ['chemin' => $pdfGenere['chemin'], 'nom_original' => $pdfGenere['nom'], 'taille_ko' => $pdfGenere['taille_ko']];
                $stmtLiv = db()->prepare("SELECT id, statut FROM livrables WHERE dossier_id = ? AND titre = 'CV corrigé' AND est_version_courante = 1 ORDER BY id DESC LIMIT 1");
                $stmtLiv->execute([$dossierId]);
                $livrable = $stmtLiv->fetch();
                if ($livrable) {
                    classic_publier_livrable((int) $livrable['id'], $dossierId, $nouveauFichier);
                } else {
                    $livrableId = classic_creer_ou_maj_brouillon_livrable(
                        $dossierId, 'CV corrigé', $nouveauFichier['nom_original'], $nouveauFichier['chemin'], $nouveauFichier['taille_ko'],
                        classic_livrable_categorie_auto('CV corrigé')
                    );
                    classic_publier_livrable($livrableId, $dossierId);
                }
                log_activite($dossierId, 'CV publié au candidat (CV Builder structuré)');
                $succesPublication = 'Le CV a bien été envoyé au candidat.';
            }
            classic_cv_builder_avancer_etape($dossierId, 6);
            break;
    }
}

$etape = max(1, min(6, (int) ($_GET['etape'] ?? 1)));
classic_cv_builder_avancer_etape($dossierId, $etape);
// Relit le profil (les actions POST ci-dessus peuvent l'avoir modifié avant redirection, ou on est arrivé ici en GET direct).
$cv = classic_cv_builder_recuperer($dossierId);
$experiences = classic_cv_builder_experiences($dossierId);
$formations = classic_cv_builder_formations($dossierId);
$photoActuelle = classic_documents_par_slot($dossierId)['photo']['actuel'] ?? null;
$pourcentage = classic_cv_builder_pourcentage_completion($dossierId);

$etapesLabels = [
    1 => 'Infos personnelles', 2 => 'Ce que tu cherches', 3 => 'Expériences',
    4 => 'Formation', 5 => 'Compétences et langues', 6 => 'Ton CV',
];

$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

render_head('CV Builder structuré — ' . $etapesLabels[$etape], 'Construction du CV par étapes.');
?>
<div class="flex flex-col min-h-screen">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></a>
      <nav class="hidden md:flex items-center gap-space-sm">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
        <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>" class="px-space-md h-9 flex items-center rounded-xl bg-primary-container text-white font-label-lg text-label-lg font-bold">Corrections</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiersApercu, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div class="flex items-center justify-between gap-space-sm flex-wrap">
        <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>" class="font-label-md text-label-md text-primary flex items-center gap-1 w-fit"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour au dossier</a>
        <div class="flex items-center gap-space-sm">
          <a href="consultant-cv-builder-choix.php?dossier=<?php echo $dossierId; ?>&changer=1" class="font-label-md text-[12px] text-secondary underline">Changer le modèle</a>
          <span class="px-space-sm py-1 rounded-full bg-primary-container/10 text-primary font-label-md text-[12px] font-bold">Profil <?php echo $pourcentage; ?>%</span>
        </div>
      </div>

      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">CV Builder — <?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></h1>

      <?php if ($succesPublication !== ''): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm"><span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succesPublication); ?></p></div>
      <?php endif; ?>
      <?php if ($erreurPublication !== ''): ?>
      <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($erreurPublication); ?></p></div>
      <?php endif; ?>

      <div class="flex items-center gap-space-2xs flex-wrap border-b border-outline-variant/50 pb-space-xs">
        <?php foreach ($etapesLabels as $n => $label): ?>
        <a href="consultant-cv-builder.php?dossier=<?php echo $dossierId; ?>&etape=<?php echo $n; ?>" class="px-space-sm h-10 rounded-full flex items-center gap-1 font-label-md text-label-md <?php echo $etape === $n ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low'; ?>">
          <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] font-bold <?php echo $etape === $n ? 'bg-white/20' : 'bg-surface-container-low'; ?>"><?php echo $n; ?></span><?php echo htmlspecialchars($label); ?>
        </a>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-md items-start">

        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-md">

          <?php if ($etape === 1): ?>
          <form method="post" class="flex flex-col gap-space-sm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sauver_infos_personnelles">
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Intitulé affiché (sous le nom)</span><input type="text" name="titre_poste" value="<?php echo htmlspecialchars($cv['titre_poste'] ?? ''); ?>" placeholder="Ex. Chef de projet digital" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Ville</span><input type="text" name="ville" value="<?php echo htmlspecialchars($cv['ville'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Téléphone affiché sur le CV</span><input type="text" name="telephone_affiche" value="<?php echo htmlspecialchars($cv['telephone_affiche'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">E-mail affiché sur le CV</span><input type="email" name="email_affiche" value="<?php echo htmlspecialchars($cv['email_affiche'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Résumé / accroche</span><textarea name="resume_profil" rows="4" class="p-space-sm rounded-xl border border-outline-variant"><?php echo htmlspecialchars($cv['resume_profil'] ?? ''); ?></textarea></label>
            <button type="submit" class="self-start h-11 px-space-lg rounded-full bg-primary-container text-white font-label-lg text-label-lg font-bold">Enregistrer et continuer</button>
          </form>

          <?php elseif ($etape === 2): ?>
          <form method="post" class="flex flex-col gap-space-sm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sauver_recherche">
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Poste recherché</span><input type="text" name="poste_recherche" value="<?php echo htmlspecialchars($cv['poste_recherche'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Type de contrat</span><input type="text" name="type_contrat" value="<?php echo htmlspecialchars($cv['type_contrat'] ?? ''); ?>" placeholder="CDI, CDD, stage, alternance..." class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Secteur visé</span><input type="text" name="secteur_vise" value="<?php echo htmlspecialchars($cv['secteur_vise'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Mobilité</span><input type="text" name="mobilite" value="<?php echo htmlspecialchars($cv['mobilite'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Disponibilité</span><input type="text" name="disponibilite" value="<?php echo htmlspecialchars($cv['disponibilite'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant"></label>
            <button type="submit" class="self-start h-11 px-space-lg rounded-full bg-primary-container text-white font-label-lg text-label-lg font-bold">Enregistrer et continuer</button>
          </form>

          <?php elseif ($etape === 3): ?>
          <div class="flex flex-col gap-space-sm">
            <?php foreach ($experiences as $e): ?>
            <div class="border border-outline-variant/60 rounded-xl p-space-sm flex items-start justify-between gap-space-sm">
              <div><p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($e['poste']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($e['entreprise'] ?: ''); ?></p></div>
              <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="supprimer_experience"><input type="hidden" name="id" value="<?php echo $e['id']; ?>"><button type="submit" class="text-error" title="Supprimer"><span class="material-symbols-outlined text-[18px]">delete</span></button></form>
            </div>
            <?php endforeach; ?>
            <form method="post" class="flex flex-col gap-space-sm border-t border-outline-variant/40 pt-space-sm mt-space-xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_experience">
              <p class="font-label-md text-label-md text-on-surface font-bold">Ajouter une expérience</p>
              <input type="text" name="poste" placeholder="Intitulé du poste" required class="h-11 px-space-sm rounded-xl border border-outline-variant">
              <input type="text" name="entreprise" placeholder="Entreprise" class="h-11 px-space-sm rounded-xl border border-outline-variant">
              <input type="text" name="lieu" placeholder="Lieu" class="h-11 px-space-sm rounded-xl border border-outline-variant">
              <div class="flex gap-space-sm">
                <input type="month" name="date_debut" class="h-11 px-space-sm rounded-xl border border-outline-variant flex-1">
                <input type="month" name="date_fin" class="h-11 px-space-sm rounded-xl border border-outline-variant flex-1">
              </div>
              <label class="flex items-center gap-1 font-body-sm text-body-sm"><input type="checkbox" name="poste_actuel" value="1" class="accent-primary">Poste actuel</label>
              <textarea name="description" rows="3" placeholder="Missions principales..." class="p-space-sm rounded-xl border border-outline-variant"></textarea>
              <button type="submit" class="self-start h-11 px-space-lg rounded-full bg-primary-container text-white font-label-lg text-label-lg font-bold">Ajouter</button>
            </form>
          </div>

          <?php elseif ($etape === 4): ?>
          <div class="flex flex-col gap-space-sm">
            <?php foreach ($formations as $f): ?>
            <div class="border border-outline-variant/60 rounded-xl p-space-sm flex items-start justify-between gap-space-sm">
              <div><p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($f['diplome']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($f['etablissement'] ?: ''); ?></p></div>
              <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="supprimer_formation"><input type="hidden" name="id" value="<?php echo $f['id']; ?>"><button type="submit" class="text-error" title="Supprimer"><span class="material-symbols-outlined text-[18px]">delete</span></button></form>
            </div>
            <?php endforeach; ?>
            <form method="post" class="flex flex-col gap-space-sm border-t border-outline-variant/40 pt-space-sm mt-space-xs">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="ajouter_formation">
              <p class="font-label-md text-label-md text-on-surface font-bold">Ajouter une formation</p>
              <input type="text" name="diplome" placeholder="Diplôme" required class="h-11 px-space-sm rounded-xl border border-outline-variant">
              <input type="text" name="etablissement" placeholder="Établissement" class="h-11 px-space-sm rounded-xl border border-outline-variant">
              <input type="text" name="lieu" placeholder="Lieu" class="h-11 px-space-sm rounded-xl border border-outline-variant">
              <div class="flex gap-space-sm">
                <input type="month" name="date_debut" class="h-11 px-space-sm rounded-xl border border-outline-variant flex-1">
                <input type="month" name="date_fin" class="h-11 px-space-sm rounded-xl border border-outline-variant flex-1">
              </div>
              <textarea name="description" rows="3" placeholder="Détails..." class="p-space-sm rounded-xl border border-outline-variant"></textarea>
              <button type="submit" class="self-start h-11 px-space-lg rounded-full bg-primary-container text-white font-label-lg text-label-lg font-bold">Ajouter</button>
            </form>
          </div>

          <?php elseif ($etape === 5): ?>
          <form method="post" class="flex flex-col gap-space-sm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sauver_competences_langues">
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Compétences (séparées par des virgules)</span><textarea name="competences" rows="3" class="p-space-sm rounded-xl border border-outline-variant"><?php echo htmlspecialchars(implode(', ', $cv['competences'])); ?></textarea></label>
            <label class="flex flex-col gap-1"><span class="font-label-md text-label-md text-on-surface font-semibold">Langues (séparées par des virgules, ex. "Français (natif), Anglais (courant)")</span><textarea name="langues" rows="2" class="p-space-sm rounded-xl border border-outline-variant"><?php echo htmlspecialchars(implode(', ', $cv['langues'])); ?></textarea></label>
            <button type="submit" class="self-start h-11 px-space-lg rounded-full bg-primary-container text-white font-label-lg text-label-lg font-bold">Enregistrer et continuer</button>
          </form>

          <?php else: // étape 6 ?>
          <div class="flex flex-col gap-space-md">
            <?php if ($cv['layout'] === 'avec_photo'): ?>
            <div class="flex items-center gap-space-sm">
              <?php if ($photoActuelle): ?>
              <span class="font-body-sm text-body-sm text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-green-700">check_circle</span>Photo déposée : <?php echo htmlspecialchars($photoActuelle['nom_original']); ?></span>
              <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" class="flex items-center gap-space-sm">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="deposer_photo">
              <input type="file" name="fichier_photo" accept="image/png,image/jpeg" class="font-body-sm text-[12px]">
              <button type="submit" class="h-10 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md"><?php echo $photoActuelle ? 'Remplacer la photo' : 'Déposer la photo'; ?></button>
            </form>
            <?php endif; ?>

            <?php echo cv_builder_rendre_recap_html($cv, $experiences, $formations); ?>

            <form method="post" onsubmit="return confirm('Publier ce CV au candidat maintenant ? Il remplacera la version actuellement envoyée.');">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="publier_cv">
              <button type="submit" class="h-12 px-space-xl rounded-full text-white font-label-lg text-label-lg font-bold flex items-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity" style="background:linear-gradient(135deg,#2563EB,#13203F)"><span class="material-symbols-outlined text-[18px]">send</span>Publier au candidat</button>
            </form>
          </div>
          <?php endif; ?>

        </div>

        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-sm sticky top-space-md">
          <p class="font-label-md text-label-md text-on-surface font-bold uppercase tracking-wide">Aperçu du CV</p>
          <?php echo cv_builder_rendre_recap_html($cv, $experiences, $formations); ?>
        </div>

      </div>
    </main>
  </div>
</div>
</body>
</html>
