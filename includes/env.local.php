<?php
/**
 * NexRaiz — Variables d'environnement locales (clés API réelles de test).
 *
 * ⚠️ NE JAMAIS PARTAGER CE FICHIER : ne pas l'envoyer par email/chat, ne pas
 * le zipper avec le projet, ne pas le committer si vous initialisez git un
 * jour (ajoutez includes/env.local.php à .gitignore — déjà fait à la racine
 * du projet). Si ces clés Neemii sont un jour rendues publiques par erreur,
 * régénérez-les immédiatement depuis l'espace développeur Neemii.
 *
 * Chargé automatiquement par includes/payment-config.php s'il existe.
 */

putenv('NEEMII_PUBLIC_KEY=NPPUBK_TEST-64da31e9deaeeb4c7c4b3768abb283a4e6113528');
putenv('NEEMII_SECRET_KEY=NPSECK_TEST-0017b367d57ea755eceb5aa624d3d3ba8c189acc6881b168');
putenv('NEEMII_ENCRYPTION_KEY=NPSECK_TEST8fd9064121ac53a4e492e15a');
putenv('NEEMII_MERCHANT_ID=mer_d0c2fbcd81bee0b7a74dec8865c5beb4');

putenv('STRIPE_SECRET_KEY=sk_test_51S6ATlA9HzC44GskLDKFMIHIYHNrbpzX6xy3rQbwoQZvhrU6IAOZH5i77llAm0f1luR1vHkJPswChuNFueeNYP8y00ChvCBXM3');
putenv('STRIPE_PUBLISHABLE_KEY=pk_test_51S6ATlA9HzC44GskHy7kTil8rTlAPXhkxS4mbH3SfQVjcP4o7pD0O1oJiUmOrsWv2keyEPHinMWw1ElfGcjcJN4H009D2lQzx5');

putenv('GROQ_API_KEY=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');
putenv('GROQ_MODEL=openai/gpt-oss-20b');

// Clé dédiée au chatbot d'orientation (choix du pack) : modèle plus
// grand (120b) que celui utilisé pour la correction de documents, car la
// conversation libre avec un visiteur demande plus de finesse.
putenv('GROQ_CHATBOT_API_KEY=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');
putenv('GROQ_CHATBOT_MODEL=openai/gpt-oss-120b');

// Connexion Google (OAuth) — candidats envoyant leurs candidatures depuis
// leur propre Gmail. Tant que l'app Google n'est pas vérifiée, seuls les
// comptes ajoutés comme "testeurs" dans la Google Cloud Console fonctionnent.
putenv('GOOGLE_OAUTH_CLIENT_ID=115619585012-ssqumffbfuv6gnm8jrs1g2fkjd6a3ncd.apps.googleusercontent.com');
putenv('GOOGLE_OAUTH_CLIENT_SECRET=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');
// URI de redirection à enregistrer EXACTEMENT à l'identique dans la Google
// Cloud Console (OAuth > Identifiants > URI de redirection autorisés).
putenv('GOOGLE_OAUTH_REDIRECT_URI=https://nexraiz.com/2/google-oauth-callback.php');

// Base URL de l'application — utilisée pour construire des liens absolus
// (pixel de suivi de lecture des emails, redirection OAuth) quand il n'y a
// pas de requête HTTP en cours pour la déduire automatiquement.
putenv('NEXRAIZ_APP_URL=https://nexraiz.com/2');
