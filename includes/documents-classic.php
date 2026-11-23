<?php
/**
 * NexRaiz — Documents du Pack Classic : dépôt, historique de versions,
 * validation/correction par le consultant, stockage protégé.
 *
 * Chaque "slot" (cv, lettre, offre, portfolio) peut avoir plusieurs lignes
 * dans `documents` (une par dépôt) : la plus récente par
 * (dossier_id, categorie) est la version courante, les précédentes restent
 * consultables comme historique — jamais d'écrasement silencieux.
 *
 * Les nouveaux dépôts sont stockés hors du répertoire servi par le serveur
 * web (voir classic_stockage_prive_racine()) et ne sont accessibles qu'à
 * travers classic-document-telecharger.php, qui vérifie les autorisations
 * avant de servir le fichier. Les documents déposés avant ce changement
 * restent à leur ancien emplacement public (uploads/classic/...) — non
 * migrés rétroactivement, signalé dans la livraison.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // log_activite()
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/documents.php'; // fournit nx_icone_fichier()

/**
 * Les documents attendus pour le Pack Classic : même règle métier pour tous
 * les clients (service de correction CV/lettre), aucune table ne permet de
 * la personnaliser par dossier — ce n'est pas une liste inventée, c'est la
 * seule déjà présente dans le code (classic-depot-documents.php). Seul le
 * CV est obligatoire.
 *
 * @return array<string,array{label:string,explication:string,obligatoire:bool}>
 */
function classic_slots_documents(): array
{
    return [
        'cv'        => ['label' => 'CV actuel', 'explication' => 'Nécessaire pour analyser votre profil et proposer des corrections pertinentes.', 'obligatoire' => true],
        'lettre'    => ['label' => 'Lettre de motivation', 'explication' => 'Utile pour harmoniser le ton avec votre CV, si vous en avez déjà une.', 'obligatoire' => false],
        'offre'     => ['label' => "Offre d'emploi ciblée", 'explication' => 'Permet d\'adapter vos documents au poste visé.', 'obligatoire' => false],
        'portfolio' => ['label' => 'Portfolio ou lien professionnel', 'explication' => 'Un atout supplémentaire pour valoriser vos réalisations.', 'obligatoire' => false],
        'photo'     => ['label' => 'Photo de profil', 'explication' => 'Utilisée uniquement si votre consultant choisit un modèle de CV avec photo.', 'obligatoire' => false],
    ];
}

function classic_statuts_documents(): array
{
    return [
        'en_verification' => ['En cours de vérification', 'bg-orange-100 text-orange-700'],
        'valide'           => ['Validé', 'bg-green-100 text-green-700'],
        'a_corriger'       => ['À corriger', 'bg-error-container/40 text-error'],
    ];
}

/**
 * Racine de stockage hors webroot (en dehors de htdocs), calculée
 * relativement à ce fichier plutôt qu'en chemin absolu codé en dur.
 */
function classic_stockage_prive_racine(): string
{
    return dirname(__DIR__, 3) . '/nexraiz_private_storage/classic/documents';
}

/**
 * Enregistre un nouveau dépôt pour un slot donné. Valide extension, taille
 * ET contenu réel du fichier (pas seulement son nom), stocke hors webroot,
 * crée une nouvelle ligne `documents` (jamais d'écrasement de l'historique).
 *
 * @return string Chaîne vide en cas de succès, sinon message d'erreur.
 */
