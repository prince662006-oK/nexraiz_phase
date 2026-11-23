<?php
/**
 * NexRaiz — Réinitialisation de mot de passe par lien temporaire à usage
 * unique (section 7 du cahier des charges). Utilisé par
 * mot-de-passe-oublie.php et reinitialiser-mot-de-passe.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const NX_RESET_TOKEN_TTL_MIN = 60;

function nx_hash_reset_token(string $token): string
{
    return hash('sha256', $token);
}

function nx_app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * Déclenche l'envoi d'un lien de réinitialisation si l'email correspond à un
 * compte actif. Ne renvoie rien et ne lève jamais d'erreur : l'appelant doit
 * afficher le même message de confirmation que l'email existe ou non, pour
 * ne pas permettre de deviner quels emails sont enregistrés.
 */
function request_password_reset(string $email): void
{
    $stmt = db()->prepare('SELECT id, prenom FROM users WHERE email = ? AND actif = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + NX_RESET_TOKEN_TTL_MIN * 60);

    $stmt = db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'], nx_hash_reset_token($token), $expiresAt]);

    $lien = nx_app_base_url() . '/reinitialiser-mot-de-passe.php?token=' . urlencode($token);

    $corps = '<p>Bonjour ' . htmlspecialchars($user['prenom']) . ',</p>'
        . '<p>Vous avez demandé la réinitialisation de votre mot de passe NexRaiz. Ce lien est valable '
        . NX_RESET_TOKEN_TTL_MIN . ' minutes :</p>'
        . '<p><a href="' . htmlspecialchars($lien) . '">' . htmlspecialchars($lien) . '</a></p>'
        . "<p>Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.</p>";

    nx_send_mail($email, 'Réinitialisation de votre mot de passe NexRaiz', $corps);
}

/**
 * @return array{reset_id:int,user_id:int,email:string,prenom:string}|null
 */
function find_valid_reset(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT pr.id AS reset_id, u.id AS user_id, u.email, u.prenom
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         ORDER BY pr.id DESC LIMIT 1'
    );
    $stmt->execute([nx_hash_reset_token($token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Met à jour le mot de passe et invalide tous les jetons de réinitialisation
 * en attente pour cet utilisateur. Retourne false si le jeton est
 * invalide/expiré/déjà utilisé.
 */
function consume_password_reset(string $token, string $newPassword): bool
{
    $reset = find_valid_reset($token);
    if (!$reset) {
        return false;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $reset['user_id']]);

        $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$reset['user_id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
