<?php
/**
 * Retour du client depuis CinetPay. Partagé par tous les packs : le pack
 * et le dossier sont retrouvés à partir du paiement lui-même. On revérifie
 * le statut réel auprès de CinetPay (ne jamais faire confiance aux seuls
 * paramètres d'URL) ; la notification serveur-à-serveur
 * (cinetpay-notify.php) reste la source de vérité en production.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

$user = require_login('mon-espace.php');
$paiementId = (int) ($_GET['paiement'] ?? 0);

$packSlug = 'assistance';
if ($paiementId > 0) {
    $stmt = db()->prepare(
        'SELECT p.reference_externe, d.user_id, pk.slug AS pack_slug
         FROM paiements p
         JOIN dossiers d ON d.id = p.dossier_id
         JOIN packs pk ON pk.id = d.pack_id
         WHERE p.id = ?'
    );
    $stmt->execute([$paiementId]);
    $row = $stmt->fetch();

    if ($row && (int) $row['user_id'] === (int) $user['id']) {
        $packSlug = $row['pack_slug'];
        if ($row['reference_externe'] && cinetpay_transaction_est_payee($row['reference_externe'])) {
            marquer_paiement_paye($paiementId);
        }
    }
}

$steps = pack_steps($packSlug);
$paiementStep = null;
foreach ($steps as $num => $step) {
    if ($step['label'] === 'Paiement') { $paiementStep = $num; break; }
}
$targetStep = $paiementStep !== null ? $paiementStep + 1 : max(array_keys($steps));

header('Location: ' . step_url($targetStep, $packSlug));
exit;
