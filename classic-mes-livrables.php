<?php
/**
 * NexRaiz — « Mes livrables », Pack Classic. Bibliothèque des documents
 * remis au client par son consultant (CV corrigé, rapports, comptes rendus
 * partageables...). Ne montre jamais un livrable "en_preparation" — même
 * son titre reste invisible tant qu'il n'a pas été explicitement envoyé —
 * ni une version remplacée (seule la version courante de chaque livrable
 * logique est listée, voir includes/livrables-classic.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/livrables-classic.php';
require_once __DIR__ . '/includes/cv-builder-classic.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];
$utilisateur = current_user();
$cvStructure = classic_cv_builder_recuperer($dossierId);

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNav = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'classic-tableau-bord.php'],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'classic-mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'classic-depot-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'classic-mes-livrables.php', 'actif' => true],
    ['icone' => 'work', 'label' => 'Mes opportunités', 'url' => 'classic-mes-opportunites.php'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'classic-rendez-vous.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

// Seuls les livrables "disponible", version courante — jamais un brouillon
// interne, jamais une version remplacée (voir includes/livrables-classic.php).
$livrables = classic_livrables_du_dossier($dossierId, ['disponible']);
$categoriesLabels = classic_livrable_categories();

// "Préparé par" : le consultant actuellement assigné au dossier (aucune
// colonne ne trace qui a publié chaque livrable précisément — on réutilise
// l'assignation du dossier plutôt que d'ajouter un champ dédié).
$nomConsultant = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    if ($c = $stmt->fetch()) { $nomConsultant = $c['prenom'] . ' ' . mb_substr($c['nom'], 0, 1) . '.'; }
}

foreach ($livrables as &$l) {
    $l['premiere_publication'] = classic_livrable_date_premiere_publication($dossierId, $l['titre']) ?: $l['created_at'];
    $l['extension'] = strtolower(pathinfo($l['nom_original'], PATHINFO_EXTENSION));
    [$l['icone'], $l['icone_couleur']] = classic_icone_livrable($l['nom_original']);
}
unset($l);

// Historique (dates uniquement — jamais de lien vers une ancienne version :
// le client ne doit jamais pouvoir ouvrir un fichier obsolète/remplacé,
// voir includes/livrables-classic.php et classic-livrable-telecharger.php).
$historiqueParTitre = [];
foreach ($livrables as $l) {
    $stmt = db()->prepare('SELECT version, created_at, envoye_le FROM livrables WHERE dossier_id = ? AND titre = ? ORDER BY version DESC');
    $stmt->execute([$dossierId, $l['titre']]);
    $historiqueParTitre[$l['titre']] = $stmt->fetchAll();
}

// --- Indicateurs : calculés sur les données réelles. ---
$compte = [
    'disponibles' => count($livrables),
    'recents'     => count(array_filter($livrables, fn ($l) => strtotime($l['premiere_publication']) >= strtotime('-7 days'))),
    'consultes'   => count(array_filter($livrables, fn ($l) => $l['consultations_count'] > 0)),
    'mis_a_jour'  => count(array_filter($livrables, fn ($l) => (int) $l['version'] > 1)),
];

// --- Filtres / recherche / tri ---
$categorieFiltre = $_GET['categorie'] ?? '';
$formatFiltre = $_GET['format'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$vue = $_GET['vue'] ?? '';
$tri = $_GET['tri'] ?? 'recent';

$livrablesAffiches = array_values(array_filter($livrables, function ($l) use ($categorieFiltre, $formatFiltre, $recherche, $vue) {
    if ($categorieFiltre !== '' && $l['categorie'] !== $categorieFiltre) return false;
    if ($formatFiltre !== '' && $l['extension'] !== $formatFiltre) return false;
    if ($vue === 'recents' && strtotime($l['premiere_publication']) < strtotime('-7 days')) return false;
    if ($vue === 'consultes' && $l['consultations_count'] <= 0) return false;
    if ($vue === 'mis_a_jour' && (int) $l['version'] <= 1) return false;
    if ($recherche !== '' && stripos($l['titre'] . ' ' . ($l['description'] ?? ''), $recherche) === false) return false;
    return true;
}));
usort($livrablesAffiches, fn ($a, $b) => $tri === 'ancien'
    ? strtotime($a['premiere_publication']) - strtotime($b['premiere_publication'])
    : strtotime($b['created_at']) - strtotime($a['created_at']));

$formatsPresents = array_unique(array_column($livrables, 'extension'));

render_head('Pack Classic — Mes livrables');
?>
<style>
#dashSearchResults{display:none;}
#dashSearchResults.is-open{display:flex;}
#avatarMenu{display:none;}
#avatarMenu.is-open{display:flex;}
</style>

<header class="sticky top-0 z-40 bg-surface-container-lowest/95 backdrop-blur-xl border-b border-outline-variant/50">
  <div class="h-16 px-gutter-mobile lg:px-gutter-desktop flex items-center justify-between gap-space-md">
    <a href="index.php" class="flex items-center gap-2 shrink-0">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      <span class="hidden md:flex flex-col leading-none">
        <span class="font-headline-sm text-headline-sm font-bold text-on-surface">NexRaiz</span>
        <span class="font-body-sm text-[10px] text-secondary">Votre avenir, notre priorité</span>
      </span>
    </a>
    <div class="hidden sm:block flex-1 max-w-md relative" id="dashSearchWrapper">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-secondary text-[20px] pointer-events-none">search</span>
      <input id="dashSearch" type="text" autocomplete="off" placeholder="Rechercher une fonctionnalité... (Ctrl+K)" class="w-full h-11 pl-10 pr-space-sm rounded-xl border border-outline-variant bg-surface-container-low font-body-sm text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 transition-shadow">
      <div id="dashSearchResults" class="absolute left-0 right-0 top-full mt-2 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-2xs flex-col z-50 max-h-72 overflow-y-auto"></div>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <?php render_notification_bell((int) $utilisateur['id']); ?>
      <div class="relative" id="avatarMenuWrapper">
        <button id="avatarMenuBtn" type="button" class="flex items-center gap-space-2xs pl-1 pr-space-xs py-1 rounded-full hover:bg-surface-container-low transition-colors">
          <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[13px] font-bold shrink-0"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($utilisateur['prenom'], 0, 1) . mb_substr($utilisateur['nom'], 0, 1))); ?></span>
          <span class="hidden md:flex flex-col items-start leading-none">
            <span class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($utilisateur['prenom'] . ' ' . $utilisateur['nom']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary">Client</span>
          </span>
          <span class="material-symbols-outlined text-[18px] text-secondary">expand_more</span>
        </button>
        <div id="avatarMenu" class="absolute right-0 top-full mt-2 w-52 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-2xs flex-col z-50">
          <a href="parametres.php" class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">settings</span>Paramètres</a>
          <a href="contact.php" class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">support_agent</span>Nous contacter</a>
          <a href="logout.php" class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-error hover:bg-error-container/10 transition-colors flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">logout</span>Déconnexion</a>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="flex w-full min-h-screen bg-surface">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs nx-sidebar-under-header">
    <h2 class="font-headline-sm text-headline-sm font-bold text-inverse-on-surface px-space-xs pb-space-sm">Pack Classic</h2>
    <?php foreach ($sidebarNav as $item): ?>
    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo !empty($item['actif']) ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
      <?php if (!empty($item['badge'])): ?>
      <span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $item['badge']; ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <div class="mt-space-md rounded-2xl bg-gradient-to-br from-[#2563EB] to-[#13203F] p-space-md flex flex-col gap-space-2xs">
      <span class="material-symbols-outlined text-[#FFC700] text-[26px]">workspace_premium</span>
      <p class="font-label-lg text-label-lg font-bold text-white">Pack Classic</p>
      <p class="font-body-sm text-[11px] text-white/70">Accompagnement vers votre réussite</p>
      <a href="classic-decouverte.php" class="mt-space-2xs h-9 rounded-lg bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-bold flex items-center justify-center gap-1 hover:opacity-90 transition-opacity">Découvrir <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
    </div>
    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <p class="font-label-lg text-label-lg font-bold px-space-xs">Besoin d'aide ?</p>
      <p class="font-body-sm text-[12px] text-inverse-on-surface/70 px-space-xs">Notre équipe est là pour vous accompagner.</p>
      <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Contacter mon consultant</a>
      <a href="logout.php" class="mx-space-xs h-10 rounded-xl flex items-center justify-center gap-1 font-label-md text-label-md text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px]">logout</span>Déconnexion</a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="classic-tableau-bord.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <a href="classic-tableau-bord.php" class="hover:text-primary transition-colors">Tableau de bord</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Mes livrables</span>
      </nav>

      <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#E5EEFF] via-[#F8F9FF] to-[#DCE9FF] p-space-lg md:p-space-xl grid grid-cols-1 lg:grid-cols-[1.2fr_auto] gap-space-md items-center">
        <div class="absolute -bottom-20 -left-20 w-72 h-72 rounded-full bg-[#2563EB]/10 blur-3xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col gap-space-xs">
          <div class="w-14 h-14 rounded-2xl bg-white shadow-md flex items-center justify-center text-[#2563EB]"><span class="material-symbols-outlined text-[28px]">card_giftcard</span></div>
          <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#13203F] font-bold tracking-tight pt-space-xs">Mes <span class="text-[#2563EB]">livrables</span></h1>
          <p class="font-body-lg text-body-lg text-[#444653] max-w-md">Retrouvez ici les documents préparés par votre consultant dans le cadre de votre accompagnement.</p>
          <div class="w-14 h-1 rounded-full bg-[#FFC700] mt-space-2xs"></div>
        </div>
        <div class="relative z-10 flex items-center gap-space-md bg-white/70 backdrop-blur rounded-2xl shadow-sm p-space-md">
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-[#2563EB] to-[#13203F] shadow-md flex items-center justify-center text-white shrink-0"><span class="font-headline-md text-headline-md font-bold"><?php echo $compte['disponibles']; ?></span></div>
          <div>
            <p class="font-label-lg text-label-lg text-[#13203F] font-bold">livrable<?php echo $compte['disponibles'] > 1 ? 's' : ''; ?> disponible<?php echo $compte['disponibles'] > 1 ? 's' : ''; ?></p>
            <?php if ($compte['recents'] > 0): ?>
            <p class="font-body-sm text-[12px] text-[#2563EB] font-semibold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">fiber_new</span><?php echo $compte['recents']; ?> ajouté<?php echo $compte['recents'] > 1 ? 's' : ''; ?> cette semaine</p>
            <?php else: ?>
            <p class="font-body-sm text-[12px] text-secondary">À jour</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($livrables): ?>
      <div class="flex justify-end">
        <button type="button" onclick="document.getElementById('historiqueGlobal').classList.remove('hidden');document.getElementById('historiqueGlobal').classList.add('flex');" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[16px]">history</span>Historique des versions</button>
      </div>
      <?php endif; ?>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm">
        <?php
        $cartes = [
            ['cle' => '', 'label' => 'Disponibles', 'valeur' => $compte['disponibles'], 'couleur' => 'text-primary', 'icone' => 'description', 'caption' => 'Actif', 'caption_couleur' => 'text-primary'],
            ['cle' => 'recents', 'label' => 'Ajoutés récemment', 'valeur' => $compte['recents'], 'couleur' => 'text-on-surface', 'icone' => 'schedule', 'caption' => 'Cette semaine', 'caption_couleur' => 'text-secondary'],
            ['cle' => 'consultes', 'label' => 'Consultés', 'valeur' => $compte['consultes'], 'couleur' => 'text-green-600', 'icone' => 'visibility', 'caption' => 'Vue par le candidat', 'caption_couleur' => 'text-green-600'],
            ['cle' => 'mis_a_jour', 'label' => 'Mis à jour', 'valeur' => $compte['mis_a_jour'], 'couleur' => 'text-orange-600', 'icone' => 'autorenew', 'caption' => 'Révisions récentes', 'caption_couleur' => 'text-orange-600'],
        ];
        foreach ($cartes as $c):
        ?>
        <a href="?vue=<?php echo $c['cle']; ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-1 hover:shadow-md transition-shadow <?php echo $vue === $c['cle'] && $c['cle'] !== '' ? 'ring-2 ring-primary' : ''; ?>">
          <div class="flex items-start justify-between">
            <span class="font-headline-md text-headline-md font-bold <?php echo $c['couleur']; ?>"><?php echo $c['valeur']; ?></span>
            <span class="w-9 h-9 rounded-full bg-primary-container/10 <?php echo $c['couleur']; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $c['icone']; ?></span></span>
          </div>
          <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($c['label']); ?></span>
          <span class="font-body-sm text-[11px] <?php echo $c['caption_couleur']; ?>"><?php echo htmlspecialchars($c['caption']); ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-wrap items-center gap-space-xs">
        <?php if ($vue !== ''): ?><input type="hidden" name="vue" value="<?php echo htmlspecialchars($vue); ?>"><?php endif; ?>
        <div class="relative flex-1 min-w-[180px]">
          <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Titre ou description..." class="w-full h-10 pl-space-2xl pr-space-sm rounded-lg border border-outline-variant font-body-sm text-[13px]" style="padding-left:2.25rem">
        </div>
        <select name="categorie" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
          <option value="">Toutes les catégories</option>
          <?php foreach ($categoriesLabels as $val => $label): ?>
          <option value="<?php echo $val; ?>" <?php echo $categorieFiltre === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="format" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
          <option value="">Tous les formats</option>
          <?php foreach ($formatsPresents as $f): ?>
          <option value="<?php echo $f; ?>" <?php echo $formatFiltre === $f ? 'selected' : ''; ?>><?php echo strtoupper($f); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="tri" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
          <option value="recent" <?php echo $tri !== 'ancien' ? 'selected' : ''; ?>>Plus récent</option>
          <option value="ancien" <?php echo $tri === 'ancien' ? 'selected' : ''; ?>>Plus ancien</option>
        </select>
        <button type="submit" class="h-10 px-space-md rounded-lg bg-primary text-white font-label-md text-[13px] flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">filter_alt</span>Filtrer</button>
        <?php if ($recherche || $categorieFiltre || $formatFiltre || $vue || $tri !== 'recent'): ?>
        <a href="classic-mes-livrables.php" class="h-10 px-space-sm rounded-lg border border-outline-variant font-label-md text-[13px] text-secondary flex items-center">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="flex items-center gap-space-xs">
        <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Documents disponibles</h2>
        <span class="px-space-xs py-0.5 rounded-full bg-primary-container/10 text-primary font-label-md text-[11px] font-bold"><?php echo count($livrablesAffiches); ?> résultat<?php echo count($livrablesAffiches) > 1 ? 's' : ''; ?></span>
      </div>

      <?php if (!$livrables): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-2xl flex flex-col items-center text-center gap-space-xs">
        <span class="material-symbols-outlined text-[40px] text-primary">card_giftcard</span>
        <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Aucun livrable disponible pour le moment</p>
        <p class="font-body-sm text-body-sm text-secondary max-w-sm">Les documents préparés par votre consultant (CV corrigé, rapports, recommandations...) apparaîtront ici dès qu'ils seront prêts.</p>
      </div>
      <?php elseif (!$livrablesAffiches): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg bg-surface-container-lowest rounded-xl">Aucun livrable ne correspond à ces critères.</p>
      <?php endif; ?>

      <div class="flex flex-col gap-space-sm">
        <?php foreach ($livrablesAffiches as $l): ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
          <div class="flex items-start justify-between gap-space-sm flex-wrap">
            <div class="flex items-center gap-space-sm min-w-0">
              <div class="w-11 h-11 rounded-lg bg-primary-container/10 <?php echo $l['icone_couleur']; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo $l['icone']; ?></span></div>
              <div class="min-w-0">
                <div class="flex items-center gap-1 flex-wrap">
                  <p class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($l['titre']); ?></p>
                  <span class="shrink-0 inline-flex items-center gap-1 px-space-xs py-0.5 rounded-full bg-green-100 text-green-700 font-label-md text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-green-600"></span>Prêt à l'emploi</span>
                  <?php if ((int) $l['version'] > 1): ?>
                  <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-orange-100 text-orange-700">Mis à jour (v<?php echo (int) $l['version']; ?>)</span>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-space-sm flex-wrap font-body-sm text-[11px] text-secondary mt-0.5">
                  <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">event_available</span>Publié le <?php echo htmlspecialchars(date('d/m/Y', strtotime($l['premiere_publication']))); ?></span>
                  <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">description</span>Format : <?php echo strtoupper($l['extension']); ?> · <?php echo (int) $l['taille_ko']; ?> Ko</span>
                  <?php if ($nomConsultant): ?>
                  <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">person</span>Préparé par <?php echo htmlspecialchars($nomConsultant); ?> (Consultant)</span>
                  <?php endif; ?>
                  <?php if ($l['categorie'] && isset($categoriesLabels[$l['categorie']])): ?>
                  <span><?php echo htmlspecialchars($categoriesLabels[$l['categorie']]); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <?php if ($l['description']): ?><p class="font-body-sm text-[13px] text-primary"><?php echo htmlspecialchars($l['description']); ?></p><?php endif; ?>
          <?php if ($l['prochaine_etape']): ?>
          <p class="font-body-sm text-[12px] text-primary bg-primary-container/10 rounded-lg px-space-sm py-space-2xs flex items-start gap-1"><span class="material-symbols-outlined text-[14px] mt-0.5">arrow_forward</span><?php echo htmlspecialchars($l['prochaine_etape']); ?></p>
          <?php endif; ?>

          <div class="flex items-center gap-1 pt-space-2xs">
            <?php if ($l['extension'] === 'pdf'): ?>
            <button type="button" onclick="var m=document.getElementById('apercu-<?php echo $l['id']; ?>');m.classList.remove('hidden');m.classList.add('flex');" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">visibility</span>Prévisualiser</button>
            <?php endif; ?>
            <a href="classic-livrable-telecharger.php?id=<?php echo $l['id']; ?>" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1 hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[14px]">download</span>Télécharger</a>
            <?php if ($l['titre'] === 'CV corrigé' && $cvStructure && $cvStructure['layout']): ?>
            <a href="candidat-cv-structure.php?dossier=<?php echo $dossierId; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[14px]">list_alt</span>Voir le détail de mon CV</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($l['extension'] === 'pdf'): ?>
        <div id="apercu-<?php echo $l['id']; ?>" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60 p-gutter-mobile" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');}">
          <div class="bg-surface-container-lowest rounded-2xl shadow-xl w-full max-w-3xl h-[85vh] flex flex-col overflow-hidden">
            <div class="flex items-center justify-between px-space-lg py-space-sm border-b border-outline-variant/50 shrink-0">
              <h3 class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($l['titre']); ?></h3>
              <button type="button" onclick="var m=document.getElementById('apercu-<?php echo $l['id']; ?>');m.classList.add('hidden');m.classList.remove('flex');" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low shrink-0"><span class="material-symbols-outlined text-[20px]">close</span></button>
            </div>
            <iframe src="classic-livrable-telecharger.php?id=<?php echo $l['id']; ?>&mode=apercu" class="flex-1 w-full" title="Aperçu du livrable"></iframe>
          </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($livrables): ?>
      <div class="bg-primary-container/5 border border-primary-container/20 rounded-xl p-space-lg flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-sm">
        <div class="flex items-start gap-space-sm">
          <span class="material-symbols-outlined text-[20px] text-primary mt-0.5">info</span>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Besoin d'une mise à jour ?</p>
            <p class="font-body-sm text-[13px] text-secondary">Votre consultant mettra directement à jour ces documents depuis son espace dès que nécessaire.</p>
          </div>
        </div>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1">Contacter mon consultant <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>

<?php if ($livrables): ?>
<div id="historiqueGlobal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60 p-gutter-mobile" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');}">
  <div class="bg-surface-container-lowest rounded-2xl shadow-xl w-full max-w-md max-h-[80vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-space-lg py-space-sm border-b border-outline-variant/50 shrink-0">
      <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Historique des versions</h3>
      <button type="button" onclick="document.getElementById('historiqueGlobal').classList.add('hidden');document.getElementById('historiqueGlobal').classList.remove('flex');" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low shrink-0"><span class="material-symbols-outlined text-[20px]">close</span></button>
    </div>
    <div class="flex-1 overflow-y-auto p-space-lg flex flex-col gap-space-md">
      <?php foreach ($historiqueParTitre as $titre => $versions): ?>
      <div>
        <p class="font-label-md text-label-md text-on-surface font-bold mb-1"><?php echo htmlspecialchars($titre); ?></p>
        <div class="flex flex-col gap-1">
          <?php foreach ($versions as $v): ?>
          <div class="flex items-center justify-between gap-space-xs border border-outline-variant/50 rounded-lg p-space-xs">
            <span class="font-body-sm text-[12px] text-on-surface">Version <?php echo (int) $v['version']; ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y', strtotime($v['envoye_le'] ?: $v['created_at']))); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
(function () {
  var navItems = <?php echo json_encode(array_map(fn ($i) => ['label' => $i['label'], 'url' => $i['url']], array_filter($sidebarNav, fn ($i) => $i['url'] !== null)), JSON_UNESCAPED_UNICODE); ?>;
  var input = document.getElementById('dashSearch');
  var results = document.getElementById('dashSearchResults');
  if (input && results) {
    function render(query) {
      var q = query.trim().toLowerCase();
      var matches = q === '' ? navItems : navItems.filter(function (i) { return i.label.toLowerCase().indexOf(q) !== -1; });
      results.innerHTML = '';
      if (!matches.length) {
        results.classList.remove('is-open');
        return;
      }
      matches.forEach(function (i) {
        var a = document.createElement('a');
        a.href = i.url;
        a.className = 'px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors';
        a.textContent = i.label;
        results.appendChild(a);
      });
      results.classList.add('is-open');
    }
    input.addEventListener('focus', function () { render(input.value); });
    input.addEventListener('input', function () { render(input.value); });
    document.addEventListener('click', function (e) {
      if (!document.getElementById('dashSearchWrapper').contains(e.target)) { results.classList.remove('is-open'); }
    });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); input.focus(); input.select(); }
      if (e.key === 'Escape') { results.classList.remove('is-open'); input.blur(); }
    });
  }

  var avatarBtn = document.getElementById('avatarMenuBtn');
  var avatarMenu = document.getElementById('avatarMenu');
  if (avatarBtn && avatarMenu) {
    avatarBtn.addEventListener('click', function () { avatarMenu.classList.toggle('is-open'); });
    document.addEventListener('click', function (e) {
      if (!document.getElementById('avatarMenuWrapper').contains(e.target)) { avatarMenu.classList.remove('is-open'); }
    });
  }
})();
</script>
</body>
</html>
