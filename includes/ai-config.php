<?php
/**
 * NexRaiz — Clé API Groq (assistance IA à la correction de documents, Pack
 * Classic). Remplacez "REPLACE_ME" par votre vraie clé, ou définissez la
 * variable d'environnement GROQ_API_KEY (voir includes/env.local.php, non
 * versionné — même principe que les clés de paiement).
 */
if (is_file(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
}

return [
    'groq' => [
        'api_key' => getenv('GROQ_API_KEY') ?: 'REPLACE_ME',
        'model'   => getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile',
    ],
    // Chatbot d'orientation (choix du pack), affiché à tous les visiteurs :
    // clé/modèle distincts de ceux utilisés pour la correction de documents
    // ci-dessus, pour pouvoir les faire évoluer indépendamment.
    'groq_chatbot' => [
        'api_key' => getenv('GROQ_CHATBOT_API_KEY') ?: 'REPLACE_ME',
        'model'   => getenv('GROQ_CHATBOT_MODEL') ?: 'openai/gpt-oss-120b',
    ],
];
