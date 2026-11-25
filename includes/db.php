<?php
/**
 * NexRaiz — Connexion base de données (PDO / MySQL).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Comme ai-config.php/opportunites-config.php/payment-config.php :
        // charge les vraies valeurs si le fichier existe. Nécessaire ici
        // aussi, sinon une page qui ne passe par aucun de ces 3 fichiers
        // (ex. la connexion) peut appeler db() avant que putenv() ait
        // tourné sur ce processus PHP-FPM, et retomber sur un identifiant
        // obsolète déjà fixé par une requête précédente du même worker.
        if (is_file(__DIR__ . '/env.local.php')) {
            require_once __DIR__ . '/env.local.php';
        }
        $host = getenv('NEXRAIZ_DB_HOST') ?: '127.0.0.1';
        $name = getenv('NEXRAIZ_DB_NAME') ?: 'nexraiz';
        $user = getenv('NEXRAIZ_DB_USER') ?: 'root';
        $pass = getenv('NEXRAIZ_DB_PASS') ?: '';

        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/**
 * Racine commune du stockage privé (hors webroot) : documents, livrables,
 * ressources, OCR, certifications. Par défaut, calculée en remontant 3
 * niveaux depuis includes/ (ex. "xampp" si le projet vit dans
 * "xampp/htdocs/nexraiz_phase") — mais cette profondeur fixe ne tient pas
 * sur tous les hébergements : sur un cPanel où l'app est à la racine de
 * public_html (au lieu d'un sous-dossier comme en local), remonter 3
 * niveaux dépasse le dossier personnel et tombe sur un chemin non
 * accessible en écriture, ce qui provoque une erreur 500. Définir
 * NEXRAIZ_PRIVATE_STORAGE_ROOT (ex. "/home/nexraiz") dans
 * includes/env.local.php fixe le chemin explicitement, sans dépendre de
 * cette profondeur.
 */
function nx_racine_stockage_prive(): string
{
    $override = getenv('NEXRAIZ_PRIVATE_STORAGE_ROOT');
    if ($override) {
        return rtrim($override, '/\\');
    }
    return dirname(__DIR__, 3);
}

/**
 * URL de base de l'application (sans slash final), pour construire des URLs
 * absolues dans des contextes qui n'ont pas de requête HTTP en cours (email
 * envoyé par un cron, pixel de suivi, redirection OAuth). Priorité à
 * NEXRAIZ_APP_URL (includes/env.local.php) ; à défaut, reconstruite depuis
 * la requête courante — ne fonctionne alors que dans un contexte web.
 */
function nx_base_url(): string
{
    $override = getenv('NEXRAIZ_APP_URL');
    if ($override) {
        return rtrim($override, '/');
    }
    if (empty($_SERVER['HTTP_HOST'])) {
        error_log('[NexRaiz] nx_base_url() appelée hors contexte web sans NEXRAIZ_APP_URL défini.');
        return '';
    }
    $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dossier = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $schema . '://' . $_SERVER['HTTP_HOST'] . $dossier;
}
