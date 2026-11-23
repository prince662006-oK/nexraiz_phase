<?php
/**
 * NexRaiz — Analyse IA du profil de recherche (Pack Classic), via l'API
 * Anthropic (Claude) pour l'analyse de profil/CV. La génération de lettre
 * de motivation (opportunites_generer_lettre_ia()) essaie Anthropic si
 * configuré, puis se rabat sur Groq (includes/ai.php, déjà utilisé pour la
 * correction de documents) — décision explicite du client : tant qu'aucune
 * clé Anthropic n'est fournie, autant utiliser la clé Groq déjà active
 * plutôt que de laisser un repli sans IA par défaut.
 *
 * Point d'entrée unique pour les pages : opportunites_analyser_profil().
 * Ne lève JAMAIS d'exception — si aucune IA n'est configurée ou que toutes
 * échouent, repli automatique sur une extraction de mots-clés simple
 * (opportunites_analyse_secours()) / un modèle de lettre sans IA
 * (opportunites_lettre_secours()), et le résultat précise toujours sa
 * provenance ('ia' ou 'secours') pour rester honnête avec le client.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

function opportunites_ia_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/opportunites-config.php';
    }
    return $config;
}

function anthropic_configured(): bool
{
    return strpos(opportunites_ia_config()['anthropic']['api_key'], 'REPLACE_ME') === false;
}

/**
 * Appel générique à l'API Anthropic (Messages API). Format distinct de
 * l'OpenAI-compatible utilisé par groq_chat_completion() : le "system"
 * prompt est un champ séparé, pas un message role=system, et
 * l'authentification se fait par l'en-tête x-api-key (pas Authorization).
 *
 * @throws RuntimeException en cas d'erreur réseau ou de refus de l'API.
 */
