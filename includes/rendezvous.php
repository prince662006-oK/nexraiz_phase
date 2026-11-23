<?php
/**
 * NexRaiz — Disponibilité des créneaux de rendez-vous.
 * Un consultant n'est pas encore affecté au moment de la prise de
 * rendez-vous (l'affectation se fait plus tard, à l'ouverture du dossier
 * par un consultant) : la disponibilité est donc vérifiée par pack, sur un
 * agenda partagé entre tous les dossiers de ce pack, pas par consultant.
 */
require_once __DIR__ . '/db.php';

/**
 * @return array<int,string> Les heures (format "HH:MM") déjà réservées ce
 * jour-là pour ce pack (chaque pack a sa propre liste de créneaux
 * possibles, définie dans sa page de prise de rendez-vous).
 */
function creneaux_pris(int $packId, string $jourIso): array
{
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(r.date_heure, '%H:%i') AS heure
         FROM rendezvous r
         JOIN dossiers d ON d.id = r.dossier_id
         WHERE d.pack_id = ? AND DATE(r.date_heure) = ? AND r.statut != 'annule'"
    );
    $stmt->execute([$packId, $jourIso]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function creneau_est_pris(int $packId, string $jourIso, string $heure): bool
{
    return in_array($heure, creneaux_pris($packId, $jourIso), true);
}

/**
 * Premier créneau de $creneauxPossibles non encore pris ce jour-là (ou le
 * premier de la liste par défaut si tous sont pris).
 * @param array<int,string> $creneauxPossibles
 */
function premier_creneau_disponible(int $packId, string $jourIso, array $creneauxPossibles): string
{
    $pris = creneaux_pris($packId, $jourIso);
    foreach ($creneauxPossibles as $c) {
        if (!in_array($c, $pris, true)) {
            return $c;
        }
    }
    return $creneauxPossibles[0];
}
