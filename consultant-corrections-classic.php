<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/documents-classic.php';
require_once __DIR__ . '/includes/livrables-classic.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$dossierId = (int) ($_GET['dossier'] ?? 0);
$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom, u.email, u.telephone
     FROM dossiers ds
     JOIN users u ON u.id = ds.user_id
     WHERE ds.id = ? AND ds.pack_id = ?'
);
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();

if (!$dossier) {
    header('Location: consultant-analyse-classic.php');
    exit;
}

// Le premier consultant qui ouvre un dossier non assigné se l'approprie.
if ($dossier['consultant_id'] === null) {
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ?');
    $stmt->execute([$consultant['id'], $dossierId]);
    $dossier['consultant_id'] = $consultant['id'];
}

// Critères de la checklist qualité manuelle, par type de document — jamais
// cochés automatiquement par l'IA, uniquement par le consultant au moment de
// la relecture finale. Définis ici (avant le traitement du POST) car utilisés
// à la fois pour valider les données envoyées et pour l'affichage.
$checklistCriteresParType = [
    'cv' => [
        'coherence'     => 'Dates et expériences cohérentes avec les documents fournis',
        'orthographe'   => 'Orthographe et grammaire vérifiées',
        'competences'   => 'Compétences mises en valeur sans invention',
        'mise_en_forme' => 'Mise en forme claire et homogène',
    ],
    'lettre' => [
        'structure'     => 'Structure respectée (introduction, corps, conclusion)',
        'orthographe'   => 'Orthographe et grammaire vérifiées',
        'pertinence'    => 'Pertinence par rapport au poste visé',
        'mise_en_forme' => 'Mise en forme claire et homogène',
    ],
    'linkedin' => [
        'headline'    => 'Titre professionnel (headline) clair',
        'orthographe' => 'Orthographe et grammaire vérifiées',
        'coherence'   => "Cohérence avec le CV et l'expérience réelle",
    ],
];

$succes = '';
$erreurLivrable = '';
$erreurAction = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'enregistrer_notes') {
        $notes = trim($_POST['notes_consultant'] ?? '');
        $stmt = db()->prepare('UPDATE dossiers SET notes_consultant = ? WHERE id = ?');
        $stmt->execute([$notes, $dossierId]);
        $dossier['notes_consultant'] = $notes;
        $succes = 'Notes enregistrées.';
    } elseif ($action === 'valider_corrections') {
        $stmt = db()->prepare("UPDATE dossiers SET statut = 'cloture' WHERE id = ?");
        $stmt->execute([$dossierId]);
        log_activite($dossierId, 'Corrections validées par le consultant — dossier clôturé');
        notify((int) $dossier['user_id'], 'statut_dossier', 'Votre accompagnement est terminé', 'Votre dossier Pack Classic a été clôturé par votre consultant.', 'classic-tableau-bord.php');
        header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '&valide=1');
        exit;
    } elseif ($action === 'ajouter_livrable') {
        $titre = trim($_POST['titre_livrable'] ?? '');
        $categorieLivrable = array_key_exists($_POST['categorie_livrable'] ?? '', classic_livrable_categories()) ? $_POST['categorie_livrable'] : null;
        $descriptionLivrable = trim($_POST['description_livrable'] ?? '') ?: null;
        $prochaineEtapeLivrable = trim($_POST['prochaine_etape_livrable'] ?? '') ?: null;
        if ($titre === '') {
            $erreurLivrable = 'Merci de renseigner un titre pour ce livrable.';
        } else {
            $resultatDepot = classic_deposer_fichier_livrable($dossierId);
            if (is_string($resultatDepot)) {
                $erreurLivrable = $resultatDepot;
            } else {
                classic_creer_ou_maj_brouillon_livrable(
                    $dossierId, $titre, $resultatDepot['nom_original'], $resultatDepot['chemin'], $resultatDepot['taille_ko'],
                    $categorieLivrable, $descriptionLivrable, $prochaineEtapeLivrable
                );
                $succes = "Livrable déposé. Il reste invisible pour le candidat tant que vous ne l'avez pas envoyé.";
            }
        }
    } elseif ($action === 'remplacer_livrable') {
        $livrableId = (int) ($_POST['livrable_id'] ?? 0);
        $resultatDepot = classic_deposer_fichier_livrable($dossierId);
        if (is_string($resultatDepot)) {
            $erreurLivrable = $resultatDepot;
        } else {
            classic_publier_livrable($livrableId, $dossierId, $resultatDepot);
            $succes = 'Nouvelle version publiée.';
        }
    } elseif ($action === 'envoyer_livrable') {
        $livrableId = (int) ($_POST['livrable_id'] ?? 0);

        // Si ce livrable est un "CV corrigé"/"Lettre corrigée" issu de
        // l'éditeur de corrections (texte brut affiché via
        // correction-document.php), on génère maintenant le vrai PDF mis en
        // forme à partir de la toute dernière version du texte, et on
        // remplace le lien par ce fichier avant de l'envoyer.
        $stmt = db()->prepare("SELECT chemin FROM livrables WHERE id = ? AND dossier_id = ? AND statut = 'en_preparation'");
        $stmt->execute([$livrableId, $dossierId]);
        $cheminActuel = $stmt->fetchColumn();
        $nouveauFichierCorrection = null;
        if ($cheminActuel && strpos($cheminActuel, 'correction-document.php') === 0) {
            $typeDoc = strpos($cheminActuel, 'type=lettre') !== false ? 'lettre' : 'cv';
            $pdfGenere = generer_pdf_correction($dossierId, $typeDoc, $dossier['prenom'], $dossier['nom']);
            if ($pdfGenere) {
                $nouveauFichierCorrection = ['chemin' => $pdfGenere['chemin'], 'nom_original' => $pdfGenere['nom'], 'taille_ko' => $pdfGenere['taille_ko']];
            }
        }

        if (classic_publier_livrable($livrableId, $dossierId, $nouveauFichierCorrection) > 0) {
            $succes = 'Livrable envoyé au candidat.';
        }
    } elseif ($action === 'ajouter_action') {
        $titreAction = trim($_POST['titre_action'] ?? '');
        $descriptionAction = trim($_POST['description_action'] ?? '');
        $echeance = trim($_POST['echeance_action'] ?? '');
        $prioriteAction = ($_POST['priorite_action'] ?? 'normale') === 'haute' ? 'haute' : 'normale';
        if ($titreAction === '') {
            $erreurAction = 'Merci de renseigner un intitulé pour cette action.';
        } else {
            $stmt = db()->prepare('INSERT INTO actions_plan (dossier_id, titre, description, proprietaire, priorite, echeance, statut) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$dossierId, $titreAction, $descriptionAction ?: null, $consultant['prenom'] . ' ' . $consultant['nom'], $prioriteAction, $echeance !== '' ? $echeance : null, 'a_faire']);
            log_activite($dossierId, 'Nouvelle action ajoutée : ' . $titreAction);
            $succes = 'Action ajoutée au plan du candidat.';
        }
    } elseif ($action === 'valider_action') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $stmt = db()->prepare("UPDATE actions_plan SET statut = 'termine', motif_correction = NULL, statut_maj_le = NOW(), statut_maj_par = 'consultant' WHERE id = ? AND dossier_id = ? AND statut = 'a_verifier'");
        $stmt->execute([$actionId, $dossierId]);
        if ($stmt->rowCount() > 0) {
            log_activite($dossierId, 'Action validée par le consultant');
            $succes = 'Action validée.';
        }
    } elseif ($action === 'renvoyer_action_correction') {
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $motif = trim($_POST['motif_correction'] ?? '');
        if ($motif === '') {
            $erreurAction = 'Merci de préciser le motif de la demande de correction.';
        } else {
            $stmt = db()->prepare("UPDATE actions_plan SET statut = 'a_faire', motif_correction = ?, statut_maj_le = NOW(), statut_maj_par = 'consultant' WHERE id = ? AND dossier_id = ? AND statut = 'a_verifier'");
            $stmt->execute([$motif, $actionId, $dossierId]);
            if ($stmt->rowCount() > 0) {
                log_activite($dossierId, 'Action renvoyée en correction au candidat');
                $succes = 'Action renvoyée au candidat avec votre commentaire.';
            }
        }
    } elseif ($action === 'envoyer_message') {
        $corpsMessage = trim($_POST['corps_message'] ?? '');
        if ($corpsMessage !== '') {
            $stmt = db()->prepare(
                'INSERT INTO messages (dossier_id, expediteur_id, expediteur_type, corps, lu_par_client, lu_par_consultant)
                 VALUES (?, ?, ?, ?, 0, 1)'
            );
            $stmt->execute([$dossierId, $consultant['id'], 'consultant', $corpsMessage]);
            header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '#messagerie');
            exit;
        }
    } elseif ($action === 'enregistrer_correction') {
        $documentType = $_POST['document_type'] ?? '';
        if (in_array($documentType, ['cv', 'lettre', 'linkedin'], true)) {
            $contenuInitial = trim($_POST['contenu_initial'] ?? '');
            $contenuCorrige = trim($_POST['contenu_corrige'] ?? '');

            // Même garde que studio-envoyer.php : une « Version corrigée »
            // vide ne doit jamais pouvoir être enregistrée ni, a fortiori,
            // devenir le livrable envoyé au candidat (un CV « corrigé » vide
            // a réellement été envoyé à un candidat avant ce correctif).
            if ($contenuCorrige === '') {
                $erreurLivrable = 'La « Version corrigée » est vide — rien n\'a été enregistré. Complétez-la (ou utilisez « Améliorer avec l\'IA ») avant d\'enregistrer.';
            } else {
                // Checklist qualité : la case à cocher HTML n'envoie RIEN quand
                // elle est décochée, donc on ne peut pas distinguer "l'utilisateur
                // a tout décoché" de "cette requête ne gère pas la checklist"
                // (ex. l'autosauvegarde du Studio, qui ne connaît pas la
                // checklist) juste par l'absence du champ "checklist". Le champ
                // "checklist_gere" (envoyé uniquement par le formulaire de
                // l'atelier principal) lève cette ambiguïté explicitement :
                // présent -> on valide et on enregistre l'état transmis (y
                // compris "tout décoché" si c'est le cas) ; absent -> on reporte
                // la checklist de la version précédente, jamais une remise à
                // zéro silencieuse.
                if (($_POST['checklist_gere'] ?? '') === '1') {
                    $checklistPostee = is_array($_POST['checklist'] ?? null) ? $_POST['checklist'] : [];
                    $checklistValide = [];
                    foreach (array_keys($checklistCriteresParType[$documentType] ?? []) as $cle) {
                        $checklistValide[$cle] = isset($checklistPostee[$cle]);
                    }
                    $checklistJson = json_encode($checklistValide);
                } else {
                    $stmt = db()->prepare('SELECT checklist_json FROM corrections WHERE dossier_id = ? AND document_type = ? ORDER BY created_at DESC LIMIT 1');
                    $stmt->execute([$dossierId, $documentType]);
                    $checklistJson = $stmt->fetchColumn() ?: null;
                }

                $stmt = db()->prepare('INSERT INTO corrections (dossier_id, consultant_id, document_type, contenu_initial, contenu_corrige, checklist_json) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$dossierId, $consultant['id'], $documentType, $contenuInitial, $contenuCorrige, $checklistJson]);
                log_activite($dossierId, 'Correction enregistrée : ' . $documentType);

                // Le CV et la lettre corrigés deviennent un livrable (candidat) dès la
                // première correction enregistrée : "en préparation" tant que le
                // consultant ne l'envoie pas explicitement (voir includes/livrables.php)
                // — le candidat voit alors "en cours de traitement", jamais le contenu
                // en cours de rédaction. Si une version est déjà "disponible" (déjà
                // envoyée au candidat), on n'y touche surtout pas ici : republier une
                // nouvelle version se fait explicitement (action
                // publier_version_depuis_texte), jamais en silence à chaque sauvegarde.
                if (in_array($documentType, ['cv', 'lettre'], true)) {
                    $titreLivrable = $documentType === 'cv' ? 'CV corrigé' : 'Lettre de motivation corrigée';
                    $stmt = db()->prepare("SELECT statut FROM livrables WHERE dossier_id = ? AND titre = ? AND est_version_courante = 1 ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$dossierId, $titreLivrable]);
                    $statutLivrableActuel = $stmt->fetchColumn();
                    if ($statutLivrableActuel === false || $statutLivrableActuel === 'en_preparation') {
                        classic_creer_ou_maj_brouillon_livrable(
                            $dossierId, $titreLivrable, $titreLivrable,
                            'correction-document.php?dossier=' . $dossierId . '&type=' . $documentType, 0
                        );
                    }
                }

                header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '&onglet=' . $documentType . '#corrections');
                exit;
            }
        }
    } elseif ($action === 'publier_version_depuis_texte') {
        $documentType = $_POST['document_type'] ?? '';
        if (in_array($documentType, ['cv', 'lettre'], true)) {
            $titreLivrable = $documentType === 'cv' ? 'CV corrigé' : 'Lettre de motivation corrigée';
            $stmt = db()->prepare("SELECT id FROM livrables WHERE dossier_id = ? AND titre = ? AND est_version_courante = 1 AND statut = 'disponible'");
            $stmt->execute([$dossierId, $titreLivrable]);
            $livrableId = (int) $stmt->fetchColumn();
            if ($livrableId > 0) {
                $pdfGenere = generer_pdf_correction($dossierId, $documentType, $dossier['prenom'], $dossier['nom']);
                if ($pdfGenere) {
                    $nouveauFichier = ['chemin' => $pdfGenere['chemin'], 'nom_original' => $pdfGenere['nom'], 'taille_ko' => $pdfGenere['taille_ko']];
                    classic_publier_livrable($livrableId, $dossierId, $nouveauFichier);
                    $succes = 'Nouvelle version publiée au candidat à partir du texte actuel.';
                } else {
                    $erreurLivrable = 'Aucune correction enregistrée pour ce document : rien à publier.';
                }
            } else {
                $erreurLivrable = "Ce document n'a pas encore été envoyé au candidat une première fois — utilisez « Envoyer au candidat » dans la section Livrables.";
            }
        }
    } elseif ($action === 'ajouter_annotation') {
        $categorie = $_POST['categorie_annotation'] ?? '';
        $texteAnnotation = trim($_POST['texte_annotation'] ?? '');
        if (in_array($categorie, ['contenu', 'competence', 'style'], true) && $texteAnnotation !== '') {
            $stmt = db()->prepare('INSERT INTO correction_annotations (dossier_id, categorie, texte) VALUES (?, ?, ?)');
            $stmt->execute([$dossierId, $categorie, $texteAnnotation]);
            header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '#corrections');
            exit;
        }
    } elseif ($action === 'valider_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if (classic_valider_document($documentId, $dossierId, (int) $consultant['id'])) {
            $succes = 'Document validé.';
        } else {
            $erreurAction = 'Document introuvable.';
        }
        header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '#documents-verification');
        exit;
    } elseif ($action === 'corriger_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $motif = trim($_POST['motif_correction_document'] ?? '');
        if ($motif === '') {
            $erreurAction = 'Merci de préciser le motif de la correction.';
        } elseif (classic_corriger_document($documentId, $dossierId, $motif)) {
            header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '&succes_doc=1#documents-verification');
            exit;
        } else {
            $erreurAction = 'Document introuvable.';
        }
    } elseif ($action === 'planifier_simulation') {
        $jourSim = $_POST['jour_simulation'] ?? '';
        $heureSim = $_POST['heure_simulation'] ?? '';
        $formatSim = $_POST['format_simulation'] ?? 'ligne';
        if ($jourSim !== '' && $heureSim !== '') {
            $stmt = db()->prepare('INSERT INTO rendezvous (dossier_id, date_heure, format) VALUES (?, ?, ?)');
            $stmt->execute([$dossierId, $jourSim . ' ' . $heureSim . ':00', $formatSim]);
            log_activite($dossierId, 'Simulation d\'entretien planifiée pour le ' . $jourSim . ' à ' . $heureSim);
            header('Location: consultant-corrections-classic.php?dossier=' . $dossierId . '#preparation-entretien');
            exit;
        }
    }
}

