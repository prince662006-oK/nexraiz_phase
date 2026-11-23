<?php
/**
 * Notification serveur-à-serveur CinetPay (notify_url).
 * CinetPay appelle cette URL directement depuis leurs serveurs — pas de
 * session utilisateur ici. On revérifie toujours le statut via l'API
 * CinetPay plutôt que de faire confiance au contenu du POST.
 */
require_once __DIR__ . '/includes/payments.php';

$transactionId = $_POST['cpm_trans_id'] ?? $_POST['transaction_id'] ?? '';

if ($transactionId !== '' && cinetpay_transaction_est_payee($transactionId)) {
    $stmt = db()->prepare('SELECT id FROM paiements WHERE reference_externe = ?');
    $stmt->execute([$transactionId]);
    $paiementId = $stmt->fetchColumn();
    if ($paiementId) {
        marquer_paiement_paye((int) $paiementId);
    }
}

http_response_code(200);
echo 'OK';
