<?php
/**
 * NexRaiz — Assistance IA (Groq) à la correction de documents, Pack
 * Classic. Propose une amélioration du CV/lettre à partir du contenu
 * existant et du contexte du candidat ; ne modifie jamais rien directement
 * en base — le texte proposé remplit le champ "version corrigée" de
 * l'éditeur, le consultant doit relire et cliquer sur "Enregistrer cette
 * version" pour que ce soit pris en compte.
 */
require_once __DIR__ . '/db.php';

function ai_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/ai-config.php';
    }
    return $config;
}

function groq_configured(): bool
{
    return strpos(ai_config()['groq']['api_key'], 'REPLACE_ME') === false;
}

function chatbot_configured(): bool
{
    return strpos(ai_config()['groq_chatbot']['api_key'], 'REPLACE_ME') === false;
}

/**
 * Réponse du chatbot d'orientation affiché à tous les visiteurs (public,
 * sans connexion) pour les aider à choisir le pack NexRaiz adapté à leur
 * situation. Contrairement aux fonctions groq_* ci-dessus (usage interne,
 * consultants), celle-ci s'adresse directement au grand public : le prompt
 * système fige donc les faits (packs, tarifs) pour que le modèle ne puisse
 * pas en inventer d'autres, et borne le rôle du bot à l'orientation — jamais
 * de conseil RH personnalisé, de promesse de résultat ni de négociation de
 * prix, qui restent le rôle des consultants humains une fois le pack choisi.
 *
 * @param array<int,array{role:string,content:string}> $historique Derniers
 * échanges (hors message courant), déjà limités en nombre/longueur par
 * l'appelant.
 * @throws RuntimeException si le chatbot n'est pas configuré ou si Groq
 * renvoie une erreur.
 */
function groq_chatbot_repondre(string $message, array $historique): string
{
    if (!chatbot_configured()) {
        throw new RuntimeException("Le chatbot n'est pas encore configuré. Contactez l'administrateur pour l'activer.");
    }
    if (trim($message) === '') {
        throw new RuntimeException('Message vide.');
    }

    $config = ai_config()['groq_chatbot'];

    $systemPrompt = "Tu es l'assistant d'orientation NexRaiz, affiché en bulle de chat sur le site public. "
        . "NexRaiz propose 4 accompagnements RH, et ce sont les SEULS faits que tu peux donner sur l'offre (n'invente jamais un autre pack, un autre tarif, ou un détail contractuel) :\n"
        . "- Pack Assistance (30 000 FCFA) : soutien ponctuel avec un consultant RH face à une situation professionnelle sensible ou difficile (conflit, mal-être, décision délicate...). Page : decouverte.php\n"
        . "- Pack Classic (49 900 FCFA) : accompagnement recherche d'emploi — CV, lettre de motivation, profil LinkedIn retravaillés avec un consultant (et assistance IA de relecture), préparation de candidature. Page : classic-decouverte.php\n"
        . "- Pack Booster (150 000 FCFA) : accompagnement complet et approfondi pour faire évoluer sa carrière (le plus complet des 4). Page : booster-decouverte.php\n"
        . "- Pack Wellbeing (75 000 FCFA, environ 91 450 FCFA frais et TVA inclus) : équilibre et bien-être au travail, gestion du stress, accompagnement personnalisé. Un test de stress gratuit et sans inscription est disponible avant de s'engager : wellbeing-quiz-stress.php. Page : wellbeing-decouverte.php\n"
        . "Ton rôle : comprendre en 1-2 questions courtes la situation du visiteur, puis l'orienter vers LE pack le plus pertinent en expliquant pourquoi en une ou deux phrases, et toujours terminer en indiquant la page à visiter (donne juste le nom de fichier, ex. \"rendez-vous sur wellbeing-decouverte.php\"). "
        . "Si la situation évoque du stress, un épuisement, un déséquilibre vie pro/perso ou un mal-être au travail, mets en avant le Pack Wellbeing et propose spontanément le test de stress gratuit (wellbeing-quiz-stress.php) comme premier pas sans engagement. "
        . "Si plusieurs packs pourraient convenir, dis-le honnêtement et présente les 2 options plutôt que d'en inventer une seule. "
        . "Ne donne jamais de conseil RH, juridique ou psychologique de fond (ça, c'est le rôle des consultants humains une fois le pack choisi) : reste sur l'orientation. Ne négocie jamais les prix, ne promets aucun résultat ni délai précis. "
        . "Si une question sort du cadre (sujet hors NexRaiz, ou détail que tu ne connais pas), dis-le simplement et renvoie vers contact.php. "
        . "Réponds en français, ton chaleureux et professionnel, de façon TRÈS concise (2-4 phrases maximum, jamais de liste à puces ni de markdown) car c'est affiché dans une petite bulle de chat.";

    $messages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $historique,
        [['role' => 'user', 'content' => $message]]
    );

    $reponse = groq_chat_completion($config['api_key'], $config['model'], $messages);

    $texte = trim($reponse['choices'][0]['message']['content'] ?? '');
    if ($texte === '') {
        throw new RuntimeException('Réponse IA invalide ou vide.');
    }
    return $texte;
}

