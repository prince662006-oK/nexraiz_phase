<?php
/**
 * NexRaiz — Comptes rendus structurés d'intervention, Pack Assistance.
 * Document professionnel distinct du résumé auto-généré compte-rendu.php
 * (conservé tel quel) : sections structurées, cycle de vie
 * brouillon/à valider/validé/correction demandée/archivé, séparation
 * stricte interne/partageable.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // log_activite()

function cr_types_labels(): array
{
    return [
        'entretien' => 'Entretien', 'appel' => 'Appel téléphonique', 'rendez_vous' => 'Rendez-vous',
        'reunion' => 'Réunion', 'intervention_rh' => 'Intervention RH', 'autre' => 'Autre',
    ];
}

function cr_statuts_labels(): array
{
    return [
        'brouillon' => ['Brouillon', 'bg-surface-container-high text-secondary'],
        'a_valider' => ['À valider', 'bg-orange-100 text-orange-700'],
        'valide' => ['Validé', 'bg-green-100 text-green-700'],
        'correction_demandee' => ['Correction demandée', 'bg-error-container text-error'],
        'archive' => ['Archivé', 'bg-surface-container-high text-secondary'],
    ];
}

/** Seul un admin/super_admin valide ou renvoie en correction — jamais l'auteur lui-même sur son propre brouillon. */
function cr_peut_valider(array $user): bool
{
    return in_array($user['role'], ['admin', 'super_admin'], true);
}

function cr_peut_modifier(array $compteRendu, array $user): bool
{
    $estAdmin = in_array($user['role'], ['admin', 'super_admin'], true);
    $estAuteur = (int) $compteRendu['auteur_id'] === (int) $user['id'];
    $statutModifiable = in_array($compteRendu['statut'], ['brouillon', 'correction_demandee'], true);
    return $statutModifiable && ($estAuteur || $estAdmin);
}

/**
 * Crée un compte rendu en brouillon et lui attribue une référence unique
 * lisible (ex. CR-2026-000123), générée après l'insertion pour inclure
 * l'identifiant réel.
 */
function cr_creer(int $dossierId, int $auteurId, array $champs): int
{
    $stmt = db()->prepare(
        'INSERT INTO comptes_rendus (dossier_id, reference, auteur_id, type_intervention, date_intervention, participants, objet,
            contexte, objectifs, resume_echanges, faits_rapportes, observations_internes, decisions, actions_proposees_json, prochaine_etape, statut)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "brouillon")'
    );
    $referenceTemporaire = 'TMP-' . bin2hex(random_bytes(6));
    $stmt->execute([
        $dossierId, $referenceTemporaire, $auteurId, $champs['type_intervention'], $champs['date_intervention'],
        $champs['participants'] ?: null, $champs['objet'] ?: null, $champs['contexte'] ?: null, $champs['objectifs'] ?: null,
        $champs['resume_echanges'] ?: null, $champs['faits_rapportes'] ?: null, $champs['observations_internes'] ?: null,
        $champs['decisions'] ?: null, json_encode($champs['actions_proposees'] ?? [], JSON_UNESCAPED_UNICODE),
        $champs['prochaine_etape'] ?: null,
    ]);
    $id = (int) db()->lastInsertId();
    $reference = 'CR-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    db()->prepare('UPDATE comptes_rendus SET reference = ? WHERE id = ?')->execute([$reference, $id]);
    log_activite($dossierId, 'Compte rendu créé (brouillon) : ' . $reference);
    return $id;
}

function cr_mettre_a_jour(int $id, int $dossierId, array $champs): void
{
    $stmt = db()->prepare(
        'UPDATE comptes_rendus SET type_intervention = ?, date_intervention = ?, participants = ?, objet = ?,
            contexte = ?, objectifs = ?, resume_echanges = ?, faits_rapportes = ?, observations_internes = ?,
            decisions = ?, actions_proposees_json = ?, prochaine_etape = ?
         WHERE id = ? AND dossier_id = ?'
    );
    $stmt->execute([
        $champs['type_intervention'], $champs['date_intervention'], $champs['participants'] ?: null, $champs['objet'] ?: null,
        $champs['contexte'] ?: null, $champs['objectifs'] ?: null, $champs['resume_echanges'] ?: null,
        $champs['faits_rapportes'] ?: null, $champs['observations_internes'] ?: null, $champs['decisions'] ?: null,
        json_encode($champs['actions_proposees'] ?? [], JSON_UNESCAPED_UNICODE), $champs['prochaine_etape'] ?: null,
        $id, $dossierId,
    ]);
    log_activite($dossierId, 'Compte rendu mis à jour (brouillon)');
}
