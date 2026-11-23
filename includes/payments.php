<?php
/**
 * NexRaiz — Intégration paiement (Stripe pour carte, CinetPay pour mobile
 * money / Afrique de l'Ouest). Toutes les fonctions lèvent une
 * RuntimeException lisible en cas d'échec, à afficher tel quel au client.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bootstrap.php'; // fournit pack_steps() (requiert lui-même auth.php)
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mailer.php';

function payment_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/payment-config.php';
    }
    return $config;
}

function stripe_configured(): bool
{
    return strpos(payment_config()['stripe']['secret_key'], 'REPLACE_ME') === false;
}

function cinetpay_configured(): bool
{
    return strpos(payment_config()['cinetpay']['api_key'], 'REPLACE_ME') === false;
}

function neemii_configured(): bool
{
    return strpos(payment_config()['neemii']['secret_key'], 'REPLACE_ME') === false;
}

function create_paiement_record(int $dossierId, float $montant, string $methode): int
{
    $stmt = db()->prepare('INSERT INTO paiements (dossier_id, montant, devise, methode) VALUES (?, ?, "XOF", ?)');
    $stmt->execute([$dossierId, $montant, $methode]);
    return (int) db()->lastInsertId();
}

/**
 * Crée une session Stripe Checkout et renvoie l'URL vers laquelle rediriger le client.
 * @throws RuntimeException si Stripe n'est pas configuré ou refuse la requête.
 */
function create_stripe_checkout_url(int $paiementId, float $montant, string $numeroDossier, string $successUrl, string $cancelUrl): string
{
    if (!stripe_configured()) {
        throw new RuntimeException("Le paiement par carte n'est pas encore configuré. Merci de choisir un autre moyen de paiement ou de réessayer plus tard.");
    }

    \Stripe\Stripe::setApiKey(payment_config()['stripe']['secret_key']);

    try {
        $session = \Stripe\Checkout\Session::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'xof',
                    'unit_amount' => (int) round($montant),
                    'product_data' => ['name' => 'NexRaiz — Dossier ' . $numeroDossier],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'paiement=' . $paiementId . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => ['paiement_id' => (string) $paiementId],
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new RuntimeException('Stripe a refusé la requête : ' . $e->getMessage());
    }

    $stmt = db()->prepare('UPDATE paiements SET reference_externe = ? WHERE id = ?');
    $stmt->execute([$session->id, $paiementId]);

    return $session->url;
}

/**
 * Vérifie le statut d'une session Stripe Checkout (utilisé sur la page de retour,
 * en complément du webhook qui ne peut pas atteindre localhost).
 */
function stripe_session_est_payee(string $sessionId): bool
{
    \Stripe\Stripe::setApiKey(payment_config()['stripe']['secret_key']);
    try {
        $session = \Stripe\Checkout\Session::retrieve($sessionId);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return false;
    }
    return $session->payment_status === 'paid';
}

/**
 * Initialise un paiement CinetPay et renvoie l'URL de paiement à laquelle rediriger le client.
 * @throws RuntimeException si CinetPay n'est pas configuré ou renvoie une erreur.
 */
function create_cinetpay_payment_url(int $paiementId, float $montant, string $numeroDossier, string $returnUrl, string $notifyUrl): string
{
    if (!cinetpay_configured()) {
        throw new RuntimeException("Le paiement Mobile Money n'est pas encore configuré. Merci de choisir un autre moyen de paiement ou de réessayer plus tard.");
    }

    $config = payment_config()['cinetpay'];
    $transactionId = 'NX' . $paiementId . '-' . time();

    $payload = [
        'apikey'         => $config['api_key'],
        'site_id'        => $config['site_id'],
        'transaction_id' => $transactionId,
        'amount'         => (int) round($montant),
        'currency'       => 'XOF',
        'description'    => 'Pack Assistance — Dossier ' . $numeroDossier,
        'return_url'     => $returnUrl,
        'notify_url'     => $notifyUrl,
        'channels'       => 'ALL',
    ];

    $reponse = cinetpay_post('https://api-checkout.cinetpay.com/v2/payment', $payload);
    if (!isset($reponse['data']['payment_url'])) {
        throw new RuntimeException($reponse['message'] ?? 'Réponse CinetPay invalide.');
    }

    $stmt = db()->prepare('UPDATE paiements SET reference_externe = ? WHERE id = ?');
    $stmt->execute([$transactionId, $paiementId]);

    return $reponse['data']['payment_url'];
}

