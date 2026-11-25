<?php
/**
 * NexRaiz — Connexion Google (OAuth) pour l'envoi de candidatures depuis le
 * vrai Gmail du candidat plutôt que depuis l'adresse technique de NexRaiz.
 * Portée volontairement restreinte à gmail.send + email : on n'envoie
 * jamais rien d'autre que ses propres candidatures, on ne lit jamais ses
 * messages.
 *
 * Tant que l'application Google n'est pas vérifiée (processus Google,
 * potentiellement long pour un scope sensible comme gmail.send), seuls les
 * comptes ajoutés comme "testeurs" dans la Google Cloud Console peuvent
 * mener ce flux à son terme — les autres verront un écran de refus Google,
 * géré ici comme un simple repli (voir google-oauth-callback.php).
 */
require_once __DIR__ . '/db.php';

function google_oauth_config(): array
{
    static $config = null;
    if ($config === null) {
        if (is_file(__DIR__ . '/env.local.php')) {
            require_once __DIR__ . '/env.local.php';
        }
        $config = [
            'client_id'     => getenv('GOOGLE_OAUTH_CLIENT_ID') ?: 'REPLACE_ME',
            'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: 'REPLACE_ME',
            'redirect_uri'  => getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: (nx_base_url() . '/google-oauth-callback.php'),
        ];
    }
    return $config;
}

function google_oauth_configure(): bool
{
    return strpos(google_oauth_config()['client_id'], 'REPLACE_ME') === false;
}

/** URL de consentement Google pour l'utilisateur $userId (déjà authentifié côté NexRaiz). */
function google_oauth_url(int $userId): string
{
    $cfg = google_oauth_config();
    $nonce = bin2hex(random_bytes(16));
    $payload = $userId . '|' . $nonce;
    $signature = hash_hmac('sha256', $payload, $cfg['client_secret']);
    $state = base64_encode($payload . '|' . $signature);

    $params = [
        'client_id'     => $cfg['client_id'],
        'redirect_uri'  => $cfg['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/gmail.send email',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/** Vérifie le "state" signé reçu au retour de Google ; retourne l'id utilisateur si valide. */
function google_oauth_verifier_state(string $state): ?int
{
    $decoded = base64_decode($state, true);
    if ($decoded === false) {
        return null;
    }
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) {
        return null;
    }
    [$userId, $nonce, $signature] = $parts;
    $cfg = google_oauth_config();
    $attendu = hash_hmac('sha256', $userId . '|' . $nonce, $cfg['client_secret']);
    if (!hash_equals($attendu, $signature)) {
        return null;
    }
    return (int) $userId;
}

function google_oauth_echanger_code(string $code): array
{
    $cfg = google_oauth_config();
    $donnees = nx_google_oauth_requete([
        'code'          => $code,
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'redirect_uri'  => $cfg['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]);
    return $donnees;
}

/** @return array{access_token:string,expires_in:int} */
function google_oauth_rafraichir(string $refreshToken): array
{
    $cfg = google_oauth_config();
    return nx_google_oauth_requete([
        'refresh_token' => $refreshToken,
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'grant_type'    => 'refresh_token',
    ]);
}

function nx_google_oauth_requete(array $corps): array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($corps),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $reponseBrute = curl_exec($ch);
    $erreurCurl = curl_error($ch);
    $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($reponseBrute === false) {
        throw new RuntimeException('Connexion à Google impossible : ' . $erreurCurl);
    }
    $donnees = json_decode($reponseBrute, true);
    if (!is_array($donnees) || $codeHttp >= 400) {
        $message = $donnees['error_description'] ?? $donnees['error'] ?? ('Erreur Google OAuth HTTP ' . $codeHttp);
        throw new RuntimeException($message);
    }
    return $donnees;
}

/** Lit l'email du compte Google depuis l'id_token (simple décodage, pas une vérification cryptographique — Google nous l'a envoyé directement en HTTPS, pas besoin de revérifier sa propre signature). */
function google_email_depuis_id_token(string $idToken): ?string
{
    $parties = explode('.', $idToken);
    if (count($parties) < 2) {
        return null;
    }
    $payload = json_decode(base64_decode(strtr($parties[1], '-_', '+/')), true);
    return $payload['email'] ?? null;
}

function google_compte_pour_utilisateur(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM google_comptes WHERE user_id = ?');
    $stmt->execute([$userId]);
    $compte = $stmt->fetch();
    return $compte ?: null;
}

function google_compte_enregistrer(int $userId, string $email, string $accessToken, ?string $refreshToken, int $expiresIn): void
{
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);
    if ($refreshToken) {
        $stmt = db()->prepare(
            'INSERT INTO google_comptes (user_id, google_email, access_token, refresh_token, expires_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE google_email = VALUES(google_email), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at)'
        );
        $stmt->execute([$userId, $email, $accessToken, $refreshToken, $expiresAt]);
        return;
    }
    // Google ne renvoie un refresh_token qu'à la première autorisation (ou
    // quand prompt=consent force un nouveau consentement, ce qu'on fait
    // toujours) — ce chemin ne devrait normalement jamais arriver ; gardé
    // par sécurité pour ne jamais écraser un refresh_token existant par
    // rien si jamais Google n'en renvoie pas.
    $stmt = db()->prepare('UPDATE google_comptes SET google_email = ?, access_token = ?, expires_at = ? WHERE user_id = ?');
    $stmt->execute([$email, $accessToken, $expiresAt, $userId]);
}

function google_compte_deconnecter(int $userId): void
{
    $stmt = db()->prepare('DELETE FROM google_comptes WHERE user_id = ?');
    $stmt->execute([$userId]);
}

/**
 * Jeton d'accès Google utilisable pour ce client, rafraîchi si nécessaire.
 * Retourne null si aucun compte connecté, ou si le rafraîchissement échoue
 * (jeton révoqué par l'utilisateur côté Google) — c'est ce null qui permet
 * à l'appelant de retomber silencieusement sur l'envoi NexRaiz habituel.
 */
function google_access_token_valide(int $userId): ?string
{
    $compte = google_compte_pour_utilisateur($userId);
    if (!$compte) {
        return null;
    }
    if (strtotime($compte['expires_at']) > time()) {
        return $compte['access_token'];
    }
    try {
        $tokens = google_oauth_rafraichir($compte['refresh_token']);
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) ($tokens['expires_in'] ?? 3600)) - 60);
        $stmt = db()->prepare('UPDATE google_comptes SET access_token = ?, expires_at = ? WHERE user_id = ?');
        $stmt->execute([$tokens['access_token'], $expiresAt, $userId]);
        return $tokens['access_token'];
    } catch (\Throwable $e) {
        error_log('[NexRaiz google-oauth] Échec du rafraîchissement pour user ' . $userId . ' : ' . $e->getMessage());
        return null;
    }
}
