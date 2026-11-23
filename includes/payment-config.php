<?php
/**
 * NexRaiz — Clés API de paiement.
 * Remplacez les valeurs "REPLACE_ME" par vos vraies clés (test ou live).
 * Vous pouvez aussi les définir comme variables d'environnement du serveur
 * (STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY, STRIPE_WEBHOOK_SECRET,
 * CINETPAY_API_KEY, CINETPAY_SITE_ID, NEEMII_PUBLIC_KEY, NEEMII_SECRET_KEY,
 * NEEMII_ENCRYPTION_KEY, NEEMII_WEBHOOK_SECRET) plutôt que de les écrire ici
 * en dur. Ce fichier ne doit jamais contenir de vraie clé secrète : gardez
 * les valeurs réelles dans includes/env.local.php (non versionné, voir ce
 * fichier — absent par défaut, à créer localement) ou dans la config du
 * serveur de production.
 */
if (is_file(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
}

return [
    'stripe' => [
        'secret_key'      => getenv('STRIPE_SECRET_KEY') ?: 'REPLACE_ME',
        'publishable_key' => getenv('STRIPE_PUBLISHABLE_KEY') ?: 'REPLACE_ME',
        'webhook_secret'  => getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_REPLACE_ME',
    ],
    'cinetpay' => [
        'api_key' => getenv('CINETPAY_API_KEY') ?: 'REPLACE_ME',
        'site_id' => getenv('CINETPAY_SITE_ID') ?: 'REPLACE_ME',
    ],
    'neemii' => [
        'public_key'     => getenv('NEEMII_PUBLIC_KEY') ?: 'REPLACE_ME',
        'secret_key'     => getenv('NEEMII_SECRET_KEY') ?: 'REPLACE_ME',
        'merchant_id'    => getenv('NEEMII_MERCHANT_ID') ?: 'REPLACE_ME',
        // "Clé de chiffrement" de l'espace développeur Neemii. Non utilisée
        // par l'intégration actuelle (API secrète en Bearer + webhook signé
        // HMAC-SHA256 avec la secret key, d'après la documentation
        // publique) ; conservée si Neemii l'exige pour d'autres opérations.
        'encryption_key' => getenv('NEEMII_ENCRYPTION_KEY') ?: 'REPLACE_ME',
        // Secret de signature des webhooks, configuré séparément dans
        // l'espace développeur Neemii (Réglages > Webhooks). Si vous n'en
        // avez pas généré de dédié, laissez vide : le code retombe sur la
        // secret key ci-dessus.
        'webhook_secret' => getenv('NEEMII_WEBHOOK_SECRET') ?: '',
    ],
];
