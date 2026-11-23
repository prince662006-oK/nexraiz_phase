<?php
/**
 * NexRaiz — Notifications applicatives (cloche affichée dans l'en-tête).
 * Distinctes des badges de messagerie non lue (déjà gérés par
 * messages/messages_internes) : couvrent les événements sans badge dédié
 * (paiement confirmé, changement de statut de dossier, rendez-vous...).
 */
require_once __DIR__ . '/db.php';

function notify(int $userId, string $type, string $titre, ?string $corps = null, ?string $lien = null): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, type, titre, corps, lien) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $type, $titre, $corps, $lien]);
}

function notifications_non_lues(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lu = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function notifications_recentes(int $userId, int $limite = 8): array
{
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limite);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function marquer_notifications_lues(int $userId): void
{
    $stmt = db()->prepare('UPDATE notifications SET lu = 1 WHERE user_id = ? AND lu = 0');
    $stmt->execute([$userId]);
}
