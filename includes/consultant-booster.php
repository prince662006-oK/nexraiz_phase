<?php
/**
 * Éléments partagés par les pages de l'espace consultant du Pack Booster.
 */

/**
 * Statut visuel d'un dossier Pack Booster, déduit de son avancement réel
 * (qualification du projet, plan d'action) — jamais stocké tel quel en base.
 */
function booster_statut_dossier(array $dossier): array
{
    if ($dossier['statut'] === 'cloture') {
        return ['label' => 'Clôturé', 'classe' => 'bg-green-100 text-green-700'];
    }
    if (!$dossier['a_qualification']) {
        return ['label' => 'Nouveau', 'classe' => 'bg-surface-container-high text-secondary'];
    }
    if ((int) $dossier['nb_actions'] === 0) {
        return ['label' => 'En cours', 'classe' => 'bg-blue-100 text-blue-700'];
    }

    return ['label' => 'En cours', 'classe' => 'bg-primary-container/10 text-primary'];
}

/**
 * Aperçu de tous les dossiers du Pack Booster, avec les indicateurs réels
 * (objectif et secteur extraits de la qualification, statut calculé).
 */
function booster_dossiers_apercu(int $packId, array $consultant): array
{
    $stmt = db()->prepare(
        'SELECT ds.id, ds.numero, ds.statut, ds.consultant_id, ds.created_at, ds.updated_at,
                u.nom, u.prenom,
                q.donnees_json,
                (q.dossier_id IS NOT NULL) AS a_qualification,
                (SELECT COUNT(*) FROM actions_plan ap WHERE ap.dossier_id = ds.id) AS nb_actions
         FROM dossiers ds
         JOIN users u ON u.id = ds.user_id
         LEFT JOIN qualifications q ON q.dossier_id = ds.id
         WHERE ds.pack_id = ?
         ORDER BY ds.updated_at DESC'
    );
    $stmt->execute([$packId]);
    $bruts = $stmt->fetchAll();

    return array_map(function ($d) use ($consultant) {
        $donnees = json_decode((string) $d['donnees_json'], true) ?: [];
        $statut = booster_statut_dossier($d);

        return [
            'id' => $d['id'],
            'numero' => $d['numero'],
            'initiales' => strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)),
            'client' => $d['prenom'] . ' ' . $d['nom'],
            'objectif' => $donnees['objectif_principal'] ?? 'Non renseigné',
            'secteur' => $donnees['secteur_activite'] ?? '—',
            'statut_label' => $statut['label'],
            'statut_classe' => $statut['classe'],
            'maj' => $d['updated_at'],
            'created_at' => $d['created_at'],
            'consultant_id' => $d['consultant_id'] ? (int) $d['consultant_id'] : null,
            'est_mienne' => (int) $d['consultant_id'] === (int) $consultant['id'],
            'a_qualification' => (bool) $d['a_qualification'],
            'nb_actions' => (int) $d['nb_actions'],
            'a_traiter' => $d['statut'] !== 'cloture' && (int) $d['nb_actions'] === 0,
        ];
    }, $bruts);
}

/**
 * Nombre total de messages candidats non lus par les consultants, tous
 * dossiers Pack Booster confondus (badge du menu Messages).
 */
