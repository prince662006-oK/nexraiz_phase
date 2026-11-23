<?php
/**
 * NexRaiz — « Mes opportunités » (Pack Classic) : profil de recherche,
 * calcul du score de correspondance, liens de recherche externes,
 * interactions client (enregistrer/masquer/suivi de candidature), CRUD
 * admin des offres, et aperçu consultant. Même convention que le reste du
 * projet : fonctions procédurales, pas de classe.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/documents-classic.php';
require_once __DIR__ . '/extraction.php';
require_once __DIR__ . '/opportunites-ia.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mailer.php';

function opportunites_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/opportunites-config.php';
    }
    return $config;
}

// ===========================================================================
// Profil de recherche
// ===========================================================================

const OPPORTUNITES_CHAMPS_JSON = ['types', 'metiers', 'secteurs', 'competences', 'langues', 'villes'];

/** Décode une ligne `classic_profils_recherche` en tableau PHP exploitable par le score/l'IA. */
function opportunites_decoder_profil(array $ligne): array
{
    $profil = [
        'niveau_etudes' => $ligne['niveau_etudes'] ?? null,
        'annees_experience' => $ligne['annees_experience'] !== null ? (int) $ligne['annees_experience'] : null,
        'mobilite' => $ligne['mobilite'] ?? 'sur_place',
        'teletravail' => $ligne['teletravail'] ?? 'non',
        'disponibilite_le' => $ligne['disponibilite_le'] ?? null,
        'cv_document_id' => $ligne['cv_document_id'] ?? null,
        'consent_matching' => (bool) ($ligne['consent_matching'] ?? false),
        'consent_recruteurs' => (bool) ($ligne['consent_recruteurs'] ?? false),
    ];
    foreach (OPPORTUNITES_CHAMPS_JSON as $champ) {
        $profil[$champ] = json_decode($ligne[$champ . '_json'] ?? '[]', true) ?: [];
    }
    return $profil;
}

function opportunites_profil_dossier(int $dossierId): ?array
{
    $stmt = db()->prepare('SELECT * FROM classic_profils_recherche WHERE dossier_id = ?');
    $stmt->execute([$dossierId]);
    $ligne = $stmt->fetch();
    return $ligne ? opportunites_decoder_profil($ligne) : null;
}

/**
 * Le consentement seul ne suffit pas : « Mes opportunités » (recommandations,
 * recherche externe, candidature) doit rester réservé aux dossiers dont le
 * CV a réellement été analysé par NexRaiz (cv_document_id renseigné par
 * opportunites_enregistrer_profil()). Un profil avec consent_matching=1 mais
 * sans cv_document_id — un ancien profil saisi à la main avant la bascule
 * vers l'extraction par IA, par exemple — est traité comme non activé tant
 * que le client n'a pas relancé l'analyse depuis classic-mon-profil-recherche.php.
 */
function opportunites_profil_actif(?array $profil): bool
{
    return $profil !== null && $profil['consent_matching'] && !empty($profil['cv_document_id']);
}

/**
 * Enregistre (crée ou met à jour) le profil de recherche d'un dossier.
 * Les consentements ne sont marqués "accordés le ..." qu'au moment où ils
 * passent de non-coché à coché ; un consentement retiré repasse à 0 avec
 * une date vidée (voir opportunites_revoquer_consentement() pour un retrait
 * explicite et isolé, utilisé par le bouton dédié côté client).
 *
 * @return string[] Liste des erreurs de validation (vide si tout est valide).
 */