/**
 * @param array $contexte Champs libres sur le candidat (poste_vise, secteur,
 * experience, message_libre...), extraits de qualifications.donnees_json.
 * @param string $tonalite Tonalité souhaitée pour la réécriture (ex.
 * "Professionnel (cabinet de recrutement)"), optionnelle — utilisée par le
 * studio de correction ("Réécrire la section" avec un ton choisi).
 * @throws RuntimeException si Groq n'est pas configuré ou renvoie une erreur.
 */
function groq_ameliorer_document(string $typeDocument, string $contenu, array $contexte, string $tonalite = ''): string
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée. Contactez l'administrateur pour activer cette fonctionnalité.");
    }
    if (trim($contenu) === '') {
        throw new RuntimeException("Merci de renseigner d'abord la version initiale à améliorer.");
    }

    $config = ai_config()['groq'];
    $libelleType = ['cv' => 'CV', 'lettre' => 'lettre de motivation', 'linkedin' => 'profil LinkedIn'][$typeDocument] ?? 'document';

    $contexteTexte = [];
    if (!empty($contexte['poste_vise']))    $contexteTexte[] = 'Poste visé : ' . $contexte['poste_vise'];
    if (!empty($contexte['secteur']))       $contexteTexte[] = 'Secteur : ' . $contexte['secteur'];
    if (!empty($contexte['experience']))    $contexteTexte[] = 'Expérience : ' . $contexte['experience'];
    if (!empty($contexte['message_libre'])) $contexteTexte[] = 'Précisions du candidat : ' . $contexte['message_libre'];

    // Consignes spécifiques au type de document (cahier des charges, phase 6B).
    $consignesParType = [
        'cv' => "Porte une attention particulière à : la clarté de chaque expérience, la mise en valeur des compétences réellement déclarées (jamais une compétence absente du texte d'origine), la cohérence des dates, la qualité des formulations, et l'adaptation au poste visé si celui-ci est renseigné.",
        'lettre' => "Porte une attention particulière à : la structure (introduction, corps, conclusion), la clarté du projet professionnel, la pertinence par rapport au poste visé, un ton professionnel, et la suppression des répétitions.",
        'linkedin' => "Porte une attention particulière à : la clarté du titre professionnel (headline), la qualité du résumé ('à propos'), la cohérence des compétences mises en avant, et la mise en valeur de l'expérience réellement déclarée.",
    ];

    $systemPrompt = "Tu es un consultant RH senior chez NexRaiz, spécialisé dans l'optimisation de CV, lettres de motivation et profils LinkedIn. "
        . "Tu reçois le contenu actuel d'un(e) {$libelleType} et le contexte du candidat. "
        . "Réécris-le pour qu'il soit plus percutant, clair et professionnel : structure soignée, verbes d'action, résultats chiffrés quand c'est pertinent, orthographe et grammaire irréprochables, ton adapté au poste visé. "
        . ($consignesParType[$typeDocument] ?? '') . ' '
        . ($tonalite !== '' ? "Adopte cette tonalité précise : {$tonalite}. " : '')
        . "Ne change pas les faits (expériences, dates, diplômes, formations, certifications) : améliore uniquement la formulation et la mise en valeur, n'invente jamais un diplôme, une expérience, un résultat, une compétence ou une certification absente du texte fourni. "
        . "Si une information nécessaire manque pour bien valoriser un point, n'invente rien : laisse le passage tel quel plutôt que de combler le vide. "
        . "Conserve la même structure générale (mêmes sections, même ordre) et une longueur proche de l'original. "
        . "Réponds uniquement avec le texte amélioré du document, en texte brut : conserve les éventuels marqueurs **gras** et *italique* déjà présents dans le texte source s'il y en a, mais n'en ajoute pas de nouveaux ; pas de dièses ni d'autre symbole de formatage ; aucune introduction, aucun commentaire, aucun guillemet autour du texte.";

    $userPrompt = "Contexte du candidat :\n" . ($contexteTexte ? implode("\n", $contexteTexte) : 'Non renseigné')
        . "\n\nContenu actuel du {$libelleType} à améliorer :\n" . $contenu;

    $reponse = groq_chat_completion($config['api_key'], $config['model'], [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ]);

    $texte = trim($reponse['choices'][0]['message']['content'] ?? '');
    if ($texte === '') {
        throw new RuntimeException('Réponse IA invalide ou vide.');
    }
    return $texte;
}

