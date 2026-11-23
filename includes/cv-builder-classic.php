<?php
/**
 * NexRaiz — CV Builder structuré par étapes (Pack Classic). Troisième outil
 * d'édition du CV pour le consultant, strictement additif à l'atelier/Studio
 * (table `corrections`, texte libre) et à l'éditeur Canvas (table
 * `canvas_editions`, patch visuel du PDF original) : aucune des deux n'est
 * lue ni modifiée ici. Le CV est construit à partir de champs typés
 * (expériences/formations datées, compétences/langues) plutôt que d'un bloc
 * de texte, pour un rendu PDF cohérent avec un modèle choisi à l'avance
 * (avec/sans photo + couleur d'accent).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/documents-classic.php';

/** Palette de couleurs fixe — jamais une valeur hex libre saisie par le consultant. */
function cv_builder_palette_couleurs(): array
{
    return [
        'navy'     => ['label' => 'Marine', 'hex' => '#13203F'],
        'blue'     => ['label' => 'Bleu NexRaiz', 'hex' => '#2563EB'],
        'gold'     => ['label' => 'Doré', 'hex' => '#8A6D00'],
        'graphite' => ['label' => 'Graphite', 'hex' => '#334155'],
        'emerald'  => ['label' => 'Émeraude', 'hex' => '#0F766E'],
    ];
}

/** Mises en page disponibles — jamais plus que ces deux valeurs stockées en base. */
function cv_builder_layouts(): array
{
    return [
        'avec_photo' => 'Avec photo',
        'sans_photo' => 'Sans photo',
    ];
}

function cv_builder_decoder(array $ligne): array
{
    $ligne['competences'] = json_decode($ligne['competences_json'] ?? '[]', true) ?: [];
    $ligne['langues'] = json_decode($ligne['langues_json'] ?? '[]', true) ?: [];
    return $ligne;
}

function classic_cv_builder_recuperer(int $dossierId): ?array
{
    $stmt = db()->prepare('SELECT * FROM classic_cv_builder WHERE dossier_id = ?');
    $stmt->execute([$dossierId]);
    $ligne = $stmt->fetch();
    return $ligne ? cv_builder_decoder($ligne) : null;
}

/** Crée la ligne si besoin et pose le modèle choisi — passage obligatoire avant la première étape. */
function classic_cv_builder_definir_template(int $dossierId, string $layout, string $couleur): array
{
    $erreurs = [];
    if (!array_key_exists($layout, cv_builder_layouts())) {
        $erreurs[] = 'Mise en page invalide.';
    }
    if (!array_key_exists($couleur, cv_builder_palette_couleurs())) {
        $erreurs[] = 'Couleur invalide.';
    }
    if (!empty($erreurs)) {
        return $erreurs;
    }

    db()->prepare(
        'INSERT INTO classic_cv_builder (dossier_id, layout, couleur_accent) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE layout = VALUES(layout), couleur_accent = VALUES(couleur_accent)'
    )->execute([$dossierId, $layout, $couleur]);

    return [];
}

/** Dernière étape atteinte — ratchet avant uniquement, jamais lié à dossiers.current_step (onboarding). */
function classic_cv_builder_avancer_etape(int $dossierId, int $etape): void
{
    db()->prepare('UPDATE classic_cv_builder SET etape_courante = GREATEST(etape_courante, ?) WHERE dossier_id = ?')
        ->execute([$etape, $dossierId]);
}

function classic_cv_builder_sauver_infos_personnelles(int $dossierId, array $d): void
{
    db()->prepare(
        'UPDATE classic_cv_builder SET titre_poste = ?, ville = ?, telephone_affiche = ?, email_affiche = ?, resume_profil = ? WHERE dossier_id = ?'
    )->execute([
        trim($d['titre_poste'] ?? '') ?: null, trim($d['ville'] ?? '') ?: null,
        trim($d['telephone_affiche'] ?? '') ?: null, trim($d['email_affiche'] ?? '') ?: null,
        trim($d['resume_profil'] ?? '') ?: null, $dossierId,
    ]);
}