function opportunites_enregistrer_profil(int $dossierId, array $donnees): array
{
    $erreurs = [];
    $typesValides = ['emploi', 'stage', 'alternance', 'mission'];
    $mobiliteValides = ['sur_place', 'regionale', 'nationale', 'internationale'];
    $teletravailValides = ['non', 'partiel', 'total'];

    $types = array_values(array_intersect((array) ($donnees['types'] ?? []), $typesValides));
    if (empty($types)) {
        $erreurs[] = 'Choisissez au moins un type de recherche (emploi, stage, alternance ou mission).';
    }
    $metiers = array_values(array_filter(array_map('trim', (array) ($donnees['metiers'] ?? []))));
    $secteurs = array_values(array_filter(array_map('trim', (array) ($donnees['secteurs'] ?? []))));
    $competences = array_values(array_filter(array_map('trim', (array) ($donnees['competences'] ?? []))));
    $langues = array_values(array_filter((array) ($donnees['langues'] ?? [])));
    $villes = array_values(array_filter(array_map('trim', (array) ($donnees['villes'] ?? []))));

    $niveauEtudes = trim((string) ($donnees['niveau_etudes'] ?? '')) ?: null;
    $anneesExperience = $donnees['annees_experience'] !== '' && isset($donnees['annees_experience']) ? max(0, (int) $donnees['annees_experience']) : null;
    $mobilite = in_array($donnees['mobilite'] ?? '', $mobiliteValides, true) ? $donnees['mobilite'] : 'sur_place';
    $teletravail = in_array($donnees['teletravail'] ?? '', $teletravailValides, true) ? $donnees['teletravail'] : 'non';
    $disponibiliteLe = !empty($donnees['disponibilite_le']) ? $donnees['disponibilite_le'] : null;
    if ($disponibiliteLe !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $disponibiliteLe)) {
        $erreurs[] = 'Date de disponibilité invalide.';
        $disponibiliteLe = null;
    }

    $consentMatching = !empty($donnees['consent_matching']);
    $consentRecruteurs = !empty($donnees['consent_recruteurs']);
    if (!$consentMatching) {
        $erreurs[] = "Vous devez accepter que NexRaiz utilise votre profil pour vous proposer des offres afin d'activer « Mes opportunités ».";
    }

    if (!empty($erreurs)) {
        return $erreurs;
    }

    // Date "candidate" si le consentement est coché maintenant ; la requête
    // SQL (ON DUPLICATE KEY ... CASE) ne l'adopte réellement que si le
    // consentement vient d'être accordé (passage de 0 à 1), et conserve la
    // date d'origine si déjà accordé avant — donc pas besoin de relire
    // l'état précédent ici pour décider, seulement pour l'INSERT initial.
    $matchingLe = $consentMatching ? date('Y-m-d H:i:s') : null;
    $recruteursLe = $consentRecruteurs ? date('Y-m-d H:i:s') : null;

    // Le CV réellement utilisé pour produire CES données (issu de
    // opportunites_extraire_profil_cv()) — jamais null quand le consentement
    // matching est accordé : classic-mon-profil-recherche.php bloque déjà
    // l'activation sans CV déposé, et opportunites_recommandees_pour_dossier()
    // exige ce champ en plus du consentement (voir opportunites_profil_actif())
    // pour qu'aucun profil non analysé par l'IA ne puisse jamais être
    // recommandé — y compris un ancien profil saisi à la main avant que
    // cette fonctionnalité ne devienne pilotée par le CV.
    $cvDocumentId = isset($donnees['cv_document_id']) && $donnees['cv_document_id'] ? (int) $donnees['cv_document_id'] : null;

    $stmt = db()->prepare(
        'INSERT INTO classic_profils_recherche
            (dossier_id, types_json, metiers_json, secteurs_json, niveau_etudes, annees_experience, competences_json, langues_json, villes_json, mobilite, teletravail, disponibilite_le, cv_document_id, consent_matching, consent_matching_le, consent_recruteurs, consent_recruteurs_le)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            types_json = VALUES(types_json), metiers_json = VALUES(metiers_json), secteurs_json = VALUES(secteurs_json),
            niveau_etudes = VALUES(niveau_etudes), annees_experience = VALUES(annees_experience),
            competences_json = VALUES(competences_json), langues_json = VALUES(langues_json), villes_json = VALUES(villes_json),
            mobilite = VALUES(mobilite), teletravail = VALUES(teletravail), disponibilite_le = VALUES(disponibilite_le),
            cv_document_id = VALUES(cv_document_id),
            consent_matching = VALUES(consent_matching),
            consent_matching_le = CASE WHEN VALUES(consent_matching) = 1 AND consent_matching = 0 THEN VALUES(consent_matching_le)
                                       WHEN VALUES(consent_matching) = 0 THEN NULL
                                       ELSE consent_matching_le END,
            consent_recruteurs = VALUES(consent_recruteurs),
            consent_recruteurs_le = CASE WHEN VALUES(consent_recruteurs) = 1 AND consent_recruteurs = 0 THEN VALUES(consent_recruteurs_le)
                                         WHEN VALUES(consent_recruteurs) = 0 THEN NULL
                                         ELSE consent_recruteurs_le END'
    );
    $stmt->execute([
        $dossierId,
        json_encode($types, JSON_UNESCAPED_UNICODE), json_encode($metiers, JSON_UNESCAPED_UNICODE), json_encode($secteurs, JSON_UNESCAPED_UNICODE),
        $niveauEtudes, $anneesExperience, json_encode($competences, JSON_UNESCAPED_UNICODE), json_encode($langues, JSON_UNESCAPED_UNICODE), json_encode($villes, JSON_UNESCAPED_UNICODE),
        $mobilite, $teletravail, $disponibiliteLe, $cvDocumentId,
        $consentMatching ? 1 : 0, $matchingLe,
        $consentRecruteurs ? 1 : 0, $recruteursLe,
    ]);

    return [];
}

/** Retrait explicite et isolé d'un consentement (bouton dédié, hors formulaire complet). */
function opportunites_revoquer_consentement(int $dossierId, string $type): void
{
    $colonne = $type === 'recruteurs' ? 'consent_recruteurs' : 'consent_matching';
    $stmt = db()->prepare("UPDATE classic_profils_recherche SET {$colonne} = 0, {$colonne}_le = NULL WHERE dossier_id = ?");
    $stmt->execute([$dossierId]);
}

/** Texte du CV actuellement déposé par le client (slot "cv"), ou chaîne vide si indisponible — ne lève jamais d'exception. */
function opportunites_texte_cv_dossier(int $dossierId): string
{
    try {
        $document = classic_documents_par_slot($dossierId)['cv']['actuel'] ?? null;
        if (!$document) {
            return '';
        }
        $cheminAbsolu = classic_chemin_absolu_fichier($document['chemin']);
        $extension = pathinfo($document['nom_original'], PATHINFO_EXTENSION);
        return extraire_texte_document($cheminAbsolu, $extension) ?? '';
    } catch (\Throwable $e) {
        error_log('[opportunites] Extraction CV impossible : ' . $e->getMessage());
        return '';
    }
}

/** Texte de la lettre de motivation actuellement déposée (slot "lettre"), ou chaîne vide — ne lève jamais d'exception. */
function opportunites_texte_lettre_dossier(int $dossierId): string
{
    try {
        $document = classic_documents_par_slot($dossierId)['lettre']['actuel'] ?? null;
        if (!$document) {
            return '';
        }
        $cheminAbsolu = classic_chemin_absolu_fichier($document['chemin']);
        $extension = pathinfo($document['nom_original'], PATHINFO_EXTENSION);
        return extraire_texte_document($cheminAbsolu, $extension) ?? '';
    } catch (\Throwable $e) {
        error_log('[opportunites] Extraction lettre de motivation impossible : ' . $e->getMessage());
        return '';
    }
}