/**
 * Détecte les fautes et incohérences dans un CV/lettre (sans le réécrire) :
 * renvoie la liste des passages à problème, pour les souligner dans
 * l'éditeur et laisser le consultant décider — correction manuelle, ou
 * utiliser "Améliorer avec l'IA" pour une réécriture complète.
 *
 * @return array<int,array{extrait:string,probleme:string}> Chaque "extrait"
 * est un passage copié mot pour mot du texte d'origine (pour pouvoir le
 * repérer et le souligner), jamais reformulé.
 * @throws RuntimeException si Groq n'est pas configuré ou renvoie une erreur.
 */
/** Catégories réelles que l'endpoint peut renvoyer — pour l'affichage structuré côté consultant. */
function classic_categories_erreurs_ia(): array
{
    return [
        'langue'      => ['Faute de langue', 'bg-red-100 text-red-700'],
        'repetition'  => ['Répétition', 'bg-orange-100 text-orange-700'],
        'ambiguite'   => ['Formulation ambiguë', 'bg-yellow-100 text-yellow-800'],
        'structure'   => ['Problème de structure', 'bg-blue-100 text-blue-700'],
        'incoherence' => ['Incohérence', 'bg-purple-100 text-purple-700'],
    ];
}

function groq_detecter_erreurs(string $typeDocument, string $contenu): array
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée. Contactez l'administrateur pour activer cette fonctionnalité.");
    }
    if (trim($contenu) === '') {
        throw new RuntimeException("Merci de renseigner d'abord la version initiale à analyser.");
    }

    $config = ai_config()['groq'];
    $libelleType = ['cv' => 'CV', 'lettre' => 'lettre de motivation', 'linkedin' => 'profil LinkedIn'][$typeDocument] ?? 'document';

    $systemPrompt = "Tu es un relecteur RH senior chez NexRaiz. Tu reçois le contenu d'un(e) {$libelleType} et tu dois repérer "
        . "les fautes d'orthographe/grammaire (catégorie \"langue\"), les répétitions de mots ou de tournures (catégorie \"repetition\"), "
        . "les formulations ambiguës ou peu claires (catégorie \"ambiguite\"), les problèmes de structure ou d'organisation (catégorie \"structure\"), "
        . "et les incohérences factuelles comme des dates qui ne s'enchaînent pas ou des informations contradictoires (catégorie \"incoherence\"). "
        . "Réponds UNIQUEMENT avec un objet JSON de la forme {\"erreurs\":[{\"extrait\":\"...\",\"probleme\":\"...\",\"categorie\":\"...\"}]}. "
        . "Chaque \"extrait\" doit être une COPIE EXACTE, mot pour mot, d'un passage court (quelques mots à une phrase) du texte fourni — "
        . "ne reformule jamais l'extrait, il doit pouvoir être retrouvé tel quel dans le texte d'origine. "
        . "\"probleme\" est une explication très courte (une phrase) de ce qui ne va pas. \"categorie\" vaut exactement l'une de : langue, repetition, ambiguite, structure, incoherence. "
        . "Limite-toi aux 10 problèmes les plus importants. S'il n'y a aucun problème notable, renvoie {\"erreurs\":[]}.";

    $reponse = groq_chat_completion($config['api_key'], $config['model'], [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Contenu du {$libelleType} à analyser :\n" . $contenu],
    ], true);

    $brut = trim($reponse['choices'][0]['message']['content'] ?? '');
    $donnees = json_decode($brut, true);
    $erreurs = $donnees['erreurs'] ?? null;
    if (!is_array($erreurs)) {
        throw new RuntimeException('Réponse IA invalide.');
    }

    $categoriesValides = array_keys(classic_categories_erreurs_ia());
    $resultat = [];
    foreach ($erreurs as $e) {
        $extrait = trim((string) ($e['extrait'] ?? ''));
        $probleme = trim((string) ($e['probleme'] ?? ''));
        $categorie = trim((string) ($e['categorie'] ?? ''));
        if (!in_array($categorie, $categoriesValides, true)) {
            $categorie = 'langue';
        }
        // On ne garde que les extraits réellement présents dans le texte
        // d'origine : un extrait reformulé par l'IA serait impossible à
        // localiser pour le soulignement côté navigateur.
        if ($extrait !== '' && $probleme !== '' && mb_stripos($contenu, $extrait) !== false) {
            $resultat[] = ['extrait' => $extrait, 'probleme' => $probleme, 'categorie' => $categorie];
        }
    }
    return $resultat;
}

