<?php
/**
 * Notification serveur-à-serveur Neemii Pay (webhook, configuré une fois
 * pour toutes dans l'espace développeur Neemii — pas d'URL par requête
 * comme CinetPay). Neemii appelle cette URL directement depuis leurs
 * serveurs — pas de session utilisateur ici. On vérifie la signature
 * HMAC-SHA256 (en-tête X-Neemii-Signature) avant de faire confiance au
 * contenu, puis on se base sur l'événement (payment.success/payment.failed)
 * plutôt que sur un statut dont le nom exact n'est pas garanti.
 */
require_once __DIR__ . '/includes/payments.php';

$corpsBrut = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_NEEMII_SIGNATURE'] ?? '';

if (!neemii_verify_webhook_signature($corpsBrut, $signature)) {
    http_response_code(401);
    exit('Signature invalide.');
}

$evenement = json_decode($corpsBrut, true) ?: [];
$type = $evenement['event'] ?? $evenement['type'] ?? '';
$neemiiId = $evenement['data']['id'] ?? $evenement['data']['payment_id'] ?? $evenement['id'] ?? '';

if ($type === 'payment.success' && $neemiiId !== '') {
    $stmt = db()->prepare('SELECT id FROM paiements WHERE reference_externe = ?');
    $stmt->execute([(string) $neemiiId]);
    $paiementId = $stmt->fetchColumn();
    if ($paiementId) {
        marquer_paiement_paye((int) $paiementId);
    }
}

http_response_code(200);
echo 'OK';