function anthropic_chat_completion(string $apiKey, string $model, string $systemPrompt, string $userPrompt): array
{
    $payload = [
        'model'      => $model,
        'max_tokens' => 2048,
        'system'     => $systemPrompt,
        'messages'   => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $reponseBrute = curl_exec($ch);
    $erreur = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($erreur) {
        throw new RuntimeException("Impossible de joindre le service IA Anthropic : {$erreur}");
    }
    $donnees = json_decode((string) $reponseBrute, true) ?: [];
    if ($httpCode >= 400) {
        throw new RuntimeException('Anthropic a refusé la requête : ' . ($donnees['error']['message'] ?? "code HTTP {$httpCode}"));
    }
    return $donnees;
}

function anthropic_extraire_texte(array $reponse): string
{
    // Messages API : contenu dans $reponse['content'][0]['text'] (bloc de
    // type "text" en premier dans la plupart des réponses sans tool-use).
    foreach ($reponse['content'] ?? [] as $bloc) {
        if (($bloc['type'] ?? '') === 'text' && isset($bloc['text'])) {
            return trim($bloc['text']);
        }
    }
    return '';
}

/**
 * @param array $profil Profil de recherche décodé (types, metiers,
 * secteurs, competences, langues, villes, niveau_etudes, annees_experience).
 * @throws RuntimeException si Anthropic n'est pas configuré, si l'appel
 * échoue, ou si la réponse n'est pas un JSON exploitable — l'appelant
 * (opportunites_analyser_profil()) capture systématiquement cette
 * exception et bascule sur le repli sans IA.
 */
function opportunites_analyser_profil_ia(array $profil, string $texteCv, string $texteLettre = ''): array
{
    if (!anthropic_configured()) {
        throw new RuntimeException("L'analyse IA n'est pas configurée (AI_API_KEY absente).");
    }

    $config = opportunites_ia_config()['anthropic'];

    $systemPrompt = "Tu es un conseiller en orientation professionnelle pour NexRaiz, une plateforme RH en Côte d'Ivoire. "
        . "Tu reçois le profil de recherche d'un candidat et, si disponibles, le texte de son CV et de sa lettre de motivation. "
        . "N'invente jamais une compétence, une expérience ou un diplôme absent des informations fournies. "
        . "Réponds UNIQUEMENT avec un objet JSON strict de la forme : "
        . '{"mots_cles_fr":["..."],"mots_cles_en":["..."],"intitules_proches":["..."],"competences_fortes":["..."],"points_amelioration":["..."],"conseils_candidature":["...","...","..."]}. '
        . "\"mots_cles_fr\" et \"mots_cles_en\" sont des mots-clés de recherche d'emploi pertinents (5 à 10 chacun). "
        . "\"intitules_proches\" liste 3 à 6 intitulés de poste proches du profil. \"competences_fortes\" reprend les compétences "
        . "les plus valorisables du profil/CV/lettre. \"points_amelioration\" liste 2 à 4 points à renforcer. \"conseils_candidature\" "
        . "donne exactement 3 conseils concrets et courts pour candidater efficacement. Aucun texte en dehors du JSON.";

    $userPrompt = "Profil de recherche :\n" . json_encode($profil, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\nTexte du CV (le cas échéant) :\n" . ($texteCv !== '' ? mb_substr($texteCv, 0, 6000) : '(aucun CV disponible)')
        . "\n\nTexte de la lettre de motivation (le cas échéant) :\n" . ($texteLettre !== '' ? mb_substr($texteLettre, 0, 4000) : '(aucune lettre de motivation disponible)');

    $reponse = anthropic_chat_completion($config['api_key'], $config['model'], $systemPrompt, $userPrompt);
    $texte = anthropic_extraire_texte($reponse);
    $donnees = json_decode($texte, true);
    if (!is_array($donnees)) {
        throw new RuntimeException('Réponse IA invalide (JSON non exploitable).');
    }

    $champs = ['mots_cles_fr', 'mots_cles_en', 'intitules_proches', 'competences_fortes', 'points_amelioration', 'conseils_candidature'];
    $resultat = [];
    foreach ($champs as $champ) {
        $valeur = $donnees[$champ] ?? [];
        $resultat[$champ] = is_array($valeur) ? array_values(array_filter(array_map('strval', $valeur))) : [];
    }
    return $resultat;
}

/**
 * Repli sans IA : ne lève jamais d'exception. Construit des mots-clés à
 * partir du profil (métiers + compétences déjà déclarés) et d'une analyse
 * de fréquence très simple du texte du CV (mots de 4 lettres ou plus, hors
 * mots vides courants). Pas de conseils IA — liste vide, affichée
 * honnêtement côté client comme « analyse simplifiée, sans IA ».
 */
function opportunites_analyse_secours(array $profil, string $texteCv, string $texteLettre = ''): array
{
    // La lettre de motivation n'est pas exploitée par le repli sans IA
    // (simple extraction de mots-clés) : la laisser de côté ici évite de
    // dupliquer la logique de fréquence déjà appliquée au CV, sans rien
    // retirer d'utile — la lettre reprend généralement les mêmes mots-clés
    // que le CV.
    $motsCles = array_values(array_unique(array_merge(
        array_map('strval', $profil['metiers'] ?? []),
        array_map('strval', $profil['competences'] ?? [])
    )));

    $motsVides = ['dans', 'pour', 'avec', 'sans', 'plus', 'être', 'avoir', 'cette', 'cette', 'votre', 'notre', 'leurs', 'ainsi', 'donc', 'mais', 'tout', 'tous', 'toute', 'toutes', 'that', 'this', 'with', 'from', 'have', 'been', 'will'];
    $frequence = [];
    if ($texteCv !== '') {
        $texteNettoye = mb_strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $texteCv), 'UTF-8');
        foreach (preg_split('/\s+/u', $texteNettoye) as $mot) {
            if (mb_strlen($mot) < 4 || in_array($mot, $motsVides, true)) {
                continue;
            }
            $frequence[$mot] = ($frequence[$mot] ?? 0) + 1;
        }
        arsort($frequence);
        $motsCles = array_values(array_unique(array_merge($motsCles, array_slice(array_keys($frequence), 0, 10))));
    }

    return [
        'mots_cles_fr' => array_slice($motsCles, 0, 10),
        'mots_cles_en' => [],
        'intitules_proches' => array_map('strval', $profil['metiers'] ?? []),
        'competences_fortes' => array_map('strval', $profil['competences'] ?? []),
        'points_amelioration' => [],
        'conseils_candidature' => [],
    ];
}

