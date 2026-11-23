<?php
/**
 * Extraction automatique du profil de recherche depuis le CV — le client
 * ne doit plus le saisir à la main (voir classic-mon-profil-recherche.php).
 * Ne doit jamais fabriquer un secteur, un niveau d'études ou une ville
 * absente du CV.
 */

// 1) Pas de CV du tout -> profil vide honnête (type par défaut "emploi" seulement, tout le reste vide).
$resultat = opportunites_extraire_profil_cv('');
test_cas('Pas de CV -> source "indisponible"', $resultat['source'] === 'indisponible');
test_cas('Pas de CV -> types par défaut ["emploi"]', $resultat['donnees']['types'] === ['emploi']);
test_cas('Pas de CV -> aucun métier/secteur inventé', $resultat['donnees']['metiers'] === [] && $resultat['donnees']['secteurs'] === []);
test_cas('Pas de CV -> aucune ville inventée', $resultat['donnees']['villes'] === []);

// 2) CV présent mais pas d'IA configurée dans cet environnement de test -> repli honnête.
$texteCv = "Jean Kouassi\nAssistant RH chez Atlas Demo Textile depuis 2020.\nCompétences : recrutement, excel, paie, communication.\nFrançais natif, anglais intermédiaire.";
$resultat = opportunites_extraire_profil_cv($texteCv);
test_cas('CV sans IA configurée -> source "secours"', $resultat['source'] === 'secours');
test_cas('Repli sans IA -> au moins une compétence extraite par fréquence du texte', count($resultat['donnees']['competences']) > 0);
test_cas('Repli sans IA -> aucun secteur deviné (nécessite une vraie compréhension du texte)', $resultat['donnees']['secteurs'] === []);
test_cas('Repli sans IA -> aucun niveau d\'études deviné', $resultat['donnees']['niveau_etudes'] === null);
test_cas('Repli sans IA -> mobilité par défaut "sur_place"', $resultat['donnees']['mobilite'] === 'sur_place');

// 3) Le profil extrait (via le repli, ici) doit être directement acceptable par opportunites_enregistrer_profil().
$dossierId = test_creer_dossier_classic();
$erreurs = opportunites_enregistrer_profil($dossierId, array_merge($resultat['donnees'], [
    'consent_matching' => true, 'consent_recruteurs' => false,
]));
test_cas('Profil extrait du CV accepté sans erreur par opportunites_enregistrer_profil()', $erreurs === []);
test_cas('opportunites_extraire_profil_cv() ne fournit jamais cv_document_id lui-même (c\'est à l\'appelant de le fournir depuis le vrai document déposé)', !isset($resultat['donnees']['cv_document_id']));
test_cas('Consentement coché mais sans cv_document_id transmis -> profil jamais considéré "actif"', !opportunites_profil_actif(opportunites_profil_dossier($dossierId)));

// 4) Normalisation : une valeur hors liste renvoyée par l'IA ne doit jamais être stockée telle quelle.
$normalise = opportunites_normaliser_profil_extrait([
    'types' => ['emploi', 'valeur_inventee'], 'niveau_etudes' => 'Doctorat improbable', 'mobilite' => 'planete_entiere',
    'teletravail' => 'toujours', 'annees_experience' => 'beaucoup',
]);
test_cas('Normalisation : type invalide retiré, type valide conservé', $normalise['types'] === ['emploi']);
test_cas('Normalisation : niveau d\'études hors liste -> null (jamais stocké tel quel)', $normalise['niveau_etudes'] === null);
test_cas('Normalisation : mobilité hors liste -> repli "sur_place"', $normalise['mobilite'] === 'sur_place');
test_cas('Normalisation : télétravail hors liste -> repli "non"', $normalise['teletravail'] === 'non');
test_cas('Normalisation : expérience non numérique -> null (jamais 0 par défaut silencieux)', $normalise['annees_experience'] === null);
