<?php
/**
 * NexRaiz — Lanceur des tests « Opportunités ». Usage :
 *   php tests/run.php
 * Transaction PDO ouverte et jamais commitée (voir bootstrap.php) : aucune
 * donnée de test ne persiste, exécution sans risque sur la base réelle.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut être exécuté qu\'en ligne de commande.');
}

require __DIR__ . '/bootstrap.php';

$fichiers = [
    'test_score_calcul.php',
    'test_acces_controle.php',
    'test_expiration.php',
    'test_ia_secours.php',
    'test_candidature.php',
    'test_extraction_profil_cv.php',
];

$total = 0;
$echecs = 0;

foreach ($fichiers as $fichier) {
    $avant = count($GLOBALS['__tests_resultats']);
    echo "\n--- {$fichier} ---\n";
    try {
        require __DIR__ . '/' . $fichier;
    } catch (\Throwable $e) {
        echo "  ERREUR FATALE : {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n";
        $echecs++;
        continue;
    }
    foreach (array_slice($GLOBALS['__tests_resultats'], $avant) as $resultat) {
        $total++;
        if ($resultat['ok']) {
            echo "  OK   — {$resultat['libelle']}\n";
        } else {
            $echecs++;
            echo "  FAIL — {$resultat['libelle']}\n";
        }
    }
}

echo "\n==========================================\n";
echo $echecs === 0
    ? "SUCCÈS : {$total} vérification(s), 0 échec.\n"
    : "ÉCHEC : {$echecs} échec(s) sur {$total} vérification(s).\n";

exit($echecs === 0 ? 0 : 1);
