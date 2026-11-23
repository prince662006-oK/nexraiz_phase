<?php
/**
 * Éléments partagés par les pages de l'espace consultant du Pack Wellbeing.
 */

/**
 * Statut visuel d'un dossier Pack Wellbeing, déduit de son avancement réel
 * (qualification, évaluations réalisées) — jamais stocké tel quel en base.
 */
function wellbeing_statut_dossier(array $dossier): array
{
    if ($dossier['statut'] === 'cloture') {
        return ['label' => 'Clôturé', 'classe' => 'bg-green-100 text-green-700'];
    }
    if (!$dossier['a_qualification']) {
        return ['label' => 'Nouveau', 'classe' => 'bg-surface-container-high text-secondary'];
    }
    if ((int) $dossier['nb_evaluations'] === 0) {
        return ['label' => 'Diagnostic à réaliser', 'classe' => 'bg-orange-100 text-orange-700'];
    }

    return ['label' => 'En cours', 'classe' => 'bg-primary-container/10 text-primary'];
}

/**
 * Aperçu de tous les dossiers du Pack Wellbeing, avec les indicateurs réels
 * (motif et niveau de stress extraits de la qualification, nombre
 * d'évaluations réalisées, statut calculé).
 */
function wellbeing_dossiers_apercu(int $packId, array $consultant): array
{
    $stmt = db()->prepare(
        'SELECT ds.id, ds.numero, ds.statut, ds.consultant_id, ds.created_at, ds.updated_at,
                u.nom, u.prenom,
                q.donnees_json,
                (q.dossier_id IS NOT NULL) AS a_qualification,
                (SELECT COUNT(*) FROM wellbeing_evaluations we WHERE we.dossier_id = ds.id) AS nb_evaluations,
                (SELECT ROUND(AVG(we.score)) FROM wellbeing_evaluations we WHERE we.dossier_id = ds.id) AS score_moyen
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
        $statut = wellbeing_statut_dossier($d);

        return [
            'id' => $d['id'],
            'numero' => $d['numero'],
            'initiales' => strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)),
            'client' => $d['prenom'] . ' ' . $d['nom'],
            'motif' => $donnees['motif_principal'] ?? 'Non renseigné',
            'niveau_stress' => $donnees['niveau_stress'] ?? null,
            'score_moyen' => $d['score_moyen'] !== null ? (int) $d['score_moyen'] : null,
            'statut_label' => $statut['label'],
            'statut_classe' => $statut['classe'],
            'maj' => $d['updated_at'],
            'created_at' => $d['created_at'],
            'consultant_id' => $d['consultant_id'] ? (int) $d['consultant_id'] : null,
            'est_mienne' => (int) $d['consultant_id'] === (int) $consultant['id'],
            'a_qualification' => (bool) $d['a_qualification'],
            'nb_evaluations' => (int) $d['nb_evaluations'],
            'a_traiter' => $d['statut'] !== 'cloture' && (int) $d['nb_evaluations'] === 0,
        ];
    }, $bruts);
}

/**
 * Nombre total de messages candidats non lus par les consultants, tous
 * dossiers Pack Wellbeing confondus (badge du menu Messages).
 */
function wellbeing_messages_non_lus_total(int $packId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id
         WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0"
    );
    $stmt->execute([$packId]);

    return (int) $stmt->fetchColumn();
}

/** Définitions communes des 3 modules d'évaluation Wellbeing (libellés, icônes, dimensions). */
function wellbeing_types_evaluation(): array
{
    return [
        'stress_burnout' => [
            'titre' => 'Évaluation Stress / Burnout',
            'icone' => 'psychology',
            'dimensions' => ['Charge de travail', 'Équilibre vie pro / vie perso', 'État émotionnel', 'Énergie physique', 'Soutien social', 'Sens / motivation'],
        ],
        'gestion_temps' => [
            'titre' => 'Gestion du temps & du stress',
            'icone' => 'schedule',
            'dimensions' => ['Planification', 'Priorisation', 'Interruptions', 'Organisation', 'Énergie et pauses', 'Gestion du stress'],
        ],
        'equilibre_vie' => [
            'titre' => 'Équilibre vie pro / vie perso',
            'icone' => 'balance',
            'dimensions' => ['Déconnexion', 'Temps pour proches', 'Temps pour soi', 'Limites professionnelles', 'Habitudes quotidiennes', 'Soutien environnemental'],
        ],
    ];
}

/** Badge qualitatif dérivé d'un score /100, commun à toutes les évaluations Wellbeing. */
function wellbeing_badge_score(int $score): array
{
    if ($score >= 65) {
        return ['label' => 'Stable', 'classe' => 'bg-green-100 text-green-700', 'couleur_texte' => 'text-green-600', 'couleur_point' => 'bg-green-500'];
    }
    if ($score >= 55) {
        return ['label' => 'Attention', 'classe' => 'bg-orange-100 text-orange-700', 'couleur_texte' => 'text-orange-600', 'couleur_point' => 'bg-orange-500'];
    }

    return ['label' => 'Vigilance', 'classe' => 'bg-red-100 text-red-700', 'couleur_texte' => 'text-error', 'couleur_point' => 'bg-red-500'];
}

