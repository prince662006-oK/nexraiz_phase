<?php
/**
 * NexRaiz — Connecteurs d'offres externes (optionnel, désactivé par
 * défaut). Le projet est 100 % procédural (aucune classe nulle part) :
 * plutôt qu'une interface PHP pour un seul connecteur d'exemple, un simple
 * registre associant un nom de fournisseur à une fonction d'import,
 * appelée via call_user_func() — ajouter un connecteur = ajouter une
 * fonction + une entrée dans ce registre.
 *
 * Toute offre importée arrive en statut 'brouillon' avec source=api_partner :
 * un admin doit la valider avant publication (voir
 * includes/opportunites.php::job_api_importer_executer()).
 */
require_once __DIR__ . '/opportunites-ia.php';

/** @return array<string,string> Nom du fournisseur => nom de la fonction d'import. */
function opportunites_connecteurs_disponibles(): array
{
    return [
        'jooble' => 'jooble_importer_offres',
    ];
}

/**
 * Connecteur d'exemple pour l'API Jooble (agrégateur officiel d'offres,
 * clé API requise : https://jooble.org/api/about). Retourne un tableau de
 * lignes normalisées prêtes à être insérées dans `opportunites` ; ne crée
 * ni ne modifie jamais directement la base — c'est le rôle de
 * job_api_importer_executer().
 *
 * @param array $config ex. opportunites_config()['job_api'].
 * @return array<int,array{titre:string,type:string,entreprise:?string,ville:?string,description:string,lien_candidature:?string,date_limite:?string,source_reference:string}>
 * @throws RuntimeException en cas d'erreur réseau ou de réponse invalide.
 */
function jooble_importer_offres(array $config): array
{
    $ch = curl_init('https://jooble.org/api/' . $config['api_key']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['keywords' => '', 'location' => "Côte d'Ivoire"]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $reponseBrute = curl_exec($ch);
    $erreur = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($erreur) {
        throw new RuntimeException("Connecteur Jooble : erreur réseau ({$erreur}).");
    }
    if ($httpCode >= 400) {
        throw new RuntimeException("Connecteur Jooble : code HTTP {$httpCode}.");
    }
    $donnees = json_decode((string) $reponseBrute, true);
    if (!is_array($donnees) || !isset($donnees['jobs']) || !is_array($donnees['jobs'])) {
        throw new RuntimeException('Connecteur Jooble : réponse inattendue.');
    }

    $resultat = [];
    foreach ($donnees['jobs'] as $offre) {
        if (empty($offre['id']) || empty($offre['title'])) {
            continue; // Ligne incomplète : on ignore plutôt que d'insérer une offre inexploitable.
        }
        $resultat[] = [
            'titre' => (string) $offre['title'],
            'type' => 'emploi', // Jooble n'étiquette pas stage/alternance/mission : toujours importé comme "emploi", à corriger par l'admin si besoin.
            'entreprise' => $offre['company'] ?? null,
            'ville' => $offre['location'] ?? null,
            'description' => (string) ($offre['snippet'] ?? ''),
            'lien_candidature' => $offre['link'] ?? null,
            'date_limite' => null, // Jooble ne fournit pas de date limite exploitable.
            'source_reference' => 'jooble-' . $offre['id'],
        ];
    }
    return $resultat;
}