function classic_cv_builder_sauver_recherche(int $dossierId, array $d): void
{
    db()->prepare(
        'UPDATE classic_cv_builder SET poste_recherche = ?, type_contrat = ?, secteur_vise = ?, mobilite = ?, disponibilite = ? WHERE dossier_id = ?'
    )->execute([
        trim($d['poste_recherche'] ?? '') ?: null, trim($d['type_contrat'] ?? '') ?: null,
        trim($d['secteur_vise'] ?? '') ?: null, trim($d['mobilite'] ?? '') ?: null,
        trim($d['disponibilite'] ?? '') ?: null, $dossierId,
    ]);
}

function classic_cv_builder_sauver_competences_langues(int $dossierId, array $competences, array $langues): void
{
    $competences = array_values(array_filter(array_map('trim', $competences)));
    $langues = array_values(array_filter(array_map('trim', $langues)));
    db()->prepare('UPDATE classic_cv_builder SET competences_json = ?, langues_json = ? WHERE dossier_id = ?')
        ->execute([json_encode($competences, JSON_UNESCAPED_UNICODE), json_encode($langues, JSON_UNESCAPED_UNICODE), $dossierId]);
}

/**
 * Les champs <input type="month"> soumettent "AAAA-MM" — jamais une
 * valeur valide pour une colonne DATE (MySQL la tronquerait/rejetterait
 * selon le sql_mode, et strtotime() dessus produit des dates aberrantes).
 * Normalisé en "AAAA-MM-01" ; une vraie date "AAAA-MM-JJ" passe inchangée.
 */
function cv_builder_normaliser_date_mois(?string $valeur): ?string
{
    $valeur = trim((string) $valeur);
    if ($valeur === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}$/', $valeur)) {
        return $valeur . '-01';
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) ? $valeur : null;
}

function classic_cv_builder_photo_definir(int $dossierId, int $documentId): void
{
    db()->prepare('UPDATE classic_cv_builder SET photo_document_id = ? WHERE dossier_id = ?')->execute([$documentId, $dossierId]);
}

// --- Expériences (étape 3) ---------------------------------------------

function classic_cv_builder_experiences(int $dossierId): array
{
    $stmt = db()->prepare('SELECT * FROM classic_cv_builder_experiences WHERE dossier_id = ? ORDER BY ordre ASC, id ASC');
    $stmt->execute([$dossierId]);
    return $stmt->fetchAll();
}

function classic_cv_builder_ajouter_experience(int $dossierId, array $d): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM classic_cv_builder_experiences WHERE dossier_id = ?');
    $stmt->execute([$dossierId]);
    $ordre = (int) $stmt->fetchColumn();

    $posteActuel = !empty($d['poste_actuel']);
    db()->prepare(
        'INSERT INTO classic_cv_builder_experiences (dossier_id, poste, entreprise, lieu, date_debut, date_fin, poste_actuel, description, ordre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $dossierId, trim($d['poste'] ?? ''), trim($d['entreprise'] ?? '') ?: null, trim($d['lieu'] ?? '') ?: null,
        cv_builder_normaliser_date_mois($d['date_debut'] ?? null), $posteActuel ? null : cv_builder_normaliser_date_mois($d['date_fin'] ?? null), $posteActuel ? 1 : 0,
        trim($d['description'] ?? '') ?: null, $ordre,
    ]);
    return (int) db()->lastInsertId();
}

function classic_cv_builder_modifier_experience(int $id, int $dossierId, array $d): void
{
    $posteActuel = !empty($d['poste_actuel']);
    db()->prepare(
        'UPDATE classic_cv_builder_experiences SET poste = ?, entreprise = ?, lieu = ?, date_debut = ?, date_fin = ?, poste_actuel = ?, description = ?
         WHERE id = ? AND dossier_id = ?'
    )->execute([
        trim($d['poste'] ?? ''), trim($d['entreprise'] ?? '') ?: null, trim($d['lieu'] ?? '') ?: null,
        cv_builder_normaliser_date_mois($d['date_debut'] ?? null), $posteActuel ? null : cv_builder_normaliser_date_mois($d['date_fin'] ?? null), $posteActuel ? 1 : 0,
        trim($d['description'] ?? '') ?: null, $id, $dossierId,
    ]);
}

function classic_cv_builder_supprimer_experience(int $id, int $dossierId): void
{
    db()->prepare('DELETE FROM classic_cv_builder_experiences WHERE id = ? AND dossier_id = ?')->execute([$id, $dossierId]);
}

// --- Formations (étape 4) -----------------------------------------------

