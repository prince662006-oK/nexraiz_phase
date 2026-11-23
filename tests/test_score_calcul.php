<?php
/**
 * Tests de calculer_score_match() — fonction pure, aucun accès base.
 */

$poids = ['type' => 15, 'ville' => 15, 'niveau_etudes' => 15, 'experience' => 15, 'competences' => 30, 'intitule' => 10];

// 1) Correspondance parfaite -> 100.
$profilParfait = [
    'types' => ['emploi'], 'villes' => ['Abidjan'], 'teletravail' => 'non', 'niveau_etudes' => 'BAC+3',
    'annees_experience' => 3, 'competences' => ['recrutement', 'excel', 'paie'], 'metiers' => ['Assistant RH'],
];
$offreParfaite = [
    'type' => 'emploi', 'ville' => 'Abidjan', 'teletravail' => 'non', 'niveau_etudes_min' => 'BAC+3',
    'experience_min_annees' => 3, 'competences_requises' => ['recrutement', 'excel', 'paie'], 'titre' => 'Assistant RH',
];
$resultat = calculer_score_match($profilParfait, $offreParfaite, $poids);
test_cas('Correspondance parfaite -> score 100', $resultat['score'] === 100);

// 2) Aucune préférence côté profil ET aucune exigence côté offre -> 100
//    (type/ville/compétences/intitulé : un champ vide = "pas de préférence"
//    = crédit plein. niveau_etudes/expérience sont différents : un profil
//    qui ne déclare rien n'est pas crédité comme "sur-qualifié" face à une
//    exigence réelle, voir le test suivant).
$profilVide = [
    'types' => [], 'villes' => [], 'teletravail' => 'non', 'niveau_etudes' => null,
    'annees_experience' => 0, 'competences' => [], 'metiers' => [],
];
$offreSansExigences = array_merge($offreParfaite, ['niveau_etudes_min' => null, 'experience_min_annees' => null, 'competences_requises' => []]);
$resultatVide = calculer_score_match($profilVide, $offreSansExigences, $poids);
test_cas('Profil et offre sans aucune exigence -> score 100', $resultatVide['score'] === 100);

// 2b) À l'inverse, un profil qui ne déclare ni niveau d'études ni expérience
//     ne doit PAS être crédité face à une offre qui, elle, exige BAC+3/3 ans
//     (champ non déclaré = inconnu, pas "aucune exigence" côté qualification).
$resultatNonQualifie = calculer_score_match($profilVide, $offreParfaite, $poids);
test_cas('Profil sans niveau/expérience déclarés face à une offre exigeante -> 0 crédit niveau', $resultatNonQualifie['details']['niveau_etudes']['obtenu'] === 0);
test_cas('Profil sans niveau/expérience déclarés face à une offre exigeante -> 0 crédit expérience', $resultatNonQualifie['details']['experience']['obtenu'] === 0);

// 3) Opposition totale (mauvais type, mauvaise ville, sous-qualifié, 0 compétence commune) -> score bas mais pas négatif.
$profilOppose = [
    'types' => ['stage'], 'villes' => ['Yamoussoukro'], 'teletravail' => 'non', 'niveau_etudes' => 'BAC',
    'annees_experience' => 0, 'competences' => ['photographie'], 'metiers' => ['Photographe'],
];
$resultatOppose = calculer_score_match($profilOppose, $offreParfaite, $poids);
test_cas('Profil totalement opposé -> score >= 0', $resultatOppose['score'] >= 0);
test_cas('Profil totalement opposé -> score < 50', $resultatOppose['score'] < 50);

// 4) Expérience à exactement un an de la cible -> demi-crédit (règle du "presque qualifié").
$profilPresque = array_merge($profilParfait, ['annees_experience' => 2]);
$resultatPresque = calculer_score_match($profilPresque, $offreParfaite, $poids);
test_cas('Expérience à 1 an de la cible -> demi-crédit expérience', $resultatPresque['details']['experience']['obtenu'] === (int) round(15 * 0.5));

// 5) Télétravail compatible compense une ville différente (75% du poids ville).
$profilTeletravail = array_merge($profilParfait, ['villes' => ['Yamoussoukro'], 'teletravail' => 'total']);
$offreTeletravail = array_merge($offreParfaite, ['teletravail' => 'total']);
$resultatTeletravail = calculer_score_match($profilTeletravail, $offreTeletravail, $poids);
test_cas('Télétravail compatible malgré ville différente -> 75% du poids ville', $resultatTeletravail['details']['ville']['obtenu'] === (int) round(15 * 0.75));

// 6) Recoupement partiel de compétences -> score proportionnel.
$profilCompetencesPartielles = array_merge($profilParfait, ['competences' => ['recrutement']]);
$resultatPartiel = calculer_score_match($profilCompetencesPartielles, $offreParfaite, $poids);
test_cas('1 compétence commune sur 3 requises -> ~1/3 du poids compétences', $resultatPartiel['details']['competences']['obtenu'] === (int) round(30 * (1 / 3)));

// 7) Le score reste toujours entre 0 et 100 (bornes).
foreach ([$resultat, $resultatVide, $resultatOppose, $resultatPresque, $resultatTeletravail, $resultatPartiel] as $r) {
    test_cas("Score borné 0-100 (score={$r['score']})", $r['score'] >= 0 && $r['score'] <= 100);
}