function classic_enregistrer_document(int $dossierId, string $slot, string $champFichier, string $description = ''): string
{
    $slots = classic_slots_documents();
    if (!isset($slots[$slot])) {
        return 'Type de document invalide.';
    }
    if (!isset($_FILES[$champFichier]) || $_FILES[$champFichier]['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Merci de sélectionner un fichier.';
    }
    if ($_FILES[$champFichier]['error'] !== UPLOAD_ERR_OK) {
        return "Erreur lors de l'envoi du fichier.";
    }

    $tailleMaxOctets = 10 * 1024 * 1024;
    if ($_FILES[$champFichier]['size'] > $tailleMaxOctets) {
        return 'Le fichier dépasse la taille maximale de 10 Mo.';
    }

    $extension = strtolower(pathinfo($_FILES[$champFichier]['name'], PATHINFO_EXTENSION));
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

    // Ne jamais se fier au seul nom/extension transmis : on vérifie le type
    // MIME réel du contenu envoyé (détection serveur, pas l'en-tête du
    // navigateur) avant d'accepter le fichier.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReel = finfo_file($finfo, $_FILES[$champFichier]['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeReel, $mimesAutorises[$extension], true)) {
        return "Le contenu du fichier ne correspond pas à son extension ({$extension}). Merci de vérifier le fichier transmis.";
    }

    $dossierStockage = classic_stockage_prive_racine() . '/' . $dossierId . '/' . $slot;
    if (!is_dir($dossierStockage) && !mkdir($dossierStockage, 0750, true) && !is_dir($dossierStockage)) {
        return "Impossible de préparer l'espace de stockage.";
    }

    // Nom de stockage non prévisible (ni le nom d'origine, ni un compteur
    // séquentiel) : le fichier d'origine reste affiché côté interface via
    // nom_original, jamais utilisé pour retrouver le fichier sur le disque.
    $nomStockage = bin2hex(random_bytes(16)) . '.' . $extension;
    $cheminAbsolu = $dossierStockage . '/' . $nomStockage;
    if (!move_uploaded_file($_FILES[$champFichier]['tmp_name'], $cheminAbsolu)) {
        return "Erreur lors de l'enregistrement du fichier.";
    }

    $cheminStocke = 'prive:' . $dossierId . '/' . $slot . '/' . $nomStockage;
    $stmt = db()->prepare(
        'INSERT INTO documents (dossier_id, nom_original, categorie, chemin, taille_ko, description, statut)
         VALUES (?, ?, ?, ?, ?, ?, "en_verification")'
    );
    $stmt->execute([
        $dossierId,
        basename($_FILES[$champFichier]['name']),
        $slot,
        $cheminStocke,
        (int) round($_FILES[$champFichier]['size'] / 1024),
        $description !== '' ? $description : null,
    ]);

    log_activite($dossierId, 'Document transmis : ' . $slots[$slot]['label']);

    return '';
}

/**
 * Pour chaque slot : la version courante (dernier dépôt) et son historique
 * complet (du plus récent au plus ancien), triés une seule fois.
 *
 * @return array<string,array{actuel:?array,historique:array}>
 */
function classic_documents_par_slot(int $dossierId): array
{
    $stmt = db()->prepare('SELECT * FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC, id DESC');
    $stmt->execute([$dossierId]);
    $tousLesDocuments = $stmt->fetchAll();

    $parSlot = [];
    foreach (array_keys(classic_slots_documents()) as $slot) {
        $parSlot[$slot] = ['actuel' => null, 'historique' => []];
    }
    foreach ($tousLesDocuments as $d) {
        $slot = $d['categorie'];
        if (!isset($parSlot[$slot])) {
            // Dépôts antérieurs à l'ajout de la colonne categorie :
            // categorie est NULL, le slot reste déductible du chemin de
            // stockage (prive:{dossier}/{slot}/{fichier}), même repli que
            // includes/consultant-classic.php pour rester cohérent.
            $slot = null;
            foreach (array_keys($parSlot) as $slotConnu) {
                if (strpos($d['chemin'], '/' . $slotConnu . '/') !== false) {
                    $slot = $slotConnu;
                    break;
                }
            }
            if ($slot === null) {
                continue; // vraiment hors des slots connus : ignoré plutôt que planté.
            }
        }
        if ($parSlot[$slot]['actuel'] === null) {
            $parSlot[$slot]['actuel'] = $d;
        } else {
            $parSlot[$slot]['historique'][] = $d;
        }
    }
    return $parSlot;
}

// Validation/correction : logique commune à tous les packs, centralisée
// dans includes/documents.php (nx_valider_document/nx_corriger_document).
// Ces wrappers ne changent rien pour les appelants existants côté Classic.
function classic_valider_document(int $documentId, int $dossierId, int $consultantId): bool
{
    return nx_valider_document($documentId, $dossierId, $consultantId, 'classic-depot-documents.php');
}

function classic_corriger_document(int $documentId, int $dossierId, string $motif): bool
{
    return nx_corriger_document($documentId, $dossierId, $motif, 'classic-depot-documents.php');
}

/**
 * Chemin absolu réel sur le disque pour une valeur de `documents.chemin`,
 * qu'il s'agisse d'un nouveau dépôt protégé ("prive:...") ou d'un ancien
 * chemin public historique ("uploads/..."), pour tout code qui a besoin de
 * lire le contenu du fichier (ex. extraction de texte) plutôt que de le
 * servir via la passerelle HTTP.
 */
function classic_chemin_absolu_fichier(string $chemin): string
{
    if (str_starts_with($chemin, 'prive:')) {
        return classic_stockage_prive_racine() . '/' . substr($chemin, strlen('prive:'));
    }
    return dirname(__DIR__) . '/' . $chemin;
}

/**
 * Icône/couleur par extension — même logique que includes/documents.php
 * (nx_icone_fichier), dupliquée volontairement ici pour accepter un chemin
 * "prive:..." qui n'a pas de nom de fichier exploitable directement ;
 * reçoit donc toujours le nom_original, jamais le chemin de stockage.
 */
function classic_icone_fichier(string $nomOriginal): array
{
    return nx_icone_fichier($nomOriginal);
}
