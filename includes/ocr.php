<?php
/**
 * NexRaiz — Reconnaissance optique de caractères (OCR), pour l'éditeur
 * visuel "Canva" (Pack Classic) sur un document scanné/aplati : PDF.js rend
 * déjà la page en image côté navigateur (aucune rasterisation serveur
 * nécessaire) ; ce fichier lance Tesseract OCR en sous-processus sur cette
 * image et renvoie les mots détectés avec leurs coordonnées et un score de
 * confiance, pour les présenter comme des blocs modifiables — réutilisant
 * ensuite exactement le même mécanisme de correctif (fond + texte) que les
 * blocs natifs d'un PDF textuel.
 *
 * Tesseract est installé sur la machine, mais son dossier de langues
 * (Program Files) n'est pas modifiable sans élévation : on utilise donc un
 * dossier de langues dédié, sous le stockage privé NexRaiz, contenant une
 * copie du modèle anglais et le modèle français téléchargé pour ce projet.
 */
require_once __DIR__ . '/db.php';

const NX_TESSERACT_EXE = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';

function nx_tessdata_dir(): string
{
    return nx_racine_stockage_prive() . '/nexraiz_private_storage/tessdata';
}

function ocr_disponible(): bool
{
    return is_file(NX_TESSERACT_EXE) && is_dir(nx_tessdata_dir());
}

/**
 * Lance Tesseract sur une image PNG/JPEG déjà sur disque et renvoie les mots
 * détectés avec leurs coordonnées (en pixels de l'image fournie) et un score
 * de confiance 0-100.
 *
 * @return array<int,array{texte:string,confiance:int,left:int,top:int,width:int,height:int}>
 * @throws RuntimeException si Tesseract n'est pas disponible ou échoue.
 */
function ocr_image_vers_mots(string $cheminImageAbsolu): array
{
    if (!ocr_disponible()) {
        throw new RuntimeException("La reconnaissance optique (OCR) n'est pas disponible sur ce serveur.");
    }
    if (!is_file($cheminImageAbsolu)) {
        throw new RuntimeException('Image introuvable.');
    }

    $prefixeSortie = $cheminImageAbsolu . '-out';
    // "-c tessedit_create_tsv=1" plutôt que le config nommé "tsv" : ce
    // dernier est un fichier livré dans le dossier tessdata standard de
    // Tesseract (configs/tsv), introuvable une fois --tessdata-dir redirigé
    // vers notre dossier de langues dédié (qui ne contient que les .traineddata).
    $commande = sprintf(
        '%s %s %s --tessdata-dir %s -l fra+eng -c tessedit_create_tsv=1',
        escapeshellarg(NX_TESSERACT_EXE),
        escapeshellarg($cheminImageAbsolu),
        escapeshellarg($prefixeSortie),
        escapeshellarg(nx_tessdata_dir())
    );

    $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processus = proc_open($commande, $descripteurs, $pipes);
    if (!is_resource($processus)) {
        throw new RuntimeException("Impossible de lancer l'OCR.");
    }
    stream_set_timeout($pipes[1], 25);
    $sortie = stream_get_contents($pipes[1]);
    $erreurs = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codeRetour = proc_close($processus);

    $cheminTsv = $prefixeSortie . '.tsv';
    if ($codeRetour !== 0 || !is_file($cheminTsv)) {
        throw new RuntimeException("L'OCR a échoué : " . trim($erreurs ?: 'erreur inconnue') . '.');
    }

    $lignes = file($cheminTsv, FILE_IGNORE_NEW_LINES);
    @unlink($cheminTsv);
    if ($lignes === false || count($lignes) < 2) {
        return [];
    }

    $entetes = explode("\t", $lignes[0]);
    $indexNiveau = array_search('level', $entetes, true);
    $indexGauche = array_search('left', $entetes, true);
    $indexHaut = array_search('top', $entetes, true);
    $indexLargeur = array_search('width', $entetes, true);
    $indexHauteur = array_search('height', $entetes, true);
    $indexConf = array_search('conf', $entetes, true);
    $indexTexte = array_search('text', $entetes, true);

    $mots = [];
    for ($i = 1; $i < count($lignes); $i++) {
        $colonnes = explode("\t", $lignes[$i]);
        // Niveau 5 = mot individuel (1=page, 2=bloc, 3=paragraphe, 4=ligne, 5=mot).
        if (($colonnes[$indexNiveau] ?? '') !== '5') {
            continue;
        }
        $texte = trim($colonnes[$indexTexte] ?? '');
        $confiance = (int) ($colonnes[$indexConf] ?? -1);
        if ($texte === '' || $confiance < 0) {
            continue;
        }
        $mots[] = [
            'texte'     => $texte,
            'confiance' => $confiance,
            'left'      => (int) ($colonnes[$indexGauche] ?? 0),
            'top'       => (int) ($colonnes[$indexHaut] ?? 0),
            'width'     => (int) ($colonnes[$indexLargeur] ?? 0),
            'height'    => (int) ($colonnes[$indexHauteur] ?? 0),
        ];
    }
    return $mots;
}

