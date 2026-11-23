<?php
/**
 * Endpoint JSON (AJAX) : chatbot d'orientation public (choix du pack),
 * affiché en bulle de chat sur tout le site. Accessible sans connexion —
 * c'est volontaire, un visiteur pas encore client doit pouvoir s'en servir.
 * L'historique de conversation est tenu côté navigateur (pas de stockage
 * serveur) : le client renvoie les derniers échanges à chaque message.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

// Le widget envoie un corps JSON (pas un formulaire classique), donc le
// jeton CSRF n'atterrit pas dans $_POST : on le valide nous-mêmes à partir
// du corps décodé plutôt que d'appeler require_csrf().
$corps = json_decode(file_get_contents('php://input'), true) ?: [];
$jetonRecu = (string) ($corps['_csrf'] ?? '');
if (empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $jetonRecu)) {
    http_response_code(403);
    echo json_encode(['error' => 'Session expirée ou requête invalide. Merci de recharger la page et de réessayer.']);
    exit;
}

// Limite d'usage par session (40/h) : confortable pour un visiteur normal,
// mais contournable par un script qui n'envoie jamais de cookie (chaque
// requête repart alors avec une session, donc un compteur, vierge). On la
// garde pour un échec rapide dans le cas normal, mais la vraie protection
// contre l'abus est la limite par IP ci-dessous, en base, qui survit à
// l'absence de session.
$maintenant = time();
if (empty($_SESSION['chatbot_fenetre']) || ($maintenant - $_SESSION['chatbot_fenetre']) > 3600) {
    $_SESSION['chatbot_fenetre'] = $maintenant;
    $_SESSION['chatbot_compteur'] = 0;
}
if ($_SESSION['chatbot_compteur'] >= 40) {
    http_response_code(429);
    echo json_encode(['error' => "Vous avez atteint la limite de messages pour l'instant. Réessayez un peu plus tard, ou contactez-nous directement."]);
    exit;
}

// Limite par IP (60/h), en base : ne dépend d'aucun cookie, donc ne peut
// pas être contournée en répétant des requêtes "sans session".
$ip = nx_client_ip();
$stmt = db()->prepare('SELECT COUNT(*) FROM chatbot_requests WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
$stmt->execute([$ip]);
if ((int) $stmt->fetchColumn() >= 60) {
    http_response_code(429);
    echo json_encode(['error' => "Vous avez atteint la limite de messages pour l'instant. Réessayez un peu plus tard, ou contactez-nous directement."]);
    exit;
}
db()->prepare('INSERT INTO chatbot_requests (ip) VALUES (?)')->execute([$ip]);
// Purge occasionnelle des anciennes lignes (pas de cron sur ce projet) :
// évite une croissance illimitée de la table sans impacter chaque requête.
if (random_int(1, 100) === 1) {
    db()->exec('DELETE FROM chatbot_requests WHERE created_at < (NOW() - INTERVAL 1 DAY)');
}

$message = trim((string) ($corps['message'] ?? ''));
$historiqueRecu = $corps['historique'] ?? [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message vide.']);
    exit;
}
if (mb_strlen($message) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Message trop long (500 caractères maximum).']);
    exit;
}

// On ne garde que les derniers échanges, avec un rôle valide et une
// longueur raisonnable : l'historique vient du navigateur, donc non fiable.
$historique = [];
if (is_array($historiqueRecu)) {
    foreach (array_slice($historiqueRecu, -8) as $echange) {
        $role = $echange['role'] ?? '';
        $contenu = trim((string) ($echange['content'] ?? ''));
        if (in_array($role, ['user', 'assistant'], true) && $contenu !== '' && mb_strlen($contenu) <= 800) {
            $historique[] = ['role' => $role, 'content' => mb_substr($contenu, 0, 800)];
        }
    }
}

$_SESSION['chatbot_compteur']++;

try {
    $reponse = groq_chatbot_repondre($message, $historique);
    echo json_encode(['reponse' => $reponse]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