/**
 * Interroge CinetPay pour connaître le vrai statut d'une transaction
 * (à utiliser côté serveur : ne jamais faire confiance aux seuls paramètres
 * envoyés par le navigateur sur la page de retour).
 */
function cinetpay_transaction_est_payee(string $transactionId): bool
{
    $config = payment_config()['cinetpay'];
    $reponse = cinetpay_post('https://api-checkout.cinetpay.com/v2/payment/check', [
        'apikey'         => $config['api_key'],
        'site_id'        => $config['site_id'],
        'transaction_id' => $transactionId,
    ]);
    return ($reponse['code'] ?? null) === '00' && ($reponse['data']['status'] ?? null) === 'ACCEPTED';
}

/**
 * Initialise un paiement Neemii Pay (mobile money) et renvoie l'URL de
 * paiement (checkout_url) à laquelle rediriger le client.
 *
 * Intégration confirmée par la spécification OpenAPI officielle de Neemii
 * Pay (GET https://www.neemii.com/api/v1/openapi) : POST /payments, body
 * {amount, currency, customer:{email, name?, phone?}, description?,
 * payment_method?, redirect_url?, metadata?}, réponse enveloppée dans
 * {success, data, error, meta}. L'URL de notification (webhook) n'est pas
 * transmise par requête : elle se configure une fois pour toutes dans
 * l'espace développeur Neemii (voir neemii-notify.php). Les noms de champs
 * à l'intérieur de "data" (checkout_url, id...) ne sont pas détaillés par
 * le schéma OpenAPI (réponses non typées) : on garde des repli
 * raisonnables au cas où.
 *
 * @throws RuntimeException si Neemii n'est pas configuré ou renvoie une erreur.
 */
function create_neemii_payment_url(int $paiementId, float $montant, string $numeroDossier, string $customerEmail, string $customerNom, string $returnUrl): string
{
    if (!neemii_configured()) {
        throw new RuntimeException("Le paiement Mobile Money n'est pas encore configuré. Merci de choisir un autre moyen de paiement ou de réessayer plus tard.");
    }

    $reponse = neemii_request('POST', '/payments', [
        'amount'       => (int) round($montant),
        'currency'     => 'XOF',
        'customer'     => ['email' => $customerEmail, 'name' => $customerNom],
        'description'  => 'NexRaiz — Dossier ' . $numeroDossier,
        'redirect_url' => $returnUrl,
        'metadata'     => ['paiement_id' => $paiementId],
    ], 'order-' . $paiementId);

    if (($reponse['success'] ?? null) === false) {
        throw new RuntimeException($reponse['error']['message'] ?? 'Réponse Neemii Pay invalide.');
    }

    $data = $reponse['data'] ?? $reponse;
    $checkoutUrl = $data['checkout_url'] ?? $data['payment_url'] ?? null;
    $neemiiId = $data['id'] ?? $data['payment_id'] ?? ('order-' . $paiementId);

    if (!$checkoutUrl) {
        throw new RuntimeException('Réponse Neemii Pay invalide (URL de paiement absente).');
    }

    $stmt = db()->prepare('UPDATE paiements SET reference_externe = ? WHERE id = ?');
    $stmt->execute([(string) $neemiiId, $paiementId]);

    neemii_warmer_url_paiement($checkoutUrl);

    return $checkoutUrl;
}

/**
 * Atténuation d'un bug constaté côté Neemii Pay : leur page de paiement
 * hébergée reste parfois bloquée sur un état incomplet (montant et moyens
 * de paiement absents) lors de sa toute première ouverture après création
 * du paiement, et ne se répare qu'après un rechargement — reproduit en test
 * à plusieurs reprises (voir discussion). On "préchauffe" donc la page en
 * la chargeant une première fois depuis notre serveur, avant que le client
 * ne l'ouvre lui-même : le client arrive alors sur une page déjà "vue" une
 * fois, ce qui évite le blocage dans nos tests. Best effort uniquement :
 * toute erreur est ignorée, elle ne doit jamais empêcher le client de payer.
 */