/** Fichier CV actuel (chemin absolu + nom d'origine), pour pièce jointe d'une candidature — null si aucun CV. */
function opportunites_fichier_cv_dossier(int $dossierId): ?array
{
    try {
        $document = classic_documents_par_slot($dossierId)['cv']['actuel'] ?? null;
        if (!$document) {
            return null;
        }
        return ['chemin' => classic_chemin_absolu_fichier($document['chemin']), 'nom' => $document['nom_original']];
    } catch (\Throwable $e) {
        error_log('[opportunites] Fichier CV introuvable : ' . $e->getMessage());
        return null;
    }
}

// ===========================================================================
// Score de correspondance (fonction pure, sans accès base)
// ===========================================================================

function opportunites_rang_niveau_etudes(?string $niveau): int
{
    $rangs = ['bac' => 1, 'bac+2' => 2, 'bac+3' => 3, 'bac+4' => 4, 'bac+5 et plus' => 5, 'bac+5' => 5];
    return $rangs[mb_strtolower(trim((string) $niveau))] ?? 0;
}

/** Compare deux chaînes normalisées (minuscules, sans accents superflus, espaces compactés). */
function opportunites_normaliser_texte(string $texte): string
{
    $texte = mb_strtolower(trim($texte), 'UTF-8');
    return preg_replace('/\s+/', ' ', $texte) ?? $texte;
}

/**
 * Calcule un score de correspondance 0-100 entre un profil de recherche
 * (opportunites_decoder_profil()) et une offre (ligne `opportunites`
 * décodée : competences_requises en tableau, pas en JSON texte).
 * Fonction pure, déterministe et explicable — aucun accès base, aucun
 * appel réseau, entièrement testable isolément.
 *
 * @return array{score:int, details:array<string,array{poids:int,obtenu:int,sur:int,label:string}>}
 */
function calculer_score_match(array $profil, array $offre, array $poids): array
{
    $details = [];

    // Type recherché
    $poidsType = $poids['type'] ?? 0;
    $types = $profil['types'] ?? [];
    $obtenuType = empty($types) || in_array($offre['type'] ?? '', $types, true) ? $poidsType : 0;
    $details['type'] = ['poids' => $poidsType, 'obtenu' => $obtenuType, 'sur' => $poidsType, 'label' => 'Type de contrat recherché'];

    // Ville / mobilité / télétravail
    $poidsVille = $poids['ville'] ?? 0;
    $villes = array_map('opportunites_normaliser_texte', $profil['villes'] ?? []);
    $villeOffre = opportunites_normaliser_texte((string) ($offre['ville'] ?? ''));
    $teletravailCompatible = ($offre['teletravail'] ?? 'non') !== 'non' && ($profil['teletravail'] ?? 'non') !== 'non';
    if (empty($villes) || $villeOffre === '' || in_array($villeOffre, $villes, true)) {
        $obtenuVille = $poidsVille;
    } elseif ($teletravailCompatible) {
        $obtenuVille = (int) round($poidsVille * 0.75);
    } else {
        $obtenuVille = 0;
    }
    $details['ville'] = ['poids' => $poidsVille, 'obtenu' => $obtenuVille, 'sur' => $poidsVille, 'label' => 'Ville / télétravail'];

    // Niveau d'études
    $poidsNiveau = $poids['niveau_etudes'] ?? 0;
    $rangRequis = opportunites_rang_niveau_etudes($offre['niveau_etudes_min'] ?? null);
    $rangProfil = opportunites_rang_niveau_etudes($profil['niveau_etudes'] ?? null);
    if ($rangRequis === 0 || $rangProfil >= $rangRequis) {
        $obtenuNiveau = $poidsNiveau;
    } elseif ($rangProfil === $rangRequis - 1) {
        $obtenuNiveau = (int) round($poidsNiveau * 0.5);
    } else {
        $obtenuNiveau = 0;
    }
    $details['niveau_etudes'] = ['poids' => $poidsNiveau, 'obtenu' => $obtenuNiveau, 'sur' => $poidsNiveau, 'label' => "Niveau d'études"];

    // Expérience
    $poidsExperience = $poids['experience'] ?? 0;
    $requis = $offre['experience_min_annees'] !== null ? (int) $offre['experience_min_annees'] : null;
    $possede = $profil['annees_experience'] ?? 0;
    if ($requis === null || $possede >= $requis) {
        $obtenuExperience = $poidsExperience;
    } elseif ($possede >= $requis - 1) {
        $obtenuExperience = (int) round($poidsExperience * 0.5);
    } else {
        $obtenuExperience = 0;
    }
    $details['experience'] = ['poids' => $poidsExperience, 'obtenu' => $obtenuExperience, 'sur' => $poidsExperience, 'label' => "Années d'expérience"];

    // Compétences (recouvrement)
    $poidsCompetences = $poids['competences'] ?? 0;
    $competencesProfil = array_map('opportunites_normaliser_texte', $profil['competences'] ?? []);
    $competencesRequises = array_map('opportunites_normaliser_texte', $offre['competences_requises'] ?? []);
    if (empty($competencesRequises)) {
        $obtenuCompetences = $poidsCompetences;
    } else {
        $recoupement = count(array_intersect($competencesProfil, $competencesRequises));
        $obtenuCompetences = (int) round($poidsCompetences * min(1, $recoupement / count($competencesRequises)));
    }
    $details['competences'] = ['poids' => $poidsCompetences, 'obtenu' => $obtenuCompetences, 'sur' => $poidsCompetences, 'label' => 'Compétences'];

    // Intitulé de poste (correspondance textuelle simple)
    $poidsIntitule = $poids['intitule'] ?? 0;
    $metiers = array_map('opportunites_normaliser_texte', $profil['metiers'] ?? []);
    $titreOffre = opportunites_normaliser_texte((string) ($offre['titre'] ?? ''));
    $correspondIntitule = empty($metiers);
    foreach ($metiers as $metier) {
        if ($metier !== '' && (str_contains($titreOffre, $metier) || str_contains($metier, $titreOffre))) {
            $correspondIntitule = true;
            break;
        }
    }
    $obtenuIntitule = $correspondIntitule ? $poidsIntitule : 0;
    $details['intitule'] = ['poids' => $poidsIntitule, 'obtenu' => $obtenuIntitule, 'sur' => $poidsIntitule, 'label' => 'Intitulé de poste'];

    $score = array_sum(array_column($details, 'obtenu'));
    return ['score' => max(0, min(100, $score)), 'details' => $details];
}

