<?php
/**
 * NexRaiz — Éditeur visuel "Canva" du CV/lettre (Pack Classic). Persiste le
 * brouillon d'édition (liste des blocs de texte modifiés, position par
 * position) associé à un dossier/type de document. Tant que statut =
 * 'brouillon', ce contenu n'est jamais exposé au candidat — seul l'export
 * validé (pdf_final_chemin), publié comme livrable via
 * includes/livrables.php, lui devient visible.
 */
require_once __DIR__ . '/db.php';

function canvas_edition_get(int $dossierId, string $documentType): ?array
{
    $stmt = db()->prepare('SELECT * FROM canvas_editions WHERE dossier_id = ? AND document_type = ?');
    $stmt->execute([$dossierId, $documentType]);
    $edition = $stmt->fetch();
    return $edition ?: null;
}

/**
 * Crée ou met à jour le brouillon. Repasse toujours l'édition en
 * "brouillon" : si un document déjà validé est retouché, la nouvelle
 * version doit être revalidée avant de remplacer le livrable publié.
 *
 * Enregistre aussi un instantané dans canvas_editions_historique — mais
 * seulement si le contenu a réellement changé depuis le dernier instantané,
 * pour ne pas créer une ligne à chaque frappe (l'autosauvegarde se déclenche
 * très fréquemment).
 */
function canvas_edition_sauvegarder(int $dossierId, string $documentType, string $sourceChemin, array $edits, int $consultantId): void
{
    $editsJson = json_encode($edits, JSON_UNESCAPED_UNICODE);
    $stmt = db()->prepare(
        'INSERT INTO canvas_editions (dossier_id, document_type, consultant_id, source_chemin, edits_json, statut)
         VALUES (?, ?, ?, ?, ?, "brouillon")
         ON DUPLICATE KEY UPDATE consultant_id = VALUES(consultant_id), source_chemin = VALUES(source_chemin), edits_json = VALUES(edits_json), statut = "brouillon"'
    );
    $stmt->execute([$dossierId, $documentType, $consultantId, $sourceChemin, $editsJson]);

    $stmt = db()->prepare('SELECT edits_json FROM canvas_editions_historique WHERE dossier_id = ? AND document_type = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$dossierId, $documentType]);
    $dernierInstantane = $stmt->fetchColumn();
    if ($dernierInstantane !== $editsJson) {
        $stmt = db()->prepare('INSERT INTO canvas_editions_historique (dossier_id, document_type, consultant_id, edits_json) VALUES (?, ?, ?, ?)');
        $stmt->execute([$dossierId, $documentType, $consultantId, $editsJson]);
    }
}

/** Les 15 derniers instantanés distincts, du plus récent au plus ancien. */
function canvas_edition_historique(int $dossierId, string $documentType): array
{
    $stmt = db()->prepare(
        'SELECT ceh.id, ceh.edits_json, ceh.created_at, u.prenom, u.nom
         FROM canvas_editions_historique ceh
         LEFT JOIN users u ON u.id = ceh.consultant_id
         WHERE ceh.dossier_id = ? AND ceh.document_type = ?
         ORDER BY ceh.id DESC LIMIT 15'
    );
    $stmt->execute([$dossierId, $documentType]);
    return $stmt->fetchAll();
}

function canvas_edition_valider(int $dossierId, string $documentType, string $pdfFinalChemin): void
{
    $stmt = db()->prepare(
        "UPDATE canvas_editions SET statut = 'valide', pdf_final_chemin = ?, validated_at = NOW()
         WHERE dossier_id = ? AND document_type = ?"
    );
    $stmt->execute([$pdfFinalChemin, $dossierId, $documentType]);
}