/**
 * Sidebar commune à toutes les pages de l'espace consultant Pack Wellbeing
 * (reprend fidèlement la maquette "Diagnostic et évaluations Wellbeing").
 */
function render_wellbeing_sidebar(int $packId, string $actif = ''): void
{
    $nbMessages = wellbeing_messages_non_lus_total($packId);
    $items = [
        ['cle' => 'dossiers', 'icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'consultant-dossiers-wellbeing.php'],
        ['cle' => 'clients', 'icone' => 'person', 'label' => 'Mes clients', 'url' => 'consultant-dossiers-wellbeing.php'],
        ['cle' => 'parcours', 'icone' => 'route', 'label' => 'Parcours client', 'url' => 'consultant-parcours-wellbeing.php'],
        ['cle' => 'diagnostic', 'icone' => 'spa', 'label' => 'Pack Wellbeing', 'url' => 'consultant-diagnostic-wellbeing.php'],
        ['cle' => 'evaluations', 'icone' => 'description', 'label' => 'Évaluations', 'url' => 'consultant-evaluations-wellbeing.php'],
        ['cle' => 'outils', 'icone' => 'construction', 'label' => 'Outils & modèles', 'url' => 'modeles-documents.php?pack=wellbeing'],
        ['cle' => 'calendrier', 'icone' => 'calendar_month', 'label' => 'Calendrier', 'url' => 'consultant-calendrier-wellbeing.php'],
        ['cle' => 'messages', 'icone' => 'mail', 'label' => 'Messages', 'url' => 'consultant-messages-wellbeing.php', 'badge' => $nbMessages],
        ['cle' => 'reporting', 'icone' => 'bar_chart', 'label' => 'Reporting', 'url' => 'consultant-reporting-wellbeing.php'],
        ['cle' => 'messages_internes', 'icone' => 'forum', 'label' => 'Messages internes', 'url' => 'admin-messages.php', 'badge' => messages_internes_non_lus((int) current_user()['id'])],
    ];
    ?>
    <aside class="hidden lg:flex flex-col w-64 shrink-0 border-r border-outline-variant/50 bg-surface-container-lowest p-space-md gap-space-2xs overflow-y-auto min-h-0">
      <a href="index.php" class="flex items-center gap-1.5 pb-space-xs">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pb-space-2xs pt-space-xs">Espace consultant</span>
      <?php foreach ($items as $it): ?>
      <a href="<?php echo htmlspecialchars($it['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo $actif === $it['cle'] ? 'bg-primary-container/10 text-primary font-bold border-l-2 border-primary-container' : 'text-secondary hover:bg-surface-container-low'; ?>">
        <span class="material-symbols-outlined text-[20px]"><?php echo $it['icone']; ?></span>
        <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($it['label']); ?></span>
        <?php if (!empty($it['badge'])): ?><span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $it['badge']; ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pt-space-sm pb-space-2xs">Administration</span>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <a href="admin-utilisateurs.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-secondary hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px]">manage_accounts</span><span class="font-label-lg text-label-lg">Utilisateurs</span></a>
      <?php else: ?>
      <span class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-outline-variant cursor-not-allowed" title="Réservé aux administrateurs"><span class="material-symbols-outlined text-[20px]">manage_accounts</span><span class="font-label-lg text-label-lg">Utilisateurs</span></span>
      <?php endif; ?>

      <div class="mt-auto flex flex-col gap-space-2xs pt-space-md border-t border-outline-variant/40">
        <a href="contact.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-secondary hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px]">help</span><span class="font-label-lg text-label-lg">Aide &amp; support</span></a>
        <a href="logout.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-secondary hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px]">logout</span><span class="font-label-lg text-label-lg">Déconnexion</span></a>
      </div>
    </aside>
    <?php
}

/** En-tête commune (barre de recherche, notifications, profil), reprend la maquette. */
function render_wellbeing_header(array $consultant): void
{
    ?>
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center gap-space-md px-gutter-mobile lg:px-gutter-desktop">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <div class="flex-1 max-w-md relative hidden sm:block">
        <input type="search" placeholder="Rechercher un client, une évaluation, un document..." class="w-full h-10 pl-space-sm pr-10 rounded-xl bg-surface-container-low font-body-sm text-body-sm focus:outline-none">
        <span class="material-symbols-outlined absolute right-space-sm top-1/2 -translate-y-1/2 text-outline text-[18px]">search</span>
      </div>
      <div class="flex items-center gap-space-md ml-auto shrink-0">
        <a href="mon-espace.php" class="font-label-md text-label-md text-secondary hover:text-on-surface transition-colors flex items-center gap-1" title="Retour à mon espace"><span class="material-symbols-outlined text-[18px]">arrow_back</span></a>
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
          <div class="hidden sm:flex flex-col leading-tight">
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo $consultant['role'] === 'admin' ? 'Administrateur' : 'Consultant Wellbeing'; ?></span>
          </div>
        </div>
        <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
      </div>
    </header>
    <?php
}