/**
 * Explication en une phrase générée par l'IA (optionnelle, jamais appelée
 * en masse — un bouton dédié par offre côté client). Ne lève jamais
 * d'exception : retourne null si l'IA n'est pas configurée ou échoue.
 */
function opportunites_explication_ia(array $profil, array $offre, int $score): ?string
{
    if (!anthropic_configured()) {
        return null;
    }
    try {
        $config = opportunites_ia_config()['anthropic'];
        $systeme = "Tu es un conseiller NexRaiz. En UNE SEULE phrase courte et chaleureuse, explique au candidat pourquoi cette offre "
            . "lui correspond (score {$score}/100), à partir UNIQUEMENT des informations fournies. Ne mentionne jamais d'information absente des données. Réponds en texte brut, sans guillemets.";
        $utilisateur = "Profil : " . json_encode($profil, JSON_UNESCAPED_UNICODE) . "\nOffre : " . json_encode($offre, JSON_UNESCAPED_UNICODE);
        $reponse = anthropic_chat_completion($config['api_key'], $config['model'], $systeme, $utilisateur);
        $texte = trim(anthropic_extraire_texte($reponse));
        return $texte !== '' ? $texte : null;
    } catch (\Throwable $e) {
        error_log('[opportunites] Explication IA indisponible : ' . $e->getMessage());
        return null;
    }
}

// ===========================================================================
// Lecture des offres / recommandations
// ===========================================================================

function opportunites_decoder_offre(array $ligne): array
{
    $ligne['competences_requises'] = json_decode($ligne['competences_requises_json'] ?? '[]', true) ?: [];
    return $ligne;
}

function opportunites_est_expiree(array $offre): bool
{
    return !empty($offre['date_limite']) && $offre['date_limite'] < date('Y-m-d');
}

/**
 * @return array<int,array{offre:array,score:int,details:array}> Offres
 * publiées, non expirées, triées par score décroissant, filtrées au seuil
 * configuré (ou $seuilOverride).
 */
function opportunites_recommandees_pour_dossier(int $dossierId, ?int $seuilOverride = null): array
{
    $profil = opportunites_profil_dossier($dossierId);
    if (!opportunites_profil_actif($profil)) {
        return [];
    }
    $seuil = $seuilOverride ?? opportunites_config()['seuil_score_minimum'];
    $poids = opportunites_config()['poids_score'];

    $stmt = db()->query("SELECT * FROM opportunites WHERE statut = 'publie' AND (date_limite IS NULL OR date_limite >= CURDATE())");
    $resultats = [];
    foreach ($stmt->fetchAll() as $ligneOffre) {
        $offre = opportunites_decoder_offre($ligneOffre);
        $calcul = calculer_score_match($profil, $offre, $poids);
        if ($calcul['score'] >= $seuil) {
            $resultats[] = ['offre' => $offre, 'score' => $calcul['score'], 'details' => $calcul['details']];
        }
    }
    usort($resultats, fn($a, $b) => $b['score'] <=> $a['score']);
    return $resultats;
}