function classic_cv_builder_formations(int $dossierId): array
{
    $stmt = db()->prepare('SELECT * FROM classic_cv_builder_formations WHERE dossier_id = ? ORDER BY ordre ASC, id ASC');
    $stmt->execute([$dossierId]);
    return $stmt->fetchAll();
}

function classic_cv_builder_ajouter_formation(int $dossierId, array $d): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM classic_cv_builder_formations WHERE dossier_id = ?');
    $stmt->execute([$dossierId]);
    $ordre = (int) $stmt->fetchColumn();

    db()->prepare(
        'INSERT INTO classic_cv_builder_formations (dossier_id, diplome, etablissement, lieu, date_debut, date_fin, description, ordre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $dossierId, trim($d['diplome'] ?? ''), trim($d['etablissement'] ?? '') ?: null, trim($d['lieu'] ?? '') ?: null,
        cv_builder_normaliser_date_mois($d['date_debut'] ?? null), cv_builder_normaliser_date_mois($d['date_fin'] ?? null), trim($d['description'] ?? '') ?: null, $ordre,
    ]);
    return (int) db()->lastInsertId();
}

function classic_cv_builder_modifier_formation(int $id, int $dossierId, array $d): void
{
    db()->prepare(
        'UPDATE classic_cv_builder_formations SET diplome = ?, etablissement = ?, lieu = ?, date_debut = ?, date_fin = ?, description = ?
         WHERE id = ? AND dossier_id = ?'
    )->execute([
        trim($d['diplome'] ?? ''), trim($d['etablissement'] ?? '') ?: null, trim($d['lieu'] ?? '') ?: null,
        cv_builder_normaliser_date_mois($d['date_debut'] ?? null), cv_builder_normaliser_date_mois($d['date_fin'] ?? null), trim($d['description'] ?? '') ?: null, $id, $dossierId,
    ]);
}

function classic_cv_builder_supprimer_formation(int $id, int $dossierId): void
{
    db()->prepare('DELETE FROM classic_cv_builder_formations WHERE id = ? AND dossier_id = ?')->execute([$id, $dossierId]);
}

/**
 * Calculé à la volée, jamais stocké (même principe que le reste du projet :
 * ne jamais garder en cache un nombre dérivable, pour ne jamais l'afficher
 * périmé). Chaque étape ne compte que si elle a un vrai contenu saisi.
 */
function classic_cv_builder_pourcentage_completion(int $dossierId): int
{
    $cv = classic_cv_builder_recuperer($dossierId);
    if (!$cv) {
        return 0;
    }
    $etapes = [
        trim((string) ($cv['titre_poste'] ?? '')) !== '' || trim((string) ($cv['ville'] ?? '')) !== '',
        trim((string) ($cv['poste_recherche'] ?? '')) !== '',
        count(classic_cv_builder_experiences($dossierId)) > 0,
        count(classic_cv_builder_formations($dossierId)) > 0,
        !empty($cv['competences']) || !empty($cv['langues']),
        $cv['layout'] !== null,
    ];
    return (int) round((count(array_filter($etapes)) / count($etapes)) * 100);
}

/**
 * Rendu HTML partagé (lecture seule) des étapes 1-5 — utilisé à l'identique
 * par le récapitulatif de l'étape 6 côté consultant ET par la page candidat
 * en lecture seule, pour ne jamais faire diverger ce que voient les deux.
 */