/**
 * Regroupe les mots détectés en lignes de texte (mots qui se chevauchent
 * verticalement et sont proches horizontalement) : éditer une ligne entière
 * est plus utile pour un consultant qu'éditer chaque mot séparément, et
 * c'est la même granularité que les blocs natifs d'un PDF textuel
 * (généralement une ligne par "run" de texte).
 *
 * @param array<int,array{texte:string,confiance:int,left:int,top:int,width:int,height:int}> $mots
 * @return array<int,array{texte:string,confiance:int,left:int,top:int,width:int,height:int}>
 */
function ocr_mots_vers_lignes(array $mots): array
{
    if (!$mots) {
        return [];
    }

    // Étape 1 : regroupe par bande verticale (même ligne visuelle), au cas
    // par cas plutôt qu'avec un seuil fixe — un mot rejoint la bande dont le
    // centre vertical "glissant" (mis à jour à chaque ajout) est le plus
    // proche, tant que l'écart reste sous 60 % de sa hauteur. Limite connue :
    // une heuristique géométrique simple, pas une vraie analyse de mise en
    // page — suffisante pour proposer des zones modifiables, pas une
    // reconstruction garantie à 100 %.
    usort($mots, fn (array $a, array $b): int => $a['top'] <=> $b['top']);
    $bandes = [];
    foreach ($mots as $mot) {
        $motCentre = $mot['top'] + $mot['height'] / 2;
        $indexBande = null;
        $meilleurEcart = null;
        foreach ($bandes as $i => $bande) {
            $ecart = abs($motCentre - $bande['centre']);
            if ($ecart < max($mot['height'], $bande['hauteurMax']) * 0.6 && ($meilleurEcart === null || $ecart < $meilleurEcart)) {
                $indexBande = $i;
                $meilleurEcart = $ecart;
            }
        }
        if ($indexBande === null) {
            $bandes[] = ['mots' => [$mot], 'centre' => $motCentre, 'hauteurMax' => $mot['height']];
        } else {
            $bandes[$indexBande]['mots'][] = $mot;
            $nb = count($bandes[$indexBande]['mots']);
            // Centre glissant : moyenne pondérée, pour que la bande "dérive"
            // avec les mots qui la composent plutôt que de rester figée sur
            // le tout premier mot rencontré.
            $bandes[$indexBande]['centre'] = (($bandes[$indexBande]['centre'] * ($nb - 1)) + $motCentre) / $nb;
            $bandes[$indexBande]['hauteurMax'] = max($bandes[$indexBande]['hauteurMax'], $mot['height']);
        }
    }

    // Étape 2 : dans chaque bande, trie par position horizontale puis
    // segmente en lignes distinctes si l'écart entre deux mots est trop
    // grand (colonnes séparées sur un CV à deux colonnes, par exemple).
    $lignes = [];
    foreach ($bandes as $bande) {
        $motsBande = $bande['mots'];
        usort($motsBande, fn (array $a, array $b): int => $a['left'] <=> $b['left']);
        $segment = null;
        foreach ($motsBande as $mot) {
            if ($segment !== null && $mot['left'] - ($segment['left'] + $segment['width']) > $segment['height'] * 1.5) {
                $lignes[] = $segment;
                $segment = null;
            }
            if ($segment === null) {
                $segment = [
                    'texte' => $mot['texte'], 'confiance' => $mot['confiance'],
                    'left' => $mot['left'], 'top' => $mot['top'],
                    'width' => $mot['width'], 'height' => $mot['height'],
                ];
            } else {
                $segment['texte'] .= ' ' . $mot['texte'];
                $segment['confiance'] = min($segment['confiance'], $mot['confiance']);
                $nouveauDroite = max($segment['left'] + $segment['width'], $mot['left'] + $mot['width']);
                $nouveauBas = max($segment['top'] + $segment['height'], $mot['top'] + $mot['height']);
                $segment['top'] = min($segment['top'], $mot['top']);
                $segment['width'] = $nouveauDroite - $segment['left'];
                $segment['height'] = $nouveauBas - $segment['top'];
            }
        }
        if ($segment !== null) {
            $lignes[] = $segment;
        }
    }
    return $lignes;
}