/** Substitue {mots_cles}/{ville} dans les gabarits configurés et ne retient que les URL valides. */
function opportunites_valider_url(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function opportunites_liens_recherche(array $motsCles, ?string $ville): array
{
    $motsClesTexte = urlencode(implode(' ', array_slice($motsCles, 0, 6)));
    $villeTexte = urlencode($ville ?? '');
    $liens = [];
    foreach (opportunites_config()['liens_recherche'] as $cle => $site) {
        $url = str_replace(['{mots_cles}', '{ville}'], [$motsClesTexte, $villeTexte], $site['url']);
        if (opportunites_valider_url($url)) {
            $liens[$cle] = ['label' => $site['label'], 'url' => $url];
        }
    }
    return $liens;
}

/**
 * Libellé court du niveau de correspondance, affiché à côté du score (donut)
 * dans le panneau de détail d'une offre.
 */
function opportunites_libelle_score(int $score): string
{
    if ($score >= 70) {
        return 'Très bonne correspondance';
    }
    if ($score >= 50) {
        return 'Bonne correspondance';
    }
    return 'Correspondance partielle';
}

/**
 * Initiales (1 ou 2 lettres) pour le badge avatar d'une offre dans la liste
 * et le panneau de détail — basées sur l'entreprise, ou "NX" si elle n'est
 * pas communiquée (recrutement confidentiel).
 */
function opportunites_initiales_entreprise(?string $entreprise): string
{
    $entreprise = trim((string) $entreprise);
    if ($entreprise === '') {
        return 'NX';
    }
    $mots = preg_split('/\s+/', $entreprise, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initiales = '';
    foreach (array_slice($mots, 0, 2) as $mot) {
        $initiales .= mb_substr($mot, 0, 1);
    }
    return mb_strtoupper($initiales !== '' ? $initiales : mb_substr($entreprise, 0, 2));
}

/**
 * « Publiée aujourd'hui » / « Publiée il y a Xj », pour l'en-tête du panneau
 * de détail d'une offre (jamais d'heure précise, non pertinente ici).
 */
function opportunites_publication_relative(string $createdAt): string
{
    $jours = (int) floor((time() - strtotime($createdAt)) / 86400);
    if ($jours <= 0) {
        return "Publiée aujourd'hui";
    }
    if ($jours === 1) {
        return 'Publiée il y a 1 jour';
    }
    return "Publiée il y a {$jours} jours";
}

// ===========================================================================
// Interactions client (enregistrer / masquer / suivi de candidature)
// ===========================================================================

function opportunites_interaction(int $opportuniteId, int $dossierId): ?array
{
    $stmt = db()->prepare('SELECT * FROM opportunites_candidatures WHERE opportunite_id = ? AND dossier_id = ?');
    $stmt->execute([$opportuniteId, $dossierId]);
    return $stmt->fetch() ?: null;
}

function opportunites_assurer_ligne(int $opportuniteId, int $dossierId): void
{
    $stmt = db()->prepare('INSERT INTO opportunites_candidatures (opportunite_id, dossier_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE opportunite_id = opportunite_id');
    $stmt->execute([$opportuniteId, $dossierId]);
}

function opportunites_enregistrer(int $opportuniteId, int $dossierId): void
{
    opportunites_assurer_ligne($opportuniteId, $dossierId);
    $stmt = db()->prepare('UPDATE opportunites_candidatures SET enregistre = 1, enregistre_le = NOW() WHERE opportunite_id = ? AND dossier_id = ?');
    $stmt->execute([$opportuniteId, $dossierId]);
}

function opportunites_masquer(int $opportuniteId, int $dossierId, string $motif = ''): void
{
    opportunites_assurer_ligne($opportuniteId, $dossierId);
    $stmt = db()->prepare('UPDATE opportunites_candidatures SET masquee = 1, masquee_motif = ?, masquee_le = NOW() WHERE opportunite_id = ? AND dossier_id = ?');
    $stmt->execute([$motif !== '' ? $motif : null, $opportuniteId, $dossierId]);
}

function opportunites_demasquer(int $opportuniteId, int $dossierId): void
{
    $stmt = db()->prepare('UPDATE opportunites_candidatures SET masquee = 0, masquee_motif = NULL, masquee_le = NULL WHERE opportunite_id = ? AND dossier_id = ?');
    $stmt->execute([$opportuniteId, $dossierId]);
}

function opportunites_mettre_a_jour_statut(int $opportuniteId, int $dossierId, string $statut, ?string $date = null, ?string $notes = null): void
{
    $statutsValides = ['non_postule', 'postule', 'entretien', 'refus', 'acceptee'];
    if (!in_array($statut, $statutsValides, true)) {
        return;
    }
    opportunites_assurer_ligne($opportuniteId, $dossierId);
    $enregistre = $statut !== 'non_postule' ? 1 : null; // postuler implique de considérer l'offre comme suivie
    $stmt = db()->prepare(
        'UPDATE opportunites_candidatures
         SET statut = ?, statut_le = ?, notes = ?' . ($enregistre !== null ? ', enregistre = 1' : '') . '
         WHERE opportunite_id = ? AND dossier_id = ?'
    );
    $stmt->execute([$statut, $date ?: date('Y-m-d'), $notes, $opportuniteId, $dossierId]);
}

/**
 * « Postuler via NexRaiz » : envoie réellement la candidature (CV en pièce
 * jointe, lettre de motivation en corps de message) à l'adresse de
 * candidature de l'offre, avec le Reply-To réglé sur l'e-mail réel du
 * client (jamais usurpé en "From" — voir includes/mailer.php). N'envoie
 * jamais sans un texte de lettre non vide : le client valide toujours le
 * contenu avant l'envoi (classic-mes-opportunites.php).
 *
 * @return array{ok:bool,erreur:string}
 */
function opportunites_candidater_via_nexraiz(int $opportuniteId, int $dossierId, string $texteLettre, array $client): array
{
    $texteLettre = trim($texteLettre);
    if ($texteLettre === '') {
        return ['ok' => false, 'erreur' => 'La lettre de motivation ne peut pas être vide.'];
    }

    $stmt = db()->prepare('SELECT * FROM opportunites WHERE id = ? AND statut = "publie"');
    $stmt->execute([$opportuniteId]);
    $offre = $stmt->fetch();
    if (!$offre || empty($offre['email_candidature'])) {
        return ['ok' => false, 'erreur' => "Cette offre n'a pas d'adresse de candidature par e-mail."];
    }

    $cv = opportunites_fichier_cv_dossier($dossierId);
    if (!$cv) {
        return ['ok' => false, 'erreur' => 'Déposez votre CV dans « Mes documents » avant de postuler.'];
    }

    $nomClient = trim(($client['prenom'] ?? '') . ' ' . ($client['nom'] ?? ''));
    $sujet = 'Candidature — ' . $offre['titre'] . ($nomClient !== '' ? ' — ' . $nomClient : '');
    $corps = $texteLettre . "\n\n—\nCandidature transmise via NexRaiz pour le compte de {$nomClient}."
        . (!empty($client['email']) ? " Répondre directement à {$client['email']}." : '');

    $resultat = nx_envoyer_candidature(
        $offre['email_candidature'],
        $sujet,
        $corps,
        [['chemin' => $cv['chemin'], 'nom' => $cv['nom']]],
        $client['email'] ?? '',
        $nomClient
    );

    if ($resultat['ok']) {
        opportunites_mettre_a_jour_statut($opportuniteId, $dossierId, 'postule', date('Y-m-d'), 'Candidature envoyée via NexRaiz le ' . date('d/m/Y à H:i') . '.');
        log_activite($dossierId, 'Candidature envoyée via NexRaiz : ' . $offre['titre']);
    }

    return ['ok' => $resultat['ok'], 'erreur' => $resultat['ok'] ? '' : "L'envoi a échoué (" . $resultat['erreur'] . '). Réessayez ou contactez votre consultant.'];
}

/** Offres suivies par le client (enregistrées et/ou avec une candidature en cours), pour la vue "Suivi" et la vue coach. */
function opportunites_candidatures_dossier(int $dossierId): array
{
    $stmt = db()->prepare(
        "SELECT oc.*, o.titre, o.entreprise, o.type, o.ville, o.date_limite, o.statut AS statut_offre
         FROM opportunites_candidatures oc
         JOIN opportunites o ON o.id = oc.opportunite_id
         WHERE oc.dossier_id = ? AND (oc.enregistre = 1 OR oc.statut != 'non_postule')
         ORDER BY oc.updated_at DESC"
    );
    $stmt->execute([$dossierId]);
    return $stmt->fetchAll();
}

// ===========================================================================
// CRUD admin (consultant/admin)
// ===========================================================================

const OPPORTUNITES_TYPES = ['emploi', 'stage', 'alternance', 'mission'];
const OPPORTUNITES_TELETRAVAIL = ['non', 'partiel', 'total'];
const OPPORTUNITES_STATUTS = ['brouillon', 'publie', 'expire', 'depublie'];

function opportunites_admin_liste(array $filtres = []): array
{
    $ou = [];
    $params = [];
    if (!empty($filtres['statut']) && in_array($filtres['statut'], OPPORTUNITES_STATUTS, true)) {
        $ou[] = 'statut = ?';
        $params[] = $filtres['statut'];
    }
    if (!empty($filtres['type']) && in_array($filtres['type'], OPPORTUNITES_TYPES, true)) {
        $ou[] = 'type = ?';
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['ville'])) {
        $ou[] = 'ville LIKE ?';
        $params[] = '%' . $filtres['ville'] . '%';
    }
    $sql = 'SELECT * FROM opportunites' . ($ou ? ' WHERE ' . implode(' AND ', $ou) : '') . ' ORDER BY created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('opportunites_decoder_offre', $stmt->fetchAll());
}

function opportunites_admin_valider_champs(array $d): array
{
    $erreurs = [];
    if (trim((string) ($d['titre'] ?? '')) === '') {
        $erreurs[] = 'Le titre est obligatoire.';
    }
    if (!in_array($d['type'] ?? '', OPPORTUNITES_TYPES, true)) {
        $erreurs[] = 'Le type de contrat est invalide.';
    }
    if (trim((string) ($d['description'] ?? '')) === '') {
        $erreurs[] = 'La description est obligatoire.';
    }
    if (!empty($d['lien_candidature']) && !opportunites_valider_url($d['lien_candidature'])) {
        $erreurs[] = 'Le lien de candidature n\'est pas une URL valide.';
    }
    if (!empty($d['email_candidature']) && !filter_var($d['email_candidature'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'L\'e-mail de candidature n\'est pas valide.';
    }
    return $erreurs;
}

function opportunites_admin_creer(array $d, int $creeParUserId): array
{
    $erreurs = opportunites_admin_valider_champs($d);
    if (!empty($erreurs)) {
        return ['erreurs' => $erreurs, 'id' => null];
    }
    $competences = array_values(array_filter(array_map('trim', (array) ($d['competences_requises'] ?? []))));
    $stmt = db()->prepare(
        'INSERT INTO opportunites
            (source, titre, type, entreprise, secteur, ville, pays, teletravail, niveau_etudes_min, experience_min_annees, competences_requises_json, description, lien_candidature, email_candidature, salaire_indicatif, date_limite, statut, created_by)
         VALUES ("admin", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "brouillon", ?)'
    );
    $stmt->execute([
        trim($d['titre']), $d['type'], $d['entreprise'] ?: null, $d['secteur'] ?: null, $d['ville'] ?: null, $d['pays'] ?: null,
        in_array($d['teletravail'] ?? '', OPPORTUNITES_TELETRAVAIL, true) ? $d['teletravail'] : 'non',
        $d['niveau_etudes_min'] ?: null, $d['experience_min_annees'] !== '' && isset($d['experience_min_annees']) ? (int) $d['experience_min_annees'] : null,
        json_encode($competences, JSON_UNESCAPED_UNICODE), trim($d['description']), $d['lien_candidature'] ?: null, $d['email_candidature'] ?: null,
        $d['salaire_indicatif'] ?: null, $d['date_limite'] ?: null, $creeParUserId,
    ]);
    return ['erreurs' => [], 'id' => (int) db()->lastInsertId()];
}

function opportunites_admin_modifier(int $id, array $d): array
{
    $erreurs = opportunites_admin_valider_champs($d);
    if (!empty($erreurs)) {
        return $erreurs;
    }
    $competences = array_values(array_filter(array_map('trim', (array) ($d['competences_requises'] ?? []))));
    $stmt = db()->prepare(
        'UPDATE opportunites SET titre=?, type=?, entreprise=?, secteur=?, ville=?, pays=?, teletravail=?, niveau_etudes_min=?, experience_min_annees=?, competences_requises_json=?, description=?, lien_candidature=?, email_candidature=?, salaire_indicatif=?, date_limite=?
         WHERE id = ? AND source = "admin"'
    );
    $stmt->execute([
        trim($d['titre']), $d['type'], $d['entreprise'] ?: null, $d['secteur'] ?: null, $d['ville'] ?: null, $d['pays'] ?: null,
        in_array($d['teletravail'] ?? '', OPPORTUNITES_TELETRAVAIL, true) ? $d['teletravail'] : 'non',
        $d['niveau_etudes_min'] ?: null, $d['experience_min_annees'] !== '' && isset($d['experience_min_annees']) ? (int) $d['experience_min_annees'] : null,
        json_encode($competences, JSON_UNESCAPED_UNICODE), trim($d['description']), $d['lien_candidature'] ?: null, $d['email_candidature'] ?: null,
        $d['salaire_indicatif'] ?: null, $d['date_limite'] ?: null, $id,
    ]);
    return [];
}

function opportunites_admin_dupliquer(int $id): ?int
{
    $stmt = db()->prepare('SELECT * FROM opportunites WHERE id = ?');
    $stmt->execute([$id]);
    $o = $stmt->fetch();
    if (!$o) {
        return null;
    }
    $stmt = db()->prepare(
        'INSERT INTO opportunites (source, titre, type, entreprise, secteur, ville, pays, teletravail, niveau_etudes_min, experience_min_annees, competences_requises_json, description, lien_candidature, email_candidature, salaire_indicatif, date_limite, statut, created_by)
         VALUES ("admin", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "brouillon", ?)'
    );
    $stmt->execute([
        $o['titre'] . ' (copie)', $o['type'], $o['entreprise'], $o['secteur'], $o['ville'], $o['pays'], $o['teletravail'],
        $o['niveau_etudes_min'], $o['experience_min_annees'], $o['competences_requises_json'], $o['description'], $o['lien_candidature'],
        $o['email_candidature'], $o['salaire_indicatif'], $o['date_limite'], $o['created_by'],
    ]);
    return (int) db()->lastInsertId();
}

function opportunites_admin_publier(int $id): void
{
    db()->prepare('UPDATE opportunites SET statut = "publie" WHERE id = ?')->execute([$id]);
}

function opportunites_admin_depublier(int $id): void
{
    db()->prepare('UPDATE opportunites SET statut = "depublie" WHERE id = ?')->execute([$id]);
}

/** @return int Nombre d'offres passées en statut "expire". */
function opportunites_expirer_echues(): int
{
    $stmt = db()->prepare("UPDATE opportunites SET statut = 'expire' WHERE statut = 'publie' AND date_limite IS NOT NULL AND date_limite < CURDATE()");
    $stmt->execute();
    return $stmt->rowCount();
}

// ===========================================================================
// Vue consultant/coach (même esprit que classic_dossiers_apercu())
// ===========================================================================

/** @return array<int,array> Un élément par dossier Classic avec profil, avec ses compteurs d'opportunités/candidatures. */
function classic_opportunites_apercu(int $packId): array
{
    $stmt = db()->prepare(
        "SELECT d.id AS dossier_id, d.numero, u.nom, u.prenom, u.email, d.statut AS statut_dossier
         FROM dossiers d
         JOIN users u ON u.id = d.user_id
         JOIN classic_profils_recherche p ON p.dossier_id = d.id
         WHERE d.pack_id = ? AND p.consent_matching = 1 AND p.cv_document_id IS NOT NULL
         ORDER BY d.updated_at DESC"
    );
    $stmt->execute([$packId]);
    $lignes = $stmt->fetchAll();

    $resultat = [];
    foreach ($lignes as $ligne) {
        $recommandees = opportunites_recommandees_pour_dossier((int) $ligne['dossier_id']);
        $candidatures = opportunites_candidatures_dossier((int) $ligne['dossier_id']);
        $resultat[] = $ligne + [
            'nb_recommandees' => count($recommandees),
            'nb_enregistrees' => count(array_filter($candidatures, fn($c) => (bool) $c['enregistre'])),
            'nb_postulees' => count(array_filter($candidatures, fn($c) => $c['statut'] !== 'non_postule')),
            'candidatures' => $candidatures,
        ];
    }
    return $resultat;
}

// ===========================================================================
// Notifications (cron + publication manuelle)
// ===========================================================================

const OPPORTUNITES_DISCLAIMER = "NexRaiz ne garantit pas l'obtention d'un emploi. Aucun recruteur ne doit vous demander de paiement : signalez-nous toute demande suspecte.";

/** @return int Nombre de notifications envoyées. */
function opportunites_notifier_nouvelles_offres(int $seuilNotif): int
{
    $stmt = db()->query("SELECT * FROM opportunites WHERE statut = 'publie' AND (date_limite IS NULL OR date_limite >= CURDATE())");
    $offres = array_map('opportunites_decoder_offre', $stmt->fetchAll());
    if (empty($offres)) {
        return 0;
    }

    $stmt = db()->query(
        "SELECT p.*, d.id AS dossier_id, u.id AS user_id, u.email
         FROM classic_profils_recherche p
         JOIN dossiers d ON d.id = p.dossier_id
         JOIN users u ON u.id = d.user_id
         WHERE p.consent_matching = 1 AND p.cv_document_id IS NOT NULL AND d.statut != 'cloture'"
    );
    $poids = opportunites_config()['poids_score'];
    $envoyes = 0;

    foreach ($stmt->fetchAll() as $ligneProfil) {
        $profil = opportunites_decoder_profil($ligneProfil);
        foreach ($offres as $offre) {
            $score = calculer_score_match($profil, $offre, $poids)['score'];
            if ($score < $seuilNotif) {
                continue;
            }
            $dejaNotifie = db()->prepare('SELECT 1 FROM opportunites_notifications_envoyees WHERE opportunite_id = ? AND dossier_id = ? AND type = "nouvelle_offre"');
            $dejaNotifie->execute([$offre['id'], $ligneProfil['dossier_id']]);
            if ($dejaNotifie->fetch()) {
                continue;
            }
            notify(
                (int) $ligneProfil['user_id'],
                'opportunite',
                'Une offre correspond à votre profil',
                htmlspecialchars($offre['titre']) . ' (' . $score . '/100)',
                'classic-mes-opportunites.php'
            );
            if (!empty($ligneProfil['email'])) {
                nx_send_mail($ligneProfil['email'], 'Nouvelle opportunité NexRaiz', '<p>Une offre correspond à votre profil : <strong>' . htmlspecialchars($offre['titre']) . '</strong> (score ' . $score . '/100).</p><p>' . OPPORTUNITES_DISCLAIMER . '</p>');
            }
            db()->prepare('INSERT INTO opportunites_notifications_envoyees (opportunite_id, dossier_id, type) VALUES (?, ?, "nouvelle_offre")')->execute([$offre['id'], $ligneProfil['dossier_id']]);
            $envoyes++;
        }
    }
    return $envoyes;
}

/** @return int Nombre de rappels envoyés. */
function opportunites_notifier_echeances_proches(int $joursAvant = 3): int
{
    $stmt = db()->prepare(
        "SELECT oc.opportunite_id, oc.dossier_id, o.titre, o.date_limite, u.id AS user_id, u.email
         FROM opportunites_candidatures oc
         JOIN opportunites o ON o.id = oc.opportunite_id
         JOIN dossiers d ON d.id = oc.dossier_id
         JOIN users u ON u.id = d.user_id
         WHERE oc.enregistre = 1 AND o.date_limite = DATE_ADD(CURDATE(), INTERVAL ? DAY)"
    );
    $stmt->execute([$joursAvant]);
    $envoyes = 0;
    foreach ($stmt->fetchAll() as $ligne) {
        $dejaNotifie = db()->prepare('SELECT 1 FROM opportunites_notifications_envoyees WHERE opportunite_id = ? AND dossier_id = ? AND type = "rappel_echeance"');
        $dejaNotifie->execute([$ligne['opportunite_id'], $ligne['dossier_id']]);
        if ($dejaNotifie->fetch()) {
            continue;
        }
        notify(
            (int) $ligne['user_id'],
            'opportunite_rappel',
            "Une offre que vous avez enregistrée arrive à échéance",
            htmlspecialchars($ligne['titre']) . ' — date limite dans ' . $joursAvant . ' jours',
            'classic-mes-opportunites.php?vue=suivi'
        );
        if (!empty($ligne['email'])) {
            nx_send_mail($ligne['email'], 'Rappel : échéance proche', '<p>L\'offre <strong>' . htmlspecialchars($ligne['titre']) . '</strong> arrive à échéance dans ' . $joursAvant . ' jours.</p>');
        }
        db()->prepare('INSERT INTO opportunites_notifications_envoyees (opportunite_id, dossier_id, type) VALUES (?, ?, "rappel_echeance")')->execute([$ligne['opportunite_id'], $ligne['dossier_id']]);
        $envoyes++;
    }
    return $envoyes;
}

// ===========================================================================
// Connecteur d'offres externes (optionnel)
// ===========================================================================

function jobaggregator_configured(): bool
{
    $config = opportunites_config()['job_api'];
    return $config['enabled'] && strpos($config['api_key'], 'REPLACE_ME') === false;
}

/** @return array{nouvelles:int,ignorees_doublons:int} */
function job_api_importer_executer(): array
{
    require_once __DIR__ . '/opportunites-connecteurs.php';
    if (!jobaggregator_configured()) {
        return ['nouvelles' => 0, 'ignorees_doublons' => 0];
    }
    $config = opportunites_config()['job_api'];
    $registre = opportunites_connecteurs_disponibles();
    $fonction = $registre[$config['provider']] ?? null;
    if (!$fonction || !function_exists($fonction)) {
        error_log('[opportunites] Connecteur inconnu : ' . $config['provider']);
        return ['nouvelles' => 0, 'ignorees_doublons' => 0];
    }

    try {
        $offresImportees = call_user_func($fonction, $config);
    } catch (\Throwable $e) {
        error_log('[opportunites] Import connecteur échoué : ' . $e->getMessage());
        return ['nouvelles' => 0, 'ignorees_doublons' => 0];
    }

    $nouvelles = 0;
    $doublons = 0;
    $stmtExiste = db()->prepare('SELECT 1 FROM opportunites WHERE source = "api_partner" AND source_reference = ?');
    $stmtInsere = db()->prepare(
        'INSERT INTO opportunites (source, titre, type, entreprise, ville, description, lien_candidature, date_limite, statut, source_reference)
         VALUES ("api_partner", ?, ?, ?, ?, ?, ?, ?, "brouillon", ?)'
    );
    foreach ($offresImportees as $offre) {
        $stmtExiste->execute([$offre['source_reference']]);
        if ($stmtExiste->fetch()) {
            $doublons++;
            continue;
        }
        $stmtInsere->execute([
            $offre['titre'], $offre['type'], $offre['entreprise'], $offre['ville'],
            $offre['description'], $offre['lien_candidature'], $offre['date_limite'], $offre['source_reference'],
        ]);
        $nouvelles++;
    }
    return ['nouvelles' => $nouvelles, 'ignorees_doublons' => $doublons];
}
