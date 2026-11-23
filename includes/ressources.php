<?php
/**
 * NexRaiz — Bibliothèque de ressources (consultant), partagée par tous les
 * packs (voir ressources.php). Une ressource est un contenu texte, un
 * fichier joint, ou les deux — jamais les deux absents à la fois (vérifié
 * côté serveur dans ressource_enregistrer_fichier() / ressources.php).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/documents.php'; // fournit nx_icone_fichier()

/**
 * Racine de stockage privé des fichiers de ressources — hors du répertoire
 * servi par Apache, même principe que includes/documents.php.
 */
function ressources_stockage_prive_racine(): string
{
    return dirname(__DIR__, 3) . '/nexraiz_private_storage/ressources';
}

/**
 * Valide et enregistre le fichier joint à une ressource (champ "fichier").
 * Retourne les métadonnées à insérer en base, ou une chaîne d'erreur.
 *
 * @return array{chemin:string,nom:string,extension:string,taille_ko:int}|string
 */
function ressource_enregistrer_fichier(array $fichierPost)
{
    if ($fichierPost['error'] !== UPLOAD_ERR_OK) {
        return "Erreur lors de l'envoi du fichier.";
    }
    $tailleMaxOctets = 20 * 1024 * 1024;
    if ($fichierPost['size'] > $tailleMaxOctets) {
        return 'Le fichier dépasse la taille maximale de 20 Mo.';
    }

    $extension = strtolower(pathinfo($fichierPost['name'], PATHINFO_EXTENSION));
    $mimesAutorises = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];
    if (!isset($mimesAutorises[$extension])) {
        return 'Format non accepté (PDF, Word, Excel, PowerPoint, JPG ou PNG uniquement).';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReel = finfo_file($finfo, $fichierPost['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeReel, $mimesAutorises[$extension], true)) {
        return "Le contenu du fichier ne correspond pas à son extension ({$extension}).";
    }

    $dossierStockage = ressources_stockage_prive_racine();
    if (!is_dir($dossierStockage) && !mkdir($dossierStockage, 0750, true) && !is_dir($dossierStockage)) {
        return "Impossible de préparer l'espace de stockage.";
    }
    $nomStockage = bin2hex(random_bytes(16)) . '.' . $extension;
    $cheminAbsolu = $dossierStockage . '/' . $nomStockage;
    if (!move_uploaded_file($fichierPost['tmp_name'], $cheminAbsolu)) {
        return "Erreur lors de l'enregistrement du fichier.";
    }

    return [
        'chemin' => 'prive:' . $nomStockage,
        'nom' => basename($fichierPost['name']),
        'extension' => $extension,
        'taille_ko' => (int) round($fichierPost['size'] / 1024),
    ];
}

function ressource_chemin_absolu(string $cheminStocke): string
{
    return ressources_stockage_prive_racine() . '/' . substr($cheminStocke, strlen('prive:'));
}