/**
 * Variante de groq_detecter_erreurs() qui propose, pour chaque problème
 * repéré, une correction prête à appliquer (pas seulement un diagnostic) —
 * utilisée par le studio de correction (suggestions avec bouton
 * "Appliquer"). Le consultant reste décisionnaire : "Appliquer" ne fait
 * qu'un remplacement local du passage signalé dans l'éditeur, jamais une
 * réécriture automatique du document entier.
 *
 * @return array<int,array{extrait:string,probleme:string,correction:string,categorie:string}>
 * @throws RuntimeException si Groq n'est pas configuré ou renvoie une erreur.
 */
function groq_suggestions_document(string $typeDocument, string $contenu): array
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée. Contactez l'administrateur pour activer cette fonctionnalité.");
    }
    if (trim($contenu) === '') {
        throw new RuntimeException("Merci de renseigner d'abord la version à analyser.");
    }

    $config = ai_config()['groq'];
    $libelleType = $typeDocument === 'lettre' ? 'lettre de motivation' : 'CV';

    $systemPrompt = "Tu es un relecteur RH senior chez NexRaiz. Tu reçois le contenu d'un(e) {$libelleType} et tu dois proposer "
        . "des corrections concrètes, prêtes à appliquer d'un clic : fautes d'orthographe/grammaire, formulations maladroites, "
        . "verbes d'action faibles, manque d'impact (résultats non chiffrés). "
        . "Réponds UNIQUEMENT avec un objet JSON {\"suggestions\":[{\"extrait\":\"...\",\"probleme\":\"...\",\"correction\":\"...\",\"categorie\":\"...\"}]}. "
        . "\"extrait\" doit être une COPIE EXACTE, mot pour mot, d'un passage court (quelques mots à une phrase) du texte fourni — "
        . "ne le reformule jamais, il doit pouvoir être retrouvé tel quel. \"correction\" est le texte de remplacement proposé pour cet extrait "
        . "exclusivement (ne change jamais les faits : dates, chiffres, noms, diplômes, employeurs ; n'invente aucune expérience ni compétence absente du texte). "
        . "\"probleme\" est une explication très courte (une phrase). \"categorie\" vaut exactement \"ortho\" (orthographe/grammaire), "
        . "\"style\" (formulation, clarté, verbes d'action) ou \"impact\" (manque de résultats concrets/chiffrés, valorisation RH). "
        . "Limite-toi aux 8 suggestions les plus importantes. S'il n'y a rien à proposer, renvoie {\"suggestions\":[]}.";

    $reponse = groq_chat_completion($config['api_key'], $config['model'], [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Contenu du {$libelleType} à analyser :\n" . $contenu],
    ], true);

    $brut = trim($reponse['choices'][0]['message']['content'] ?? '');
    $donnees = json_decode($brut, true);
    $suggestions = $donnees['suggestions'] ?? null;
    if (!is_array($suggestions)) {
        throw new RuntimeException('Réponse IA invalide.');
    }

    $categoriesValides = ['ortho', 'style', 'impact'];
    $resultat = [];
    foreach ($suggestions as $s) {
        $extrait = trim((string) ($s['extrait'] ?? ''));
        $probleme = trim((string) ($s['probleme'] ?? ''));
        $correction = trim((string) ($s['correction'] ?? ''));
        $categorieBrute = mb_strtolower(trim((string) ($s['categorie'] ?? '')), 'UTF-8');
        if (strpos($categorieBrute, 'ortho') === 0) {
            $categorie = 'ortho';
        } elseif (strpos($categorieBrute, 'impact') === 0) {
            $categorie = 'impact';
        } elseif (in_array($categorieBrute, $categoriesValides, true)) {
            $categorie = $categorieBrute;
        } else {
            $categorie = 'style';
        }
        if ($extrait !== '' && $probleme !== '' && $correction !== '' && mb_stripos($contenu, $extrait) !== false) {
            $resultat[] = ['extrait' => $extrait, 'probleme' => $probleme, 'correction' => $correction, 'categorie' => $categorie];
        }
    }
    return $resultat;
}

