<?php
/**
 * opportunites_analyser_profil() ne doit jamais lever d'exception, et doit
 * retomber sur l'analyse de repli (mots-clés) quand la clé Anthropic n'est
 * pas configurée — cas par défaut de cet environnement de test, puisque
 * includes/env.local.php n'est pas chargé ici avec une vraie clé.
 */

$dossierId = test_creer_dossier_classic();
$profil = [
    'types' => ['emploi'], 'metiers' => ['Assistant RH'], 'secteurs' => ['RH'],
    'niveau_etudes' => 'BAC+2', 'annees_experience' => 2, 'competences' => ['recrutement', 'excel'],
    'langues' => ['Français'], 'villes' => ['Abidjan'], 'mobilite' => 'sur_place', 'teletravail' => 'non',
    'disponibilite_le' => null, 'cv_document_id' => null, 'consent_matching' => true, 'consent_recruteurs' => false,
];

test_cas('Clé Anthropic non configurée dans cet environnement de test', !anthropic_configured());

$exceptionLevee = false;
try {
    $analyse = opportunites_analyser_profil($dossierId, $profil, 'Texte de CV de test avec recrutement et excel.');
} catch (\Throwable $e) {
    $exceptionLevee = true;
    $analyse = ['source' => null, 'mots_cles_fr' => []];
}
test_cas('opportunites_analyser_profil() ne lève jamais d\'exception', !$exceptionLevee);
test_cas('Sans clé IA configurée -> source "secours" (repli honnête, jamais présenté comme IA)', $analyse['source'] === 'secours');
test_cas('Analyse de repli -> au moins un mot-clé extrait du profil', count($analyse['mots_cles_fr']) > 0);
test_cas('Analyse de repli -> le métier du profil fait partie des mots-clés', in_array('Assistant RH', $analyse['mots_cles_fr'], true));

// Les liens de recherche externes doivent toujours être des URL bien formées, repli ou pas.
$liens = opportunites_liens_recherche($analyse['mots_cles_fr'], 'Abidjan');
foreach ($liens as $cle => $lien) {
    test_cas("Lien de recherche '{$cle}' est une URL valide", opportunites_valider_url($lien['url']));
}

// Rejouer avec le même profil/CV doit relire le cache (même résultat, pas de nouvel appel réseau).
$analyseRejouee = opportunites_analyser_profil($dossierId, $profil, 'Texte de CV de test avec recrutement et excel.');
test_cas('Rejouer avec le même profil/CV -> résultat mis en cache identique', $analyseRejouee['mots_cles_fr'] === $analyse['mots_cles_fr']);