function neemii_warmer_url_paiement(string $checkoutUrl): void
{
    // Délai volontairement court : il s'agit d'amorcer la requête côté
    // Neemii, pas d'attendre le rendu complet de leur page — un délai plus
    // long ferait autant attendre le client que le bug qu'on cherche à
    // éviter.
    $ch = curl_init($checkoutUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT_MS     => 2000,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Interroge Neemii Pay pour connaître le vrai statut d'un paiement, via
 * l'endpoint dédié POST /payments/{id}/verify confirmé par leur
 * spécification OpenAPI (à utiliser côté serveur, en complément du
 * webhook : ne jamais faire confiance aux seuls paramètres envoyés par le
 * navigateur sur la page de retour).
 *
 * Testé en réel le 23/09/2026 : un paiement fraîchement créé renvoie
 * "pending", puis "processing" après un appel à /verify — confirmés. La
 * valeur exacte du statut final réussi ("completed" ? "succeeded" ?) n'a
 * pas pu être observée (aucun paiement sandbox mené à terme). Le webhook
 * (neemii-notify.php, événements payment.success/payment.failed, ceux-là
 * bien documentés) reste donc la source de vérité principale ; cette
 * fonction est une vérification best effort en secours.
 */
function neemii_transaction_est_payee(string $neemiiId): bool
{
    try {
        $reponse = neemii_request('POST', '/payments/' . rawurlencode($neemiiId) . '/verify');
    } catch (RuntimeException $e) {
        return false;
    }
    if (($reponse['success'] ?? null) === false) {
        return false;
    }
    $data = $reponse['data'] ?? $reponse;
    $statut = strtolower((string) ($data['status'] ?? ''));
    return in_array($statut, ['succeeded', 'success', 'paid', 'completed'], true);
}

/**
 * Vérifie la signature HMAC-SHA256 d'un webhook Neemii Pay (en-tête
 * X-Neemii-Signature, calculée sur le corps brut de la requête).
 */
function neemii_verify_webhook_signature(string $corpsBrut, string $signatureRecue): bool
{
    $config = payment_config()['neemii'];
    $secret = $config['webhook_secret'] !== '' ? $config['webhook_secret'] : $config['secret_key'];
    if ($secret === '' || $secret === 'REPLACE_ME' || $signatureRecue === '') {
        return false;
    }
    $signatureCalculee = hash_hmac('sha256', $corpsBrut, $secret);
    return hash_equals($signatureCalculee, $signatureRecue);
}

/**
 * Appel authentifié à l'API Neemii Pay.
 *
 * Base réelle confirmée via leur spécification OpenAPI
 * (https://www.neemii.com/api/v1/openapi, champ "servers": [{"url":
 * "/api/v1"}], chemin relatif au même domaine) : le texte de la
 * documentation publique mentionne à tort "pay.neemii.com", qui n'a aucune
 * résolution DNS.
 *
 * @throws RuntimeException en cas d'erreur réseau.
 */
function neemii_request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
{
    $config = payment_config()['neemii'];
    $headers = [
        'Authorization: Bearer ' . $config['secret_key'],
        'Content-Type: application/json',
    ];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init('https://www.neemii.com/api/v1' . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $options);
    $reponseBrute = curl_execute_with_error_check($ch);

    return json_decode($reponseBrute, true) ?: [];
}

function cinetpay_post(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $reponseBrute = curl_execute_with_error_check($ch);
    return json_decode($reponseBrute, true) ?: [];
}

function curl_execute_with_error_check($ch): string
{
    $reponse = curl_exec($ch);
    $erreur = curl_error($ch);
    curl_close($ch);
    if ($erreur) {
        throw new RuntimeException('Erreur de connexion au service de paiement : ' . $erreur);
    }
    return (string) $reponse;
}

/**
 * Enregistre le justificatif de virement déposé par le client sur le
 * paiement (utilisé sur les tableaux de bord clients — section 9 du cahier
 * des charges). Retourne une chaîne vide en cas de succès, sinon un message
 * d'erreur à afficher.
 */
function nx_enregistrer_justificatif_virement(int $paiementId, int $dossierId, string $packSlug): string
{
    if (!isset($_FILES['justificatif']) || $_FILES['justificatif']['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Merci de sélectionner un fichier.';
    }
    if ($_FILES['justificatif']['error'] !== UPLOAD_ERR_OK) {
        return "Erreur lors de l'envoi du fichier.";
    }

    $tailleMaxOctets = 10 * 1024 * 1024;
    if ($_FILES['justificatif']['size'] > $tailleMaxOctets) {
        return 'Le fichier dépasse la taille maximale de 10 Mo.';
    }

    $extension = strtolower(pathinfo($_FILES['justificatif']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
        return 'Format non accepté (PDF, JPG ou PNG uniquement).';
    }

    $dossierUpload = __DIR__ . '/../uploads/' . $packSlug . '/' . $dossierId . '/justificatifs_paiement';
    if (!is_dir($dossierUpload)) {
        mkdir($dossierUpload, 0755, true);
    }

    $nomFichier = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['justificatif']['name']));
    $cheminAbsolu = $dossierUpload . '/' . $nomFichier;
    if (!move_uploaded_file($_FILES['justificatif']['tmp_name'], $cheminAbsolu)) {
        return "Erreur lors de l'enregistrement du fichier.";
    }

    $cheminRelatif = 'uploads/' . $packSlug . '/' . $dossierId . '/justificatifs_paiement/' . $nomFichier;
    $stmt = db()->prepare(
        'UPDATE paiements SET justificatif_nom = ?, justificatif_chemin = ?, justificatif_taille_ko = ? WHERE id = ?'
    );
    $stmt->execute([
        basename($_FILES['justificatif']['name']),
        $cheminRelatif,
        (int) round($_FILES['justificatif']['size'] / 1024),
        $paiementId,
    ]);

    log_activite($dossierId, 'Justificatif de virement déposé par le client');

    return '';
}

/**
 * Marque un paiement comme payé et fait avancer le dossier à l'étape confirmation.
 */
function marquer_paiement_paye(int $paiementId): void
{
    $stmt = db()->prepare('UPDATE paiements SET statut = "paye" WHERE id = ? AND statut != "paye"');
    $stmt->execute([$paiementId]);
    if ($stmt->rowCount() === 0) {
        return; // déjà marqué payé (webhook + page de retour peuvent arriver tous les deux)
    }

    $stmt = db()->prepare(
        'SELECT p.dossier_id, p.montant, pk.slug AS pack_slug, pk.nom AS pack_nom,
                d.numero, d.user_id, u.email, u.prenom
         FROM paiements p
         JOIN dossiers d ON d.id = p.dossier_id
         JOIN packs pk ON pk.id = d.pack_id
         JOIN users u ON u.id = d.user_id
         WHERE p.id = ?'
    );
    $stmt->execute([$paiementId]);
    $row = $stmt->fetch();

    if ($row) {
        $dossierId = (int) $row['dossier_id'];

        notify(
            (int) $row['user_id'],
            'paiement_confirme',
            'Paiement confirmé',
            'Votre paiement pour le dossier ' . $row['numero'] . ' (' . $row['pack_nom'] . ') a bien été reçu.',
            'recu-paiement.php?paiement=' . $paiementId
        );
        nx_send_mail(
            $row['email'],
            'Paiement confirmé — NexRaiz',
            '<p>Bonjour ' . htmlspecialchars($row['prenom']) . ',</p>'
                . '<p>Nous confirmons la réception de votre paiement de ' . number_format((float) $row['montant'], 0, ',', ' ')
                . ' FCFA pour votre dossier ' . htmlspecialchars($row['numero']) . ' (' . htmlspecialchars($row['pack_nom']) . ').</p>'
                . '<p>Vous pouvez consulter votre reçu depuis votre espace NexRaiz.</p>'
        );

        // Le numéro de l'étape "Paiement" (et donc de celle qui la suit) varie selon
        // le pack : le calculer plutôt que de le coder en dur pour rester correct
        // pour tous les parcours (le Pack Classic n'a par exemple pas d'étape
        // "Confirmation" séparée après le paiement, contrairement aux autres packs).
        $steps = pack_steps($row['pack_slug']);
        $paiementStep = null;
        foreach ($steps as $num => $step) {
            if ($step['label'] === 'Paiement') { $paiementStep = $num; break; }
        }
        $etapeSuivante = $paiementStep !== null ? $paiementStep + 1 : max(array_keys($steps));

        update_dossier_step($dossierId, $etapeSuivante);
        log_activite($dossierId, 'Paiement confirmé');
    }
}