/**
 * Évalue la qualité globale du document (score indicatif + sous-scores),
 * pour le tableau de bord du studio de correction. Purement informatif :
 * n'influence aucune décision automatique, juste un repère pour le
 * consultant.
 *
 * @return array{score:int,orthographe:int,style:int,impact:int}
 * @throws RuntimeException si Groq n'est pas configuré ou renvoie une erreur.
 */
function groq_evaluer_qualite(string $typeDocument, string $contenu): array
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée.");
    }
    if (trim($contenu) === '') {
        throw new RuntimeException('Document vide.');
    }

    $config = ai_config()['groq'];
    $libelleType = $typeDocument === 'lettre' ? 'lettre de motivation' : 'CV';

    $systemPrompt = "Tu es un relecteur RH senior. Évalue ce(tte) {$libelleType} et réponds UNIQUEMENT avec un objet JSON "
        . "{\"orthographe\":N,\"style\":N,\"impact\":N} où chaque N est un entier de 0 à 100 : "
        . "\"orthographe\" = qualité orthographique/grammaticale, \"style\" = clarté/professionnalisme/verbes d'action, "
        . "\"impact\" = présence de résultats concrets et chiffrés valorisant le candidat. Sois honnête, ne surnote pas.";

    $reponse = groq_chat_completion($config['api_key'], $config['model'], [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Contenu du {$libelleType} :\n" . $contenu],
    ], true);

    $brut = trim($reponse['choices'][0]['message']['content'] ?? '');
    $donnees = json_decode($brut, true);
    if (!is_array($donnees)) {
        throw new RuntimeException('Réponse IA invalide.');
    }

    $ortho = max(0, min(100, (int) ($donnees['orthographe'] ?? -1)));
    $style = max(0, min(100, (int) ($donnees['style'] ?? -1)));
    $impact = max(0, min(100, (int) ($donnees['impact'] ?? -1)));
    if (!isset($donnees['orthographe'], $donnees['style'], $donnees['impact'])) {
        throw new RuntimeException('Réponse IA incomplète.');
    }

    return [
        'score'       => (int) round(($ortho + $style + $impact) / 3),
        'orthographe' => $ortho,
        'style'       => $style,
        'impact'      => $impact,
    ];
}

/**
 * Propose une reformulation d'un seul bloc de texte du document (éditeur
 * visuel "Canva", Pack Classic) : contrairement à groq_ameliorer_document(),
 * qui réécrit tout le document, cette fonction cible un passage précis
 * (une ligne, une puce, un paragraphe) repéré par le consultant sur le
 * canevas. La proposition n'est jamais appliquée automatiquement : elle
 * est affichée au consultant pour acceptation, modification ou refus.
 *
 * @throws RuntimeException si Groq n'est pas configuré ou renvoie une erreur.
 */
