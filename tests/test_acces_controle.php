<?php
/**
 * Sans consentement « matching », aucune recommandation ne doit jamais être
 * rendue, même si des offres publiées correspondent parfaitement au profil.
 */

$dossierId = test_creer_dossier_classic();
$offreId = test_creer_offre(['titre' => 'Assistant RH', 'competences_requises' => ['recrutement', 'excel']]);

// 1) Pas de profil du tout -> aucune recommandation.
test_cas('Pas de profil enregistré -> 0 recommandation', opportunites_recommandees_pour_dossier($dossierId) === []);

// 2) Profil enregistré mais consentement refusé -> l'enregistrement doit être rejeté (erreur de validation).
$erreurs = opportunites_enregistrer_profil($dossierId, [
    'types' => ['emploi'], 'metiers' => ['Assistant RH'], 'competences' => ['recrutement', 'excel'],
    'villes' => ['Abidjan'], 'annees_experience' => '', 'consent_matching' => false, 'consent_recruteurs' => false,
]);
test_cas('Consentement matching non coché -> erreur de validation', !empty($erreurs));
test_cas('Consentement matching non coché -> toujours 0 recommandation', opportunites_recommandees_pour_dossier($dossierId) === []);

// 3) Consentement accordé mais SANS CV analysé (cv_document_id absent) -> toujours
//    aucune recommandation. C'est le cas d'un ancien profil saisi à la main avant
//    que la fonctionnalité ne devienne pilotée par l'IA à partir du CV : le simple
//    consentement ne doit jamais suffire à lui seul (voir opportunites_profil_actif()).
$erreurs = opportunites_enregistrer_profil($dossierId, [
    'types' => ['emploi'], 'metiers' => ['Assistant RH'], 'competences' => ['recrutement', 'excel'],
    'villes' => ['Abidjan'], 'annees_experience' => '', 'consent_matching' => true, 'consent_recruteurs' => false,
]);
test_cas('Consentement coché sans CV analysé -> enregistrement sans erreur', $erreurs === []);
test_cas('Consentement coché mais cv_document_id absent -> 0 recommandation (jamais de profil "actif" sans CV réellement analysé)', opportunites_recommandees_pour_dossier($dossierId) === []);

// 4) Consentement accordé ET CV analysé (cv_document_id renseigné) -> la recommandation apparaît enfin.
$cvId = test_creer_document_cv($dossierId);
$erreurs = opportunites_enregistrer_profil($dossierId, [
    'types' => ['emploi'], 'metiers' => ['Assistant RH'], 'competences' => ['recrutement', 'excel'],
    'villes' => ['Abidjan'], 'annees_experience' => '', 'cv_document_id' => $cvId, 'consent_matching' => true, 'consent_recruteurs' => false,
]);
test_cas('Consentement + CV analysé -> enregistrement sans erreur', $erreurs === []);
$recommandees = opportunites_recommandees_pour_dossier($dossierId);
test_cas('Consentement accordé + CV analysé + profil compatible -> au moins 1 recommandation', count($recommandees) > 0);

// 4) Révocation explicite du consentement -> retour immédiat à 0 recommandation.
opportunites_revoquer_consentement($dossierId, 'matching');
test_cas('Consentement révoqué -> 0 recommandation à nouveau', opportunites_recommandees_pour_dossier($dossierId) === []);

$profilApres = opportunites_profil_dossier($dossierId);
test_cas('Consentement révoqué -> consent_matching=false en base', $profilApres['consent_matching'] === false);
test_cas('consent_recruteurs reste distinct et inchangé (false, jamais coché dans ce test)', $profilApres['consent_recruteurs'] === false);
