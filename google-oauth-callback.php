<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-oauth.php';

$state = $_GET['state'] ?? '';
$userId = $state !== '' ? google_oauth_verifier_state($state) : null;

// Si une session est active et ne correspond pas à l'utilisateur encodé
// dans le state signé, on refuse plutôt que de lier le compte Google à la
// mauvaise personne (ex. session expirée puis reconnexion avec un autre
// compte pendant le va-et-vient vers Google).
$connecte = current_user();
if ($userId === null || ($connecte && (int) $connecte['id'] !== $userId)) {
    header('Location: parametres.php?google=erreur');
    exit;
}

if (isset($_GET['error']) || empty($_GET['code'])) {
    // Consentement refusé par l'utilisateur, ou compte non ajouté comme
    // testeur tant que l'application Google n'est pas vérifiée.
    header('Location: parametres.php?google=refuse');
    exit;
}

try {
    $tokens = google_oauth_echanger_code($_GET['code']);
    $email = google_email_depuis_id_token($tokens['id_token'] ?? '');
    if (!$email) {
        throw new RuntimeException("Impossible de récupérer l'adresse Gmail depuis la réponse Google.");
    }
    google_compte_enregistrer($userId, $email, $tokens['access_token'], $tokens['refresh_token'] ?? null, (int) ($tokens['expires_in'] ?? 3600));
    header('Location: parametres.php?google=connecte');
} catch (\Throwable $e) {
    error_log('[NexRaiz google-oauth-callback] ' . $e->getMessage());
    header('Location: parametres.php?google=erreur');
}
exit;
