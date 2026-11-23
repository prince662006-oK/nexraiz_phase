<?php
/**
 * NexRaiz — Dépôt de documents par le client, en dehors des parcours qui ont
 * déjà leurs propres slots dédiés (ex. Pack Classic : CV/lettre/offre dans
 * classic-depot-documents.php). Pensé pour les packs d'accompagnement
 * (Booster, Wellbeing...) où les documents utiles (CV, diplômes, bilans
 * antérieurs...) arrivent au fil de l'accompagnement plutôt qu'en une seule
 * étape bloquante — le client peut déposer un document à tout moment depuis
 * son tableau de bord.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // fournit log_activite()
require_once __DIR__ . '/notifications.php';

/**
 * Racine de stockage privé (hors webroot) pour les documents déposés via
 * nx_enregistrer_document_client() — un dossier par pack, pour rester
 * cohérent avec le découpage déjà en place côté Pack Classic
 * (includes/documents-classic.php::classic_stockage_prive_racine()).
 */
function nx_documents_stockage_prive_racine(string $packSlug): string
{
    return dirname(__DIR__, 3) . '/nexraiz_private_storage/' . $packSlug . '/documents';
}

/**
 * Chemin réel sur disque pour un "chemin" de document : gère à la fois le
 * nouveau marquage "prive:..." (stockage protégé) et l'ancien chemin public
 * "uploads/..." (dépôts antérieurs à la migration vers le stockage privé,
 * non déplacés rétroactivement — limite connue, documentée).
 */
function nx_document_client_chemin_absolu(string $chemin, string $packSlug): string
{
    if (str_starts_with($chemin, 'prive:')) {
        return nx_documents_stockage_prive_racine($packSlug) . '/' . substr($chemin, strlen('prive:'));
    }
    return dirname(__DIR__) . '/' . $chemin;
}

/**
 * Enregistre un document déposé par le client (champ de formulaire
 * "document"). Retourne une chaîne vide en cas de succès, sinon un message
 * d'erreur à afficher.
 */
function nx_enregistrer_document_client(int $dossierId, string $packSlug, string $categorie, string $lienCoach = ''): string
{
    if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Merci de sélectionner un fichier.';
    }
    if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        return "Erreur lors de l'envoi du fichier.";
    }

    $tailleMaxOctets = 10 * 1024 * 1024;
    if ($_FILES['document']['size'] > $tailleMaxOctets) {
        return 'Le fichier dépasse la taille maximale de 10 Mo.';
    }

    $extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
    $mimesAutorises = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];
    if (!isset($mimesAutorises[$extension])) {
        return 'Format non accepté (PDF, DOC, DOCX, JPG ou PNG uniquement).';
    }
    // Ne jamais se fier au seul nom/extension transmis : vérifie le type
    // MIME réel du contenu envoyé (même règle que le dépôt Pack Classic).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReel = finfo_file($finfo, $_FILES['document']['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeReel, $mimesAutorises[$extension], true)) {
        return "Le contenu du fichier ne correspond pas à son extension ({$extension}). Merci de vérifier le fichier transmis.";
    }

    $dossierUpload = nx_documents_stockage_prive_racine($packSlug) . '/' . $dossierId;
    if (!is_dir($dossierUpload) && !mkdir($dossierUpload, 0750, true) && !is_dir($dossierUpload)) {
        return "Impossible de préparer l'espace de stockage.";
    }

    // Nom de stockage non prévisible : le nom d'origine reste affiché via
    // nom_original, jamais utilisé pour retrouver le fichier sur le disque.
    $nomFichier = bin2hex(random_bytes(16)) . '.' . $extension;
    $cheminAbsolu = $dossierUpload . '/' . $nomFichier;
    if (!move_uploaded_file($_FILES['document']['tmp_name'], $cheminAbsolu)) {
        return "Erreur lors de l'enregistrement du fichier.";
    }

    $cheminStocke = 'prive:' . $dossierId . '/' . $nomFichier;
    $stmt = db()->prepare('INSERT INTO documents (dossier_id, nom_original, categorie, chemin, taille_ko) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $dossierId,
        basename($_FILES['document']['name']),
        $categorie !== '' ? $categorie : null,
        $cheminStocke,
        (int) round($_FILES['document']['size'] / 1024),
    ]);

    log_activite($dossierId, 'Document transmis' . ($categorie !== '' ? ' : ' . $categorie : ''));

    // Le coach assigné (s'il y en a un) doit savoir qu'un document attend sa
    // vérification — même système de notifications que pour les actions.
    $stmt = db()->prepare('SELECT consultant_id FROM dossiers WHERE id = ?');
    $stmt->execute([$dossierId]);
    $consultantId = $stmt->fetchColumn();
    if ($consultantId && $lienCoach !== '') {
        notify((int) $consultantId, 'document', 'Nouveau document à vérifier', basename($_FILES['document']['name']), $lienCoach);
    }

    return '';
}

