<?php
/**
 * NexRaiz — Client Stripe minimal, sans dépendance au SDK officiel
 * `stripe/stripe-php` (retiré de composer.json : plusieurs milliers de
 * fichiers pour une centaine de ressources API dont ce projet n'utilise
 * que 2 — Checkout Session et Webhook — ce qui dépassait la limite
 * d'inodes de certains hébergements mutualisés et empêchait l'extraction
 * de vendor/ sur cPanel). N'implémente que ce qui est réellement utilisé
 * par includes/payments.php et stripe-webhook.php : créer/récupérer une
 * session Checkout, et vérifier la signature d'un webhook. Mêmes noms de
 * champs que l'API Stripe officielle (JSON décodé en tableau associatif),
 * pour que le reste du code n'ait presque rien à changer.
 */

/** Appel HTTP générique vers l'API Stripe (authentification par clé secrète en Basic Auth). */
function nx_stripe_requete(string $methode, string $chemin, string $secretKey, array $corps = []): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($chemin, '/'));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $secretKey . ':',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
    ];
    if ($methode === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($corps);
    }
    curl_setopt_array($ch, $options);
    $reponseBrute = curl_exec($ch);
    $erreurCurl = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($reponseBrute === false) {
        throw new RuntimeException('Connexion à Stripe impossible : ' . $erreurCurl);
    }
    $donnees = json_decode($reponseBrute, true);
    if ($code >= 400) {
        $message = $donnees['error']['message'] ?? ('Erreur Stripe HTTP ' . $code);
        throw new RuntimeException($message);
    }
    return is_array($donnees) ? $donnees : [];
}

/**
 * Crée une session Stripe Checkout. $params utilise exactement les mêmes
 * clés que l'API Stripe (mode, payment_method_types, line_items,
 * success_url, cancel_url, metadata...) — http_build_query() produit
 * naturellement la notation à crochets attendue par l'API pour les
 * tableaux imbriqués (ex. line_items[0][price_data][currency]).
 */
function nx_stripe_checkout_creer(string $secretKey, array $params): array
{
    return nx_stripe_requete('POST', 'checkout/sessions', $secretKey, $params);
}

function nx_stripe_checkout_recuperer(string $secretKey, string $sessionId): array
{
    return nx_stripe_requete('GET', 'checkout/sessions/' . urlencode($sessionId), $secretKey);
}

/**
 * Vérifie la signature d'un webhook Stripe (schéma officiel : HMAC-SHA256
 * de "{timestamp}.{payload}" avec le secret de webhook, comparaison en
 * temps constant). Tolérance de 5 minutes sur l'horodatage, comme le SDK
 * officiel, pour limiter le rejeu. Lève une exception si la signature est
 * invalide ou absente — jamais de traitement d'un webhook non vérifié.
 *
 * @return array L'événement décodé (mêmes clés que l'API Stripe : type, data...).
 */
function nx_stripe_webhook_verifier(string $payload, string $sigHeader, string $secret): array
{
    $paires = [];
    foreach (explode(',', $sigHeader) as $partie) {
        [$cle, $valeur] = array_pad(explode('=', trim($partie), 2), 2, null);
        $paires[$cle][] = $valeur;
    }
    $timestamp = $paires['t'][0] ?? null;
    $signaturesV1 = $paires['v1'] ?? [];
    if (!$timestamp || empty($signaturesV1)) {
        throw new RuntimeException('En-tête de signature Stripe absent ou mal formé.');
    }
    if (abs(time() - (int) $timestamp) > 300) {
        throw new RuntimeException('Horodatage du webhook trop ancien.');
    }

    $signatureAttendue = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $valide = false;
    foreach ($signaturesV1 as $signature) {
        if (hash_equals($signatureAttendue, (string) $signature)) {
            $valide = true;
            break;
        }
    }
    if (!$valide) {
        throw new RuntimeException('Signature du webhook invalide.');
    }

    $evenement = json_decode($payload, true);
    if (!is_array($evenement)) {
        throw new RuntimeException('Corps du webhook illisible.');
    }
    return $evenement;
}