// Marque comme lus les messages du candidat à l'ouverture du dossier.
$stmt = db()->prepare("UPDATE messages SET lu_par_consultant = 1 WHERE dossier_id = ? AND expediteur_type = 'client'");
$stmt->execute([$dossierId]);

$stmt = db()->prepare('SELECT expediteur_type, corps, created_at FROM messages WHERE dossier_id = ? ORDER BY id ASC');
$stmt->execute([$dossierId]);
$filMessages = $stmt->fetchAll();
if (($_GET['valide'] ?? '') === '1') {
    $succes = 'Dossier validé et clôturé.';
}
if (($_GET['succes_doc'] ?? '') === '1') {
    $succes = 'Document renvoyé au candidat avec votre motif.';
}
if (($_GET['canvas_erreur'] ?? '') === '1') {
    $erreurAction = "L'éditeur visuel n'est disponible que pour un document original déposé au format PDF.";
}

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = json_decode((string) $stmt->fetchColumn(), true) ?: [];

// Source unique de vérité pour les documents par slot (statut réel,
// historique de versions, stockage protégé) — voir
// includes/documents-classic.php. $documentsParSlot reste une liste plate
// par compatibilité avec l'affichage existant (onglets CV/lettre/offre/
// portfolio plus bas), construite à partir de la version courante +
// l'historique de chaque slot.
$documentsParSlotStructure = classic_documents_par_slot($dossierId);
$documentsParSlot = ['cv' => [], 'lettre' => [], 'offre' => [], 'portfolio' => []];
foreach ($documentsParSlotStructure as $slot => $d) {
    $documentsParSlot[$slot] = array_values(array_filter(array_merge([$d['actuel']], $d['historique'])));
}
$statutsDocumentsLabels = classic_statuts_documents();
$slotsDocumentsLabels = classic_slots_documents();

$stmt = db()->prepare('SELECT date_heure, format, statut FROM rendezvous WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId]);
$rdv = $stmt->fetch();

$stmt = db()->prepare('SELECT * FROM livrables WHERE dossier_id = ? AND est_version_courante = 1 ORDER BY created_at DESC');
$stmt->execute([$dossierId]);
$livrablesExistants = $stmt->fetchAll();
$livrableCategoriesLabels = classic_livrable_categories();

$actionStatutLabels = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'a_venir' => 'À venir', 'a_verifier' => 'À vérifier', 'termine' => 'Terminé'];
$stmt = db()->prepare('SELECT id, titre, description, proprietaire, priorite, echeance, statut, motif_correction FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC, id DESC');
$stmt->execute([$dossierId]);
$actionsExistantes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT statut FROM paiements WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId]);
$paiement = $stmt->fetch();
$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir'];

// --- Corrections (versions initiale/corrigée) par type de document, historique complet. ---
$stmt = db()->prepare('SELECT document_type, contenu_initial, contenu_corrige, checklist_json, created_at FROM corrections WHERE dossier_id = ? ORDER BY created_at ASC');
$stmt->execute([$dossierId]);
$correctionsHistorique = ['cv' => [], 'lettre' => [], 'linkedin' => []];
foreach ($stmt->fetchAll() as $c) {
    $correctionsHistorique[$c['document_type']][] = $c;
}
$correctionsActuelles = [];
foreach ($correctionsHistorique as $type => $versions) {
    $correctionsActuelles[$type] = $versions ? end($versions) : null;
}

$onglet = $_GET['onglet'] ?? 'cv';
if (!isset($correctionsHistorique[$onglet])) {
    $onglet = 'cv';
}

