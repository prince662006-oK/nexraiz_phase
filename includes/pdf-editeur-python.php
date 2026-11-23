<?php
/**
 * NexRaiz — Pont vers le script Python (PyMuPDF) qui effectue la véritable
 * édition du PDF original : contrairement à l'export précédent (pdf-lib,
 * côté navigateur, qui ne faisait que dessiner un correctif par-dessus),
 * PyMuPDF supprime réellement le contenu d'origine dans le flux du PDF
 * (rédaction) avant d'insérer le nouveau texte. Voir python/pdf_editeur.py.
 *
 * Aucune donnée n'est jamais exécutée à l'aveugle : les arguments passés au
 * script Python viennent exclusivement de ce fichier (chemins résolus côté
 * serveur, jamais depuis une entrée utilisateur brute), et le résultat est
 * toujours un JSON strict, jamais interprété comme du code.
 */

const NX_PYTHON_EXE = 'C:\\Users\\simplon\\AppData\\Local\\Programs\\Python\\Python314\\python.exe';

function pdf_editeur_python_disponible(): bool
{
    return is_file(NX_PYTHON_EXE) && is_file(__DIR__ . '/../python/pdf_editeur.py');
}

/**
 * Applique une liste de modifications à un PDF existant via PyMuPDF :
 * rédaction réelle de chaque zone modifiée (le texte d'origine est
 * véritablement supprimé du flux, pas seulement recouvert), puis insertion
 * du nouveau texte à la même position.
 *
 * @param string $cheminSourceAbsolu PDF original, jamais modifié en place.
 * @param array<int,array{page:int,x0:float,y0:float,x1:float,y1:float,texte:string,taille_police:float,gras?:bool,italique?:bool,souligne?:bool,police?:string,couleur_texte?:array,couleur_fond?:array}> $edits
 * @param array{texte:string}|null $tampon Texte du tampon NexRaiz, ajouté en toute fin de document.
 * @return array{chemin:string,avertissements:array<int,string>} Chemin absolu du PDF édité (fichier
 * temporaire, à déplacer/lire par l'appelant) et avertissements non bloquants (ex. texte réduit
 * parce que la zone d'origine était trop étroite pour le remplacement).
 * @throws RuntimeException si Python/PyMuPDF est indisponible ou si l'édition échoue.
 */
function pdf_editeur_python_appliquer(string $cheminSourceAbsolu, array $edits, ?array $tampon = null): array
{
    if (!pdf_editeur_python_disponible()) {
        throw new RuntimeException("L'édition réelle du PDF (Python/PyMuPDF) n'est pas disponible sur ce serveur.");
    }
    if (!is_file($cheminSourceAbsolu)) {
        throw new RuntimeException('Document source introuvable.');
    }

    $dossierTemp = sys_get_temp_dir() . '/nexraiz_pdf_edit';
    if (!is_dir($dossierTemp)) {
        mkdir($dossierTemp, 0700, true);
    }
    $identifiant = bin2hex(random_bytes(16));
    $cheminRequete = $dossierTemp . '/' . $identifiant . '-requete.json';
    $cheminSortie = $dossierTemp . '/' . $identifiant . '-sortie.pdf';

    $requete = [
        'source' => $cheminSourceAbsolu,
        'sortie' => $cheminSortie,
        'edits' => $edits,
        'tampon' => $tampon,
    ];
    file_put_contents($cheminRequete, json_encode($requete, JSON_UNESCAPED_UNICODE));

    $commande = sprintf(
        '%s %s %s',
        escapeshellarg(NX_PYTHON_EXE),
        escapeshellarg(__DIR__ . '/../python/pdf_editeur.py'),
        escapeshellarg($cheminRequete)
    );

    $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processus = proc_open($commande, $descripteurs, $pipes);
    if (!is_resource($processus)) {
        @unlink($cheminRequete);
        throw new RuntimeException("Impossible de lancer l'éditeur PDF Python.");
    }
    $sortieJson = stream_get_contents($pipes[1]);
    $erreurs = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($processus);
    @unlink($cheminRequete);

    $resultat = json_decode((string) $sortieJson, true);
    if (!is_array($resultat) || empty($resultat['ok'])) {
        $messageErreur = is_array($resultat) ? ($resultat['erreur'] ?? 'Erreur inconnue.') : trim($erreurs ?: 'Réponse Python invalide.');
        throw new RuntimeException("L'édition du PDF a échoué : {$messageErreur}");
    }
    if (!is_file($cheminSortie)) {
        throw new RuntimeException("L'édition du PDF a échoué : fichier de sortie introuvable.");
    }

    return ['chemin' => $cheminSortie, 'avertissements' => $resultat['avertissements'] ?? []];
}