function opportunites_profil_hash(array $profil): string
{
    ksort($profil);
    return hash('sha256', json_encode($profil, JSON_UNESCAPED_UNICODE));
}

/**
 * Point d'entrée unique : gère le cache (table
 * classic_analyses_ia_opportunites), ne recalcule que si le profil ou le
 * CV a changé, essaie l'IA puis bascule silencieusement sur le repli en
 * cas d'échec. Ne lève jamais d'exception.
 *
 * @return array{source:string,mots_cles_fr:array,mots_cles_en:array,intitules_proches:array,competences_fortes:array,points_amelioration:array,conseils_candidature:array}
 */
function opportunites_analyser_profil(int $dossierId, array $profil, string $texteCv, string $texteLettre = ''): array
{
    $profilHash = opportunites_profil_hash($profil);
    $cvHash = $texteCv !== '' ? hash('sha256', $texteCv) : null;
    $lettreHash = $texteLettre !== '' ? hash('sha256', $texteLettre) : null;

    $stmt = db()->prepare('SELECT * FROM classic_analyses_ia_opportunites WHERE dossier_id = ?');
    $stmt->execute([$dossierId]);
    $cache = $stmt->fetch();

    if ($cache && $cache['profil_hash'] === $profilHash && $cache['cv_hash'] === $cvHash && $cache['lettre_hash'] === $lettreHash) {
        return [
            'source' => $cache['source'],
            'mots_cles_fr' => json_decode($cache['mots_cles_fr_json'] ?? '[]', true) ?: [],
            'mots_cles_en' => json_decode($cache['mots_cles_en_json'] ?? '[]', true) ?: [],
            'intitules_proches' => json_decode($cache['intitules_proches_json'] ?? '[]', true) ?: [],
            'competences_fortes' => json_decode($cache['competences_fortes_json'] ?? '[]', true) ?: [],
            'points_amelioration' => json_decode($cache['points_amelioration_json'] ?? '[]', true) ?: [],
            'conseils_candidature' => json_decode($cache['conseils_candidature_json'] ?? '[]', true) ?: [],
        ];
    }

    try {
        $resultat = opportunites_analyser_profil_ia($profil, $texteCv, $texteLettre);
        $source = 'ia';
    } catch (\Throwable $e) {
        error_log('[opportunites] Repli sans IA : ' . $e->getMessage());
        $resultat = opportunites_analyse_secours($profil, $texteCv, $texteLettre);
        $source = 'secours';
    }

    $stmt = db()->prepare(
        'INSERT INTO classic_analyses_ia_opportunites
            (dossier_id, profil_hash, cv_hash, lettre_hash, source, mots_cles_fr_json, mots_cles_en_json, intitules_proches_json, competences_fortes_json, points_amelioration_json, conseils_candidature_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            profil_hash = VALUES(profil_hash), cv_hash = VALUES(cv_hash), lettre_hash = VALUES(lettre_hash), source = VALUES(source),
            mots_cles_fr_json = VALUES(mots_cles_fr_json), mots_cles_en_json = VALUES(mots_cles_en_json),
            intitules_proches_json = VALUES(intitules_proches_json), competences_fortes_json = VALUES(competences_fortes_json),
            points_amelioration_json = VALUES(points_amelioration_json), conseils_candidature_json = VALUES(conseils_candidature_json)'
    );
    $stmt->execute([
        $dossierId, $profilHash, $cvHash, $lettreHash, $source,
        json_encode($resultat['mots_cles_fr'], JSON_UNESCAPED_UNICODE),
        json_encode($resultat['mots_cles_en'], JSON_UNESCAPED_UNICODE),
        json_encode($resultat['intitules_proches'], JSON_UNESCAPED_UNICODE),
        json_encode($resultat['competences_fortes'], JSON_UNESCAPED_UNICODE),
        json_encode($resultat['points_amelioration'], JSON_UNESCAPED_UNICODE),
        json_encode($resultat['conseils_candidature'], JSON_UNESCAPED_UNICODE),
    ]);

    return array_merge(['source' => $source], $resultat);
}

/**
 * Extrait un profil de recherche structuré (types/métiers/secteurs/niveau
 * d'études/expérience/compétences/langues/villes/mobilité/télétravail) à
 * partir du texte du CV — le client n'a plus à le saisir lui-même (voir
 * classic-mon-profil-recherche.php). N'invente jamais une information
 * absente du CV : un champ non déterminable reste vide/null plutôt que
 * deviné, ce qui correspond déjà à « aucune préférence » dans le calcul du
 * score (calculer_score_match()) — jamais une fausse restriction.
 *
 * @return array{donnees:array,source:string}
 */
function opportunites_extraire_profil_cv(string $texteCv): array
{
    if (trim($texteCv) === '') {
        return ['donnees' => opportunites_profil_vide(), 'source' => 'indisponible'];
    }
    if (!anthropic_configured()) {
        return ['donnees' => opportunites_profil_secours_depuis_cv($texteCv), 'source' => 'secours'];
    }

    $config = opportunites_ia_config()['anthropic'];
    $niveaux = ['BAC', 'BAC+2', 'BAC+3', 'BAC+4', 'BAC+5 et plus'];
    $systemPrompt = "Tu extrais un profil de recherche d'emploi structuré à partir d'un CV, pour un candidat ivoirien. "
        . "N'invente JAMAIS une information absente du texte : si un champ n'est pas clairement déterminable, renvoie "
        . "un tableau vide ou null plutôt qu'une supposition. Réponds UNIQUEMENT avec un objet JSON strict de la forme : "
        . '{"types":["emploi"],"metiers":["..."],"secteurs":["..."],"niveau_etudes":"BAC+3","annees_experience":3,"competences":["..."],"langues":["..."],"villes":["..."],"mobilite":"sur_place","teletravail":"non"}. '
        . '"types" : sous-ensemble de ["emploi","stage","alternance","mission"] déduit du profil (défaut ["emploi"] si le CV ne '
        . "suggère pas un profil étudiant/stagiaire). \"metiers\" : 1 à 4 intitulés de poste visés, déduits de l'expérience/formation "
        . "réelle. \"secteurs\" : 1 à 3 secteurs d'activité réels (RH, Finance, Commercial, Assistanat, IT / Digital, Logistique, "
        . "Hôtellerie, Autre — choisis parmi cette liste uniquement). \"niveau_etudes\" : une valeur EXACTE parmi "
        . implode(', ', $niveaux) . ', ou null si non déterminable. "annees_experience" : nombre entier d\'années '
        . "d'expérience professionnelle totale réelle, ou null. \"competences\" : 5 à 10 compétences réelles listées dans le CV. "
        . "\"langues\" : langues réellement mentionnées, avec niveau si précisé (ex. \"Français (natif)\"). \"villes\" : villes où "
        . "le candidat habite ou a indiqué vouloir travailler — tableau VIDE si rien de clair n'est indiqué (ne jamais deviner). "
        . '"mobilite" : "sur_place"/"regionale"/"nationale"/"internationale" selon ce que le CV indique, "sur_place" par défaut. '
        . '"teletravail" : "non"/"partiel"/"total" selon ce que le CV indique, "non" par défaut. Aucun texte en dehors du JSON.';

    $userPrompt = "Texte du CV :\n" . mb_substr($texteCv, 0, 8000);

    try {
        $reponse = anthropic_chat_completion($config['api_key'], $config['model'], $systemPrompt, $userPrompt);
        $texte = anthropic_extraire_texte($reponse);
        $donnees = json_decode($texte, true);
        if (!is_array($donnees)) {
            throw new RuntimeException('Réponse IA invalide (JSON non exploitable).');
        }
        return ['donnees' => opportunites_normaliser_profil_extrait($donnees), 'source' => 'ia'];
    } catch (\Throwable $e) {
        error_log('[opportunites] Extraction de profil CV : repli sans IA — ' . $e->getMessage());
        return ['donnees' => opportunites_profil_secours_depuis_cv($texteCv), 'source' => 'secours'];
    }
}

/** Valide/nettoie les champs renvoyés par l'IA avant de les transmettre à opportunites_enregistrer_profil(). */
function opportunites_normaliser_profil_extrait(array $donnees): array
{
    $typesValides = ['emploi', 'stage', 'alternance', 'mission'];
    $niveauxValides = ['BAC', 'BAC+2', 'BAC+3', 'BAC+4', 'BAC+5 et plus'];
    $mobiliteValides = ['sur_place', 'regionale', 'nationale', 'internationale'];
    $teletravailValides = ['non', 'partiel', 'total'];

    $types = array_values(array_intersect((array) ($donnees['types'] ?? []), $typesValides));
    $niveauEtudes = in_array($donnees['niveau_etudes'] ?? '', $niveauxValides, true) ? $donnees['niveau_etudes'] : null;
    $anneesExperience = is_numeric($donnees['annees_experience'] ?? null) ? max(0, min(50, (int) $donnees['annees_experience'])) : null;

    return [
        'types' => $types ?: ['emploi'],
        'metiers' => array_values(array_filter(array_map('strval', (array) ($donnees['metiers'] ?? [])))),
        'secteurs' => array_values(array_filter(array_map('strval', (array) ($donnees['secteurs'] ?? [])))),
        'niveau_etudes' => $niveauEtudes,
        'annees_experience' => $anneesExperience,
        'competences' => array_values(array_filter(array_map('strval', (array) ($donnees['competences'] ?? [])))),
        'langues' => array_values(array_filter(array_map('strval', (array) ($donnees['langues'] ?? [])))),
        'villes' => array_values(array_filter(array_map('strval', (array) ($donnees['villes'] ?? [])))),
        'mobilite' => in_array($donnees['mobilite'] ?? '', $mobiliteValides, true) ? $donnees['mobilite'] : 'sur_place',
        'teletravail' => in_array($donnees['teletravail'] ?? '', $teletravailValides, true) ? $donnees['teletravail'] : 'non',
    ];
}

function opportunites_profil_vide(): array
{
    return [
        'types' => ['emploi'], 'metiers' => [], 'secteurs' => [], 'niveau_etudes' => null, 'annees_experience' => null,
        'competences' => [], 'langues' => [], 'villes' => [], 'mobilite' => 'sur_place', 'teletravail' => 'non',
    ];
}

/**
 * Repli sans IA : seule la liste de compétences est déduite (fréquence des
 * mots du CV, même technique que opportunites_analyse_secours()) — tous les
 * champs nécessitant une vraie compréhension du texte (secteur, niveau
 * d'études, métiers visés...) restent vides plutôt que devinés au hasard.
 */
function opportunites_profil_secours_depuis_cv(string $texteCv): array
{
    $profil = opportunites_profil_vide();
    $motsVides = ['dans', 'pour', 'avec', 'sans', 'plus', 'être', 'avoir', 'cette', 'votre', 'notre', 'leurs', 'ainsi', 'donc', 'mais', 'tout', 'tous', 'toute', 'toutes', 'that', 'this', 'with', 'from', 'have', 'been', 'will'];
    $frequence = [];
    $texteNettoye = mb_strtolower(preg_replace('/[^\p{L}\s]/u', ' ', $texteCv), 'UTF-8');
    foreach (preg_split('/\s+/u', $texteNettoye) as $mot) {
        if (mb_strlen($mot) < 4 || in_array($mot, $motsVides, true)) {
            continue;
        }
        $frequence[$mot] = ($frequence[$mot] ?? 0) + 1;
    }
    arsort($frequence);
    $profil['competences'] = array_slice(array_keys($frequence), 0, 8);
    return $profil;
}

/**
 * Génère (ou adapte) une lettre de motivation pour une offre précise, à
 * partir du profil/CV/lettre existante du client — jamais d'invention
 * d'expérience ou de compétence absente des informations fournies. Le
 * client reste maître : ce texte est présenté pour relecture/modification
 * avant tout envoi (voir classic-mes-opportunites.php), jamais envoyé
 * automatiquement sans validation.
 *
 * @return array{texte:string,source:string}
 */
function opportunites_generer_lettre_ia(array $profil, string $texteCv, string $texteLettreExistante, array $offre, string $nomClient): array
{
    $systemPrompt = "Tu rédiges une lettre de motivation en français pour un candidat ivoirien, au nom de NexRaiz (plateforme RH). "
        . "Base-toi UNIQUEMENT sur le profil, le CV et (si fournie) la lettre existante du candidat : n'invente jamais une "
        . "expérience, un diplôme ou une compétence absente de ces informations. Ton professionnel, sincère, 250 à 350 mots, "
        . "sans fioritures excessives. Structure : accroche liée au poste, 2-3 paragraphes reliant le profil réel du candidat "
        . "aux besoins de l'offre, formule de politesse. Réponds UNIQUEMENT avec le texte de la lettre, sans titre ni "
        . "commentaire, en commençant directement par \"Madame, Monsieur,\" ou équivalent.";

    $userPrompt = "Candidat : {$nomClient}\n\nOffre visée :\n" . json_encode([
        'titre' => $offre['titre'] ?? '', 'entreprise' => $offre['entreprise'] ?? 'non communiquée',
        'ville' => $offre['ville'] ?? '', 'description' => mb_substr((string) ($offre['description'] ?? ''), 0, 1500),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\nProfil de recherche :\n" . json_encode($profil, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\nTexte du CV :\n" . ($texteCv !== '' ? mb_substr($texteCv, 0, 6000) : '(aucun CV disponible)')
        . "\n\nLettre de motivation existante (à adapter si fournie, sinon à écrire depuis le profil/CV) :\n"
        . ($texteLettreExistante !== '' ? mb_substr($texteLettreExistante, 0, 3000) : '(aucune — le candidat n\'en a pas)');

    if (anthropic_configured()) {
        try {
            $config = opportunites_ia_config()['anthropic'];
            $reponse = anthropic_chat_completion($config['api_key'], $config['model'], $systemPrompt, $userPrompt);
            $texte = anthropic_extraire_texte($reponse);
            if ($texte === '') {
                throw new RuntimeException('Réponse IA vide.');
            }
            return ['texte' => $texte, 'source' => 'ia'];
        } catch (\Throwable $e) {
            error_log('[opportunites] Génération de lettre (Anthropic) : repli — ' . $e->getMessage());
        }
    }

    // Pas de clé Anthropic (ou échec) : on utilise la clé Groq déjà active
    // pour le reste du projet plutôt que de retomber directement sur le
    // modèle sans IA — décision explicite du client.
    if (groq_configured()) {
        try {
            $configGroq = ai_config()['groq'];
            $reponse = groq_chat_completion($configGroq['api_key'], $configGroq['model'], [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ]);
            $texte = trim($reponse['choices'][0]['message']['content'] ?? '');
            if ($texte === '') {
                throw new RuntimeException('Réponse IA (Groq) vide.');
            }
            return ['texte' => $texte, 'source' => 'ia'];
        } catch (\Throwable $e) {
            error_log('[opportunites] Génération de lettre (Groq) : repli sans IA — ' . $e->getMessage());
        }
    }

    return ['texte' => opportunites_lettre_secours($profil, $offre, $nomClient), 'source' => 'secours'];
}

/**
 * Modèle simplifié sans IA : uniquement des champs réels (nom, métier
 * déclaré, titre/entreprise de l'offre) — jamais de phrase inventée sur
 * une expérience ou une compétence. Le client complète lui-même le corps.
 */
function opportunites_lettre_secours(array $profil, array $offre, string $nomClient): string
{
    $metier = $profil['metiers'][0] ?? 'ce poste';
    $entreprise = $offre['entreprise'] ?? '[nom de l\'entreprise]';
    $titre = $offre['titre'] ?? '[intitulé du poste]';
    return "Madame, Monsieur,\n\n"
        . "Je vous soumets ma candidature pour le poste de {$titre}" . ($entreprise ? " au sein de {$entreprise}" : '') . ".\n\n"
        . "[Complétez ici : en quoi votre expérience en tant que {$metier} correspond aux besoins de ce poste.]\n\n"
        . "Je reste à votre disposition pour échanger plus en détail sur ma candidature lors d'un entretien.\n\n"
        . "Cordialement,\n{$nomClient}\n\n"
        . "— Lettre modèle simplifiée (IA non configurée) : merci de la compléter avant envoi.";
}
