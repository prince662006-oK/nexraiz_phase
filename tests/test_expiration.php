<?php
/**
 * opportunites_expirer_echues() doit faire basculer en "expire" les offres
 * publiées dont la date limite est dépassée, sans toucher aux autres.
 */

$offreEchue = test_creer_offre(['titre' => 'Offre échue de test', 'date_limite' => date('Y-m-d', strtotime('-1 day'))]);
$offreFuture = test_creer_offre(['titre' => 'Offre future de test', 'date_limite' => date('Y-m-d', strtotime('+30 days'))]);
$offreSansDate = test_creer_offre(['titre' => 'Offre sans échéance de test', 'date_limite' => null]);

$lireStatut = function (int $id): string {
    $stmt = db()->prepare('SELECT statut FROM opportunites WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
};

test_cas('Avant expiration : offre échue encore "publie"', $lireStatut($offreEchue) === 'publie');

$n = opportunites_expirer_echues();

test_cas('opportunites_expirer_echues() a traité au moins l\'offre échue créée', $n >= 1);
test_cas('Offre à date limite passée -> statut "expire"', $lireStatut($offreEchue) === 'expire');
test_cas('Offre à date limite future -> reste "publie"', $lireStatut($offreFuture) === 'publie');
test_cas('Offre sans date limite -> reste "publie" (jamais expirée automatiquement)', $lireStatut($offreSansDate) === 'publie');

// Deuxième passage : idempotent, ne doit pas re-expirer ce qui l'est déjà ni planter.
$n2 = opportunites_expirer_echues();
test_cas('Deuxième passage sans nouvelle offre échue -> ne replante pas', $lireStatut($offreEchue) === 'expire');

// Une offre expirée ne doit plus jamais apparaître dans les recommandations.
$dossierId = test_creer_dossier_classic();
opportunites_enregistrer_profil($dossierId, [
    'types' => ['emploi'], 'metiers' => ['Offre échue de test'], 'villes' => [],
    'annees_experience' => '', 'cv_document_id' => test_creer_document_cv($dossierId),
    'consent_matching' => true, 'consent_recruteurs' => false,
]);
$titres = array_map(fn($r) => $r['offre']['titre'], opportunites_recommandees_pour_dossier($dossierId));
test_cas('Offre expirée absente des recommandations', !in_array('Offre échue de test', $titres, true));
