<?php
/**
 * NexRaiz — Protection CSRF.
 * Un jeton unique par session, à inclure dans chaque formulaire POST via
 * csrf_field(), et vérifié en tout début de traitement via require_csrf().
 */
require_once __DIR__ . '/auth.php';

function csrf_token(): string
{
    nx_session_start();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Champ caché à insérer dans chaque <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_valid(): bool
{
    nx_session_start();
    $submitted = $_POST['_csrf'] ?? '';
    return !empty($_SESSION['_csrf']) && is_string($submitted) && $submitted !== '' && hash_equals($_SESSION['_csrf'], $submitted);
}

/**
 * À appeler en tout début du bloc de traitement d'une requête POST issue
 * d'un formulaire du site. Arrête l'exécution avec 403 si le jeton est
 * absent ou invalide (session expirée, page ouverte trop longtemps,
 * requête forgée depuis un autre site...).
 */
function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_valid()) {
        http_response_code(403);
        exit('Session expirée ou requête invalide. Merci de recharger la page et de réessayer.');
    }
}
