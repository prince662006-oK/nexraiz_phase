<?php
/**
 * Éléments partagés par les pages de l'espace consultant du Pack Assistance.
 */

function assistance_urgence_info(?string $urgence): array
{
    $map = [
        'faible'   => ['label' => 'Faible',  'dot' => 'bg-green-500'],
        'moyen'    => ['label' => 'Moyenne', 'dot' => 'bg-orange-500'],
        'eleve'    => ['label' => 'Élevée',  'dot' => 'bg-red-500'],
        'critique' => ['label' => 'Critique', 'dot' => 'bg-red-700'],
    ];

    return $map[$urgence] ?? ['label' => 'Non évaluée', 'dot' => 'bg-outline-variant'];
}

/**
 * Statut visuel d'un dossier Pack Assistance, déduit de son avancement réel
 * (aucune valeur stockée en base pour ce libellé : on le recalcule à partir
 * des enregistrements présents — qualification, validation du diagnostic,
 * plan d'action — pour qu'il reste toujours synchronisé avec les faits).
 */
function assistance_statut_dossier(array $dossier): array
{
    if ($dossier['statut'] === 'cloture') {
        return ['label' => 'Clôturé', 'classe' => 'bg-green-100 text-green-700'];
    }
    if (!$dossier['a_qualification']) {
        return ['label' => 'Nouveau', 'classe' => 'bg-surface-container-high text-secondary'];
    }
    if (!$dossier['diagnostic_valide']) {
        return ['label' => 'En cours', 'classe' => 'bg-blue-100 text-blue-700'];
    }
    if ((int) $dossier['nb_actions'] === 0) {
        return ['label' => 'Analyse', 'classe' => 'bg-orange-100 text-orange-700'];
    }

    return ['label' => 'Préparation', 'classe' => 'bg-purple-100 text-purple-700'];
}

/**
 * Aperçu de tous les dossiers du Pack Assistance avec les indicateurs réels
 * utilisés par le tableau de bord, la liste des dossiers et la sidebar.
 */
function assistance_dossiers_apercu(int $packId, array $consultant): array
{
    $stmt = db()->prepare(
        'SELECT ds.id, ds.numero, ds.statut, ds.consultant_id, ds.created_at, ds.updated_at,
                u.nom, u.prenom, u.email, u.telephone,
                q.situation_type, q.urgence, q.documents_disponibles, q.diagnostic_valide_le,
                (q.dossier_id IS NOT NULL) AS a_qualification,
                (SELECT COUNT(*) FROM documents doc WHERE doc.dossier_id = ds.id) AS nb_documents,
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
        $d['diagnostic_valide'] = !empty($d['diagnostic_valide_le']);
        $statut = assistance_statut_dossier($d);
        $urgence = assistance_urgence_info($d['urgence']);
        $documentsAttendusDecodes = json_decode((string) $d['documents_disponibles'], true);
        $documentsAttendus = is_array($documentsAttendusDecodes) ? $documentsAttendusDecodes : [];

        return [
            'id' => $d['id'],
            'numero' => $d['numero'],
            'initiales' => strtoupper(mb_substr($d['prenom'], 0, 1) . mb_substr($d['nom'], 0, 1)),
            'client' => $d['prenom'] . ' ' . $d['nom'],
            'email' => $d['email'],
            'telephone' => $d['telephone'],
            'situation' => $d['situation_type'] ?: 'Non renseignée',
            'urgence_label' => $urgence['label'],
            'urgence_dot' => $urgence['dot'],
            'nb_documents' => (int) $d['nb_documents'],
            'nb_documents_attendus' => count($documentsAttendus),
            'statut_label' => $statut['label'],
            'statut_classe' => $statut['classe'],
            'maj' => $d['updated_at'],
            'created_at' => $d['created_at'],
            'consultant_id' => $d['consultant_id'] ? (int) $d['consultant_id'] : null,
            'est_mienne' => (int) $d['consultant_id'] === (int) $consultant['id'],
            'a_qualification' => (bool) $d['a_qualification'],
            'diagnostic_valide' => $d['diagnostic_valide'],
            'nb_actions' => (int) $d['nb_actions'],
            'a_traiter' => $d['statut'] !== 'cloture' && (int) $d['nb_actions'] === 0,
        ];
    }, $bruts);
}

