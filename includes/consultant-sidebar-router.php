<?php
/**
 * Pages partagées entre packs (Ressources, Modèles de documents...) :
 * affiche la sidebar du pack de rattachement du consultant connecté, pour
 * qu'il garde sa navigation habituelle même sur une page commune. Sans pack
 * de rattachement (ex. administrateur générique), aucune sidebar n'est
 * affichée — la page reste utilisable en plein largeur.
 */
function consultant_pack_slug(array $consultant): ?string
{
    if (empty($consultant['pack_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT slug FROM packs WHERE id = ?');
    $stmt->execute([$consultant['pack_id']]);
    return $stmt->fetchColumn() ?: null;
}

function render_consultant_pack_sidebar(array $consultant, string $actif = ''): void
{
    $packSlug = consultant_pack_slug($consultant);

    if ($packSlug === 'classic') {
        require_once __DIR__ . '/consultant-classic.php';
        $packId = get_pack_id('classic');
        $dossiers = classic_dossiers_apercu($packId, $consultant);
        render_classic_sidebar($dossiers, $packId);
    } elseif ($packSlug === 'assistance') {
        require_once __DIR__ . '/consultant-assistance.php';
        render_assistance_dashboard_sidebar(get_pack_id('assistance'), $actif);
    } elseif ($packSlug === 'booster') {
        require_once __DIR__ . '/consultant-booster.php';
        render_booster_sidebar(get_pack_id('booster'), $actif);
    } elseif ($packSlug === 'wellbeing') {
        require_once __DIR__ . '/consultant-wellbeing.php';
        render_wellbeing_sidebar(get_pack_id('wellbeing'), $actif);
    } elseif (in_array($consultant['role'], ['admin', 'super_admin'], true)) {
        // Administrateur/super administrateur sans pack de rattachement précis :
        // navigation de direction, transverse à tous les packs.
        render_admin_sidebar($actif);
    }
}

/**
 * Sidebar de l'espace administration/direction (pas de pack de rattachement,
 * navigation transverse), reprend le même langage visuel que les sidebars
 * de pack (thème clair, item actif en surbrillance).
 */
function render_admin_sidebar(string $actif = ''): void
{
    $moi = current_user();
    $estSuperAdmin = ($moi['role'] ?? '') === 'super_admin';
    $nbMessagesInternes = messages_internes_non_lus((int) $moi['id']);
    $items = [
        ['cle' => 'dashboard', 'icone' => 'space_dashboard', 'label' => 'Tableau de bord', 'url' => 'admin-dashboard.php', 'super_admin_only' => true],
        ['cle' => 'dossiers', 'icone' => 'folder_shared', 'label' => 'Tous les dossiers', 'url' => 'admin-dossiers.php'],
        ['cle' => 'paiements', 'icone' => 'payments', 'label' => 'Paiements', 'url' => 'admin-paiements.php'],
        ['cle' => 'rendezvous', 'icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'admin-rendezvous.php'],
        ['cle' => 'utilisateurs', 'icone' => 'manage_accounts', 'label' => 'Utilisateurs', 'url' => 'admin-utilisateurs.php'],
        ['cle' => 'messages_internes', 'icone' => 'forum', 'label' => 'Messages internes', 'url' => 'admin-messages.php', 'badge' => $nbMessagesInternes],
        ['cle' => 'ressources', 'icone' => 'folder_special', 'label' => 'Ressources', 'url' => 'ressources.php'],
        ['cle' => 'formations', 'icone' => 'school', 'label' => 'Formations & certifications', 'url' => 'formations.php'],
        ['cle' => 'modeles', 'icone' => 'description', 'label' => 'Modèles de documents', 'url' => 'modeles-documents.php'],
        ['cle' => 'actualites', 'icone' => 'campaign', 'label' => 'Actualités', 'url' => 'actualites.php'],
    ];
    ?>
    <aside class="hidden lg:flex flex-col w-64 shrink-0 border-r border-outline-variant/50 bg-surface-container-lowest p-space-md gap-space-2xs overflow-y-auto min-h-0">
      <a href="index.php" class="flex items-center gap-1.5 pb-space-sm">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pb-space-2xs"><?php echo $estSuperAdmin ? 'Direction' : 'Administration'; ?></span>
      <?php foreach ($items as $it):
        if (!empty($it['super_admin_only']) && !$estSuperAdmin) continue;
      ?>
      <a href="<?php echo htmlspecialchars($it['url']); ?>" class="flex items-center justify-between gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo $actif === $it['cle'] ? 'bg-primary-container text-white font-bold' : 'text-secondary hover:bg-surface-container-low'; ?>">
        <span class="flex items-center gap-space-xs font-label-lg text-label-lg"><span class="material-symbols-outlined text-[20px]"><?php echo $it['icone']; ?></span><?php echo htmlspecialchars($it['label']); ?></span>
        <?php if (!empty($it['badge'])): ?>
        <span class="w-5 h-5 rounded-full <?php echo $actif === $it['cle'] ? 'bg-white text-primary' : 'bg-error text-white'; ?> flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $it['badge']; ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>

      <div class="mt-auto flex flex-col gap-space-2xs pt-space-md border-t border-outline-variant/40">
        <a href="mon-espace.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-secondary hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px]">arrow_back</span><span class="font-label-lg text-label-lg">Mon espace</span></a>
        <a href="logout.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-secondary hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px]">logout</span><span class="font-label-lg text-label-lg">Déconnexion</span></a>
      </div>
    </aside>
    <?php
}