/**
 * Icône et couleur Material Symbols selon l'extension d'un fichier, pour un
 * affichage plus parlant que la même icône générique pour tous les types.
 *
 * @return array{0:string,1:string} [nom_icone, classe_couleur_tailwind]
 */
function nx_icone_fichier(string $nomFichier): array
{
    $ext = strtolower(pathinfo($nomFichier, PATHINFO_EXTENSION));
    return match (true) {
        $ext === 'pdf' => ['picture_as_pdf', 'text-error'],
        in_array($ext, ['doc', 'docx'], true) => ['description', 'text-blue-600'],
        in_array($ext, ['xls', 'xlsx', 'csv'], true) => ['table_chart', 'text-green-700'],
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => ['image', 'text-purple-600'],
        default => ['insert_drive_file', 'text-primary'],
    };
}

/**
 * Libellés/couleurs des 3 statuts réels de `documents.statut` — mêmes
 * valeurs que `includes/documents-classic.php::classic_statuts_documents()`,
 * centralisées ici pour être réutilisées par tous les packs.
 */
function nx_documents_statuts(): array
{
    return [
        'en_verification' => ['En vérification', 'bg-orange-100 text-orange-700'],
        'valide'           => ['Validé', 'bg-green-100 text-green-700'],
        'a_corriger'       => ['À corriger', 'bg-error-container/40 text-error'],
    ];
}

/**
 * Valide un document (par un consultant/admin). Généralisation de
 * classic_valider_document() — mêmes règles, réutilisable par tous les
 * packs qui passent par la table `documents` partagée.
 */
function nx_valider_document(int $documentId, int $dossierId, int $consultantId, string $lienClient): bool
{
    $stmt = db()->prepare(
        "UPDATE documents SET statut = 'valide', motif_correction = NULL, valide_le = NOW(), valide_par = ?
         WHERE id = ? AND dossier_id = ?"
    );
    $stmt->execute([$consultantId, $documentId, $dossierId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $stmt = db()->prepare('SELECT u.id AS user_id, d.nom_original FROM documents d JOIN dossiers ds ON ds.id = d.dossier_id JOIN users u ON u.id = ds.user_id WHERE d.id = ?');
    $stmt->execute([$documentId]);
    $info = $stmt->fetch();
    log_activite($dossierId, 'Document validé : ' . $info['nom_original']);
    notify((int) $info['user_id'], 'document', 'Document validé', $info['nom_original'] . ' a été validé par votre consultant.', $lienClient);
    return true;
}

/**
 * Renvoie un document en correction, avec motif obligatoire. Généralisation
 * de classic_corriger_document().
 */
function nx_corriger_document(int $documentId, int $dossierId, string $motif, string $lienClient): bool
{
    $stmt = db()->prepare(
        "UPDATE documents SET statut = 'a_corriger', motif_correction = ?, valide_le = NULL, valide_par = NULL
         WHERE id = ? AND dossier_id = ?"
    );
    $stmt->execute([$motif, $documentId, $dossierId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $stmt = db()->prepare('SELECT u.id AS user_id, d.nom_original FROM documents d JOIN dossiers ds ON ds.id = d.dossier_id JOIN users u ON u.id = ds.user_id WHERE d.id = ?');
    $stmt->execute([$documentId]);
    $info = $stmt->fetch();
    log_activite($dossierId, 'Correction demandée sur un document : ' . $info['nom_original']);
    notify((int) $info['user_id'], 'document', 'Correction demandée', $info['nom_original'] . ' nécessite une correction.', $lienClient);
    return true;
}

/**
 * Regroupe les documents d'un dossier par `categorie` : la ligne la plus
 * récente de chaque catégorie est la version courante ("actuel"), les
 * précédentes restent consultables en historique — jamais d'écrasement.
 * Même principe que classic_documents_par_slot(), mais sans liste de slots
 * figée : ici la catégorie est le texte libre choisi au dépôt (ou une
 * catégorie canonique si le dépôt provient d'une demande du coach, voir
 * booster-mes-documents.php). Les documents sans catégorie n'ont pas de
 * relation de version entre eux : chacun forme son propre groupe.
 *
 * @return array<string,array{actuel:array,historique:array}>
 */
function nx_documents_par_categorie(int $dossierId): array
{
    $stmt = db()->prepare('SELECT * FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC, id DESC');
    $stmt->execute([$dossierId]);
    $tousLesDocuments = $stmt->fetchAll();

    $groupes = [];
    $sansCategorieIndex = 0;
    foreach ($tousLesDocuments as $d) {
        if ($d['categorie'] !== null && $d['categorie'] !== '') {
            $cle = $d['categorie'];
        } else {
            $cle = '__sans_categorie_' . $sansCategorieIndex++;
        }
        if (!isset($groupes[$cle])) {
            $groupes[$cle] = ['actuel' => $d, 'historique' => []];
        } else {
            $groupes[$cle]['historique'][] = $d;
        }
    }
    return $groupes;
}
