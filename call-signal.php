<?php
/**
 * Canal de signalisation pour les appels vocaux WebRTC (navigateur à
 * navigateur) : échange l'offre/réponse SDP et les candidats ICE entre
 * l'appelant et le destinataire via de simples requêtes HTTP (sondage côté
 * client), sans serveur WebSocket dédié. Le flux audio, une fois la
 * connexion établie, circule directement entre les deux navigateurs.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$moi = current_user();
if (!$moi) {
    http_response_code(401);
    echo json_encode(['erreur' => 'Non connecté']);
    exit;
}

function repondre(array $data): void
{
    echo json_encode($data);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'initier':
        $destinataireId = (int) ($_POST['destinataire_id'] ?? 0);
        $offer = $_POST['offer_sdp'] ?? '';
        if (!$destinataireId || $offer === '') {
            repondre(['erreur' => 'Paramètres manquants']);
        }
        if ($destinataireId === (int) $moi['id']) {
            repondre(['erreur' => 'Impossible de vous appeler vous-même']);
        }
        $stmt = db()->prepare("SELECT id FROM users WHERE id = ? AND actif = 1 AND role IN ('client', 'consultant', 'admin', 'super_admin')");
        $stmt->execute([$destinataireId]);
        if (!$stmt->fetchColumn()) {
            repondre(['erreur' => 'Destinataire introuvable']);
        }
        $stmt = db()->prepare('INSERT INTO appels (appelant_id, destinataire_id, offer_sdp) VALUES (?, ?, ?)');
        $stmt->execute([$moi['id'], $destinataireId, $offer]);
        repondre(['call_id' => (int) db()->lastInsertId()]);
        break;

    case 'sonnerie':
        $stmt = db()->prepare(
            "SELECT a.id, a.appelant_id, a.offer_sdp, u.nom, u.prenom
             FROM appels a JOIN users u ON u.id = a.appelant_id
             WHERE a.destinataire_id = ? AND a.statut = 'sonne' AND a.started_at > (NOW() - INTERVAL 45 SECOND)
             ORDER BY a.id DESC LIMIT 1"
        );
        $stmt->execute([$moi['id']]);
        $appel = $stmt->fetch();
        repondre(['appel' => $appel ?: null]);
        break;

    case 'repondre':
        $callId = (int) ($_POST['call_id'] ?? 0);
        $answer = $_POST['answer_sdp'] ?? '';
        $stmt = db()->prepare("UPDATE appels SET statut = 'accepte', answer_sdp = ?, accepted_at = NOW() WHERE id = ? AND destinataire_id = ? AND statut = 'sonne'");
        $stmt->execute([$answer, $callId, $moi['id']]);
        repondre(['ok' => $stmt->rowCount() > 0]);
        break;

    case 'refuser':
        $callId = (int) ($_POST['call_id'] ?? 0);
        $stmt = db()->prepare("UPDATE appels SET statut = 'refuse', ended_at = NOW() WHERE id = ? AND destinataire_id = ? AND statut = 'sonne'");
        $stmt->execute([$callId, $moi['id']]);
        repondre(['ok' => true]);
        break;

    case 'raccrocher':
        $callId = (int) ($_POST['call_id'] ?? 0);
        $stmt = db()->prepare("UPDATE appels SET statut = 'termine', ended_at = NOW() WHERE id = ? AND (appelant_id = ? OR destinataire_id = ?) AND statut IN ('sonne', 'accepte')");
        $stmt->execute([$callId, $moi['id'], $moi['id']]);
        repondre(['ok' => true]);
        break;

    case 'ice':
        $callId = (int) ($_POST['call_id'] ?? 0);
        $candidate = $_POST['candidate'] ?? '';
        $stmt = db()->prepare('SELECT appelant_id, destinataire_id FROM appels WHERE id = ?');
        $stmt->execute([$callId]);
        $row = $stmt->fetch();
        if (!$row) {
            repondre(['erreur' => 'Appel introuvable']);
        }
        if ((int) $row['appelant_id'] === (int) $moi['id']) {
            $colonne = 'ice_appelant';
        } elseif ((int) $row['destinataire_id'] === (int) $moi['id']) {
            $colonne = 'ice_destinataire';
        } else {
            repondre(['erreur' => 'Non autorisé']);
        }
        // $colonne provient exclusivement de la liste blanche ci-dessus, jamais de l'entrée utilisateur.
        $stmt = db()->prepare("UPDATE appels SET $colonne = CONCAT(COALESCE($colonne, ''), ?, '\n') WHERE id = ?");
        $stmt->execute([$candidate, $callId]);
        repondre(['ok' => true]);
        break;

    case 'etat':
        $callId = (int) ($_POST['call_id'] ?? $_GET['call_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM appels WHERE id = ?');
        $stmt->execute([$callId]);
        $appel = $stmt->fetch();
        if (!$appel) {
            repondre(['erreur' => 'Appel introuvable']);
        }
        if ((int) $appel['appelant_id'] !== (int) $moi['id'] && (int) $appel['destinataire_id'] !== (int) $moi['id']) {
            repondre(['erreur' => 'Non autorisé']);
        }
        $estAppelant = (int) $appel['appelant_id'] === (int) $moi['id'];
        $iceDeLautre = $estAppelant ? $appel['ice_destinataire'] : $appel['ice_appelant'];
        repondre([
            'statut' => $appel['statut'],
            'answer_sdp' => $appel['answer_sdp'],
            'ice' => $iceDeLautre ? array_values(array_filter(explode("\n", $iceDeLautre))) : [],
        ]);
        break;

    default:
        http_response_code(400);
        repondre(['erreur' => 'Action inconnue']);
}
