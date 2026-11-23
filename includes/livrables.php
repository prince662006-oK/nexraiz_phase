<?php
/**
 * NexRaiz — Cycle de vie des livrables (statuts "en_preparation" /
 * "disponible", cahier des charges section 3). Un fichier déposé par le
 * consultant reste invisible en téléchargement pour le candidat tant qu'il
 * n'est pas explicitement confirmé comme terminé.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // fournit log_activite()
require_once __DIR__ . '/notifications.php';

/**
 * Confirme qu'un livrable est terminé et le rend disponible au
 * téléchargement pour le candidat. Notifie le candidat à ce moment précis
 * (pas au dépôt du fichier).
 *
 * @return bool false si le livrable n'existe pas, n'appartient pas à ce
 * dossier, ou est déjà disponible.
 */
function marquer_livrable_disponible(int $livrableId, int $dossierId): bool
{
    $stmt = db()->prepare(
        'SELECT l.titre, d.user_id, d.numero
         FROM livrables l JOIN dossiers d ON d.id = l.dossier_id
         WHERE l.id = ? AND l.dossier_id = ? AND l.statut = "en_preparation"'
    );
    $stmt->execute([$livrableId, $dossierId]);
    $livrable = $stmt->fetch();
    if (!$livrable) {
        return false;
    }

    $stmt = db()->prepare('UPDATE livrables SET statut = "disponible", envoye_le = NOW() WHERE id = ?');
    $stmt->execute([$livrableId]);

    log_activite($dossierId, 'Livrable envoyé au candidat : ' . $livrable['titre']);
    notify(
        (int) $livrable['user_id'],
        'livrable',
        'Nouveau livrable disponible',
        $livrable['titre'] . ' — dossier ' . $livrable['numero'],
        null
    );

    return true;
}