function groq_ameliorer_bloc(string $texteOriginal, array $contexte, string $instruction = ''): string
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée. Contactez l'administrateur pour activer cette fonctionnalité.");
    }
    if (trim($texteOriginal) === '') {
        throw new RuntimeException('Ce bloc de texte est vide.');
    }

    $config = ai_config()['groq'];

    $contexteTexte = [];
    if (!empty($contexte['poste_vise']))    $contexteTexte[] = 'Poste visé : ' . $contexte['poste_vise'];
    if (!empty($contexte['secteur']))       $contexteTexte[] = 'Secteur : ' . $contexte['secteur'];
    if (!empty($contexte['experience']))    $contexteTexte[] = 'Expérience : ' . $contexte['experience'];

    $systemPrompt = "Tu es un consultant RH senior chez NexRaiz. Tu reçois UN SEUL passage court extrait d'un CV ou d'une lettre de motivation "
        . "(une ligne, une puce ou une phrase), tel qu'il apparaît visuellement sur le document. "
        . "Améliore uniquement ce passage : orthographe, grammaire, clarté, professionnalisme, verbes d'action, adaptation au poste visé si pertinent. "
        . "Ne change jamais les faits (dates, chiffres, noms, diplômes, employeurs) et n'invente aucune expérience, compétence ou diplôme absent du texte fourni. "
        . "Garde une longueur proche de l'original : ce passage occupe un espace précis sur le document, une réponse beaucoup plus longue ne tiendrait pas à sa place. "
        . "Réponds UNIQUEMENT avec le texte amélioré de ce passage, en texte brut, sans aucune mise en forme markdown, sans introduction ni commentaire, sans guillemets.";

    $userPrompt = ($contexteTexte ? "Contexte du candidat :\n" . implode("\n", $contexteTexte) . "\n\n" : '')
        . ($instruction !== '' ? "Consigne du consultant : {$instruction}\n\n" : '')
        . "Passage à améliorer :\n" . $texteOriginal;

    $reponse = groq_chat_completion($config['api_key'], $config['model'], [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ]);

    $texte = trim($reponse['choices'][0]['message']['content'] ?? '');
    $texte = trim($texte, "\"'");
    if ($texte === '') {
        throw new RuntimeException('Réponse IA invalide ou vide.');
    }
    return $texte;
}

/**
 * Assistance IA pour la rédaction d'un compte rendu structuré (Pack
 * Assistance). Deux modes, jamais appliqués automatiquement — le texte ou
 * les actions proposées restent à relire et à accepter manuellement par le
 * consultant dans le formulaire :
 *  - "reformulation" : réécrit un texte libre (contexte, résumé des
 *    échanges...) de façon plus claire et professionnelle, sans changer les
 *    faits ni rien inventer.
 *  - "actions-suggerees" : à partir des faits rapportés / décisions /
 *    prochaine étape déjà saisis, propose une liste d'actions concrètes au
 *    format utilisable par le formulaire ({description, responsable,
 *    echeance}). Ne doit jamais inventer une date ou un fait qui ne figure
 *    pas déjà dans le texte fourni.
 *
 * @return string|array Texte reformulé (mode "reformulation") ou liste
 * d'actions (mode "actions-suggerees").
 * @throws RuntimeException si Groq n'est pas configuré, si l'entrée est
 * vide, ou si la réponse de l'API est invalide.
 */
