<?php
/**
 * Webhook Stripe (à configurer dans le Dashboard Stripe sur
 * https://votre-domaine/stripe-webhook.php, événement checkout.session.completed).
 * Source de vérité en production ; paiement-retour.php sert de filet de
 * sécurité pour le développement en local, où Stripe ne peut pas nous
 * joindre directement.
 */
require_once __DIR__ . '/includes/payments.php';

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = payment_config()['stripe']['webhook_secret'];

try {
    $event = nx_stripe_webhook_verifier($payload, $sigHeader, $webhookSecret);
} catch (\Throwable $e) {
    http_response_code(400);
    exit('Signature invalide.');
}

if (($event['type'] ?? '') === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $paiementId = (int) ($session['metadata']['paiement_id'] ?? 0);
    if ($paiementId > 0) {
        marquer_paiement_paye($paiementId);
    }
}

http_response_code(200);
echo 'OK';
