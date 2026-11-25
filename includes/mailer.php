<?php
/**
 * NexRaiz — Envoi d'e-mails (best effort).
 * Utilise la fonction mail() native de PHP. Sur un serveur de production,
 * configurez un relais SMTP (sendmail_path dans php.ini, ou un vrai client
 * SMTP type PHPMailer) pour une délivrabilité fiable. En environnement de
 * développement (XAMPP sans SMTP configuré), l'envoi échoue silencieusement
 * et le contenu est tracé dans le journal d'erreurs PHP pour permettre de
 * tester le parcours sans serveur mail réel.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function nx_mail_from(): string
{
    return getenv('NEXRAIZ_MAIL_FROM') ?: 'no-reply@nexraiz.local';
}

function nx_send_mail(string $to, string $sujet, string $corpsHtml): bool
{
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: NexRaiz <' . nx_mail_from() . '>',
    ]);

    $envoye = @mail($to, $sujet, $corpsHtml, $headers);

    if (!$envoye) {
        error_log(sprintf('[NexRaiz mail] Échec envoi à %s — Sujet: %s — Corps: %s', $to, $sujet, strip_tags($corpsHtml)));
    }

    return $envoye;
}

/**
 * Envoie un e-mail avec pièce(s) jointe(s) — candidatures « Postuler via
 * NexRaiz » (includes/opportunites.php). Utilise PHPMailer plutôt qu'une
 * construction manuelle du MIME multipart (fragile : encodage base64,
 * limites de ligne, injection d'en-tête) : les pièces jointes réelles d'un
 * candidat ne doivent jamais être corrompues silencieusement.
 *
 * Expéditeur technique = NexRaiz (seule adresse dont nous maîtrisons le
 * SPF/DKIM) ; Reply-To = l'e-mail réel du client, pour que le recruteur lui
 * réponde directement. Usurper le "From" avec l'adresse du client ferait
 * échouer les vérifications anti-spoofing (SPF/DKIM) du serveur du
 * recruteur et risquerait de faire atterrir le message en spam, voire de
 * le faire rejeter.
 *
 * Le corps est du HTML (depuis includes/opportunites.php : texte de la
 * lettre + pixel de suivi de lecture invisible) ; AltBody est généré ici en
 * texte brut pour les clients mail qui n'affichent pas le HTML.
 *
 * @param array<int,array{chemin:string,nom:string}> $piecesJointes
 * @return array{ok:bool,erreur:string}
 */
function nx_envoyer_candidature(string $destinataire, string $sujet, string $corpsHtml, array $piecesJointes, string $replyToEmail, string $replyToNom): array
{
    $mail = new PHPMailer(true);
    try {
        $mail->isMail();
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(nx_mail_from(), 'NexRaiz');
        $mail->addAddress($destinataire);
        if ($replyToEmail !== '') {
            $mail->addReplyTo($replyToEmail, $replyToNom !== '' ? $replyToNom : $replyToEmail);
        }
        foreach ($piecesJointes as $piece) {
            if (!is_file($piece['chemin'])) {
                continue;
            }
            $mail->addAttachment($piece['chemin'], $piece['nom']);
        }
        $mail->Subject = $sujet;
        $mail->isHTML(true);
        $mail->Body = $corpsHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corpsHtml));
        $mail->send();
        return ['ok' => true, 'erreur' => ''];
    } catch (PHPMailerException $e) {
        $erreur = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[NexRaiz mail] Échec envoi candidature à ' . $destinataire . ' — ' . $erreur);
        return ['ok' => false, 'erreur' => $erreur];
    }
}

/**
 * Variante de nx_envoyer_candidature() qui envoie réellement depuis le
 * Gmail du candidat (via l'API Gmail, jeton OAuth) plutôt que depuis
 * l'adresse technique de NexRaiz. Pas de Reply-To nécessaire : le From
 * *est* déjà l'adresse réelle du candidat, le recruteur lui répond
 * naturellement.
 *
 * Construit le message avec PHPMailer (preSend() + getSentMIMEMessage())
 * plutôt qu'à la main : même raisonnement que nx_envoyer_candidature(),
 * construire un MIME multipart avec pièces jointes à la main est fragile
 * (encodage base64, limites de ligne, injection d'en-tête). preSend()
 * construit le message en mémoire sans jamais appeler de transport
 * (isMail/isSMTP/isSendmail ne sont pas utilisés ici) — rien n'est envoyé
 * par PHPMailer lui-même, seul le texte MIME assemblé est récupéré.
 *
 * @param array<int,array{chemin:string,nom:string}> $piecesJointes
 * @return array{ok:bool,erreur:string}
 */
function nx_envoyer_candidature_gmail(string $accessToken, string $fromEmail, string $destinataire, string $sujet, string $corpsHtml, array $piecesJointes): array
{
    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromEmail);
        $mail->addAddress($destinataire);
        foreach ($piecesJointes as $piece) {
            if (!is_file($piece['chemin'])) {
                continue;
            }
            $mail->addAttachment($piece['chemin'], $piece['nom']);
        }
        $mail->Subject = $sujet;
        $mail->isHTML(true);
        $mail->Body = $corpsHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corpsHtml));

        if (!$mail->preSend()) {
            return ['ok' => false, 'erreur' => $mail->ErrorInfo ?: 'Échec de construction du message.'];
        }
        $brut = $mail->getSentMIMEMessage();
        $rawBase64Url = rtrim(strtr(base64_encode($brut), '+/', '-_'), '=');

        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['raw' => $rawBase64Url]),
            CURLOPT_TIMEOUT    => 25,
        ]);
        $reponseBrute = curl_exec($ch);
        $erreurCurl = curl_error($ch);
        $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($reponseBrute === false) {
            return ['ok' => false, 'erreur' => 'Connexion à Gmail impossible : ' . $erreurCurl];
        }
        if ($codeHttp >= 400) {
            $donnees = json_decode($reponseBrute, true);
            $erreur = $donnees['error']['message'] ?? ('Erreur Gmail HTTP ' . $codeHttp);
            return ['ok' => false, 'erreur' => $erreur];
        }
        return ['ok' => true, 'erreur' => ''];
    } catch (\Throwable $e) {
        $erreur = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[NexRaiz mail-gmail] Échec envoi candidature à ' . $destinataire . ' depuis ' . $fromEmail . ' — ' . $erreur);
        return ['ok' => false, 'erreur' => $erreur];
    }
}