function groq_assister_compte_rendu(string $mode, string $contenu): string|array
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée. Contactez l'administrateur pour activer cette fonctionnalité.");
    }
    if (trim($contenu) === '') {
        throw new RuntimeException('Merci de renseigner du contenu avant de demander une assistance IA.');
    }

    $config = ai_config()['groq'];
    $garanties = "Règles impératives : ne jamais inventer de fait, de date, de nom, de diagnostic professionnel ou d'allégation (harcèlement, "
        . "discrimination, faute...) qui ne soit pas déjà explicitement présent dans le texte fourni. Distingue toujours ce qui est rapporté "
        . "par les parties (déclaratif) de ce qui est un constat établi. En cas de doute, reste neutre et factuel.";

    if ($mode === 'reformulation') {
        $systemPrompt = "Tu es un consultant RH senior chez NexRaiz qui aide à rédiger un compte rendu d'intervention professionnel. "
            . "Reformule le texte fourni pour le rendre plus clair, structuré et professionnel, sans changer son sens ni sa longueur de façon excessive. "
            . "Rédige systématiquement en français, quelle que soit la langue détectée dans le texte source. "
            . "{$garanties} Réponds UNIQUEMENT avec le texte reformulé, en texte brut, sans markdown ni commentaire.";

        $reponse = groq_chat_completion($config['api_key'], $config['model'], [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $contenu],
        ]);
        $texte = trim($reponse['choices'][0]['message']['content'] ?? '', " \"'\n");
        if ($texte === '') {
            throw new RuntimeException('Réponse IA invalide ou vide.');
        }
        return $texte;
    }

    if ($mode === 'actions-suggerees') {
        $systemPrompt = "Tu es un consultant RH senior chez NexRaiz. À partir des notes d'intervention fournies (faits rapportés, décisions, "
            . "prochaine étape...), propose des actions concrètes de suivi. Rédige systématiquement en français, quelle que soit la langue "
            . "détectée dans le texte source. {$garanties} "
            . "Réponds UNIQUEMENT avec un objet JSON {\"actions\":[{\"description\":\"...\",\"responsable\":\"Client\"|\"Consultant\"|\"...\",\"echeance\":\"AAAA-MM-JJ\"|null}]}. "
            . "N'invente une échéance que si une durée ou une date est explicitement mentionnée dans le texte ; sinon laisse echeance à null. "
            . "Limite-toi à 5 actions maximum. S'il n'y a rien de concret à proposer, renvoie {\"actions\":[]}.";

        $reponse = groq_chat_completion($config['api_key'], $config['model'], [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $contenu],
        ], true);
        $brut = trim($reponse['choices'][0]['message']['content'] ?? '');
        $donnees = json_decode($brut, true);
        $actionsBrutes = $donnees['actions'] ?? null;
        if (!is_array($actionsBrutes)) {
            throw new RuntimeException('Réponse IA invalide.');
        }
        $resultat = [];
        foreach (array_slice($actionsBrutes, 0, 5) as $a) {
            $description = trim((string) ($a['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $echeance = $a['echeance'] ?? null;
            $echeanceValide = is_string($echeance) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $echeance) ? $echeance : null;
            $resultat[] = [
                'description' => $description,
                'responsable' => trim((string) ($a['responsable'] ?? '')),
                'echeance'    => $echeanceValide,
            ];
        }
        return $resultat;
    }

    throw new RuntimeException('Mode d\'assistance IA inconnu.');
}

/**
 * Extrait un résumé structuré d'un CV (module "Déposer des documents", Pack
 * Classic) : poste visé probable, expérience, compétences, résumé court, et
 * les informations qui semblent manquantes. Purement informatif pour le
 * consultant — n'écrit jamais dans les réponses officielles du candidat à
 * son propre formulaire de qualification (qualifications.donnees_json n'est
 * mis à jour, séparément, que dans un espace dédié "extraction_ia", jamais
 * fusionné avec les champs que le candidat a renseignés lui-même).
 *
 * @return array{poste_detecte:?string,experience_resumee:?string,competences:array<int,string>,resume:string,informations_manquantes:array<int,string>}
 * @throws RuntimeException si Groq n'est pas configuré, le texte est vide, ou la réponse est invalide.
 */