/**
 * Rappels réels affichés dans la sidebar du tableau de bord : diagnostics en
 * retard (qualifiés depuis plus de 2 jours mais pas encore validés) et
 * dossiers avec documents reçus en attente de validation du diagnostic.
 */
function assistance_rappels(int $packId): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM qualifications q
         JOIN dossiers ds ON ds.id = q.dossier_id
         WHERE ds.pack_id = ? AND ds.statut != 'cloture'
           AND q.diagnostic_valide_le IS NULL
           AND q.created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
    );
    $stmt->execute([$packId]);
    $diagnosticsEnRetard = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT ds.id) FROM dossiers ds
         JOIN documents doc ON doc.dossier_id = ds.id
         LEFT JOIN qualifications q ON q.dossier_id = ds.id
         WHERE ds.pack_id = ? AND (q.diagnostic_valide_le IS NULL OR q.dossier_id IS NULL)"
    );
    $stmt->execute([$packId]);
    $documentsAValider = (int) $stmt->fetchColumn();

    return ['diagnostics_en_retard' => $diagnosticsEnRetard, 'documents_a_valider' => $documentsAValider];
}

/**
 * Nombre total de messages candidats non lus par les consultants, tous
 * dossiers Pack Assistance confondus (utilisé pour le badge du menu Messages).
 */
