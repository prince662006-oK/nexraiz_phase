<?php
/**
 * Endpoint JSON : créneaux déjà pris pour un pack et un jour donnés
 * (voir includes/rendezvous.php). Appelé en AJAX quand le candidat change
 * de jour dans le calendrier de prise de rendez-vous, pour griser les
 * créneaux indisponibles sans recharger la page.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

// Endpoint JSON appelé en AJAX : ne jamais utiliser require_login() ici, qui
// redirige (header Location) vers une page HTML de connexion. Un fetch()
// suit cette redirection silencieusement et reçoit du HTML à la place du
// JSON attendu, ce qui fait planter r.json() côté client avec "Unexpected
// token '<' ... is not valid JSON" dès que la session a expiré.
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expirée, merci de vous reconnecter.']);
    exit;
}

$packsValides = ['assistance', 'classic', 'booster', 'wellbeing'];
$pack = $_GET['pack'] ?? '';
$jour = $_GET['jour'] ?? '';

if (!in_array($pack, $packsValides, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

$packId = get_pack_id($pack);
if (!$packId) {
    http_response_code(400);
    echo json_encode(['error' => 'Pack inconnu']);
    exit;
}

echo json_encode(['pris' => creneaux_pris($packId, $jour)]);