function groq_extraire_infos_cv(string $texteCv): array
{
    if (!groq_configured()) {
        throw new RuntimeException("L'assistance IA n'est pas encore configurée. Contactez l'administrateur pour activer cette fonctionnalité.");
    }
    if (trim($texteCv) === '') {
        throw new RuntimeException('Document vide : rien à analyser.');
    }

    $config = ai_config()['groq'];
    $systemPrompt = "Tu es un assistant RH chez NexRaiz qui aide un consultant à prendre connaissance rapidement d'un CV déposé. "
        . "Analyse UNIQUEMENT ce qui est explicitement écrit dans le texte fourni : n'invente jamais un poste, une expérience, "
        . "un diplôme ou une compétence absente du texte. Si une information n'est pas présente, ne la devine pas. "
        . "Réponds UNIQUEMENT avec un objet JSON de la forme "
        . '{"poste_detecte":"...ou null","experience_resumee":"...ou null","competences":["..."],"resume":"...","informations_manquantes":["..."]}. '
        . '"poste_detecte" est l\'intitulé de poste le plus probable visé par ce CV (ou le dernier poste occupé si aucun objectif explicite), null si indéterminable. '
        . '"experience_resumee" est une phrase courte résumant le niveau d\'expérience (ex. "5 ans en développement web"), null si indéterminable. '
        . '"competences" est une liste de 3 à 8 compétences explicitement mentionnées. "resume" est un résumé neutre de 2-3 phrases du profil. '
        . '"informations_manquantes" liste les informations usuelles d\'un CV absentes de ce document (ex. "aucune coordonnée téléphonique", "aucune date sur les expériences").';

    $reponse = groq_chat_completion($config['api_key'], $config['model'], [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Contenu du CV :\n" . mb_substr($texteCv, 0, 8000)],
    ], true);

    $brut = trim($reponse['choices'][0]['message']['content'] ?? '');
    $donnees = json_decode($brut, true);
    if (!is_array($donnees)) {
        throw new RuntimeException('Réponse IA invalide.');
    }

    return [
        'poste_detecte' => is_string($donnees['poste_detecte'] ?? null) ? trim($donnees['poste_detecte']) : null,
        'experience_resumee' => is_string($donnees['experience_resumee'] ?? null) ? trim($donnees['experience_resumee']) : null,
        'competences' => is_array($donnees['competences'] ?? null) ? array_values(array_filter(array_map('strval', $donnees['competences']))) : [],
        'resume' => is_string($donnees['resume'] ?? null) ? trim($donnees['resume']) : '',
        'informations_manquantes' => is_array($donnees['informations_manquantes'] ?? null) ? array_values(array_filter(array_map('strval', $donnees['informations_manquantes']))) : [],
    ];
}

/**
 * Appel générique à l'API Groq (compatible format OpenAI Chat Completions).
 * @throws RuntimeException en cas d'erreur réseau ou de refus de l'API.
 */
function groq_chat_completion(string $apiKey, string $model, array $messages, bool $jsonMode = false): array
{
    $payload = [
        'model'                 => $model,
        'messages'              => $messages,
        'temperature'           => 0.4,
        // Certains modèles Groq (ex. les modèles "gpt-oss", qui raisonnent
        // avant de répondre) consomment une partie du budget de tokens en
        // raisonnement interne avant de produire la réponse finale : sur un
        // CV complet avec une réponse JSON structurée (plusieurs suggestions
        // à champs multiples), 4096 tokens se sont révélés insuffisants —
        // Groq renvoyait une erreur "Failed to validate JSON" avec
        // failed_generation vide (le raisonnement interne avait épuisé tout
        // le budget avant même de commencer le JSON). On réserve donc large,
        // et on réduit l'effort de raisonnement : les tâches de ce projet
        // (correction, classification, extraction) n'ont pas besoin d'un
        // raisonnement profond, seulement d'un suivi d'instruction fiable.
        'max_completion_tokens' => 8192,
        'reasoning_effort'      => 'low',
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        // Délai de connexion distinct du délai total : un blocage dès la
        // phase de connexion (ex. résolution IPv6 bancale sur certains
        // postes Windows/XAMPP, cause fréquente de "Timed out" intermittents
        // vers des hôtes précis) échoue vite plutôt que de consommer les 30s.
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $reponseBrute = curl_exec($ch);
    $erreur = curl_error($ch);
    $erreurEstTimeout = curl_errno($ch) === CURLE_OPERATION_TIMEOUTED;
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($erreur) {
        $message = $erreurEstTimeout
            ? "Le service IA n'a pas répondu à temps (connexion internet instable ou lente). Réessayez dans quelques instants."
            : "Impossible de joindre le service IA pour le moment. Vérifiez la connexion internet du serveur, puis réessayez.";
        throw new RuntimeException($message . ' (détail technique : ' . $erreur . ')');
    }
    $donnees = json_decode((string) $reponseBrute, true) ?: [];
    if ($httpCode >= 400) {
        throw new RuntimeException('Groq a refusé la requête : ' . ($donnees['error']['message'] ?? "code HTTP {$httpCode}"));
    }
    return $donnees;
}