// --- Statut du livrable "candidat" (CV/lettre), pour l'en-tête de la modale
// de correction : jamais envoyé / déposé en brouillon / déjà envoyé. ---
$livrableStatutParType = [];
foreach (['cv' => 'CV corrigé', 'lettre' => 'Lettre de motivation corrigée'] as $type => $titreLivrable) {
    $stmt = db()->prepare("SELECT statut FROM livrables WHERE dossier_id = ? AND titre = ? AND est_version_courante = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$dossierId, $titreLivrable]);
    $livrableStatutParType[$type] = $stmt->fetchColumn() ?: null;
}


// --- Annotations du consultant sur ce dossier. ---
$stmt = db()->prepare('SELECT categorie, texte, created_at FROM correction_annotations WHERE dossier_id = ? ORDER BY id DESC');
$stmt->execute([$dossierId]);
$annotations = $stmt->fetchAll();
$annotationStyles = [
    'contenu'    => ['icone' => 'star', 'couleur' => 'bg-blue-100 text-blue-700', 'label' => 'Amélioration de contenu'],
    'competence' => ['icone' => 'check_circle', 'couleur' => 'bg-green-100 text-green-700', 'label' => 'Compétence ajoutée'],
    'style'      => ['icone' => 'palette', 'couleur' => 'bg-purple-100 text-purple-700', 'label' => 'Style et clarté'],
];

// --- Statistiques globales Pack Classic (bandeau du haut, comme sur la liste des dossiers). ---
$stmt = db()->prepare('SELECT ds.id, ds.statut, (SELECT COUNT(*) FROM livrables l WHERE l.dossier_id = ds.id AND l.statut = "disponible") AS nb_livrables FROM dossiers ds WHERE ds.pack_id = ?');
$stmt->execute([$packId]);
$tousLesDossiersClassic = $stmt->fetchAll();
$nbCorrectionsPretes = 0;
$nbLivrablesAEnvoyer = 0;
$nbDossiersValides = 0;
foreach ($tousLesDossiersClassic as $d2) {
    if ($d2['statut'] === 'cloture') {
        $nbDossiersValides++;
    } elseif ((int) $d2['nb_livrables'] > 0) {
        $nbCorrectionsPretes++;
    } else {
        $nbLivrablesAEnvoyer++;
    }
}
$stmt = db()->prepare("SELECT COUNT(*) FROM rendezvous rdv JOIN dossiers ds ON ds.id = rdv.dossier_id WHERE ds.pack_id = ? AND rdv.date_heure >= NOW()");
$stmt->execute([$packId]);
$nbEntretiensAPreparer = (int) $stmt->fetchColumn();

$statsGlobales = [
    ['icone' => 'fact_check', 'couleur' => 'bg-primary-container/10 text-primary', 'valeur' => $nbCorrectionsPretes, 'label' => 'Corrections prêtes', 'sous' => 'livrable envoyé, en attente de clôture'],
    ['icone' => 'groups', 'couleur' => 'bg-blue-100 text-blue-700', 'valeur' => $nbEntretiensAPreparer, 'label' => 'Entretiens à préparer', 'sous' => 'rendez-vous à venir'],
    ['icone' => 'ios_share', 'couleur' => 'bg-purple-100 text-purple-700', 'valeur' => $nbLivrablesAEnvoyer, 'label' => 'Livrables à envoyer', 'sous' => 'dossiers actifs sans livrable'],
    ['icone' => 'check_circle', 'couleur' => 'bg-green-100 text-green-700', 'valeur' => $nbDossiersValides, 'label' => 'Dossiers validés', 'sous' => 'clôturés'],
];

$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

$preparationEntretien = [
    ['icone' => 'mic', 'titre' => 'Pitch de présentation', 'texte' => 'Résumé professionnel, parcours, valeur ajoutée et objectifs.'],
    ['icone' => 'favorite', 'titre' => 'Motivations', 'texte' => 'Motivations pour le poste, l\'entreprise et le secteur.'],
    ['icone' => 'star', 'titre' => 'Forces', 'texte' => 'Compétences clés, réalisations et atouts différenciants.'],
    ['icone' => 'help', 'titre' => 'Questions fréquentes', 'texte' => 'Questions classiques et réponses recommandées par le consultant.'],
    ['icone' => 'desktop_windows', 'titre' => 'Simulation d\'entretien', 'texte' => 'Préparer et planifier une simulation avec feedback personnalisé.'],
];

$dossierValide = $dossier['statut'] === 'cloture';
$workflow = [
    ['titre' => 'Corrections', 'texte' => 'Correction des documents par le consultant.', 'statut' => $dossierValide ? 'done' : 'current'],
    ['titre' => 'Validation qualité', 'texte' => 'Relecture et validation qualité interne.', 'statut' => $dossierValide ? 'done' : 'pending'],
    ['titre' => 'Préparation entretien', 'texte' => 'Préparation des contenus et simulation.', 'statut' => $dossierValide ? 'done' : 'pending'],
    ['titre' => 'Envoi des livrables', 'texte' => 'Envoi des documents et supports au candidat.', 'statut' => $dossierValide ? 'done' : 'pending'],
];

render_head('Espace NexRaiz — Corrections et préparation entretien', 'Corriger les documents, préparer l\'entretien et envoyer les livrables.');
?>
<style>
.ia-source-editable:empty:before{content:attr(data-placeholder);color:var(--tw-color-secondary,#525f75);opacity:.6;}
.ia-erreur-surlignee{text-decoration:underline;text-decoration-color:#ef4444;text-decoration-style:wavy;text-decoration-thickness:2px;cursor:help;}
.correction-diff-ajout{background:rgba(16,185,129,.15);color:#047857;font-weight:600;border-radius:3px;padding:0 2px;}
.correction-diff-suppr{background:rgba(239,68,68,.12);color:#b91c1c;text-decoration:line-through;border-radius:3px;padding:0 2px;}
</style>
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
        <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>" class="px-space-md h-9 flex items-center rounded-xl bg-primary-container text-white font-label-lg text-label-lg font-bold">Corrections</a>
        <a href="consultant-entretiens-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Entretiens</a>
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

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
      <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
    </div>
    <?php endif; ?>
    <?php if ($erreurLivrable || $erreurAction): ?>
    <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span>
      <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($erreurLivrable ?: $erreurAction); ?></p>
    </div>
    <?php endif; ?>

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="consultant-dossiers-classic.php" class="hover:text-primary transition-colors">Dossiers</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="consultant-analyse-classic.php" class="hover:text-primary transition-colors">Pack Classic</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-[1.5fr_1fr] gap-space-lg items-center">
      <div class="flex flex-col gap-space-xs">
        <span class="inline-flex self-start items-center gap-space-2xs px-space-sm py-1.5 rounded-full bg-surface-container-low font-label-md text-label-md text-on-surface font-bold"><span class="material-symbols-outlined text-[16px]">diversity_3</span>Espace interne consultant</span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Espace NexRaiz : Corrections et préparation entretien</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Corriger les documents, préparer l'entretien et envoyer les livrables</p>
        <p class="font-body-sm text-body-sm text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-primary">person</span>Dossier de <strong class="text-on-surface"><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></strong> · <?php echo htmlspecialchars($dossier['numero']); ?> · <?php echo htmlspecialchars($qualification['poste_vise'] ?? 'poste non renseigné'); ?></p>
      </div>
      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none">
        <div class="absolute inset-0 rounded-3xl bg-gradient-to-br from-primary-container/15 via-surface-container-low to-tertiary-fixed/10"></div>
        <div class="absolute inset-0 flex items-center justify-center">
          <div class="w-24 h-24 md:w-28 md:h-28 rounded-full bg-surface-container-lowest shadow-lg flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[52px]">fact_check</span>
          </div>
        </div>
        <div class="absolute top-4 right-6 w-11 h-11 rounded-full bg-surface-container-lowest shadow-md flex items-center justify-center text-primary-container"><span class="material-symbols-outlined text-[22px]">groups</span></div>
        <div class="absolute bottom-6 left-8 w-11 h-11 rounded-full bg-surface-container-lowest shadow-md flex items-center justify-center text-primary-container"><span class="material-symbols-outlined text-[22px]">calendar_month</span></div>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
      <?php foreach ($statsGlobales as $s): ?>
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs">
        <div class="w-11 h-11 rounded-full <?php echo $s['couleur']; ?> flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $s['icone']; ?></span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo $s['valeur']; ?></p>
        <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($s['label']); ?></p>
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($s['sous']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm items-start">

      <div id="corrections" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md scroll-mt-20">
        <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">fact_check</span></span>1. Corrections des documents</h2>

        <div class="flex items-center gap-space-xs flex-wrap" id="docTabs">
          <button type="button" data-tab="cv" class="doc-tab px-space-md h-9 rounded-full <?php echo $onglet === 'cv' ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface'; ?> font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">description</span>CV</button>
          <button type="button" data-tab="lettre" class="doc-tab px-space-md h-9 rounded-full <?php echo $onglet === 'lettre' ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface'; ?> font-label-md text-label-md">Lettre de motivation</button>
          <button type="button" data-tab="linkedin" class="doc-tab px-space-md h-9 rounded-full <?php echo $onglet === 'linkedin' ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface'; ?> font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">work_history</span>LinkedIn</button>
          <button type="button" data-tab="offre" class="doc-tab px-space-md h-9 rounded-full border border-outline-variant text-on-surface font-label-md text-label-md">Offre ciblée</button>
          <button type="button" data-tab="portfolio" class="doc-tab px-space-md h-9 rounded-full border border-outline-variant text-on-surface font-label-md text-label-md">Portfolio</button>
        </div>

        <?php foreach (['cv' => 'CV actuel', 'lettre' => 'Lettre de motivation', 'linkedin' => 'Profil LinkedIn'] as $type => $labelType):
          $actuelle = $correctionsActuelles[$type];
          $historique = $correctionsHistorique[$type];

          // Pas encore de correction enregistrée : on essaie de pré-remplir la
          // "Version initiale" directement depuis le fichier déposé (PDF/DOCX),
          // pour éviter d'avoir à la recopier à la main. Les .doc et les
          // images (CV scanné) ne sont pas pris en charge (pas d'OCR) : dans
          // ce cas la zone reste vide et modifiable normalement.
          $contenuInitialDefaut = $actuelle['contenu_initial'] ?? '';
          // Statut d'extraction, pour afficher un message précis plutôt que
          // de laisser croire que l'extraction a réussi si le champ est
          // simplement vide : aucun document transmis, format non pris en
          // charge (pas d'OCR pour les images/.doc), ou échec de
          // l'extraction malgré un format supporté (PDF scanné sans texte,
          // fichier corrompu...).
          $statutExtraction = 'ok';
          if ($type === 'linkedin') {
              $statutExtraction = 'non_applicable';
          } elseif (empty($documentsParSlot[$type])) {
              $statutExtraction = 'aucun_document';
          } elseif ($contenuInitialDefaut === '') {
              $dernierDoc = $documentsParSlot[$type][0];
              $extensionDernierDoc = strtolower(pathinfo($dernierDoc['nom_original'], PATHINFO_EXTENSION));
              if (!in_array($extensionDernierDoc, ['pdf', 'docx'], true)) {
                  $statutExtraction = 'non_supporte';
              } else {
                  $texteExtrait = extraire_texte_document(classic_chemin_absolu_fichier($dernierDoc['chemin']), pathinfo($dernierDoc['nom_original'], PATHINFO_EXTENSION));
                  if ($texteExtrait !== null) {
                      $contenuInitialDefaut = $texteExtrait;
                      $statutExtraction = 'extrait';
                  } else {
                      $statutExtraction = 'echec';
                  }
              }
          } elseif (empty($actuelle)) {
              $statutExtraction = 'extrait';
          }
          $messageExtraction = [
              'extrait'         => "Texte extrait automatiquement du fichier déposé — à vérifier avant de corriger.",
              'aucun_document'  => 'Aucun document transmis pour le moment : saisissez la version initiale manuellement.',
              'non_supporte'    => "Extraction automatique non disponible pour ce format (image ou .doc) : copiez le texte manuellement depuis le fichier.",
              'echec'           => "L'extraction automatique a échoué pour ce fichier (PDF scanné sans texte ou fichier illisible) : copiez le texte manuellement.",
          ][$statutExtraction] ?? '';
        ?>
        <div class="doc-panel <?php echo $onglet !== $type ? 'hidden' : ''; ?> flex flex-col gap-space-md" data-panel="<?php echo $type; ?>">

          <?php if ($type !== 'linkedin' && !empty($documentsParSlot[$type])): ?>
          <div class="flex flex-col gap-space-xs">
            <?php foreach ($documentsParSlot[$type] as $d): ?>
            <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
              <div class="flex items-center gap-space-xs min-w-0">
                <div class="w-9 h-9 rounded bg-error/10 text-error flex items-center justify-center font-label-md text-[10px] font-bold shrink-0">DOC</div>
                <div class="min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo $d['taille_ko']; ?> Ko · reçu le <?php echo htmlspecialchars(date('d/m/Y', strtotime($d['uploaded_at']))); ?></p></div>
              </div>
              <a href="classic-document-telecharger.php?id=<?php echo $d['id']; ?>" target="_blank" rel="noopener" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir le fichier</a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php elseif ($type === 'linkedin' && !empty($qualification['linkedin_url'])): ?>
          <a href="https://<?php echo htmlspecialchars(preg_replace('#^https?://#', '', $qualification['linkedin_url'])); ?>" target="_blank" rel="noopener" class="flex items-center gap-1 font-label-lg text-label-lg text-primary underline"><?php echo htmlspecialchars($qualification['linkedin_url']); ?> <span class="material-symbols-outlined text-[16px]">open_in_new</span></a>
          <?php endif; ?>

          <?php if ($actuelle): ?>
          <span class="inline-flex self-start items-center gap-1 px-space-sm py-1 rounded-full bg-primary-container/10 text-primary font-label-md text-[11px] font-bold"><span class="material-symbols-outlined text-[14px]">verified</span>Traité par NexRaiz</span>
          <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr] gap-space-sm items-start">
            <div class="rounded-xl border border-outline-variant/60 overflow-hidden">
              <div class="bg-blue-50 px-space-sm py-space-xs"><p class="font-label-md text-label-md text-blue-700 font-bold">Version initiale</p></div>
              <div class="p-space-sm font-body-sm text-[12px] text-secondary whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars($actuelle['contenu_initial'])); ?></div>
            </div>
            <div class="hidden sm:flex items-center justify-center h-full"><span class="material-symbols-outlined text-[22px] text-secondary">arrow_forward</span></div>
            <div class="rounded-xl border border-outline-variant/60 overflow-hidden">
              <div class="bg-green-50 px-space-sm py-space-xs"><p class="font-label-md text-label-md text-green-700 font-bold">Version corrigée</p></div>
              <div class="p-space-sm font-body-sm text-[12px] text-secondary whitespace-pre-wrap"><?php echo $actuelle['contenu_corrige'] !== '' ? nl2br(htmlspecialchars($actuelle['contenu_corrige'])) : '<span class="italic">Pas encore rédigée — ouvrez l\'atelier de correction pour la préparer.</span>'; ?></div>
            </div>
          </div>
          <p class="font-body-sm text-[11px] text-secondary">Dernière mise à jour le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($actuelle['created_at']))); ?></p>
          <?php else: ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune correction enregistrée pour « <?php echo htmlspecialchars($labelType); ?> ».</p>
          <?php endif; ?>

          <div class="flex flex-wrap items-center gap-space-xs">
          <button type="button" class="edit-toggle self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors" data-target="edit-<?php echo $type; ?>"><span class="material-symbols-outlined text-[16px]">edit</span><?php echo $actuelle ? 'Modifier la correction' : 'Rédiger une correction'; ?></button>
          <?php if (in_array($type, ['cv', 'lettre'], true)): ?>
          <a href="consultant-studio-classic.php?dossier=<?php echo $dossierId; ?>&type=<?php echo $type; ?>" class="self-start h-9 px-space-sm rounded-lg bg-secondary text-white font-label-md text-label-md flex items-center gap-1 hover:opacity-90 transition-colors"><span class="material-symbols-outlined text-[16px]">auto_awesome</span>Studio de correction</a>
          <a href="consultant-canvas-editeur.php?dossier=<?php echo $dossierId; ?>&type=<?php echo $type; ?>" class="self-start h-9 px-space-sm rounded-lg border border-primary-container text-primary font-label-md text-label-md flex items-center gap-1 hover:bg-primary-container/5 transition-colors"><span class="material-symbols-outlined text-[16px]">dashboard_customize</span>Éditeur visuel (Canva)</a>
          <?php endif; ?>
          <?php if ($type === 'cv'): ?>
          <a href="consultant-cv-builder-choix.php?dossier=<?php echo $dossierId; ?>" class="self-start h-9 px-space-sm rounded-lg text-white font-label-md text-label-md flex items-center gap-1 hover:opacity-90 transition-colors" style="background:linear-gradient(135deg,#2563EB,#13203F)"><span class="material-symbols-outlined text-[16px]">construction</span>CV Builder structuré</a>
          <?php endif; ?>
          </div>

          <div id="edit-<?php echo $type; ?>" class="correction-modal hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-gutter-mobile" data-type="<?php echo $type; ?>">
            <div class="bg-surface-container-lowest rounded-2xl shadow-xl w-full max-w-7xl h-[94vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">

              <div class="flex items-center justify-between gap-space-sm px-space-lg py-space-sm border-b border-outline-variant/50 shrink-0 flex-wrap">
                <div class="flex flex-col gap-0.5 min-w-0">
                  <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold flex items-center gap-space-xs"><span class="material-symbols-outlined text-[20px] text-primary">edit_document</span>Atelier de correction — <?php echo htmlspecialchars($labelType); ?></h3>
                  <p class="font-body-sm text-[11px] text-secondary flex items-center gap-1 flex-wrap">
                    <span><?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?></span>
                    <span>· <?php echo htmlspecialchars($dossier['numero']); ?></span>
                    <?php if (in_array($type, ['cv', 'lettre'], true)):
                      $statutLivActuel = $livrableStatutParType[$type];
                      [$labelLiv, $classeLiv] = $statutLivActuel === 'disponible'
                        ? ['Déjà envoyé au candidat', 'bg-green-100 text-green-700']
                        : ($statutLivActuel === 'en_preparation' ? ['Brouillon non envoyé', 'bg-orange-100 text-orange-700'] : ['Pas encore de livrable', 'bg-surface-container-low text-secondary']);
                    ?>
                    <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeLiv; ?>"><?php echo htmlspecialchars($labelLiv); ?></span>
                    <?php endif; ?>
                    <span id="save-indicateur-<?php echo $type; ?>" class="font-semibold" data-saved-text="<?php echo $actuelle ? htmlspecialchars('· Dernière sauvegarde : ' . date('d/m/Y à H:i', strtotime($actuelle['created_at']))) : ''; ?>"><?php echo $actuelle ? '· Dernière sauvegarde : ' . htmlspecialchars(date('d/m/Y à H:i', strtotime($actuelle['created_at']))) : ''; ?></span>
                  </p>
                </div>
                <button type="button" class="edit-close-btn w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low transition-colors shrink-0" data-target="edit-<?php echo $type; ?>" aria-label="Fermer"><span class="material-symbols-outlined text-[20px]">close</span></button>
              </div>

              <form method="post" id="form-edit-<?php echo $type; ?>" class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="enregistrer_correction">
                <input type="hidden" name="document_type" value="<?php echo $type; ?>">
                <input type="hidden" name="checklist_gere" value="1">

                <div class="flex-1 overflow-hidden grid grid-cols-1 lg:grid-cols-[1fr_1.3fr_1fr] divide-y lg:divide-y-0 lg:divide-x divide-outline-variant/40">

                  <!-- Zone 1 : document original -->
                  <div class="overflow-y-auto p-space-md flex flex-col gap-space-xs min-h-[12rem]">
                    <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">description</span>Document original</p>
                    <?php if ($type !== 'linkedin' && !empty($documentsParSlot[$type])):
                      $docRecent = $documentsParSlot[$type][0];
                      $extensionRecent = strtolower(pathinfo($docRecent['nom_original'], PATHINFO_EXTENSION));
                    ?>
                    <div class="flex flex-wrap items-center gap-1">
                      <a href="classic-document-telecharger.php?id=<?php echo $docRecent['id']; ?>" target="_blank" rel="noopener" class="h-8 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-label-md text-[11px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir « <?php echo htmlspecialchars($docRecent['nom_original']); ?> »</a>
                      <?php if ($extensionRecent === 'pdf'): ?>
                      <button type="button" class="doc-zoom-toggle h-8 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface flex items-center gap-1" data-target="docviewer-<?php echo $type; ?>"><span class="material-symbols-outlined text-[14px]">zoom_in</span>Vue agrandie</button>
                      <?php endif; ?>
                    </div>
                    <?php if ($extensionRecent === 'pdf'): ?>
                    <div id="docviewer-<?php echo $type; ?>" class="rounded-xl border border-outline-variant/60 overflow-hidden bg-surface-container-low h-72 transition-[height]">
                      <iframe src="classic-document-telecharger.php?id=<?php echo $docRecent['id']; ?>" class="w-full h-full block" title="Aperçu — <?php echo htmlspecialchars($docRecent['nom_original']); ?>"></iframe>
                    </div>
                    <p class="font-body-sm text-[11px] text-secondary">Aperçu natif du navigateur (dispose de son propre zoom) — « Vue agrandie » donne plus de place pour le lire pendant la correction.</p>
                    <?php else: ?>
                    <p class="font-body-sm text-[11px] text-secondary">Aperçu non disponible pour ce format (<?php echo htmlspecialchars(strtoupper($extensionRecent)); ?>) — utilisez le lien ci-dessus pour ouvrir le fichier original.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($type === 'linkedin'): ?>
                    <?php if (!empty($qualification['linkedin_url'])): ?>
                    <a href="https://<?php echo htmlspecialchars(preg_replace('#^https?://#', '', $qualification['linkedin_url'])); ?>" target="_blank" rel="noopener" class="flex items-center gap-1 font-label-lg text-label-lg text-primary underline"><?php echo htmlspecialchars($qualification['linkedin_url']); ?> <span class="material-symbols-outlined text-[16px]">open_in_new</span></a>
                    <?php else: ?>
                    <p class="font-body-sm text-[11px] text-secondary">Aucune URL de profil LinkedIn renseignée par le candidat.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($messageExtraction !== ''): ?>
                    <div class="flex items-start gap-1 bg-surface-container-low rounded-lg p-space-xs mt-space-2xs">
                      <span class="material-symbols-outlined text-[14px] text-secondary mt-0.5"><?php echo $statutExtraction === 'echec' ? 'error_outline' : 'info'; ?></span>
                      <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($messageExtraction); ?></p>
                    </div>
                    <?php endif; ?>
                  </div>

                  <!-- Zone 2 : éditeur -->
                  <div class="overflow-y-auto p-space-md flex flex-col gap-space-sm">
                    <label class="flex flex-col gap-space-2xs">
                      <span class="font-label-md text-label-md text-on-surface font-semibold">Version initiale <?php echo $contenuInitialDefaut !== '' && empty($actuelle) ? '(extraite automatiquement — vérifiez-la)' : '(telle que reçue)'; ?></span>
                      <?php if (in_array($type, ['cv', 'lettre'], true)): ?>
                      <div class="relative">
                        <textarea name="contenu_initial" id="source-hidden-<?php echo $type; ?>" class="ia-source hidden"><?php echo htmlspecialchars($contenuInitialDefaut); ?></textarea>
                        <div class="ia-source-editable source-editable-<?php echo $type; ?> w-full h-48 p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary overflow-y-auto whitespace-pre-wrap" contenteditable="true" data-placeholder="Collez ou tapez le texte ici..."><?php echo htmlspecialchars($contenuInitialDefaut); ?></div>
                      </div>
                      <?php else: ?>
                      <textarea name="contenu_initial" class="w-full h-48 p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars($contenuInitialDefaut); ?></textarea>
                      <?php endif; ?>
                    </label>

                    <div class="flex items-center justify-between gap-space-xs flex-wrap">
                      <span class="font-label-md text-label-md text-on-surface font-semibold">Version corrigée</span>
                      <?php if (in_array($type, ['cv', 'lettre'], true)): ?>
                      <div class="flex items-center gap-0.5">
                        <button type="button" class="correction-fmt h-7 w-7 rounded border border-outline-variant text-on-surface font-bold text-[12px] flex items-center justify-center hover:bg-surface-container-low" data-type="<?php echo $type; ?>" data-marker="**" title="Gras (**texte**)">G</button>
                        <button type="button" class="correction-fmt h-7 w-7 rounded border border-outline-variant text-on-surface italic text-[12px] flex items-center justify-center hover:bg-surface-container-low" data-type="<?php echo $type; ?>" data-marker="*" title="Italique (*texte*)">I</button>
                        <button type="button" class="correction-copier h-7 px-1.5 rounded border border-outline-variant text-on-surface text-[11px] flex items-center gap-0.5 hover:bg-surface-container-low" data-type="<?php echo $type; ?>" title="Copier"><span class="material-symbols-outlined text-[14px]">content_copy</span></button>
                        <button type="button" class="correction-diff-toggle h-7 px-1.5 rounded border border-outline-variant text-on-surface text-[11px] flex items-center gap-0.5 hover:bg-surface-container-low" data-type="<?php echo $type; ?>" title="Comparer avec la version initiale"><span class="material-symbols-outlined text-[14px]">compare_arrows</span>Comparer</button>
                      </div>
                      <?php endif; ?>
                    </div>
                    <?php
                      // Même règle que le studio (consultant-studio-classic.php) :
                      // 'contenu_corrige' peut exister en base en chaîne vide (une
                      // correction enregistrée avant d'avoir été réellement
                      // rédigée) — on affiche alors le texte initial comme point
                      // de départ plutôt qu'un éditeur vide qui donne l'impression
                      // que tout a disparu.
                      $contenuCorrigeDefaut = !empty($actuelle['contenu_corrige']) ? $actuelle['contenu_corrige'] : $contenuInitialDefaut;
                    ?>
                    <div class="relative">
                      <textarea name="contenu_corrige" id="cible-<?php echo $type; ?>" class="ia-cible cible-<?php echo $type; ?> w-full h-64 p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars($contenuCorrigeDefaut); ?></textarea>
                      <div class="correction-diff-vue diff-vue-<?php echo $type; ?> hidden w-full h-64 p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm overflow-y-auto whitespace-pre-wrap"></div>
                    </div>
                    <div class="flex items-center justify-between gap-space-xs">
                      <p class="compteur-<?php echo $type; ?> font-body-sm text-[11px] text-secondary"></p>
                      <p class="font-body-sm text-[11px] text-secondary">**gras** · *italique* — repris dans le PDF final</p>
                    </div>

                    <?php if (count($historique) > 0): ?>
                    <button type="button" class="history-toggle self-start h-8 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors" data-target="historique-<?php echo $type; ?>"><span class="material-symbols-outlined text-[14px]">history</span>Historique (<?php echo count($historique); ?> version<?php echo count($historique) > 1 ? 's' : ''; ?>)</button>
                    <div id="historique-<?php echo $type; ?>" class="hidden flex-col gap-space-xs">
                      <?php foreach (array_reverse($historique) as $v): ?>
                      <div class="historique-entree border border-outline-variant/60 rounded-xl p-space-sm">
                        <div class="flex items-center justify-between gap-space-xs">
                          <p class="font-body-sm text-[11px] text-secondary font-bold"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($v['created_at']))); ?></p>
                          <button type="button" class="correction-restaurer font-label-md text-[11px] text-primary underline" data-type="<?php echo $type; ?>">Restaurer cette version</button>
                        </div>
                        <textarea class="hidden version-texte"><?php echo htmlspecialchars($v['contenu_corrige']); ?></textarea>
                        <p class="font-body-sm text-[12px] text-secondary whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars(mb_strimwidth($v['contenu_corrige'], 0, 240, '…'))); ?></p>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>

                  <!-- Zone 3 : assistant IA + contrôle qualité -->
                  <div class="overflow-y-auto p-space-md flex flex-col gap-space-md">
                    <?php if (in_array($type, ['cv', 'lettre'], true)): ?>
                    <div class="flex flex-col gap-space-xs">
                      <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">auto_awesome</span>Assistant IA</p>
                      <p class="font-body-sm text-[11px] text-secondary">L'IA ne fait jamais rien automatiquement : elle propose, vous relisez et décidez.</p>
                      <div class="flex flex-wrap items-center gap-space-xs">
                        <button type="button" class="ia-reperer self-start h-9 px-space-sm rounded-lg border border-outline-variant text-on-surface font-label-md text-label-md flex items-center gap-1 hover:bg-surface-container-low transition-colors" data-dossier="<?php echo $dossierId; ?>" data-type="<?php echo $type; ?>">
                          <span class="material-symbols-outlined text-[16px]">spellcheck</span>
                          <span class="ia-reperer-label">Repérer les erreurs</span>
                        </button>
                        <button type="button" class="ia-ameliorer self-start h-9 px-space-sm rounded-lg border border-primary-container text-primary font-label-md text-label-md flex items-center gap-1 hover:bg-primary-container/5 transition-colors" data-dossier="<?php echo $dossierId; ?>" data-type="<?php echo $type; ?>">
                          <span class="material-symbols-outlined text-[16px]">auto_awesome</span>
                          <span class="ia-label">Améliorer avec l'IA</span>
                        </button>
                      </div>
                      <span class="ia-erreur font-body-sm text-[11px] text-error hidden"></span>
                      <div class="ia-erreurs-liste flex flex-col gap-1"></div>

                      <div class="ia-proposition hidden flex-col gap-space-xs bg-primary-container/5 rounded-xl p-space-sm">
                        <p class="font-label-md text-label-md text-primary font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">rate_review</span>Proposition de l'IA</p>
                        <div class="ia-proposition-texte font-body-sm text-[12px] text-secondary whitespace-pre-wrap max-h-48 overflow-y-auto bg-surface-container-lowest rounded-lg p-space-xs"></div>
                        <div class="flex items-center gap-space-xs">
                          <button type="button" class="ia-proposition-utiliser h-8 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[11px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>Utiliser cette proposition</button>
                          <button type="button" class="ia-proposition-ignorer h-8 px-space-sm rounded-lg border border-outline-variant text-on-surface font-label-md text-[11px]">Ignorer</button>
                        </div>
                      </div>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($checklistCriteresParType[$type])):
                      $checklistActuelle = json_decode($actuelle['checklist_json'] ?? '', true) ?: [];
                    ?>
                    <div class="flex flex-col gap-space-xs border-t border-outline-variant/40 pt-space-sm">
                      <p class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-primary">checklist</span>Contrôle qualité</p>
                      <p class="font-body-sm text-[11px] text-secondary">À cocher manuellement après relecture — jamais rempli automatiquement.</p>
                      <?php foreach ($checklistCriteresParType[$type] as $cle => $libelle): ?>
                      <label class="flex items-center gap-space-xs font-body-sm text-[12px] text-on-surface">
                        <input type="checkbox" name="checklist[<?php echo $cle; ?>]" form="form-edit-<?php echo $type; ?>" <?php echo !empty($checklistActuelle[$cle]) ? 'checked' : ''; ?> class="w-4 h-4 rounded border-outline-variant text-primary">
                        <span><?php echo htmlspecialchars($libelle); ?></span>
                      </label>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="shrink-0 border-t border-outline-variant/50 px-space-lg py-space-sm flex flex-wrap items-center gap-space-xs">
                  <button type="submit" class="correction-submit-btn self-start h-10 px-space-md rounded-lg bg-primary-container text-white font-label-md text-label-md flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">save</span>Enregistrer cette version</button>
                  <?php if (in_array($type, ['cv', 'lettre'], true)): ?>
                  <button type="button" class="correction-previsualiser h-10 px-space-md rounded-lg border border-outline-variant text-on-surface font-label-md text-label-md flex items-center gap-1 hover:bg-surface-container-low transition-colors" data-type="<?php echo $type; ?>" data-dossier="<?php echo $dossierId; ?>"><span class="material-symbols-outlined text-[16px]">visibility</span>Prévisualiser le PDF</button>
                  <?php if ($livrableStatutParType[$type] === 'disponible'): ?>
                  <button type="submit" form="form-publier-<?php echo $type; ?>" class="h-10 px-space-md rounded-lg border border-primary-container text-primary font-label-md text-label-md flex items-center gap-1 hover:bg-primary-container/5 transition-colors" onclick="return confirm('Publier une nouvelle version au candidat à partir de la dernière correction enregistrée ?');"><span class="material-symbols-outlined text-[16px]">publish</span>Publier une nouvelle version</button>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
          <?php if (in_array($type, ['cv', 'lettre'], true)): ?>
          <?php if ($livrableStatutParType[$type] === 'disponible'): ?>
          <form method="post" id="form-publier-<?php echo $type; ?>" class="hidden">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="publier_version_depuis_texte">
            <input type="hidden" name="document_type" value="<?php echo $type; ?>">
          </form>
          <?php endif; ?>
          <form method="post" action="apercu-correction-pdf.php" target="_blank" id="form-apercu-<?php echo $type; ?>" class="hidden">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="dossier_id" value="<?php echo $dossierId; ?>">
            <input type="hidden" name="document_type" value="<?php echo $type; ?>">
            <input type="hidden" name="contenu_corrige" value="">
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php foreach (['offre' => "Offre d'emploi ciblée", 'portfolio' => 'Portfolio ou lien professionnel'] as $slot => $label): ?>
        <div class="doc-panel <?php echo $onglet !== $slot ? 'hidden' : ''; ?>" data-panel="<?php echo $slot; ?>">
          <?php if (!$documentsParSlot[$slot]): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun document « <?php echo htmlspecialchars($label); ?> » transmis pour le moment.</p>
          <?php else: ?>
          <div class="flex flex-col gap-space-sm">
            <?php foreach ($documentsParSlot[$slot] as $d): ?>
            <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
              <div class="flex items-center gap-space-xs min-w-0">
                <div class="w-9 h-9 rounded bg-error/10 text-error flex items-center justify-center font-label-md text-[10px] font-bold shrink-0">DOC</div>
                <div class="min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo $d['taille_ko']; ?> Ko · reçu le <?php echo htmlspecialchars(date('d/m/Y', strtotime($d['uploaded_at']))); ?></p></div>
              </div>
              <a href="classic-document-telecharger.php?id=<?php echo $d['id']; ?>" target="_blank" rel="noopener" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir</a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="flex flex-col gap-space-xs">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Annotations du consultant</p>
          <?php if (!$annotations): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune annotation pour le moment.</p>
          <?php endif; ?>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
            <?php foreach ($annotations as $a): $style = $annotationStyles[$a['categorie']]; ?>
            <div class="rounded-xl border border-outline-variant/60 p-space-sm flex flex-col gap-space-2xs">
              <span class="inline-flex items-center gap-1 w-fit px-space-xs py-0.5 rounded-full <?php echo $style['couleur']; ?> font-label-md text-[11px] font-bold"><span class="material-symbols-outlined text-[13px]"><?php echo $style['icone']; ?></span><?php echo htmlspecialchars($style['label']); ?></span>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($a['texte']); ?></p>
            </div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="flex flex-col sm:flex-row gap-space-xs pt-space-xs">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="ajouter_annotation">
            <select name="categorie_annotation" class="h-10 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <option value="contenu">Amélioration de contenu</option>
              <option value="competence">Compétence ajoutée</option>
              <option value="style">Style et clarté</option>
            </select>
            <input type="text" name="texte_annotation" placeholder="Décrivez l'annotation..." class="flex-1 h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
            <button type="submit" class="h-10 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center justify-center gap-1"><span class="material-symbols-outlined text-[14px]">add</span>Ajouter</button>
          </form>
        </div>

        <?php if (!empty($qualification['message_libre'])): ?>
        <div class="rounded-xl bg-primary-container/5 p-space-sm">
          <p class="font-label-md text-label-md text-on-surface font-bold">Message du candidat</p>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($qualification['message_libre']); ?></p>
        </div>
        <?php endif; ?>

        <form method="post" id="notes" class="flex flex-col gap-space-xs scroll-mt-20">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="enregistrer_notes">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Notes internes du consultant</p>
          <textarea name="notes_consultant" rows="4" placeholder="Vos observations sur ce dossier (non visibles par le candidat)..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars($dossier['notes_consultant'] ?? ''); ?></textarea>
          <button type="submit" class="self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[16px]">save</span>Enregistrer les notes</button>
        </form>
      </div>

      <div class="flex flex-col gap-space-sm">
        <div id="preparation-entretien" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">groups</span></span>2. Préparation de l'entretien</h2>
          <?php foreach ($preparationEntretien as $p): ?>
          <div class="flex items-center gap-space-xs py-space-xs -mx-space-xs px-space-xs rounded-lg">
            <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $p['icone']; ?></span></div>
            <div class="flex-1"><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($p['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($p['texte']); ?></p></div>
          </div>
          <?php endforeach; ?>

          <div class="bg-surface-container-low rounded-xl p-space-sm flex flex-col gap-space-2xs mt-space-xs">
            <p class="font-label-md text-label-md text-on-surface font-bold">Prochaine simulation planifiée</p>
            <?php if ($rdv): ?>
            <div class="flex flex-wrap items-center justify-between gap-space-xs">
              <span class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">event</span><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure'])) . ' · ' . ($formatLabels[$rdv['format']] ?? '')); ?></span>
            </div>
            <?php else: ?>
            <p class="font-body-sm text-[12px] text-secondary">Aucun rendez-vous planifié pour le moment.</p>
            <?php endif; ?>
          </div>

          <button type="button" class="edit-toggle self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors" data-target="edit-simulation"><span class="material-symbols-outlined text-[16px]">event</span>Planifier une simulation</button>
          <form method="post" id="edit-simulation" class="hidden flex-col gap-space-sm bg-surface-container-low rounded-xl p-space-sm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="planifier_simulation">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-xs">
              <input type="date" name="jour_simulation" required class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <input type="time" name="heure_simulation" required class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <select name="format_simulation" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                <option value="ligne">En ligne</option>
                <option value="presentiel">En présentiel</option>
                <option value="hybride">Hybride</option>
              </select>
            </div>
            <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-label-md flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">check</span>Planifier</button>
          </form>
        </div>

        <div id="livrables" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-green-100 text-green-700 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">card_giftcard</span></span>Livrables</h2>
          <?php if (!$livrablesExistants): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun livrable pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($livrablesExistants as $l): ?>
          <div class="flex flex-col gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
            <div class="flex items-center justify-between gap-space-xs flex-wrap">
              <div class="flex items-center gap-space-xs min-w-0">
                <div class="w-9 h-9 rounded bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">description</span></div>
                <div class="min-w-0">
                  <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?> <span class="font-body-sm text-[11px] text-secondary font-normal">v<?php echo (int) $l['version']; ?></span></p>
                  <p class="font-body-sm text-[11px] text-secondary">
                    <?php echo $l['statut'] === 'disponible' ? 'Envoyé le ' . htmlspecialchars(date('d/m/Y', strtotime($l['envoye_le'] ?: $l['created_at']))) : 'Déposé le ' . htmlspecialchars(date('d/m/Y', strtotime($l['created_at']))) . ' — pas encore envoyé au candidat'; ?>
                    <?php if ($l['statut'] === 'disponible'): ?> · <?php echo (int) $l['consultations_count']; ?> consultation(s)<?php endif; ?>
                    <?php if ($l['categorie'] && isset($livrableCategoriesLabels[$l['categorie']])): ?> · <?php echo htmlspecialchars($livrableCategoriesLabels[$l['categorie']]); ?><?php endif; ?>
                  </p>
                </div>
              </div>
              <?php if ($l['statut'] === 'disponible'): ?>
              <span class="shrink-0 font-label-md text-[11px] font-bold px-space-xs py-0.5 rounded-full bg-green-100 text-green-700">Disponible</span>
              <?php else: ?>
              <span class="shrink-0 font-label-md text-[11px] font-bold px-space-xs py-0.5 rounded-full bg-orange-100 text-orange-700">En préparation</span>
              <?php endif; ?>
            </div>
            <?php if ($l['description']): ?><p class="font-body-sm text-[12px] text-secondary">« <?php echo htmlspecialchars($l['description']); ?> »</p><?php endif; ?>
            <div class="flex items-center gap-1 flex-wrap">
              <?php if ($l['statut'] === 'disponible'): ?>
              <a href="classic-livrable-telecharger.php?id=<?php echo $l['id']; ?>" target="_blank" rel="noopener" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir</a>
              <button type="button" onclick="var z=document.getElementById('remplacer-<?php echo $l['id']; ?>');z.classList.toggle('hidden');z.classList.toggle('flex');" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface">Publier une nouvelle version</button>
              <?php else: ?>
              <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="envoyer_livrable"><input type="hidden" name="livrable_id" value="<?php echo $l['id']; ?>">
                <button type="submit" class="h-8 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[14px]">send</span>Envoyer au candidat</button>
              </form>
              <?php endif; ?>
            </div>
            <?php if ($l['statut'] === 'disponible'): ?>
            <form method="post" enctype="multipart/form-data" id="remplacer-<?php echo $l['id']; ?>" class="hidden flex-col gap-space-2xs pt-space-xs border-t border-outline-variant/40">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="remplacer_livrable"><input type="hidden" name="livrable_id" value="<?php echo $l['id']; ?>">
              <input type="file" name="fichier_livrable" class="font-body-sm text-[12px] text-secondary">
              <button type="submit" class="self-start h-8 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px]">Publier (version <?php echo (int) $l['version'] + 1; ?>)</button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="ajouter_livrable">
            <p class="font-label-md text-label-md text-on-surface font-bold">Déposer un nouveau livrable</p>
            <p class="font-body-sm text-[11px] text-secondary">Le fichier reste invisible pour le candidat tant que vous ne l'envoyez pas explicitement (bouton « Envoyer au candidat » ci-dessus).</p>
            <input type="text" name="titre_livrable" placeholder="Ex. : Rapport d'accompagnement" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <select name="categorie_livrable" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
              <option value="">Sans catégorie</option>
              <?php foreach ($livrableCategoriesLabels as $val => $label): ?>
              <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
              <?php endforeach; ?>
            </select>
            <textarea name="description_livrable" rows="2" placeholder="Description visible par le candidat : ce que contient ce document et pourquoi il lui est remis..." class="p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-[13px] resize-none"></textarea>
            <input type="text" name="prochaine_etape_livrable" placeholder="Prochaine étape recommandée (facultatif)" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <input type="file" name="fichier_livrable" class="font-body-sm text-[12px] text-secondary">
            <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-label-md flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">upload</span>Déposer</button>
          </form>
        </div>

        <div id="documents-verification" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm scroll-mt-20">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">fact_check</span></span>Vérification des documents</h2>
          <?php foreach ($documentsParSlotStructure as $slot => $d):
            $info = $slotsDocumentsLabels[$slot];
            $actuel = $d['actuel'];
          ?>
          <div class="flex flex-col gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
            <div class="flex items-center justify-between gap-space-xs flex-wrap">
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($info['label']); ?><?php echo $info['obligatoire'] ? '' : ' <span class="font-body-sm text-[11px] text-secondary font-normal">(facultatif)</span>'; ?></p>
                <?php if ($actuel): ?>
                <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($actuel['nom_original']); ?> · reçu le <?php echo htmlspecialchars(date('d/m/Y', strtotime($actuel['uploaded_at']))); ?><?php if ($actuel['description']): ?> · « <?php echo htmlspecialchars($actuel['description']); ?> »<?php endif; ?></p>
                <?php else: ?>
                <p class="font-body-sm text-[11px] text-secondary">Non transmis.</p>
                <?php endif; ?>
              </div>
              <?php if ($actuel): [$labelStatutDoc, $classeStatutDoc] = $statutsDocumentsLabels[$actuel['statut']]; ?>
              <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeStatutDoc; ?>"><?php echo htmlspecialchars($labelStatutDoc); ?></span>
              <?php endif; ?>
            </div>
            <?php if ($actuel): ?>
            <div class="flex items-center gap-1 flex-wrap">
              <a href="classic-document-telecharger.php?id=<?php echo $actuel['id']; ?>" target="_blank" rel="noopener" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir</a>
              <?php if ($actuel['statut'] !== 'valide'): ?>
              <form method="post" onsubmit="return confirm('Valider ce document ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="valider_document"><input type="hidden" name="document_id" value="<?php echo $actuel['id']; ?>">
                <button type="submit" class="h-8 px-space-sm rounded-lg bg-green-600 text-white font-label-md text-[11px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>Valider</button>
              </form>
              <?php endif; ?>
              <?php if ($actuel['statut'] !== 'a_corriger'): ?>
              <button type="button" onclick="var f=document.getElementById('motifdoc-<?php echo $actuel['id']; ?>');f.classList.toggle('hidden');f.classList.toggle('flex');" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface">Demander une correction</button>
              <?php endif; ?>
            </div>
            <?php if ($actuel['statut'] === 'a_corriger' && $actuel['motif_correction']): ?>
            <p class="font-body-sm text-[11px] text-secondary italic">Motif envoyé : « <?php echo htmlspecialchars($actuel['motif_correction']); ?> »</p>
            <?php endif; ?>
            <form method="post" id="motifdoc-<?php echo $actuel['id']; ?>" class="hidden flex-col gap-space-2xs">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="corriger_document"><input type="hidden" name="document_id" value="<?php echo $actuel['id']; ?>">
              <textarea name="motif_correction_document" rows="2" placeholder="Expliquez ce qui doit être corrigé..." class="p-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]"></textarea>
              <button type="submit" class="self-start h-8 px-space-sm rounded-lg bg-orange-600 text-white font-label-md text-[11px]">Envoyer</button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">checklist</span></span>Plan d'action du candidat</h2>
          <?php if (!$actionsExistantes): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune action assignée pour le moment.</p>
          <?php endif; ?>
          <?php if ($erreurAction): ?>
          <p class="font-body-sm text-body-sm text-error"><?php echo htmlspecialchars($erreurAction); ?></p>
          <?php endif; ?>
          <?php foreach ($actionsExistantes as $a):
            $priBadge = $a['priorite'] === 'haute' ? '<span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-error-container/40 text-error">Priorité haute</span>' : '';
          ?>
          <div class="flex flex-col gap-space-2xs bg-surface-container-low rounded-xl p-space-sm">
            <div class="flex items-center justify-between gap-space-xs">
              <div class="min-w-0">
                <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($a['titre']); ?></p>
                <p class="font-body-sm text-[11px] text-secondary"><?php echo $a['echeance'] ? 'Échéance : ' . htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : 'Sans échéance'; ?></p>
              </div>
              <div class="shrink-0 flex items-center gap-1">
                <?php echo $priBadge; ?>
                <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $a['statut'] === 'a_verifier' ? 'bg-orange-100 text-orange-700' : ($a['statut'] === 'termine' ? 'bg-green-100 text-green-700' : 'bg-surface-container-lowest text-secondary border border-outline-variant'); ?>"><?php echo htmlspecialchars($actionStatutLabels[$a['statut']] ?? $a['statut']); ?></span>
              </div>
            </div>
            <?php if ($a['description']): ?><p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($a['description']); ?></p><?php endif; ?>
            <?php if ($a['statut'] === 'a_verifier'): ?>
            <div class="flex items-center gap-1 pt-space-2xs border-t border-outline-variant/40">
              <form method="post" onsubmit="return confirm('Valider cette action comme terminée ?');">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="valider_action"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                <button type="submit" class="h-8 px-space-sm rounded-lg bg-green-600 text-white font-label-md text-[11px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>Valider</button>
              </form>
              <button type="button" onclick="var f=document.getElementById('correction-<?php echo $a['id']; ?>');f.classList.toggle('hidden');f.classList.toggle('flex');" class="h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface">Renvoyer en correction</button>
            </div>
            <form method="post" id="correction-<?php echo $a['id']; ?>" class="hidden flex-col gap-space-2xs">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="renvoyer_action_correction"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
              <textarea name="motif_correction" rows="2" placeholder="Expliquez pourquoi cette action n'est pas encore validée..." class="p-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]"></textarea>
              <button type="submit" class="self-start h-8 px-space-sm rounded-lg bg-orange-600 text-white font-label-md text-[11px]">Envoyer</button>
            </form>
            <?php elseif ($a['motif_correction']): ?>
            <p class="font-body-sm text-[11px] text-secondary italic">Dernier commentaire envoyé au candidat : « <?php echo htmlspecialchars($a['motif_correction']); ?> »</p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <form method="post" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="ajouter_action">
            <p class="font-label-md text-label-md text-on-surface font-bold">Assigner une nouvelle action</p>
            <input type="text" name="titre_action" placeholder="Ex. : Préparer la simulation d'entretien" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <textarea name="description_action" rows="2" placeholder="Instructions, motif, critères de réussite... (visible par le candidat)" class="p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
            <div class="grid grid-cols-2 gap-space-xs">
              <input type="date" name="echeance_action" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
              <select name="priorite_action" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
                <option value="normale">Priorité normale</option>
                <option value="haute">Priorité haute</option>
              </select>
            </div>
            <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-label-md flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">add</span>Assigner au candidat</button>
          </form>
        </div>
      </div>
    </div>

    <div id="messagerie" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md scroll-mt-20">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">chat</span></span>Messagerie avec le candidat</h2>
      <div class="flex flex-col gap-space-sm max-h-[24rem] overflow-y-auto pr-space-2xs" id="messageThread">
        <?php if (!$filMessages): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-md">Aucun message pour le moment.</p>
        <?php endif; ?>
        <?php foreach ($filMessages as $m): $estConsultant = $m['expediteur_type'] === 'consultant'; ?>
        <div class="flex flex-col <?php echo $estConsultant ? 'items-end' : 'items-start'; ?>">
          <div class="max-w-[80%] rounded-xl p-space-sm <?php echo $estConsultant ? 'bg-primary-container text-white' : 'bg-surface-container-low text-on-surface'; ?>">
            <p class="font-body-sm text-body-sm whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars($m['corps'])); ?></p>
          </div>
          <span class="font-body-sm text-[11px] text-secondary mt-1"><?php echo $estConsultant ? 'Vous' : htmlspecialchars($dossier['prenom']); ?> · <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($m['created_at']))); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <form method="post" class="flex items-end gap-space-sm pt-space-sm border-t border-outline-variant/60">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="envoyer_message">
        <textarea name="corps_message" rows="2" required placeholder="Répondre au candidat..." class="flex-1 p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
        <button type="submit" class="h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors shrink-0">
          <span>Envoyer</span>
          <span class="material-symbols-outlined text-[18px]">send</span>
        </button>
      </form>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">hub</span></span>3. Workflow du Pack Classic</h2>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-space-sm">
        <?php foreach ($workflow as $i => $w): ?>
        <div class="flex flex-col items-center text-center gap-space-xs relative">
          <?php if ($i > 0): ?><div class="hidden md:block absolute top-4 right-1/2 w-full h-0 border-t-2 border-dashed border-outline-variant" style="left:-50%"></div><?php endif; ?>
          <div class="relative z-10 w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold
            <?php
            if ($w['statut'] === 'done') echo 'bg-primary-container text-white';
            elseif ($w['statut'] === 'current') echo 'bg-primary-container text-white ring-4 ring-primary-container/20';
            else echo 'bg-surface-container text-secondary';
            ?>"><?php echo $w['statut'] === 'done' ? '<span class="material-symbols-outlined text-[18px]">check</span>' : ($i + 1); ?></div>
          <span class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($w['titre']); ?></span>
          <span class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($w['texte']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex flex-col gap-space-sm">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">rocket_launch</span></span>4. Actions consultant</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <form method="post" onsubmit="return confirm('Clôturer ce dossier ? Le candidat verra son parcours marqué comme terminé.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="valider_corrections">
          <button type="submit" <?php echo $dossierValide ? 'disabled' : ''; ?> class="w-full h-14 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-xs shadow-sm hover:opacity-90 transition-opacity px-space-md disabled:opacity-50">
            <span class="material-symbols-outlined text-[20px]">task_alt</span>
            <span class="flex flex-col items-start leading-tight text-left"><span><?php echo $dossierValide ? 'Dossier clôturé' : 'Valider les corrections'; ?></span><span class="font-body-sm text-[11px] font-normal text-white/80">Clôture le dossier du candidat</span></span>
          </button>
        </form>
        <a href="#preparation-entretien" class="h-14 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-xs hover:bg-surface-container-low transition-colors px-space-md">
          <span class="material-symbols-outlined text-[20px] text-primary">event</span>
          <span class="flex flex-col items-start leading-tight text-left"><span>Planifier la simulation</span><span class="font-body-sm text-[11px] font-normal text-secondary">Choisir une date et un créneau</span></span>
        </a>
        <a href="#livrables" class="h-14 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-xs hover:bg-surface-container-low transition-colors px-space-md">
          <span class="material-symbols-outlined text-[20px] text-primary">send</span>
          <span class="flex flex-col items-start leading-tight text-left"><span>Envoyer les livrables</span><span class="font-body-sm text-[11px] font-normal text-secondary">Envoyer un document au candidat</span></span>
        </a>
        <a href="#notes" class="h-14 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-xs hover:bg-surface-container-low transition-colors px-space-md">
          <span class="material-symbols-outlined text-[20px] text-primary">folder</span>
          <span class="flex flex-col items-start leading-tight text-left"><span>Mettre à jour le dossier</span><span class="font-body-sm text-[11px] font-normal text-secondary">Ajouter notes et commentaires</span></span>
        </a>
      </div>
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
<script>
(function() {
  document.querySelectorAll('.doc-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.doc-tab').forEach(function(t) {
        t.classList.remove('bg-primary-container', 'text-white', 'font-bold', 'border-outline-variant');
        t.classList.add('border', 'border-outline-variant', 'text-on-surface');
      });
      tab.classList.remove('border', 'border-outline-variant', 'text-on-surface');
      tab.classList.add('bg-primary-container', 'text-white', 'font-bold');
      var target = tab.getAttribute('data-tab');
      document.querySelectorAll('.doc-panel').forEach(function(panel) {
        panel.classList.toggle('hidden', panel.getAttribute('data-panel') !== target);
      });
    });
  });

  document.querySelectorAll('.edit-toggle, .history-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var target = document.getElementById(btn.getAttribute('data-target'));
      if (!target) return;
      var isHidden = target.classList.contains('hidden');
      target.classList.toggle('hidden', !isHidden);
      target.classList.toggle('flex', isHidden);
    });
  });

  document.querySelectorAll('.ia-ameliorer').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var form = btn.closest('form');
      if (!form) return;
      var initial = form.querySelector('textarea[name="contenu_initial"]');
      var cible = form.querySelector('.ia-cible');
      var erreurEl = form.querySelector('.ia-erreur');
      var labelEl = btn.querySelector('.ia-label');
      var csrf = form.querySelector('input[name="_csrf"]');
      var contenu = (cible && cible.value.trim()) || (initial ? initial.value.trim() : '');

      if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] hidden'; erreurEl.textContent = ''; }
      if (!contenu) {
        if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-error'; erreurEl.textContent = 'Renseignez d\'abord la version initiale ci-dessus.'; }
        return;
      }

      btn.disabled = true;
      var labelAvant = labelEl ? labelEl.textContent : '';
      if (labelEl) labelEl.textContent = 'Amélioration en cours...';

      var donnees = new FormData();
      donnees.append('dossier_id', btn.getAttribute('data-dossier'));
      donnees.append('document_type', btn.getAttribute('data-type'));
      donnees.append('contenu', contenu);
      if (csrf) donnees.append('_csrf', csrf.value);

      fetch('ameliorer-document-ia.php', { method: 'POST', body: donnees })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) {
            if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-error'; erreurEl.textContent = data.error; }
            return;
          }
          // La proposition n'est jamais appliquée directement : elle
          // s'affiche à côté pour relecture, le consultant choisit de
          // l'utiliser ou de l'ignorer.
          var propositionWrap = form.querySelector('.ia-proposition');
          var propositionTexte = form.querySelector('.ia-proposition-texte');
          if (propositionWrap && propositionTexte) {
            propositionTexte.textContent = data.texte;
            propositionWrap.classList.remove('hidden');
            propositionWrap.classList.add('flex');
          }
        })
        .catch(function() {
          if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-error'; erreurEl.textContent = 'Erreur réseau, merci de réessayer.'; }
        })
        .finally(function() {
          btn.disabled = false;
          if (labelEl) labelEl.textContent = labelAvant;
        });
    });
  });

  // Synchronise la zone éditable (affichage + soulignement des erreurs)
  // avec le vrai <textarea> caché réellement envoyé au formulaire.
  document.querySelectorAll('.ia-source-editable').forEach(function(editable) {
    var hidden = editable.parentElement ? editable.parentElement.querySelector('.ia-source') : null;
    if (!hidden) return;
    editable.addEventListener('input', function() {
      hidden.value = editable.textContent;
    });
  });

  function escapeHtmlIA(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Doit rester synchronisé avec classic_categories_erreurs_ia() (includes/ai.php).
  var CATEGORIES_ERREURS_IA = {
    langue:      ['Faute de langue', 'bg-red-100 text-red-700'],
    repetition:  ['Répétition', 'bg-orange-100 text-orange-700'],
    ambiguite:   ['Formulation ambiguë', 'bg-yellow-100 text-yellow-800'],
    structure:   ['Problème de structure', 'bg-blue-100 text-blue-700'],
    incoherence: ['Incohérence', 'bg-purple-100 text-purple-700'],
  };

  document.querySelectorAll('.ia-proposition-utiliser').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var wrap = btn.closest('.ia-proposition');
      var form = btn.closest('form');
      if (!wrap || !form) return;
      var texteEl = wrap.querySelector('.ia-proposition-texte');
      var cible = form.querySelector('.ia-cible');
      if (cible && texteEl) {
        cible.value = texteEl.textContent;
        cible.dispatchEvent(new Event('input', { bubbles: true }));
      }
      wrap.classList.add('hidden');
      wrap.classList.remove('flex');
    });
  });
  document.querySelectorAll('.ia-proposition-ignorer').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var wrap = btn.closest('.ia-proposition');
      if (wrap) { wrap.classList.add('hidden'); wrap.classList.remove('flex'); }
    });
  });

  // --- Atelier de correction : fermeture avec avertissement si modifications non enregistrées. ---
  document.querySelectorAll('.correction-modal').forEach(function(modal) {
    var type = modal.getAttribute('data-type');
    modal.dataset.dirty = '0';

    document.querySelectorAll('.edit-toggle[data-target="edit-' + type + '"]').forEach(function(opener) {
      opener.addEventListener('click', function() { modal.dataset.dirty = '0'; });
    });

    modal.querySelectorAll('textarea, [contenteditable="true"], input[type="checkbox"]').forEach(function(el) {
      el.addEventListener('input', function() { modal.dataset.dirty = '1'; majIndicateurSauvegarde(type); });
      el.addEventListener('change', function() { modal.dataset.dirty = '1'; majIndicateurSauvegarde(type); });
    });

    function fermerModale() {
      if (modal.dataset.dirty === '1' && !confirm('Fermer sans enregistrer ? Les modifications non enregistrées seront perdues.')) return;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      modal.dataset.dirty = '0';
      majIndicateurSauvegarde(type);
    }
    modal.querySelectorAll('.edit-close-btn').forEach(function(btn) { btn.addEventListener('click', fermerModale); });
    modal.addEventListener('click', function(e) { if (e.target === modal) fermerModale(); });
  });

  function majIndicateurSauvegarde(type) {
    var el = document.getElementById('save-indicateur-' + type);
    var modal = document.getElementById('edit-' + type);
    if (!el || !modal) return;
    if (modal.dataset.dirty === '1') {
      el.className = 'font-semibold text-orange-600';
      el.textContent = '· Modifications non enregistrées';
    } else {
      el.className = 'font-semibold';
      el.textContent = el.getAttribute('data-saved-text') || '';
    }
  }

  // Double-soumission : désactive le bouton pendant l'enregistrement.
  document.querySelectorAll('[id^="form-edit-"]').forEach(function(form) {
    form.addEventListener('submit', function() {
      var btn = form.querySelector('.correction-submit-btn');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined text-[16px]">hourglass_top</span>Enregistrement...';
      }
    });
  });

  // --- Compteur mots/caractères de la version corrigée. ---
  document.querySelectorAll('.ia-cible').forEach(function(ta) {
    var m = ta.id.match(/^cible-(.+)$/);
    if (!m) return;
    var type = m[1];
    var compteur = document.querySelector('.compteur-' + type);
    function majCompteur() {
      var texte = ta.value.trim();
      var nbMots = texte ? texte.split(/\s+/).length : 0;
      if (compteur) compteur.textContent = nbMots + ' mot(s) · ' + ta.value.length + ' caractère(s)';
    }
    ta.addEventListener('input', majCompteur);
    majCompteur();
  });

  // --- Mise en forme : enrobe la sélection de **gras** ou *italique*. ---
  document.querySelectorAll('.correction-fmt').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var type = btn.getAttribute('data-type');
      var marker = btn.getAttribute('data-marker');
      var ta = document.getElementById('cible-' + type);
      if (!ta) return;
      var start = ta.selectionStart, end = ta.selectionEnd;
      var avant = ta.value.slice(0, start), selection = ta.value.slice(start, end), apres = ta.value.slice(end);
      if (selection) {
        ta.value = avant + marker + selection + marker + apres;
        ta.selectionStart = start + marker.length;
        ta.selectionEnd = end + marker.length;
      } else {
        ta.value = avant + marker + marker + apres;
        ta.selectionStart = ta.selectionEnd = start + marker.length;
      }
      ta.focus();
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  // --- Copier la version corrigée dans le presse-papiers. ---
  document.querySelectorAll('.correction-copier').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var type = btn.getAttribute('data-type');
      var ta = document.getElementById('cible-' + type);
      if (!ta || !navigator.clipboard) return;
      navigator.clipboard.writeText(ta.value).then(function() {
        var original = btn.innerHTML;
        btn.innerHTML = '<span class="material-symbols-outlined text-[14px]">check</span>';
        setTimeout(function() { btn.innerHTML = original; }, 1200);
      }).catch(function() {});
    });
  });

  // --- Comparaison mot à mot (diff) entre version initiale et version corrigée. ---
  function diffMotsCorrection(a, b) {
    var motsA = a.split(/(\s+)/);
    var motsB = b.split(/(\s+)/);
    var n = motsA.length, m = motsB.length;
    var dp = Array.from({ length: n + 1 }, function () { return new Array(m + 1).fill(0); });
    for (var i = n - 1; i >= 0; i--) {
      for (var j = m - 1; j >= 0; j--) {
        dp[i][j] = motsA[i] === motsB[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
      }
    }
    var i2 = 0, j2 = 0;
    var segments = [];
    while (i2 < n && j2 < m) {
      if (motsA[i2] === motsB[j2]) { segments.push({ type: 'egal', texte: motsA[i2] }); i2++; j2++; }
      else if (dp[i2 + 1][j2] >= dp[i2][j2 + 1]) { segments.push({ type: 'suppr', texte: motsA[i2] }); i2++; }
      else { segments.push({ type: 'ajout', texte: motsB[j2] }); j2++; }
    }
    while (i2 < n) { segments.push({ type: 'suppr', texte: motsA[i2] }); i2++; }
    while (j2 < m) { segments.push({ type: 'ajout', texte: motsB[j2] }); j2++; }
    return segments;
  }

  document.querySelectorAll('.correction-diff-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var type = btn.getAttribute('data-type');
      var ta = document.getElementById('cible-' + type);
      var vue = document.querySelector('.diff-vue-' + type);
      var editable = document.querySelector('.source-editable-' + type);
      var source = document.getElementById('source-hidden-' + type);
      if (!ta || !vue) return;
      var estAffiche = !vue.classList.contains('hidden');
      if (estAffiche) {
        vue.classList.add('hidden');
        ta.classList.remove('hidden');
        btn.classList.remove('bg-primary-container', 'text-white');
      } else {
        var texteInitial = editable ? editable.textContent : (source ? source.value : '');
        var segments = diffMotsCorrection(texteInitial || '', ta.value || '');
        var html = '';
        segments.forEach(function(s) {
          var t = s.texte.replace(/&/g, '&amp;').replace(/</g, '&lt;');
          if (s.type === 'egal') html += t;
          else if (s.type === 'suppr') html += '<span class="correction-diff-suppr">' + t + '</span>';
          else html += '<span class="correction-diff-ajout">' + t + '</span>';
        });
        vue.innerHTML = html || '<span class="text-secondary">Rien à comparer pour le moment.</span>';
        ta.classList.add('hidden');
        vue.classList.remove('hidden');
        btn.classList.add('bg-primary-container', 'text-white');
      }
    });
  });

  // --- Restaurer une version de l'historique dans l'éditeur (ne sauvegarde pas tout seul). ---
  document.querySelectorAll('.correction-restaurer').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var type = btn.getAttribute('data-type');
      var entree = btn.closest('.historique-entree');
      var modal = document.getElementById('edit-' + type);
      if (!entree) return;
      if (modal && modal.dataset.dirty === '1' && !confirm('Remplacer le contenu actuel par cette version ? Les modifications non enregistrées seront perdues.')) return;
      var texteEl = entree.querySelector('.version-texte');
      var cible = document.getElementById('cible-' + type);
      if (texteEl && cible) {
        cible.value = texteEl.value;
        cible.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });

  // --- Aperçu PDF à la demande (ne sauvegarde rien). ---
  document.querySelectorAll('.correction-previsualiser').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var type = btn.getAttribute('data-type');
      var cible = document.getElementById('cible-' + type);
      var form = document.getElementById('form-apercu-' + type);
      if (!cible || !form) return;
      if (!cible.value.trim()) { alert('Rien à prévisualiser : la version corrigée est vide.'); return; }
      form.querySelector('[name="contenu_corrige"]').value = cible.value;
      form.submit();
    });
  });

  // --- Vue agrandie du document original (hauteur de l'aperçu, sans dépendre de classes Tailwind générées dynamiquement). ---
  document.querySelectorAll('.doc-zoom-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var target = document.getElementById(btn.getAttribute('data-target'));
      if (!target) return;
      var agrandi = target.getAttribute('data-agrandi') === '1';
      if (agrandi) {
        target.style.height = '';
        target.setAttribute('data-agrandi', '0');
        btn.innerHTML = '<span class="material-symbols-outlined text-[14px]">zoom_in</span>Vue agrandie';
      } else {
        target.style.height = '65vh';
        target.setAttribute('data-agrandi', '1');
        btn.innerHTML = '<span class="material-symbols-outlined text-[14px]">zoom_out</span>Vue normale';
      }
    });
  });

  function surlignerErreursIA(editable, texte, erreurs) {
    if (!erreurs.length) { editable.textContent = texte; return; }
    var segments = [{ texte: texte, marque: false }];
    erreurs.forEach(function(err) {
      var nouveaux = [];
      segments.forEach(function(seg) {
        if (seg.marque) { nouveaux.push(seg); return; }
        var idx = seg.texte.indexOf(err.extrait);
        if (idx === -1) { nouveaux.push(seg); return; }
        if (idx > 0) nouveaux.push({ texte: seg.texte.slice(0, idx), marque: false });
        nouveaux.push({ texte: seg.texte.slice(idx, idx + err.extrait.length), marque: true, probleme: err.probleme });
        var reste = seg.texte.slice(idx + err.extrait.length);
        if (reste) nouveaux.push({ texte: reste, marque: false });
      });
      segments = nouveaux;
    });
    editable.innerHTML = segments.map(function(seg) {
      return seg.marque
        ? '<span class="ia-erreur-surlignee" title="' + escapeHtmlIA(seg.probleme) + '">' + escapeHtmlIA(seg.texte) + '</span>'
        : escapeHtmlIA(seg.texte);
    }).join('');
  }

  document.querySelectorAll('.ia-reperer').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var form = btn.closest('form');
      if (!form) return;
      var hidden = form.querySelector('.ia-source');
      var editable = form.querySelector('.ia-source-editable');
      var erreurEl = form.querySelector('.ia-erreur');
      var labelEl = btn.querySelector('.ia-reperer-label');
      var csrf = form.querySelector('input[name="_csrf"]');
      var texte = editable ? editable.textContent : (hidden ? hidden.value : '');

      if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] hidden'; erreurEl.textContent = ''; }
      if (!texte || !texte.trim()) {
        if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-error'; erreurEl.textContent = 'Renseignez d\'abord la version initiale ci-dessus.'; }
        return;
      }

      btn.disabled = true;
      var labelAvant = labelEl ? labelEl.textContent : '';
      if (labelEl) labelEl.textContent = 'Analyse en cours...';

      var donnees = new FormData();
      donnees.append('dossier_id', btn.getAttribute('data-dossier'));
      donnees.append('document_type', btn.getAttribute('data-type'));
      donnees.append('contenu', texte);
      if (csrf) donnees.append('_csrf', csrf.value);

      fetch('detecter-erreurs-ia.php', { method: 'POST', body: donnees })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) {
            if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-error'; erreurEl.textContent = data.error; }
            return;
          }
          var erreurs = data.erreurs || [];
          if (editable) surlignerErreursIA(editable, texte, erreurs);
          if (hidden) hidden.value = texte;
          if (erreurEl) {
            if (!erreurs.length) {
              erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-green-700';
              erreurEl.textContent = 'Aucun problème notable détecté.';
            } else {
              erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-orange-600';
              erreurEl.textContent = erreurs.length + ' passage(s) soulignés en rouge dans la version initiale — survolez-les pour voir le détail.';
            }
          }
          var listeEl = form.querySelector('.ia-erreurs-liste');
          if (listeEl) {
            if (!erreurs.length) {
              listeEl.innerHTML = '';
            } else {
              var parCategorie = {};
              erreurs.forEach(function(e) {
                var cat = e.categorie || 'langue';
                (parCategorie[cat] = parCategorie[cat] || []).push(e);
              });
              var html = '';
              Object.keys(parCategorie).forEach(function(cat) {
                var info = CATEGORIES_ERREURS_IA[cat] || ['Autre', 'bg-surface-container text-secondary'];
                html += '<div class="flex flex-col gap-0.5 mt-1">';
                html += '<span class="inline-flex self-start items-center px-space-xs py-0.5 rounded-full ' + info[1] + ' font-label-md text-[10px] font-bold">' + info[0] + ' (' + parCategorie[cat].length + ')</span>';
                parCategorie[cat].forEach(function(e) {
                  html += '<p class="font-body-sm text-[11px] text-secondary pl-1">« ' + escapeHtmlIA(e.extrait) + ' » — ' + escapeHtmlIA(e.probleme) + '</p>';
                });
                html += '</div>';
              });
              listeEl.innerHTML = html;
            }
          }
        })
        .catch(function() {
          if (erreurEl) { erreurEl.className = 'ia-erreur font-body-sm text-[11px] text-error'; erreurEl.textContent = 'Erreur réseau, merci de réessayer.'; }
        })
        .finally(function() {
          btn.disabled = false;
          if (labelEl) labelEl.textContent = labelAvant;
        });
    });
  });
})();
</script>
</body>
</html>
