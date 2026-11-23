<?php
/**
 * « Postuler via NexRaiz » et génération de lettre de motivation — ne
 * doivent jamais envoyer un e-mail vide ni fabriquer une expérience/un
 * diplôme absent du profil fourni.
 */

$dossierId = test_creer_dossier_classic();
$clientTest = ['prenom' => 'Awa', 'nom' => 'Koné', 'email' => 'awa.kone@example.test'];

// 1) Lettre vide -> rejetée avant tout envoi.
$offreAvecEmail = test_creer_offre(['titre' => 'Assistant RH', 'email_candidature' => 'rh@entreprise-test.example']);
$resultat = opportunites_candidater_via_nexraiz($offreAvecEmail, $dossierId, '   ', $clientTest);
test_cas('Lettre vide -> candidature rejetée sans tentative d\'envoi', $resultat['ok'] === false);
test_cas('Message d\'erreur explicite pour lettre vide', str_contains($resultat['erreur'], 'vide'));

// 2) CV manquant -> rejetée (même avec une lettre non vide).
$resultat = opportunites_candidater_via_nexraiz($offreAvecEmail, $dossierId, 'Madame, Monsieur, je vous soumets ma candidature.', $clientTest);
test_cas('Pas de CV déposé -> candidature rejetée', $resultat['ok'] === false);
test_cas('Message d\'erreur explicite pour CV manquant', str_contains($resultat['erreur'], 'CV'));

// 3) Offre sans e-mail de candidature -> rejetée.
$offreSansEmail = test_creer_offre(['titre' => 'Commercial junior', 'email_candidature' => null, 'lien_candidature' => 'https://exemple.test/offre']);
$resultat = opportunites_candidater_via_nexraiz($offreSansEmail, $dossierId, 'Un message quelconque.', $clientTest);
test_cas('Offre sans e-mail de candidature -> candidature rejetée', $resultat['ok'] === false);

// 4) Lettre de repli (sans IA) : jamais vide, reprend le vrai titre de l'offre et le vrai nom du client, jamais une expérience inventée.
$offreDecoder = ['titre' => 'Assistant RH', 'entreprise' => 'Atlas Démo Textile'];
$profilVide = ['metiers' => ['Assistant RH']];
$lettreSecours = opportunites_lettre_secours($profilVide, $offreDecoder, 'Awa Koné');
test_cas('Lettre de repli non vide', trim($lettreSecours) !== '');
test_cas('Lettre de repli mentionne le vrai titre de poste', str_contains($lettreSecours, 'Assistant RH'));
test_cas('Lettre de repli mentionne le vrai nom du client', str_contains($lettreSecours, 'Awa Koné'));
test_cas('Lettre de repli signalée comme modèle simplifié (honnêteté)', str_contains($lettreSecours, 'simplifiée'));

// 5) opportunites_generer_lettre_ia() essaie Anthropic puis Groq (déjà
//    configuré pour le reste du projet) avant de retomber sur le modèle
//    sans IA — décision explicite du client. Le test s'adapte à l'état réel
//    de l'environnement plutôt que de supposer qu'aucune clé n'est présente.
$resultatGeneration = opportunites_generer_lettre_ia($profilVide, 'Texte de CV de test.', '', $offreDecoder, 'Awa Koné');
test_cas('Génération de lettre -> ne lève jamais d\'exception, texte non vide', trim($resultatGeneration['texte']) !== '');
if (anthropic_configured() || groq_configured()) {
    test_cas('IA configurée (Anthropic ou Groq) dans cet environnement -> source "ia"', $resultatGeneration['source'] === 'ia');
} else {
    test_cas('Aucune IA configurée -> source "secours" (repli honnête)', $resultatGeneration['source'] === 'secours');
}