function assistance_messages_non_lus_total(int $packId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id
         WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0"
    );
    $stmt->execute([$packId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Sidebar de la page d'ensemble (tableau de bord / diagnostic) — thème sombre
 * "espace interne consultant".
 */
function render_assistance_dashboard_sidebar(int $packId, string $actif = 'diagnostic'): void
{
    $rappels = assistance_rappels($packId);
    $nbMessagesNonLus = assistance_messages_non_lus_total($packId);
    $items = [
        'tableau'    => ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'consultant-diagnostic.php'],
        'dossiers'   => ['icone' => 'folder', 'label' => 'Dossiers', 'url' => 'consultant-dossiers-assistance.php'],
        'diagnostic' => ['icone' => 'description', 'label' => 'Diagnostic', 'url' => 'consultant-dossiers-assistance.php?vue=a_valider'],
        'suivi'      => ['icone' => 'bar_chart', 'label' => 'Suivi', 'url' => 'consultant-suivi-assistance.php'],
        'messages'   => ['icone' => 'chat', 'label' => 'Messages', 'url' => 'consultant-messages-assistance.php', 'badge' => $nbMessagesNonLus ?: null],
        'comptes'    => ['icone' => 'summarize', 'label' => 'Comptes rendus', 'url' => 'consultant-comptes-rendus-assistance.php'],
        'parametres' => ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
    ];
    ?>
    <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs overflow-y-auto min-h-0">
      <a href="index.php" class="flex items-center gap-1.5 pb-space-sm">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
      </a>
      <span class="font-label-md text-[10px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pb-space-2xs">Espace interne consultant</span>

      <?php foreach ($items as $slug => $it): ?>
      <a href="<?php echo htmlspecialchars($it['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo $actif === $slug ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
        <span class="material-symbols-outlined text-[20px]"><?php echo $it['icone']; ?></span>
        <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($it['label']); ?></span>
        <?php if (!empty($it['badge'])): ?><span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $it['badge']; ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>

      <div class="pt-space-md mt-space-xs border-t border-white/10 flex flex-col gap-space-2xs">
        <span class="font-label-md text-[11px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pb-space-2xs">Accès rapide</span>
        <a href="consultant-nouveau-dossier-assistance.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">post_add</span><span class="font-label-md text-label-md">Nouveau dossier</span></a>
        <a href="consultant-dossiers-assistance.php#recherche" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">search</span><span class="font-label-md text-label-md">Rechercher un dossier</span></a>
        <a href="consultant-calendrier-assistance.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">calendar_month</span><span class="font-label-md text-label-md">Calendrier</span></a>
        <a href="modeles-documents.php?pack=assistance" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">description</span><span class="font-label-md text-label-md">Modèles de documents</span></a>
        <a href="ressources.php?pack=assistance" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">menu_book</span><span class="font-label-md text-label-md">Base de connaissances</span></a>
        <a href="admin-messages.php" class="flex items-center justify-between px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors">
          <span class="flex items-center gap-space-xs font-label-md text-label-md"><span class="material-symbols-outlined text-[18px]">forum</span>Messages internes</span>
          <?php $nbMessagesInternes = messages_internes_non_lus((int) current_user()['id']); ?>
          <?php if ($nbMessagesInternes > 0): ?>
          <span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $nbMessagesInternes; ?></span>
          <?php endif; ?>
        </a>
        <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
        <a href="admin-utilisateurs.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">manage_accounts</span><span class="font-label-md text-label-md">Utilisateurs</span></a>
        <?php endif; ?>
        <a href="ressources.php?categorie=faq" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">help</span><span class="font-label-md text-label-md">FAQ interne</span></a>
      </div>

      <div class="pt-space-md mt-space-xs border-t border-white/10 flex flex-col gap-space-2xs">
        <span class="font-label-md text-[11px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pb-space-2xs">Rappels</span>
        <?php if ($rappels['diagnostics_en_retard'] > 0): ?>
        <span class="flex items-center gap-space-xs px-space-xs font-body-sm text-[12px] text-inverse-on-surface/80"><span class="w-1.5 h-1.5 rounded-full bg-red-400"></span><?php echo $rappels['diagnostics_en_retard']; ?> diagnostic<?php echo $rappels['diagnostics_en_retard'] > 1 ? 's' : ''; ?> en retard</span>
        <?php endif; ?>
        <?php if ($rappels['documents_a_valider'] > 0): ?>
        <span class="flex items-center gap-space-xs px-space-xs font-body-sm text-[12px] text-inverse-on-surface/80"><span class="w-1.5 h-1.5 rounded-full bg-orange-400"></span><?php echo $rappels['documents_a_valider']; ?> dossier<?php echo $rappels['documents_a_valider'] > 1 ? 's' : ''; ?> à valider</span>
        <?php endif; ?>
        <?php if ($rappels['diagnostics_en_retard'] === 0 && $rappels['documents_a_valider'] === 0): ?>
        <span class="flex items-center gap-space-xs px-space-xs font-body-sm text-[12px] text-inverse-on-surface/60">Rien à signaler</span>
        <?php endif; ?>
      </div>

      <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-2xs">
        <span class="flex items-center gap-1.5"><span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span></span>
        <p class="font-body-sm text-[11px] text-inverse-on-surface/60">L'accompagnement qui fait grandir en toute confiance.</p>
        <p class="font-body-sm text-[10px] text-inverse-on-surface/50 pt-space-2xs">© <?php echo date('Y'); ?> NexRaiz – Tous droits réservés</p>
      </div>
    </aside>
    <?php
}

/**
 * Sidebar de la page dossier (suivi, plan d'action, orientation) — thème
 * "Pack Assistance" avec le compteur réel "À traiter".
 */
function render_assistance_dossier_sidebar(int $packId, array $consultant, string $actif = 'suivi'): void
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM (
            SELECT ds.id FROM dossiers ds
            WHERE ds.pack_id = ? AND ds.statut != 'cloture' AND (ds.consultant_id = ? OR ds.consultant_id IS NULL)
              AND (SELECT COUNT(*) FROM actions_plan ap WHERE ap.dossier_id = ds.id) = 0
         ) t"
    );
    $stmt->execute([$packId, $consultant['id']]);
    $aTraiter = (int) $stmt->fetchColumn();
    $nbMessagesNonLus = assistance_messages_non_lus_total($packId);

    $items = [
        'parcours'   => ['icone' => 'route', 'label' => 'Parcours client', 'url' => 'decouverte.php'],
        'dossiers'   => ['icone' => 'folder', 'label' => 'Mes dossiers', 'url' => 'consultant-dossiers-assistance.php'],
        'a_traiter'  => ['icone' => 'pending_actions', 'label' => 'À traiter', 'url' => 'consultant-dossiers-assistance.php?vue=a_traiter', 'badge' => $aTraiter],
        'suivi'      => ['icone' => 'bar_chart', 'label' => "Suivi &amp; plans d'action", 'url' => 'consultant-dossier.php'],
        'messages'   => ['icone' => 'chat', 'label' => 'Messages', 'url' => 'consultant-messages-assistance.php', 'badge' => $nbMessagesNonLus ?: null],
        'documents'  => ['icone' => 'description', 'label' => 'Documents', 'url' => 'consultant-dossiers-assistance.php'],
    ];
    ?>
    <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs relative" id="consultantSidebar">
      <a href="index.php" class="flex items-center gap-1.5 pb-space-sm">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
      </a>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pb-space-2xs">Pack Assistance</span>
      <?php foreach ($items as $slug => $it): ?>
      <a href="<?php echo htmlspecialchars($it['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo $actif === $slug ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
        <span class="material-symbols-outlined text-[20px]"><?php echo $it['icone']; ?></span>
        <span class="font-label-lg text-label-lg flex-1"><?php echo $it['label']; ?></span>
        <?php if (!empty($it['badge'])): ?><span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $it['badge']; ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
      <a href="modeles-documents.php?pack=assistance" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[20px]">grid_view</span><span class="font-label-lg text-label-lg">Modèles &amp; ressources</span></a>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pt-space-sm pb-space-2xs">Ressources</span>
      <a href="ressources.php?pack=assistance&categorie=outil" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">construction</span><span class="font-label-md text-label-md">Boîte à outils</span></a>
      <a href="ressources.php?pack=assistance&categorie=guide" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">menu_book</span><span class="font-label-md text-label-md">Guides &amp; procédures</span></a>
      <a href="consultant-calendrier-assistance.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">calendar_month</span><span class="font-label-md text-label-md">Calendrier</span></a>
      <a href="contact.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">help</span><span class="font-label-md text-label-md">FAQ interne</span></a>
      <a href="actualites.php?pack=assistance" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">campaign</span><span class="font-label-md text-label-md">Actualités</span></a>
      <a href="admin-messages.php" class="flex items-center justify-between px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors">
        <span class="flex items-center gap-space-xs font-label-md text-label-md"><span class="material-symbols-outlined text-[18px]">forum</span>Messages internes</span>
        <?php $nbMessagesInternesDossier = messages_internes_non_lus((int) $consultant['id']); ?>
        <?php if ($nbMessagesInternesDossier > 0): ?>
        <span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $nbMessagesInternesDossier; ?></span>
        <?php endif; ?>
      </a>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-inverse-on-surface/50 px-space-xs pt-space-sm pb-space-2xs">Paramètres</span>
      <a href="parametres.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">person</span><span class="font-label-md text-label-md">Mon profil</span></a>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <a href="admin-utilisateurs.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">groups</span><span class="font-label-md text-label-md">Équipe</span></a>
      <?php endif; ?>
      <a href="parametres.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[18px]">settings</span><span class="font-label-md text-label-md">Paramètres</span></a>

      <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
        <p class="font-label-lg text-label-lg font-bold">Besoin d'aide ?</p>
        <p class="font-body-sm text-[12px] text-inverse-on-surface/70">Consultez nos guides ou contactez le support.</p>
        <a href="contact.php" class="h-10 rounded-xl border border-white/20 flex items-center justify-center gap-1 font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px]">help_center</span>Centre d'aide</a>
      </div>
    </aside>
    <?php
}
