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
 * @param array<int,array{chemin:string,nom:string}> $piecesJointes
 * @return array{ok:bool,erreur:string}
 */
function nx_envoyer_candidature(string $destinataire, string $sujet, string $corpsTexte, array $piecesJointes, string $replyToEmail, string $replyToNom): array
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
        $mail->Body = $corpsTexte;
        $mail->isHTML(false);
        $mail->send();
        return ['ok' => true, 'erreur' => ''];
    } catch (PHPMailerException $e) {
        $erreur = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[NexRaiz mail] Échec envoi candidature à ' . $destinataire . ' — ' . $erreur);
        return ['ok' => false, 'erreur' => $erreur];
    }
}
