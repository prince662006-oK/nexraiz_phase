<?php
/**
 * Retour du client depuis Stripe Checkout (success_url).
 * Partagé par tous les packs : le pack et le dossier sont retrouvés à
 * partir du paiement lui-même, pas d'un pack codé en dur.
 * Le webhook (stripe-webhook.php) est la source de vérité en production ;
 * cette page sert de filet de sécurité, notamment en local où Stripe ne
 * peut pas atteindre le webhook.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

$user = require_login('mon-espace.php');

$sessionId = $_GET['session_id'] ?? '';
$paiementId = (int) ($_GET['paiement'] ?? 0);

$packSlug = 'assistance';
if ($paiementId > 0) {
    $stmt = db()->prepare(
        'SELECT d.user_id, pk.slug AS pack_slug
         FROM paiements p
         JOIN dossiers d ON d.id = p.dossier_id
         JOIN packs pk ON pk.id = d.pack_id
         WHERE p.id = ?'
    );
    $stmt->execute([$paiementId]);
    $row = $stmt->fetch();

    if ($row && (int) $row['user_id'] === (int) $user['id']) {
        $packSlug = $row['pack_slug'];
        if ($sessionId !== '' && stripe_session_est_payee($sessionId)) {
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