function cv_builder_rendre_recap_html(array $cv, array $experiences, array $formations): string
{
    $palette = cv_builder_palette_couleurs();
    $layouts = cv_builder_layouts();
    $html = '';

    $html .= '<div class="flex flex-col gap-space-xs bg-surface-container-low rounded-xl p-space-md">';
    $html .= '<p class="font-label-md text-label-md text-on-surface font-bold">Modèle choisi</p>';
    $html .= '<p class="font-body-sm text-body-sm text-secondary">' . htmlspecialchars($layouts[$cv['layout']] ?? 'Non choisi') . ($cv['couleur_accent'] ? ' · ' . htmlspecialchars($palette[$cv['couleur_accent']]['label'] ?? '') : '') . '</p>';
    $html .= '</div>';

    $html .= '<div class="flex flex-col gap-space-xs"><p class="font-label-md text-label-md text-on-surface font-bold">Infos personnelles</p>';
    $html .= '<p class="font-body-sm text-body-sm text-secondary">' . htmlspecialchars($cv['titre_poste'] ?: 'Intitulé non renseigné') . ($cv['ville'] ? ' · ' . htmlspecialchars($cv['ville']) : '') . '</p>';
    if ($cv['resume_profil']) { $html .= '<p class="font-body-sm text-[13px] text-on-surface whitespace-pre-line">' . nl2br(htmlspecialchars($cv['resume_profil'])) . '</p>'; }
    $html .= '</div>';

    $html .= '<div class="flex flex-col gap-space-xs"><p class="font-label-md text-label-md text-on-surface font-bold">Ce que le candidat cherche</p>';
    $html .= '<p class="font-body-sm text-body-sm text-secondary">' . htmlspecialchars($cv['poste_recherche'] ?: 'Non renseigné') . ($cv['secteur_vise'] ? ' · ' . htmlspecialchars($cv['secteur_vise']) : '') . '</p></div>';

    $html .= '<div class="flex flex-col gap-space-xs"><p class="font-label-md text-label-md text-on-surface font-bold">Expériences</p>';
    if (empty($experiences)) { $html .= '<p class="font-body-sm text-[13px] text-secondary">Aucune expérience renseignée.</p>'; }
    foreach ($experiences as $e) {
        $periode = ($e['date_debut'] ? date('m/Y', strtotime($e['date_debut'])) : '?') . ' — ' . ($e['poste_actuel'] ? 'Présent' : ($e['date_fin'] ? date('m/Y', strtotime($e['date_fin'])) : '?'));
        $html .= '<div class="border-l-2 border-outline-variant/50 pl-space-sm"><p class="font-body-sm text-[13px] text-on-surface font-bold">' . htmlspecialchars($e['poste']) . '</p>';
        $html .= '<p class="font-body-sm text-[11px] text-secondary">' . htmlspecialchars($e['entreprise'] ?: '') . ($e['lieu'] ? ' · ' . htmlspecialchars($e['lieu']) : '') . ' · ' . htmlspecialchars($periode) . '</p>';
        if ($e['description']) { $html .= '<p class="font-body-sm text-[12px] text-on-surface whitespace-pre-line">' . nl2br(htmlspecialchars($e['description'])) . '</p>'; }
        $html .= '</div>';
    }
    $html .= '</div>';

    $html .= '<div class="flex flex-col gap-space-xs"><p class="font-label-md text-label-md text-on-surface font-bold">Formation</p>';
    if (empty($formations)) { $html .= '<p class="font-body-sm text-[13px] text-secondary">Aucune formation renseignée.</p>'; }
    foreach ($formations as $f) {
        $periode = ($f['date_debut'] ? date('m/Y', strtotime($f['date_debut'])) : '?') . ' — ' . ($f['date_fin'] ? date('m/Y', strtotime($f['date_fin'])) : '?');
        $html .= '<div class="border-l-2 border-outline-variant/50 pl-space-sm"><p class="font-body-sm text-[13px] text-on-surface font-bold">' . htmlspecialchars($f['diplome']) . '</p>';
        $html .= '<p class="font-body-sm text-[11px] text-secondary">' . htmlspecialchars($f['etablissement'] ?: '') . ($f['lieu'] ? ' · ' . htmlspecialchars($f['lieu']) : '') . ' · ' . htmlspecialchars($periode) . '</p></div>';
    }
    $html .= '</div>';

    $html .= '<div class="flex flex-col gap-space-xs"><p class="font-label-md text-label-md text-on-surface font-bold">Compétences et langues</p>';
    if (!empty($cv['competences'])) {
        $html .= '<div class="flex flex-wrap gap-1">';
        foreach ($cv['competences'] as $c) { $html .= '<span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full">' . htmlspecialchars($c) . '</span>'; }
        $html .= '</div>';
    }
    if (!empty($cv['langues'])) {
        $html .= '<div class="flex flex-wrap gap-1 mt-1">';
        foreach ($cv['langues'] as $l) { $html .= '<span class="font-body-sm text-[11px] text-primary bg-primary-container/10 border border-primary-container/30 px-space-xs py-0.5 rounded-full">' . htmlspecialchars($l) . '</span>'; }
        $html .= '</div>';
    }
    if (empty($cv['competences']) && empty($cv['langues'])) { $html .= '<p class="font-body-sm text-[13px] text-secondary">Rien de renseigné.</p>'; }
    $html .= '</div>';

    return $html;
}