function booster_messages_non_lus_total(int $packId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id
         WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0"
    );
    $stmt->execute([$packId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Les étapes réelles du parcours Pack Booster, dans leur ordre réel — la
 * même chaîne que celle déjà utilisée côté client (booster-tableau-bord.php,
 * bloc "$avancement") : demande -> qualification -> rendez-vous ->
 * consentement -> paiement -> accompagnement -> clôture. Centralisée ici
 * pour que le pipeline consultant (consultant-parcours-booster.php) affiche
 * exactement la même progression que celle vue par le client, jamais une
 * progression recalculée différemment.
 *
 * "Action en attente" et "Livrable à transmettre" ne sont PAS des étapes
 * séparées : dans le workflow réel, un dossier reste "en accompagnement"
 * pendant toute leur durée de vie — ce sont des signaux (voir "raisons" dans
 * booster_parcours_dossiers()), pas des étapes du pipeline.
 */
function booster_parcours_etapes(): array
{
    return [
        'qualification'   => 'Qualification du projet',
        'rdv'              => 'Rendez-vous à confirmer',
        'consentement'     => 'Consentement à valider',
        'paiement'         => 'Paiement en attente',
        'accompagnement'   => 'Accompagnement en cours',
        'cloture'          => 'Parcours clôturé',
    ];
}

/**
 * Agrège, pour tous les dossiers Pack Booster, l'étape réelle du parcours,
 * la progression, les raisons d'intervention (avec la personne responsable
 * — client ou consultant, pour ne jamais présenter une attente légitime du
 * client comme un blocage) et les informations utiles à la liste et au
 * pipeline. Requêtes groupées (IN (...)) plutôt qu'une requête par dossier.
 *
 * Ne duplique aucune donnée : agrège depuis actions_plan, documents,
 * messages, rendezvous, paiements, consentements, qualifications,
 * activites — les tables déjà existantes.
 */
function booster_parcours_dossiers(int $packId): array
{
    $stmt = db()->prepare(
        'SELECT ds.id, ds.numero, ds.statut, ds.consultant_id, ds.notes_consultant, ds.created_at, ds.updated_at,
                u.nom, u.prenom,
                q.donnees_json, (q.dossier_id IS NOT NULL) AS a_qualification,
                (SELECT accepte FROM consentements c WHERE c.dossier_id = ds.id) AS consentement_accepte,
                (SELECT MAX(act.created_at) FROM activites act WHERE act.dossier_id = ds.id) AS derniere_activite
         FROM dossiers ds
         JOIN users u ON u.id = ds.user_id
         LEFT JOIN qualifications q ON q.dossier_id = ds.id
         WHERE ds.pack_id = ?
         ORDER BY ds.updated_at DESC'
    );
    $stmt->execute([$packId]);
    $dossiers = $stmt->fetchAll();
    if (!$dossiers) {
        return [];
    }
    $ids = array_column($dossiers, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare("SELECT p1.dossier_id, p1.statut, p1.methode FROM paiements p1 WHERE p1.id = (SELECT MAX(p2.id) FROM paiements p2 WHERE p2.dossier_id = p1.dossier_id) AND p1.dossier_id IN ($in)");
    $stmt->execute($ids);
    $paiementParDossier = [];
    foreach ($stmt->fetchAll() as $p) { $paiementParDossier[$p['dossier_id']] = $p; }

    $stmt = db()->prepare("SELECT dossier_id, date_heure, statut FROM rendezvous WHERE dossier_id IN ($in) ORDER BY date_heure ASC");
    $stmt->execute($ids);
    $rdvProchainParDossier = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!isset($rdvProchainParDossier[$r['dossier_id']]) && strtotime($r['date_heure']) >= time() && $r['statut'] !== 'annule') {
            $rdvProchainParDossier[$r['dossier_id']] = $r;
        }
    }

    $stmt = db()->prepare("SELECT dossier_id, id, titre, statut, echeance, priorite, proprietaire FROM actions_plan WHERE dossier_id IN ($in)");
    $stmt->execute($ids);
    $actionsParDossier = [];
    foreach ($stmt->fetchAll() as $a) { $actionsParDossier[$a['dossier_id']][] = $a; }

    // Versions courantes des documents (même principe que
    // nx_documents_par_categorie, en une seule requête pour tous les
    // dossiers : seule la plus récente par (dossier, catégorie) compte).
    $stmt = db()->prepare("SELECT dossier_id, categorie, statut, uploaded_at FROM documents WHERE dossier_id IN ($in) ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute($ids);
    $docsActuelsParDossier = [];
    $vusParGroupe = [];
    foreach ($stmt->fetchAll() as $d) {
        $cle = $d['dossier_id'] . '|' . ($d['categorie'] ?? ('#' . count($vusParGroupe)));
        if (isset($vusParGroupe[$cle])) { continue; }
        $vusParGroupe[$cle] = true;
        $docsActuelsParDossier[$d['dossier_id']][] = $d;
    }

    $stmt = db()->prepare("SELECT dossier_id, COUNT(*) AS n FROM messages WHERE dossier_id IN ($in) AND expediteur_type = 'client' AND lu_par_consultant = 0 GROUP BY dossier_id");
    $stmt->execute($ids);
    $msgsParDossier = [];
    foreach ($stmt->fetchAll() as $m) { $msgsParDossier[$m['dossier_id']] = (int) $m['n']; }

    $stmt = db()->prepare("SELECT dossier_id, COUNT(*) AS n FROM livrables WHERE dossier_id IN ($in) GROUP BY dossier_id");
    $stmt->execute($ids);
    $nbLivrablesParDossier = [];
    foreach ($stmt->fetchAll() as $l) { $nbLivrablesParDossier[$l['dossier_id']] = (int) $l['n']; }

    $maintenant = time();
    $resultat = [];
    foreach ($dossiers as $d) {
        $donnees = json_decode((string) $d['donnees_json'], true) ?: [];
        $qualificationFaite = (bool) $d['a_qualification'];
        $rdv = $rdvProchainParDossier[$d['id']] ?? null;
        $rdvConfirme = $rdv !== null && $rdv['statut'] === 'confirme';
        $consentementValide = (bool) $d['consentement_accepte'];
        $paiement = $paiementParDossier[$d['id']] ?? null;
        $paiementFait = ($paiement['statut'] ?? '') === 'paye';
        $dossierCloture = $d['statut'] === 'cloture';

        if ($dossierCloture) { $etape = 'cloture'; }
        elseif (!$qualificationFaite) { $etape = 'qualification'; }
        elseif (!$rdvConfirme) { $etape = 'rdv'; }
        elseif (!$consentementValide) { $etape = 'consentement'; }
        elseif (!$paiementFait) { $etape = 'paiement'; }
        else { $etape = 'accompagnement'; }

        $chaine = ['demande' => true, 'qualification' => $qualificationFaite, 'rdv' => $rdvConfirme, 'consentement' => $consentementValide, 'paiement' => $paiementFait, 'accompagnement' => $dossierCloture, 'cloture' => $dossierCloture];
        $progression = (int) round(count(array_filter($chaine)) / count($chaine) * 100);

        $actions = $actionsParDossier[$d['id']] ?? [];
        $actionsOuvertes = array_values(array_filter($actions, fn ($a) => in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true)));
        $actionsAVerifier = array_values(array_filter($actions, fn ($a) => $a['statut'] === 'a_verifier'));
        $actionsEnRetard = array_values(array_filter($actionsOuvertes, fn ($a) => $a['echeance'] && strtotime($a['echeance']) < $maintenant));
        // Tâches que le consultant doit lui-même exécuter (par opposition à
        // celles qu'il attend du client) : "proprietaire" vaut "Consultant"
        // par défaut dès que le coach ne précise personne d'autre à la
        // création de l'action (voir consultant-dossier-booster.php::ajouter_action).
        $actionsConsultant = array_values(array_filter($actionsOuvertes, fn ($a) => stripos((string) $a['proprietaire'], 'consultant') !== false));
        usort($actionsOuvertes, fn ($a, $b) => ($a['echeance'] ? strtotime($a['echeance']) : PHP_INT_MAX) <=> ($b['echeance'] ? strtotime($b['echeance']) : PHP_INT_MAX));
        $prochaineAction = $actionsOuvertes[0] ?? null;

        $docsActuels = $docsActuelsParDossier[$d['id']] ?? [];
        $docsEnVerif = array_values(array_filter($docsActuels, fn ($x) => $x['statut'] === 'en_verification'));

        $msgsNonLus = $msgsParDossier[$d['id']] ?? 0;

        $derniereActivite = $d['derniere_activite'] ?: $d['created_at'];
        $joursInactif = (int) floor(($maintenant - strtotime($derniereActivite)) / 86400);
        // Seuil d'inactivité défini ici explicitement (14 jours) — ne s'applique
        // qu'aux dossiers en accompagnement actif (pas aux dossiers tout juste
        // créés qui attendent légitimement une première action du client).
        $inactif = $etape === 'accompagnement' && $joursInactif >= 14;

        $nbLivrables = $nbLivrablesParDossier[$d['id']] ?? 0;
        // Le Pack Booster ne connaît pas de brouillon de livrable : le bouton
        // "Générer les livrables" (consultant-dossier-booster.php) crée
        // directement une version "disponible", reconstruite à chaque
        // consultation — il n'existe donc pas d'état "en préparation" à
        // distinguer ici, contrairement à Pack Classic. "À préparer" signifie
        // seulement "aucun livrable généré pour l'instant".
        $livrableAPreparer = $etape === 'accompagnement' && $nbLivrables === 0;

        $raisons = [];
        if ($msgsNonLus > 0) {
            $raisons[] = ['texte' => $msgsNonLus . ' message' . ($msgsNonLus > 1 ? 's' : '') . ' client non lu' . ($msgsNonLus > 1 ? 's' : ''), 'qui' => 'consultant', 'icone' => 'mail', 'lien' => 'consultant-messages-booster.php?dossier=' . $d['id']];
        }
        if ($docsEnVerif) {
            $raisons[] = ['texte' => count($docsEnVerif) . ' document(s) à vérifier', 'qui' => 'consultant', 'icone' => 'fact_check', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id'] . '#documents'];
        }
        if ($actionsAVerifier) {
            $raisons[] = ['texte' => count($actionsAVerifier) . ' action(s) soumise(s) à valider', 'qui' => 'consultant', 'icone' => 'task_alt', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id'] . '#plan'];
        }
        if ($rdv && !$rdvConfirme) {
            $raisons[] = ['texte' => 'Rendez-vous du ' . date('d/m/Y', strtotime($rdv['date_heure'])) . ' à confirmer', 'qui' => 'consultant', 'icone' => 'event_available', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id'] . '#session'];
        }
        if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente') {
            $raisons[] = ['texte' => 'Virement à confirmer', 'qui' => 'consultant', 'icone' => 'payments', 'lien' => 'consultant-dossiers-booster.php'];
        }
        if ($actionsEnRetard) {
            $raisons[] = ['texte' => count($actionsEnRetard) . ' action(s) en retard côté client', 'qui' => 'client', 'icone' => 'schedule', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id'] . '#plan'];
        }
        if ($inactif) {
            $raisons[] = ['texte' => 'Aucune activité depuis ' . $joursInactif . ' jours', 'qui' => 'consultant', 'icone' => 'hourglass_disabled', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id']];
        }
        if ($rdv && $rdvConfirme && strtotime($rdv['date_heure']) <= strtotime('+48 hours')) {
            $raisons[] = ['texte' => 'Rendez-vous le ' . date('d/m à H:i', strtotime($rdv['date_heure'])) . ' — à préparer', 'qui' => 'consultant', 'icone' => 'event_upcoming', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id'] . '#session'];
        }
        if ($d['consultant_id'] === null && !$dossierCloture) {
            $raisons[] = ['texte' => 'Dossier non assigné — à prendre en charge', 'qui' => 'consultant', 'icone' => 'person_add', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id']];
        }
        if ($livrableAPreparer && $paiementFait) {
            $raisons[] = ['texte' => 'Aucun livrable généré pour le moment', 'qui' => 'consultant', 'icone' => 'description', 'lien' => 'consultant-dossier-booster.php?dossier=' . $d['id'] . '#livrables'];
        }

        $raisonsConsultant = array_values(array_filter($raisons, fn ($r) => $r['qui'] === 'consultant'));
        $raisonsClient = array_values(array_filter($raisons, fn ($r) => $r['qui'] === 'client'));

        // Catégorie globale du dossier : prioritaire si le consultant doit agir
        // (le pilotage lui revient), sinon "bloqué" si l'attente dépasse la
        // normale côté client (retard), sinon dossier sain.
        $categorie = 'normal';
        if (!$dossierCloture) {
            if ($raisonsConsultant) { $categorie = 'attente_intervention'; }
            elseif ($raisonsClient) { $categorie = 'bloque'; }
        }

        $resultat[] = [
            'id' => $d['id'],
            'numero' => $d['numero'],
            'client' => $d['prenom'] . ' ' . $d['nom'],
            'initiales' => strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)),
            'objectif' => $donnees['objectif_principal'] ?? 'Non renseigné',
            'etape' => $etape,
            'progression' => $progression,
            'statut_dossier' => $d['statut'],
            'consultant_id' => $d['consultant_id'] ? (int) $d['consultant_id'] : null,
            'prise_en_charge' => $d['consultant_id'] === null,
            'created_at' => $d['created_at'],
            'derniere_activite' => $derniereActivite,
            'prochaine_action' => $prochaineAction ? ['titre' => $prochaineAction['titre'], 'echeance' => $prochaineAction['echeance'], 'responsable' => $prochaineAction['proprietaire'] ?: 'Consultant', 'priorite_haute' => $prochaineAction['priorite'] === 'haute'] : null,
            'prochain_rdv' => $rdv ? ['date_heure' => $rdv['date_heure'], 'confirme' => $rdvConfirme] : null,
            'raisons' => $raisons,
            'raisons_consultant' => $raisonsConsultant,
            'raisons_client' => $raisonsClient,
            'categorie' => $categorie,
            'notes_consultant' => $d['notes_consultant'],
            'nb_documents_a_verifier' => count($docsEnVerif),
            'nb_actions_a_verifier' => count($actionsAVerifier),
            'nb_actions_en_retard' => count($actionsEnRetard),
            'nb_actions_consultant_ouvertes' => count($actionsConsultant),
            'nb_livrables' => $nbLivrables,
            'livrable_a_preparer' => $livrableAPreparer,
        ];
    }

    return $resultat;
}

/**
 * Génère (et transmet immédiatement) le livrable "Bilan et plan d'action"
 * d'un dossier Booster. Centralisée ici pour être appelée aussi bien depuis
 * consultant-dossier-booster.php que consultant-pack-booster.php — mêmes
 * données, même table `livrables`, jamais dupliquée.
 */
function booster_generer_livrables(int $dossierId, int $userId): string
{
    $titre = 'Bilan et plan d\'action — ' . date('d/m/Y');
    $stmt = db()->prepare('INSERT INTO livrables (dossier_id, titre, nom_original, chemin, taille_ko, statut) VALUES (?, ?, ?, ?, 0, "disponible")');
    $stmt->execute([$dossierId, $titre, $titre, 'compte-rendu.php?dossier=' . $dossierId]);
    log_activite($dossierId, 'Livrables générés et transmis au client');
    notify($userId, 'livrable', 'Nouveau livrable disponible', $titre, 'booster-tableau-bord.php');

    return $titre;
}

/**
 * Prend en charge un dossier non assigné (l'affecte au consultant connecté).
 * Réutilise exactement la même opération que la réassignation manuelle de
 * consultant-dossiers-booster.php (UPDATE dossiers.consultant_id) — jamais
 * une table ou un mécanisme différent.
 */
function booster_prendre_en_charge(int $dossierId, int $packId, int $consultantId): bool
{
    $stmt = db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ? AND pack_id = ? AND consultant_id IS NULL');
    $stmt->execute([$consultantId, $dossierId, $packId]);
    if ($stmt->rowCount() > 0) {
        log_activite($dossierId, 'Dossier pris en charge par le consultant');
        return true;
    }
    return false;
}

/**
 * Sidebar commune à toutes les pages de l'espace consultant Pack Booster
 * (thème sombre, reprend fidèlement la maquette "Suivi, plan d'action et
 * accompagnement").
 */
function render_booster_sidebar(int $packId, string $actif = 'pack'): void
{
    $nbMessagesNonLus = booster_messages_non_lus_total($packId);
    $items = [
        'tableau'    => ['icone' => 'space_dashboard', 'label' => 'Tableau de bord', 'url' => 'consultant-tableau-bord-booster.php'],
        'clients'    => ['icone' => 'diversity_3', 'label' => 'Mes clients', 'url' => 'consultant-dossiers-booster.php'],
        'parcours'   => ['icone' => 'route', 'label' => 'Parcours client', 'url' => 'consultant-parcours-booster.php'],
        'pack'       => ['icone' => 'rocket_launch', 'label' => 'Pack Booster', 'url' => 'consultant-pack-booster.php'],
        'messages'   => ['icone' => 'mail', 'label' => 'Messages', 'url' => 'consultant-messages-booster.php', 'badge' => $nbMessagesNonLus ?: null],
    ];
    ?>
    <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs overflow-y-auto min-h-0">
      <a href="index.php" class="flex items-center gap-1.5 pb-space-2xs">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
      </a>
      <span class="font-label-md text-[10px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pb-space-2xs pt-space-xs">Espace consultant</span>

      <?php foreach ($items as $slug => $it): ?>
      <a href="<?php echo htmlspecialchars($it['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo $actif === $slug ? 'bg-white/10 text-white font-bold border-l-2 border-[#F0A83E]' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
        <span class="material-symbols-outlined text-[20px]"><?php echo $it['icone']; ?></span>
        <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($it['label']); ?></span>
        <?php if (!empty($it['badge'])): ?><span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $it['badge']; ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
      <a href="formations.php?pack=booster" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">school</span><span class="font-label-lg text-label-lg">Formations &amp; certifications</span></a>
      <a href="ressources.php?pack=booster" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">folder_special</span><span class="font-label-lg text-label-lg">Ressources</span></a>
      <a href="modeles-documents.php?pack=booster" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">construction</span><span class="font-label-lg text-label-lg">Outils &amp; modèles</span></a>
      <a href="consultant-calendrier-booster.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">calendar_month</span><span class="font-label-lg text-label-lg">Calendrier</span></a>
      <a href="consultant-reporting-booster.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">bar_chart</span><span class="font-label-lg text-label-lg">Reporting</span></a>
      <a href="admin-messages.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors">
        <span class="material-symbols-outlined text-[20px] shrink-0">forum</span>
        <span class="font-label-lg text-label-lg flex-1">Messages internes</span>
        <?php $nbMessagesInternes = messages_internes_non_lus((int) current_user()['id']); ?>
        <?php if ($nbMessagesInternes > 0): ?>
        <span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold shrink-0"><?php echo $nbMessagesInternes; ?></span>
        <?php endif; ?>
      </a>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pt-space-sm pb-space-2xs">Administration</span>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <a href="admin-utilisateurs.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">manage_accounts</span><span class="font-label-lg text-label-lg">Utilisateurs</span></a>
      <?php else: ?>
      <span class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/40 cursor-not-allowed" title="Réservé aux administrateurs"><span class="material-symbols-outlined text-[20px]">manage_accounts</span><span class="font-label-lg text-label-lg">Utilisateurs</span></span>
      <?php endif; ?>
      <a href="parametres.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">settings</span><span class="font-label-lg text-label-lg">Paramètres</span></a>

      <div class="mt-auto pt-space-md flex flex-col gap-space-xs bg-white/5 rounded-xl p-space-sm">
        <div class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">support_agent</span></div>
        <p class="font-label-lg text-label-lg font-bold">Besoin d'aide ?</p>
        <p class="font-body-sm text-[11px] text-inverse-on-surface/70">Consultez nos guides et ressources consultants.</p>
        <a href="contact.php" class="h-9 rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center hover:opacity-90 transition-opacity">Accéder aux ressources</a>
        <a href="logout.php" class="h-9 rounded-xl border border-white/20 text-inverse-on-surface font-label-md text-label-md flex items-center justify-center gap-1 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px]">logout</span>Déconnexion</a>
      </div>
    </aside>
    <?php
}

/**
 * En-tête commun (barre de recherche factice, notifications, profil
 * consultant) — reprend fidèlement la maquette.
 */
function render_booster_header(array $consultant): void
{
    ?>
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center gap-space-md px-gutter-mobile lg:px-gutter-desktop">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
        <span class="material-symbols-outlined text-primary text-[24px]">eco</span>
      </a>
      <div class="flex-1 max-w-md relative hidden md:block">
        <span class="material-symbols-outlined absolute left-space-sm top-1/2 -translate-y-1/2 text-outline text-[18px]">search</span>
        <input type="search" placeholder="Rechercher (client, dossier, action...)" class="w-full h-10 pl-10 pr-space-sm rounded-xl bg-surface-container-low font-body-sm text-body-sm focus:outline-none">
      </div>
      <div class="flex items-center gap-space-md ml-auto shrink-0">
        <button type="button" class="w-9 h-9 rounded-full hover:bg-surface-container-low flex items-center justify-center text-secondary" aria-label="Aide"><span class="material-symbols-outlined text-[22px]">help</span></button>
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
          <div class="hidden sm:flex flex-col leading-tight">
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo $consultant['role'] === 'admin' ? 'Administrateur' : 'Consultant interne'; ?></span>
          </div>
          <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors ml-space-xs" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
        </div>
      </div>
    </header>
    <?php
}
