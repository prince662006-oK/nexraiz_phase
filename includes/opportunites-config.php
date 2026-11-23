<?php
/**
 * NexRaiz — Configuration de la fonctionnalité « Mes opportunités » (Pack
 * Classic) : clé API Anthropic (analyse IA du profil), connecteur d'offres
 * externes optionnel (désactivé par défaut), seuils du score de
 * correspondance, poids du score, et gabarits des liens de recherche
 * externes. Mêmes conventions que includes/ai-config.php et
 * includes/payment-config.php : vraies clés via includes/env.local.php
 * (non versionné), jamais en dur ici.
 */
if (is_file(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
}

return [
    // Analyse IA du profil + CV (Anthropic Claude). Distinct des clés Groq
    // existantes (includes/ai-config.php), utilisées pour la correction de
    // documents — fonctionnalité séparée, demandée explicitement sur
    // Anthropic par le cahier des charges de « Mes opportunités ».
    'anthropic' => [
        'api_key' => getenv('AI_API_KEY') ?: 'REPLACE_ME',
        'model'   => getenv('AI_MODEL') ?: 'claude-3-5-haiku-latest',
    ],

    // Connecteur d'agrégateur d'offres externes (ex. Jooble) : désactivé
    // par défaut, à activer explicitement en production une fois une vraie
    // clé obtenue. Voir includes/opportunites-connecteurs.php.
    'job_api' => [
        'enabled'  => getenv('JOB_API_ENABLED') === '1',
        'api_key'  => getenv('JOB_API_KEY') ?: 'REPLACE_ME',
        'provider' => getenv('JOB_API_PROVIDER') ?: 'jooble',
    ],

    // Seuils d'affichage/notification du score de correspondance (0-100).
    // Modifiables sans toucher au code via les variables d'environnement.
    'seuil_score_minimum'     => (int) (getenv('OPPORTUNITES_SEUIL_SCORE') ?: 40),
    'seuil_notification_score' => (int) (getenv('OPPORTUNITES_SEUIL_NOTIF') ?: 70),

    // Pondération du calcul de score (somme = 100). Voir
    // includes/opportunites.php::calculer_score_match(). Point de décision
    // ouvert pour l'équipe NexRaiz (docs/opportunites.md).
    'poids_score' => [
        'type'        => 15,
        'ville'       => 15,
        'niveau_etudes' => 15,
        'experience'  => 15,
        'competences' => 30,
        'intitule'    => 10,
    ],

    // Gabarits de liens de recherche externes (jamais de scraping — on
    // ouvre uniquement une recherche pré-remplie que le client lance
    // lui-même). {mots_cles} et {ville} sont substitués et encodés dans
    // l'URL par opportunites_liens_recherche().
    //
    // ⚠️ Gabarits à vérifier manuellement avant mise en ligne (point de
    // décision ouvert, voir docs/opportunites.md) : les formats de
    // recherche réels de ces sites n'ont pas été vérifiés un par un.
    'liens_recherche' => [
        'linkedin' => [
            'label' => 'LinkedIn Emplois',
            'url'   => 'https://www.linkedin.com/jobs/search/?keywords={mots_cles}&location={ville}',
        ],
        'educarriere' => [
            'label' => 'Educarrière',
            'url'   => 'https://www.educarriere.ci/offre-emploi/liste-offres.html?requete={mots_cles}&lieu={ville}',
        ],
        'emploici' => [
            'label' => 'Emploi.ci',
            'url'   => 'https://emploi.ci/recherche-jobs?q={mots_cles}&l={ville}',
        ],
        'agence_emploi_jeunes' => [
            'label' => 'Agence Emploi Jeunes',
            'url'   => 'https://www.agenceemploijeunes.ci/offres?q={mots_cles}',
        ],
        'goafricaonline' => [
            'label' => 'GoAfricaOnline',
            'url'   => 'https://www.goafricaonline.com/recherche?q={mots_cles}+{ville}',
        ],
    ],
];
