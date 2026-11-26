<?php
/**
 * NexRaiz — « Mes actions », Pack Classic. Espace de pilotage des tâches du
 * client sur `actions_plan` (table partagée avec les autres packs). Toute
 * action créée pour le Pack Classic est, par construction, à la charge du
 * client (consultant-corrections-classic.php ne crée jamais une action pour
 * lui-même) : la responsabilité de la prochaine étape se déduit donc
 * uniquement du statut, jamais de `proprietaire` (qui désigne en réalité le
 * consultant qui a assigné l'action, pas celui qui doit agir).
 *
 * Cycle de statut : a_faire/en_cours/a_venir (le client agit) -> a_verifier
 * (déclaré terminé par le client, jamais "termine" directement) -> termine
 * (validé par le consultant) ou retour à a_faire avec un motif si le
 * consultant demande une correction (voir consultant-corrections-classic.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];
$utilisateur = current_user();

$succes = '';
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $actionId = (int) ($_POST['action_id'] ?? 0);

    // L'action doit appartenir au dossier du client connecté — jamais une
    // confiance aveugle dans l'identifiant transmis par le formulaire.
    $stmt = db()->prepare('SELECT * FROM actions_plan WHERE id = ? AND dossier_id = ?');
    $stmt->execute([$actionId, $dossierId]);
    $actionCible = $stmt->fetch();

    if (!$actionCible) {
        $erreur = 'Action introuvable.';
    } elseif ($action === 'demarrer_action') {
        if ($actionCible['statut'] === 'a_faire' || $actionCible['statut'] === 'a_venir') {
            db()->prepare("UPDATE actions_plan SET statut = 'en_cours', statut_maj_le = NOW(), statut_maj_par = 'client' WHERE id = ?")->execute([$actionId]);
            $succes = 'Action démarrée.';
        } else {
            $erreur = "Cette action ne peut pas être démarrée dans son état actuel.";
        }
    } elseif ($action === 'terminer_action') {
        if (in_array($actionCible['statut'], ['a_faire', 'en_cours', 'a_venir'], true)) {
            db()->prepare("UPDATE actions_plan SET statut = 'a_verifier', motif_correction = NULL, statut_maj_le = NOW(), statut_maj_par = 'client' WHERE id = ?")->execute([$actionId]);
            log_activite($dossierId, 'Action déclarée terminée par le candidat : ' . $actionCible['titre']);
            $succes = 'Action soumise à votre consultant pour vérification.';
        } else {
            $erreur = "Cette action ne peut pas être marquée terminée dans son état actuel.";
        }
    } else {
        $erreur = 'Action non reconnue.';
    }

    if ($erreur === '') {
        $parametresConserves = array_merge($_GET, ['succes' => $succes]);
        unset($parametresConserves['action'], $parametresConserves['action_id']);
        header('Location: classic-mes-actions.php?' . http_build_query($parametresConserves));
        exit;
    }
}
if (isset($_GET['succes']) && $_GET['succes'] !== '') { $succes = $_GET['succes']; }

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNav = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'classic-tableau-bord.php'],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'classic-mes-actions.php', 'actif' => true],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'classic-depot-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'classic-mes-livrables.php'],
    ['icone' => 'work', 'label' => 'Mes opportunités', 'url' => 'classic-mes-opportunites.php'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'classic-rendez-vous.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

$stmt = db()->prepare('SELECT * FROM actions_plan WHERE dossier_id = ? ORDER BY (echeance IS NULL), echeance ASC, id DESC');
$stmt->execute([$dossierId]);
$toutesActions = $stmt->fetchAll();

$aujourdhui = strtotime('today');
foreach ($toutesActions as &$a) {
    $a['en_retard'] = in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true) && $a['echeance'] && strtotime($a['echeance']) < $aujourdhui;
    $a['echeance_proche'] = !$a['en_retard'] && in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true) && $a['echeance'] && strtotime($a['echeance']) <= strtotime('+3 days') && strtotime($a['echeance']) >= $aujourdhui;

    // Ressource liée, détectée par mots-clés dans le titre/la description —
    // aucune colonne ne lie une action à un document ou un rendez-vous dans
    // le modèle actuel, donc pas d'invention de structure, juste une
    // orientation indicative pour le client.
    $texteAnalyse = mb_strtolower($a['titre'] . ' ' . ($a['description'] ?? ''), 'UTF-8');
    if (preg_match('/livrable|rapport|consulter.*document.*corrig|t[eé]l[eé]charg/u', $texteAnalyse)) {
        $a['ressource_liee'] = ['label' => 'Voir mes livrables', 'url' => 'classic-mes-livrables.php', 'icone' => 'card_giftcard'];
    } elseif (preg_match('/document|cv\b|lettre|fichier|pi[eè]ce|justificatif|scan|photo/u', $texteAnalyse)) {
        $a['ressource_liee'] = ['label' => 'Déposer un document', 'url' => 'classic-depot-documents.php', 'icone' => 'upload_file'];
    } elseif (preg_match('/rendez.vous|rdv\b|entretien|simulation|cr[eé]neau|visio|appel/u', $texteAnalyse)) {
        $a['ressource_liee'] = ['label' => 'Aller à Rendez-vous', 'url' => 'classic-rendez-vous.php', 'icone' => 'event'];
    } else {
        $a['ressource_liee'] = null;
    }
}
unset($a);

// --- Indicateurs : calculés sur les données réelles, jamais codés en dur. ---
$compte = ['total' => count($toutesActions), 'a_faire' => 0, 'en_cours' => 0, 'a_venir' => 0, 'a_verifier' => 0, 'termine' => 0, 'en_retard' => 0];
foreach ($toutesActions as $a) {
    $compte[$a['statut']]++;
    if ($a['en_retard']) { $compte['en_retard']++; }
}

// --- Filtres / recherche / tri (GET, aucune action destructrice) ---
$filtre = $_GET['filtre'] ?? '';
$prioriteFiltre = $_GET['priorite'] ?? '';
$echeanceFiltre = $_GET['echeance'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? 'echeance';

$actionsAffichees = array_values(array_filter($toutesActions, function ($a) use ($filtre, $prioriteFiltre, $echeanceFiltre, $recherche) {
    if ($filtre === 'en_retard' && !$a['en_retard']) return false;
    if ($filtre !== '' && $filtre !== 'en_retard' && $a['statut'] !== $filtre) return false;
    if ($prioriteFiltre !== '' && $a['priorite'] !== $prioriteFiltre) return false;
    if ($echeanceFiltre === 'sans_echeance' && $a['echeance']) return false;
    if ($echeanceFiltre === 'avec_echeance' && !$a['echeance']) return false;
    if ($recherche !== '' && stripos($a['titre'] . ' ' . ($a['description'] ?? ''), $recherche) === false) return false;
    return true;
}));

if ($tri === 'priorite') {
    usort($actionsAffichees, fn ($a, $b) => ($b['priorite'] === 'haute') - ($a['priorite'] === 'haute'));
} elseif ($tri === 'recent') {
    usort($actionsAffichees, fn ($a, $b) => $b['id'] - $a['id']);
}
// Hiérarchie par défaut (tri "echeance") : en retard -> à réaliser prochainement -> en attente de validation -> terminées.
if ($tri === 'echeance') {
    $rang = fn ($a) => $a['en_retard'] ? 0 : ($a['statut'] === 'a_verifier' ? 2 : ($a['statut'] === 'termine' ? 3 : 1));
    usort($actionsAffichees, function ($a, $b) use ($rang) {
        $diff = $rang($a) - $rang($b);
        if ($diff !== 0) return $diff;
        if ($a['echeance'] && $b['echeance']) return strtotime($a['echeance']) - strtotime($b['echeance']);
        return $a['echeance'] ? -1 : ($b['echeance'] ? 1 : 0);
    });
}

$statutLabels = [
    'a_faire'    => ['À faire', 'bg-orange-100 text-orange-700'],
    'en_cours'   => ['En cours', 'bg-on-surface text-white'],
    'a_venir'    => ['Prévu', 'bg-green-100 text-green-700'],
    'a_verifier' => ['En attente de validation', 'bg-blue-100 text-blue-700'],
    'termine'    => ['Terminée', 'bg-green-100 text-green-700'],
];

function classic_action_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 3600) return "il y a moins d'une heure";
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    $jours = (int) floor($diff / 86400);
    return 'il y a ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
}

render_head('Pack Classic — Mes actions');
?>
<style>
#dashSearchResults{display:none;}
#dashSearchResults.is-open{display:flex;}
#avatarMenu{display:none;}
#avatarMenu.is-open{display:flex;}
</style>

<div class="flex w-full min-h-screen bg-surface">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs overflow-y-auto min-h-0">
    <a href="index.php" class="flex items-center gap-1.5 pb-space-xs">
      <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
    </a>
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
      <a href="classic-decouverte.php" class="mt-space-2xs h-9 rounded-lg bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-bold flex items-center justify-center gap-1 hover:opacity-90 transition-opacity"><span class="flex-1 text-center">Découvrir</span> <span class="material-symbols-outlined text-[14px] shrink-0">arrow_forward</span></a>
    </div>
    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <p class="font-label-lg text-label-lg font-bold px-space-xs">Besoin d'aide ?</p>
      <p class="font-body-sm text-[12px] text-inverse-on-surface/70 px-space-xs">Notre équipe est là pour vous accompagner.</p>
      <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Contacter mon consultant</a>
      <a href="logout.php" class="mx-space-xs h-10 rounded-xl flex items-center justify-center gap-1 font-label-md text-label-md text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px] shrink-0">logout</span><span>Déconnexion</span></a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center gap-space-md px-gutter-mobile lg:px-gutter-desktop">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <div class="hidden sm:block flex-1 max-w-md relative" id="dashSearchWrapper">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-secondary text-[20px] pointer-events-none">search</span>
        <input id="dashSearch" type="text" autocomplete="off" placeholder="Rechercher une fonctionnalité... (Ctrl+K)" class="w-full h-11 pl-10 pr-space-sm rounded-xl border border-outline-variant bg-surface-container-low font-body-sm text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 transition-shadow">
        <div id="dashSearchResults" class="absolute left-0 right-0 top-full mt-2 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-2xs flex-col z-50 max-h-72 overflow-y-auto"></div>
      </div>
      <div class="flex items-center gap-space-xs shrink-0 ml-auto">
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
    </header>
    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="classic-tableau-bord.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <a href="classic-tableau-bord.php" class="hover:text-primary transition-colors">Tableau de bord</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Mes actions</span>
      </nav>

      <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#E5EEFF] via-[#F8F9FF] to-[#DCE9FF] p-space-lg md:p-space-xl grid grid-cols-1 lg:grid-cols-[1.2fr_auto] gap-space-md items-center">
        <div class="absolute -bottom-20 -left-20 w-72 h-72 rounded-full bg-[#2563EB]/10 blur-3xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col gap-space-xs">
          <div class="w-14 h-14 rounded-2xl bg-white shadow-md flex items-center justify-center text-[#2563EB]"><span class="material-symbols-outlined text-[28px]">checklist</span></div>
          <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#13203F] font-bold tracking-tight pt-space-xs">Mes <span class="text-[#2563EB]">actions</span></h1>
          <p class="font-body-lg text-body-lg text-[#444653] max-w-md">Ce que vous devez faire, et où en est votre consultant.</p>
          <div class="w-14 h-1 rounded-full bg-[#FFC700] mt-space-2xs"></div>
        </div>
        <?php if ($compte['total'] > 0): ?>
        <div class="relative z-10 flex items-center gap-space-md bg-white/70 backdrop-blur rounded-2xl shadow-sm p-space-md">
          <div class="relative w-20 h-20 shrink-0">
            <div class="absolute inset-0 rounded-full" style="background:conic-gradient(#2563EB <?php echo round($compte['termine'] / $compte['total'] * 360); ?>deg, #DCE9FF 0deg)"></div>
            <div class="absolute inset-2 rounded-full bg-white flex items-center justify-center flex-col">
              <span class="font-headline-sm text-headline-sm text-[#13203F] font-bold"><?php echo round($compte['termine'] / $compte['total'] * 100); ?>%</span>
            </div>
          </div>
          <div>
            <p class="font-label-lg text-label-lg text-[#13203F] font-bold"><?php echo $compte['termine']; ?> / <?php echo $compte['total']; ?> terminées</p>
            <?php if ($compte['en_retard'] > 0): ?>
            <p class="font-body-sm text-[12px] text-error font-semibold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">warning</span><?php echo $compte['en_retard']; ?> en retard</p>
            <?php else: ?>
            <p class="font-body-sm text-[12px] text-secondary">Aucune action en retard</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($toutesActions): ?>
      <div class="flex justify-end">
        <a href="classic-mes-actions-export.php?<?php echo http_build_query(['filtre' => $filtre, 'priorite' => $prioriteFiltre, 'echeance' => $echeanceFiltre, 'q' => $recherche, 'tri' => $tri]); ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[16px]">download</span>Exporter</a>
      </div>
      <?php endif; ?>

      <?php if ($succes): ?><div class="bg-green-50 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div><?php endif; ?>
      <?php if ($erreur): ?><div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreur); ?></div><?php endif; ?>

      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-space-xs">
        <?php
        $cartes = [
            ['cle' => '', 'label' => 'Toutes', 'valeur' => $compte['total'], 'couleur' => 'text-on-surface', 'point' => 'bg-on-surface'],
            ['cle' => 'a_faire', 'label' => 'À réaliser', 'valeur' => $compte['a_faire'], 'couleur' => 'text-orange-600', 'point' => 'bg-orange-500'],
            ['cle' => 'en_cours', 'label' => 'En cours', 'valeur' => $compte['en_cours'], 'couleur' => 'text-primary', 'badge' => 'Actif'],
            ['cle' => 'a_verifier', 'label' => 'En attente', 'valeur' => $compte['a_verifier'], 'couleur' => 'text-blue-600', 'point' => 'bg-blue-500'],
            ['cle' => 'termine', 'label' => 'Terminées', 'valeur' => $compte['termine'], 'couleur' => 'text-green-600', 'point' => 'bg-green-600'],
            ['cle' => 'en_retard', 'label' => 'En retard', 'valeur' => $compte['en_retard'], 'couleur' => 'text-error', 'point' => 'bg-error'],
        ];
        foreach ($cartes as $c):
        ?>
        <a href="?filtre=<?php echo $c['cle']; ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-col gap-0.5 hover:shadow-md transition-shadow <?php echo $filtre === $c['cle'] ? 'ring-2 ring-primary' : ''; ?>">
          <div class="flex items-center justify-between">
            <span class="font-headline-sm text-headline-sm font-bold <?php echo $c['couleur']; ?>"><?php echo $c['valeur']; ?></span>
            <?php if (isset($c['badge'])): ?>
            <span class="px-space-xs py-0.5 rounded-full bg-primary-container/10 text-primary font-label-md text-[9px] font-bold"><?php echo $c['badge']; ?></span>
            <?php else: ?>
            <span class="w-2 h-2 rounded-full <?php echo $c['point']; ?>"></span>
            <?php endif; ?>
          </div>
          <span class="font-body-sm text-[11px] text-secondary uppercase tracking-wide"><?php echo htmlspecialchars($c['label']); ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-wrap items-end gap-space-sm">
        <?php if ($filtre !== ''): ?><input type="hidden" name="filtre" value="<?php echo htmlspecialchars($filtre); ?>"><?php endif; ?>
        <div class="flex flex-col gap-0.5 flex-1 min-w-[180px]">
          <label class="font-label-md text-[10px] text-primary font-bold uppercase tracking-wide">Recherche</label>
          <div class="relative">
            <span class="material-symbols-outlined text-[16px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Titre ou description..." class="w-full h-9 pl-space-2xl pr-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]" style="padding-left:2rem">
          </div>
        </div>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[10px] text-primary font-bold uppercase tracking-wide">Priorité</label>
          <select name="priorite" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">Toutes</option>
            <option value="haute" <?php echo $prioriteFiltre === 'haute' ? 'selected' : ''; ?>>Haute</option>
            <option value="normale" <?php echo $prioriteFiltre === 'normale' ? 'selected' : ''; ?>>Normale</option>
          </select>
        </div>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[10px] text-primary font-bold uppercase tracking-wide">Échéance</label>
          <select name="echeance" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">Toutes</option>
            <option value="avec_echeance" <?php echo $echeanceFiltre === 'avec_echeance' ? 'selected' : ''; ?>>Avec échéance</option>
            <option value="sans_echeance" <?php echo $echeanceFiltre === 'sans_echeance' ? 'selected' : ''; ?>>Sans échéance</option>
          </select>
        </div>
        <div class="flex flex-col gap-0.5">
          <label class="font-label-md text-[10px] text-primary font-bold uppercase tracking-wide">Tri</label>
          <select name="tri" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="echeance" <?php echo $tri === 'echeance' ? 'selected' : ''; ?>>Urgence (recommandé)</option>
            <option value="priorite" <?php echo $tri === 'priorite' ? 'selected' : ''; ?>>Priorité</option>
            <option value="recent" <?php echo $tri === 'recent' ? 'selected' : ''; ?>>Plus récent</option>
          </select>
        </div>
        <button type="submit" class="h-9 px-space-md rounded-lg bg-on-surface text-white font-label-md text-[13px]">Filtrer</button>
        <?php if ($recherche || $prioriteFiltre || $echeanceFiltre || $filtre || $tri !== 'echeance'): ?>
        <a href="classic-mes-actions.php" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[13px] text-secondary flex items-center">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="flex flex-col gap-space-sm">
        <?php if (!$toutesActions): ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-2xl flex flex-col items-center text-center gap-space-xs">
          <span class="material-symbols-outlined text-[40px] text-primary">task_alt</span>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Aucune action pour le moment</p>
          <p class="font-body-sm text-body-sm text-secondary max-w-sm">Votre consultant vous assignera des actions au fil de votre accompagnement. Rien à faire de votre côté pour l'instant.</p>
        </div>
        <?php elseif (!$actionsAffichees): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucune action ne correspond à ces critères.</p>
        <?php elseif ($compte['total'] === $compte['termine']): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-space-md flex items-center gap-space-sm">
          <span class="material-symbols-outlined text-[24px] text-green-700">celebration</span>
          <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Toutes vos actions sont terminées</p><p class="font-body-sm text-body-sm text-secondary">Votre consultant vous informera dès qu'une nouvelle action sera nécessaire.</p></div>
        </div>
        <?php endif; ?>

        <?php foreach ($actionsAffichees as $a):
          [$labelStatut, $classeStatut] = $statutLabels[$a['statut']];
          $peutAgir = in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true);
        ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-space-xs <?php echo $a['en_retard'] ? 'border-l-4 border-error' : ''; ?>">
          <div class="flex items-start justify-between gap-space-sm flex-wrap">
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-1 flex-wrap font-body-sm text-[11px] text-secondary">
                <?php if ($a['en_retard']): ?><span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-error text-white">En retard</span><?php endif; ?>
                <?php if ($a['echeance_proche']): ?><span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold bg-orange-100 text-orange-700">Échéance proche</span><?php endif; ?>
                <?php if ($a['en_retard'] || $a['echeance_proche']): ?>·<?php endif; ?>
                <span>Priorité <?php echo $a['priorite'] === 'haute' ? 'haute' : 'normale'; ?></span>
              </div>
              <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($a['titre']); ?></p>
              <?php if ($a['description']): ?><p class="font-body-sm text-[13px] text-secondary mt-0.5"><?php echo htmlspecialchars(mb_strimwidth($a['description'], 0, 140, '…')); ?></p><?php endif; ?>
            </div>
            <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
          </div>

          <div class="flex items-center gap-space-md flex-wrap font-body-sm text-[12px] text-secondary">
            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">calendar_today</span><?php echo $a['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : 'Sans échéance'; ?></span>
            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">person</span><?php echo $a['statut'] === 'a_verifier' ? 'En attente de votre consultant' : ($a['statut'] === 'termine' ? 'Terminée' : 'À vous de jouer'); ?></span>
          </div>

          <?php if ($a['statut'] === 'a_verifier'): ?>
          <p class="font-body-sm text-[12px] text-blue-700 bg-blue-50 rounded-lg px-space-sm py-space-2xs flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">hourglass_top</span>Vous avez déclaré cette action terminée — votre consultant doit encore la valider.</p>
          <?php elseif ($a['motif_correction']): ?>
          <p class="font-body-sm text-[12px] text-orange-700 bg-orange-50 rounded-lg px-space-sm py-space-2xs flex items-start gap-1"><span class="material-symbols-outlined text-[14px] mt-0.5">info</span>Votre consultant a demandé une correction : « <?php echo htmlspecialchars($a['motif_correction']); ?> »</p>
          <?php endif; ?>

          <?php if ($peutAgir): ?>
          <div class="flex items-center justify-between gap-space-xs flex-wrap pt-space-2xs">
            <div class="flex items-center gap-space-xs flex-wrap">
              <button type="button" onclick="var m=document.getElementById('fiche-<?php echo $a['id']; ?>');m.classList.remove('hidden');m.classList.add('flex');" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">visibility</span>Voir le détail</button>
              <?php if ($a['statut'] !== 'en_cours'): ?>
              <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="demarrer_action"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                <button type="submit" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">play_arrow</span>Démarrer</button>
              </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Confirmer que cette action est terminée ? Elle sera soumise à votre consultant pour vérification.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="terminer_action"><input type="hidden" name="action_id" value="<?php echo $a['id']; ?>">
                <button type="submit" class="h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check</span>J'ai terminé</button>
              </form>
              <?php if ($a['ressource_liee']): ?>
              <a href="<?php echo htmlspecialchars($a['ressource_liee']['url']); ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]"><?php echo $a['ressource_liee']['icone']; ?></span><?php echo htmlspecialchars($a['ressource_liee']['label']); ?></a>
              <?php endif; ?>
            </div>
            <?php if ($a['statut_maj_le']): ?>
            <span class="font-body-sm text-[11px] text-secondary italic shrink-0">Dernière modification <?php echo classic_action_il_y_a($a['statut_maj_le']); ?></span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <button type="button" onclick="var m=document.getElementById('fiche-<?php echo $a['id']; ?>');m.classList.remove('hidden');m.classList.add('flex');" class="self-start font-label-md text-[12px] text-primary flex items-center gap-1 pt-space-2xs">Voir les détails de l'action <span class="material-symbols-outlined text-[14px]">chevron_right</span></button>
          <?php endif; ?>
        </div>

        <div id="fiche-<?php echo $a['id']; ?>" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-gutter-mobile" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');}">
          <div class="bg-surface-container-lowest rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden">
            <div class="flex items-center justify-between px-space-lg py-space-md border-b border-outline-variant/50 shrink-0">
              <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Détail de l'action</h3>
              <button type="button" onclick="var m=document.getElementById('fiche-<?php echo $a['id']; ?>');m.classList.add('hidden');m.classList.remove('flex');" class="w-9 h-9 rounded-full flex items-center justify-center text-secondary hover:bg-surface-container-low"><span class="material-symbols-outlined text-[20px]">close</span></button>
            </div>
            <div class="flex-1 overflow-y-auto p-space-lg flex flex-col gap-space-sm">
              <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo htmlspecialchars($a['titre']); ?></p>
              <span class="self-start px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
              <?php if ($a['description']): ?>
              <div><p class="font-label-md text-[11px] uppercase tracking-wide text-secondary font-bold">Instructions</p><p class="font-body-sm text-body-sm text-on-surface whitespace-pre-line"><?php echo htmlspecialchars($a['description']); ?></p></div>
              <?php endif; ?>
              <div class="grid grid-cols-2 gap-space-xs">
                <div><p class="font-label-md text-[11px] uppercase tracking-wide text-secondary font-bold">Dossier</p><p class="font-body-sm text-[13px] text-on-surface"><?php echo htmlspecialchars($dossier['numero']); ?></p></div>
                <div><p class="font-label-md text-[11px] uppercase tracking-wide text-secondary font-bold">Échéance</p><p class="font-body-sm text-[13px] text-on-surface"><?php echo $a['echeance'] ? htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : 'Sans échéance'; ?></p></div>
                <div><p class="font-label-md text-[11px] uppercase tracking-wide text-secondary font-bold">Priorité</p><p class="font-body-sm text-[13px] text-on-surface"><?php echo $a['priorite'] === 'haute' ? 'Haute' : 'Normale'; ?></p></div>
                <div><p class="font-label-md text-[11px] uppercase tracking-wide text-secondary font-bold">Prochaine étape</p><p class="font-body-sm text-[13px] text-on-surface"><?php echo $a['statut'] === 'a_verifier' ? 'Validation par votre consultant' : ($a['statut'] === 'termine' ? 'Aucune — terminée' : 'À réaliser par vous'); ?></p></div>
              </div>
              <?php if ($a['motif_correction']): ?>
              <div class="bg-orange-50 rounded-lg p-space-sm"><p class="font-label-md text-[11px] text-orange-700 font-bold">Commentaire de votre consultant</p><p class="font-body-sm text-[13px] text-on-surface">« <?php echo htmlspecialchars($a['motif_correction']); ?> »</p></div>
              <?php endif; ?>
              <div class="flex items-center gap-space-xs flex-wrap pt-space-xs border-t border-outline-variant/40">
                <?php if ($a['ressource_liee']): ?>
                <a href="<?php echo htmlspecialchars($a['ressource_liee']['url']); ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]"><?php echo $a['ressource_liee']['icone']; ?></span><?php echo htmlspecialchars($a['ressource_liee']['label']); ?></a>
                <?php endif; ?>
                <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">chat</span>Demander une précision</a>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($toutesActions): ?>
      <div class="bg-primary-container/5 border border-primary-container/20 rounded-xl p-space-lg flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-sm">
        <div class="flex items-start gap-space-sm">
          <span class="material-symbols-outlined text-[20px] text-primary mt-0.5">support_agent</span>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Besoin d'aide pour finaliser une tâche ?</p>
            <p class="font-body-sm text-[13px] text-secondary">Votre consultant référent est disponible par messagerie ou lors de votre prochain point d'étape.</p>
          </div>
        </div>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1">Contacter mon consultant</a>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
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
