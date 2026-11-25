<?php
/**
 * NexRaiz — « Mes opportunités » (Pack Classic). Offres recommandées
 * (score de correspondance), liens de recherche externes prêts à l'emploi,
 * conseils IA (ou repli sans IA), et suivi de candidature. Accès réservé
 * aux clients ayant un dossier Pack Classic actif ET le consentement
 * « matching » coché (voir classic-mon-profil-recherche.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/opportunites.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];
$dossierFerme = $dossier['statut'] === 'cloture';
$utilisateur = current_user();

$erreurCandidature = '';
$succesCandidature = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dossierFerme) {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $opportuniteId = (int) ($_POST['opportunite_id'] ?? 0);
    if ($opportuniteId > 0) {
        switch ($action) {
            case 'enregistrer':
                opportunites_enregistrer($opportuniteId, $dossierId);
                break;
            case 'masquer':
                opportunites_masquer($opportuniteId, $dossierId, trim($_POST['motif'] ?? ''));
                break;
            case 'demasquer':
                opportunites_demasquer($opportuniteId, $dossierId);
                break;
            case 'marquer_postule':
                opportunites_mettre_a_jour_statut($opportuniteId, $dossierId, 'postule');
                break;
            case 'maj_statut':
                opportunites_mettre_a_jour_statut($opportuniteId, $dossierId, $_POST['statut'] ?? 'non_postule', $_POST['statut_le'] ?? null, trim($_POST['notes'] ?? ''));
                break;
        }
    }
    header('Location: classic-mes-opportunites.php?vue=' . urlencode($_GET['vue'] ?? 'recommandees'));
    exit;
}

$vue = ($_GET['vue'] ?? 'recommandees') === 'suivi' ? 'suivi' : 'recommandees';
$profil = opportunites_profil_dossier($dossierId);
$aAccepte = opportunites_profil_actif($profil);

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNav = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'classic-tableau-bord.php'],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'classic-mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'classic-depot-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'classic-mes-livrables.php'],
    ['icone' => 'work', 'label' => 'Mes opportunités', 'url' => 'classic-mes-opportunites.php', 'actif' => true],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'classic-rendez-vous.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

$recommandees = [];
$candidatures = [];
$analyse = null;
$liensRecherche = [];

if ($aAccepte && !$dossierFerme) {
    $recommandees = opportunites_recommandees_pour_dossier($dossierId);
    // On ne retient pas les offres que le client a explicitement masquées.
    $recommandees = array_values(array_filter($recommandees, function ($r) use ($dossierId) {
        $interaction = opportunites_interaction($r['offre']['id'], $dossierId);
        return !($interaction && $interaction['masquee']);
    }));

    $texteCv = opportunites_texte_cv_dossier($dossierId);
    $texteLettreClient = opportunites_texte_lettre_dossier($dossierId);
    $analyse = opportunites_analyser_profil($dossierId, $profil, $texteCv, $texteLettreClient);
    $motsCles = !empty($analyse['mots_cles_fr']) ? $analyse['mots_cles_fr'] : $analyse['mots_cles_en'];
    $liensRecherche = opportunites_liens_recherche($motsCles, $profil['villes'][0] ?? null);
}
if ($aAccepte) {
    $candidatures = opportunites_candidatures_dossier($dossierId);
}

if ((int) ($_GET['candidature_envoyee'] ?? 0) > 0) {
    $succesCandidature = 'Votre candidature a bien été envoyée.';
}

// Offre affichée dans le panneau de détail : celle choisie dans la liste via
// ?offre=, sinon la première recommandation.
$offreActiveId = (int) ($_GET['offre'] ?? 0);
if ($offreActiveId <= 0 && !empty($recommandees)) {
    $offreActiveId = (int) $recommandees[0]['offre']['id'];
}

render_head('Pack Classic — Mes opportunités');
?>
<style>
#dashSearchResults{display:none;}
#dashSearchResults.is-open{display:flex;}
#avatarMenu{display:none;}
#avatarMenu.is-open{display:flex;}
@keyframes classic-float{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
.classic-float{animation:classic-float 4.5s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.classic-float{animation:none;}}
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
        <span class="text-on-surface font-semibold">Mes opportunités</span>
      </nav>

      <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#E5EEFF] via-[#F8F9FF] to-[#DCE9FF] p-space-lg md:p-space-xl grid grid-cols-1 lg:grid-cols-[1.2fr_1fr] gap-space-md items-center">
        <div class="absolute -bottom-20 -left-20 w-72 h-72 rounded-full bg-[#2563EB]/10 blur-3xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col gap-space-xs">
          <div class="w-14 h-14 rounded-2xl bg-white shadow-md flex items-center justify-center text-[#2563EB]"><span class="material-symbols-outlined text-[28px]">work</span></div>
          <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-[#13203F] font-bold tracking-tight pt-space-xs">Mes <span class="text-[#2563EB]">opportunités</span></h1>
          <p class="font-body-lg text-body-lg text-[#444653] max-w-md">Des offres adaptées à votre profil, et des liens pour chercher aussi ailleurs.</p>
          <div class="w-14 h-1 rounded-full bg-[#FFC700] mt-space-2xs"></div>
        </div>
        <div class="relative hidden lg:flex items-center justify-center h-56 w-full">
          <span class="absolute -top-2 left-0 max-w-[11rem] font-label-lg text-label-lg text-[#2563EB]/50 italic text-right leading-snug" style="font-family:Georgia,serif;">Saisissez les bonnes opportunités !</span>
          <img src="img_classic/professional_headshot_portrait_of_a_smiling_successful_young_african_woman.png" alt="Candidate Pack Classic" class="classic-float relative z-10 h-full w-auto object-cover rounded-2xl shadow-xl ml-auto" style="aspect-ratio:3/4">
        </div>
      </div>

      <div class="bg-surface-container-low rounded-xl p-space-sm">
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(OPPORTUNITES_DISCLAIMER); ?></p>
      </div>

      <?php if ($dossierFerme): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg text-center flex flex-col items-center gap-space-sm">
        <span class="material-symbols-outlined text-[36px] text-secondary">lock</span>
        <p class="font-label-lg text-label-lg text-on-surface font-bold">Votre dossier est clôturé</p>
        <p class="font-body-sm text-body-sm text-secondary max-w-md">« Mes opportunités » n'est plus modifiable sur un dossier clôturé. Contactez votre consultant si vous souhaitez le rouvrir.</p>
      </div>

      <?php elseif (!$aAccepte): ?>
      <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-xl text-center flex flex-col items-center gap-space-sm">
        <div class="relative w-24 h-24 mb-space-xs">
          <div class="absolute inset-0 rounded-full bg-gradient-to-br from-[#2563EB]/10 to-[#FFC700]/10"></div>
          <div class="absolute inset-3 rounded-full bg-gradient-to-br from-[#2563EB] to-[#13203F] shadow-lg flex items-center justify-center text-white"><span class="material-symbols-outlined text-[38px]">work</span></div>
          <div class="absolute -top-1 -right-1 w-9 h-9 rounded-full bg-[#FFC700] shadow-md flex items-center justify-center text-[#13203F]"><span class="material-symbols-outlined text-[18px]">search</span></div>
          <div class="absolute -bottom-1 -left-1 w-8 h-8 rounded-full bg-white shadow-md flex items-center justify-center text-[#2563EB]"><span class="material-symbols-outlined text-[16px]">description</span></div>
        </div>
        <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Activez « Mes opportunités »</p>
        <p class="font-body-sm text-body-sm text-secondary max-w-md">Déposez votre CV et acceptez que NexRaiz l'analyse pour vous proposer des offres adaptées — l'IA construit votre profil de recherche automatiquement, rien à saisir.</p>
        <a href="classic-mon-profil-recherche.php" class="h-12 px-space-xl rounded-full bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200"><span class="material-symbols-outlined text-[18px]">rocket_launch</span>Activer mes opportunités</a>

        <div class="w-full grid grid-cols-2 lg:grid-cols-4 gap-space-sm pt-space-lg mt-space-sm border-t border-outline-variant/40">
          <div class="flex flex-col items-center gap-space-2xs text-center px-space-xs">
            <span class="material-symbols-outlined text-[22px] text-[#2563EB]">track_changes</span>
            <p class="font-label-md text-label-md text-on-surface font-bold">Offres ciblées</p>
            <p class="font-body-sm text-[11px] text-secondary">Selon votre profil</p>
          </div>
          <div class="flex flex-col items-center gap-space-2xs text-center px-space-xs">
            <span class="material-symbols-outlined text-[22px] text-[#FFC700]">bolt</span>
            <p class="font-label-md text-label-md text-on-surface font-bold">Gain de temps</p>
            <p class="font-body-sm text-[11px] text-secondary">Trouvez plus vite des opportunités</p>
          </div>
          <div class="flex flex-col items-center gap-space-2xs text-center px-space-xs">
            <span class="material-symbols-outlined text-[22px] text-[#2563EB]">groups</span>
            <p class="font-label-md text-label-md text-on-surface font-bold">Accompagnement</p>
            <p class="font-body-sm text-[11px] text-secondary">Avec vos consultants NexRaiz</p>
          </div>
          <div class="flex flex-col items-center gap-space-2xs text-center px-space-xs">
            <span class="material-symbols-outlined text-[22px] text-[#FFC700]">trending_up</span>
            <p class="font-label-md text-label-md text-on-surface font-bold">Évolution professionnelle</p>
            <p class="font-body-sm text-[11px] text-secondary">Construisez votre avenir</p>
          </div>
        </div>
      </div>

      <?php else: ?>

      <?php if (isset($_GET['profil_enregistre'])): ?>
      <div class="bg-green-50 rounded-xl p-space-sm font-body-sm text-body-sm text-on-surface">Profil enregistré. Vos recommandations sont à jour.</div>
      <?php endif; ?>
      <?php if ($succesCandidature !== ''): ?>
      <div class="bg-green-50 rounded-xl p-space-sm font-body-sm text-body-sm text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-green-700">check_circle</span><?php echo htmlspecialchars($succesCandidature); ?></div>
      <?php endif; ?>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-space-sm">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-1">
          <span class="font-headline-md text-headline-md font-bold text-primary"><?php echo count($recommandees); ?></span>
          <span class="font-label-md text-label-md text-on-surface font-semibold">Recommandées</span>
        </div>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-1">
          <span class="font-headline-md text-headline-md font-bold text-on-surface"><?php echo count(array_filter($candidatures, fn($c) => (bool) $c['enregistre'])); ?></span>
          <span class="font-label-md text-label-md text-on-surface font-semibold">Enregistrées</span>
        </div>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-1">
          <span class="font-headline-md text-headline-md font-bold text-green-700"><?php echo count(array_filter($candidatures, fn($c) => $c['statut'] !== 'non_postule')); ?></span>
          <span class="font-label-md text-label-md text-on-surface font-semibold">Candidatures en cours</span>
        </div>
      </div>

      <div class="flex gap-space-xs border-b border-outline-variant/50">
        <a href="?vue=recommandees" class="px-space-md py-space-sm font-label-lg text-label-lg <?php echo $vue === 'recommandees' ? 'text-primary border-b-2 border-primary font-bold' : 'text-secondary'; ?>">Recommandées pour vous</a>
        <a href="?vue=suivi" class="px-space-md py-space-sm font-label-lg text-label-lg <?php echo $vue === 'suivi' ? 'text-primary border-b-2 border-primary font-bold' : 'text-secondary'; ?>">Suivi de candidature</a>
      </div>

      <?php if ($vue === 'recommandees'): ?>

      <?php if ($analyse): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[22px] text-primary">auto_awesome</span>
          <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Conseils pour votre profil</h2>
          <?php if ($analyse['source'] === 'secours'): ?>
          <span class="font-label-md text-[10px] uppercase tracking-wide text-secondary bg-surface-container-low px-space-xs py-0.5 rounded-full">Analyse simplifiée (sans IA)</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($analyse['conseils_candidature'])): ?>
        <ul class="flex flex-col gap-1">
          <?php foreach ($analyse['conseils_candidature'] as $conseil): ?>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check_circle</span><?php echo htmlspecialchars($conseil); ?></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="font-body-sm text-body-sm text-secondary">Complétez votre profil et déposez votre CV pour recevoir des conseils personnalisés.</p>
        <?php endif; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <h2 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="material-symbols-outlined text-[22px] text-primary">travel_explore</span>Chercher aussi sur…</h2>
        <div class="flex flex-wrap gap-space-xs">
          <?php foreach ($liensRecherche as $lien): ?>
          <a href="<?php echo htmlspecialchars($lien['url']); ?>" target="_blank" rel="noopener noreferrer" class="h-10 px-space-md rounded-full border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors">
            <span class="material-symbols-outlined text-[16px]">open_in_new</span><?php echo htmlspecialchars($lien['label']); ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (empty($recommandees)): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg text-center">
        <p class="font-body-sm text-body-sm text-secondary">Pas encore d'offre correspondante : utilisez les liens ci-dessus pour chercher dès maintenant, ou revenez plus tard — nous vous préviendrons dès qu'une offre adaptée sera publiée.</p>
      </div>
      <?php else:
        $offreActive = null;
        foreach ($recommandees as $r) {
            if ((int) $r['offre']['id'] === $offreActiveId) { $offreActive = $r; break; }
        }
        $offreActive = $offreActive ?: $recommandees[0];
        $offre = $offreActive['offre'];
        $interaction = opportunites_interaction($offre['id'], $dossierId);
        $palettes = [
            ['bg' => '#E5EEFF', 'text' => '#1E3E62'],
            ['bg' => '#FFF4CC', 'text' => '#8A6D00'],
            ['bg' => '#DCE9FF', 'text' => '#2563EB'],
        ];
        $typeMeta = [
            'emploi'     => ['icone' => 'work', 'label' => 'Emploi', 'bg' => '#DCE9FF', 'text' => '#1E40AF'],
            'stage'      => ['icone' => 'school', 'label' => 'Stage', 'bg' => '#FFF4CC', 'text' => '#8A6D00'],
            'alternance' => ['icone' => 'swap_horiz', 'label' => 'Alternance', 'bg' => '#EDE3FF', 'text' => '#5B21B6'],
            'mission'    => ['icone' => 'bolt', 'label' => 'Mission', 'bg' => '#DCFCE7', 'text' => '#15803D'],
        ];
        $tmActive = $typeMeta[$offre['type']] ?? $typeMeta['emploi'];
      ?>
      <div class="grid grid-cols-1 lg:grid-cols-[360px_1fr] gap-space-md items-start">

        <div class="flex flex-col gap-space-xs">
          <p class="font-label-md text-[11px] uppercase tracking-wide text-secondary font-bold px-space-2xs"><?php echo count($recommandees); ?> offre<?php echo count($recommandees) > 1 ? 's' : ''; ?></p>
          <div class="flex flex-col gap-space-xs lg:max-h-[760px] lg:overflow-y-auto lg:pr-space-2xs">
            <?php foreach ($recommandees as $r): $o = $r['offre']; $pal = $palettes[$o['id'] % 3]; $tm = $typeMeta[$o['type']] ?? $typeMeta['emploi']; $estActive = (int) $o['id'] === (int) $offre['id']; ?>
            <a href="?vue=recommandees&offre=<?php echo $o['id']; ?>#panneau-detail" class="relative overflow-hidden text-left rounded-2xl p-space-sm pl-space-md flex flex-col gap-space-2xs transition-all duration-150 <?php echo $estActive ? 'bg-[#EAF1FF] border-2 border-[#2563EB] shadow-md' : 'bg-surface-container-lowest border-2 border-transparent hover:border-outline-variant hover:shadow-md shadow-sm hover:-translate-y-0.5'; ?>">
              <span class="absolute left-0 top-0 bottom-0 w-1" style="background:<?php echo $tm['text']; ?>"></span>
              <div class="flex items-start justify-between gap-space-xs">
                <div class="flex items-center gap-space-2xs min-w-0">
                  <span class="w-9 h-9 rounded-full flex items-center justify-center font-label-md text-[12px] font-bold shrink-0" style="background:<?php echo $pal['bg']; ?>;color:<?php echo $pal['text']; ?>"><?php echo htmlspecialchars(opportunites_initiales_entreprise($o['entreprise'])); ?></span>
                  <div class="min-w-0">
                    <p class="font-label-md text-label-md text-on-surface font-bold leading-tight line-clamp-2"><?php echo htmlspecialchars($o['titre']); ?></p>
                    <p class="font-body-sm text-[11px] text-secondary truncate"><?php echo htmlspecialchars($o['entreprise'] ?: 'Entreprise non communiquée'); ?></p>
                  </div>
                </div>
                <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0" style="background:conic-gradient(#2563EB <?php echo $r['score'] * 3.6; ?>deg, #E2E8F0 0deg)">
                  <span class="w-6.5 h-6.5 rounded-full bg-surface-container-lowest flex items-center justify-center font-label-md text-[10px] font-bold text-on-surface"><?php echo $r['score']; ?>%</span>
                </div>
              </div>
              <div class="flex flex-wrap items-center gap-1 pl-space-2xs">
                <span class="font-label-md text-[10px] font-bold px-space-2xs py-0.5 rounded-full flex items-center gap-0.5" style="background:<?php echo $tm['bg']; ?>;color:<?php echo $tm['text']; ?>"><span class="material-symbols-outlined text-[12px]"><?php echo $tm['icone']; ?></span><?php echo htmlspecialchars($tm['label']); ?></span>
                <?php if ($o['ville']): ?><span class="font-body-sm text-[10px] text-secondary flex items-center gap-0.5"><span class="material-symbols-outlined text-[12px]">location_on</span><?php echo htmlspecialchars($o['ville']); ?></span><?php endif; ?>
              </div>
              <p class="font-body-sm text-[10px] text-secondary pl-space-2xs flex items-center gap-0.5"><span class="material-symbols-outlined text-[12px]">schedule</span><?php echo htmlspecialchars(opportunites_publication_relative($o['created_at'])); ?></p>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="panneau-detail" class="bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden flex flex-col gap-space-md scroll-mt-20">

          <div class="relative p-space-lg pb-space-md" style="background:linear-gradient(135deg,<?php echo $tmActive['bg']; ?>,#F8F9FF)">
            <div class="flex items-start justify-between gap-space-sm flex-wrap">
              <div class="flex items-start gap-space-sm min-w-0">
                <span class="w-14 h-14 rounded-2xl flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0 bg-white shadow-sm" style="color:<?php echo $palettes[$offre['id'] % 3]['text']; ?>"><?php echo htmlspecialchars(opportunites_initiales_entreprise($offre['entreprise'])); ?></span>
                <div class="min-w-0">
                  <span class="font-label-md text-[10px] font-bold px-space-xs py-0.5 rounded-full inline-flex items-center gap-0.5 bg-white/70 mb-1" style="color:<?php echo $tmActive['text']; ?>"><span class="material-symbols-outlined text-[13px]"><?php echo $tmActive['icone']; ?></span><?php echo htmlspecialchars($tmActive['label']); ?></span>
                  <h2 class="font-headline-sm text-headline-sm text-[#13203F] font-bold"><?php echo htmlspecialchars($offre['titre']); ?></h2>
                  <p class="font-body-sm text-body-sm text-[#444653]"><?php echo htmlspecialchars($offre['entreprise'] ?: 'Entreprise non communiquée'); ?><?php echo $offre['ville'] ? ' · ' . htmlspecialchars($offre['ville']) : ''; ?> · <?php echo htmlspecialchars(opportunites_publication_relative($offre['created_at'])); ?></p>
                  <?php if (str_starts_with($offre['titre'], '[DÉMO]')): ?>
                  <p class="font-body-sm text-[11px] text-[#8A6D00] bg-[#FFF4CC] w-fit px-space-xs py-0.5 rounded-full mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">info</span>Offre de démonstration (exemple fictif)</p>
                  <?php else: ?>
                  <p class="font-body-sm text-[11px] text-green-800 bg-green-50 w-fit px-space-xs py-0.5 rounded-full mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">verified</span>Offre publiée par votre consultant NexRaiz</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="px-space-lg flex flex-col gap-space-md">

          <div class="flex flex-wrap gap-1">
            <?php if ($offre['secteur']): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">category</span><?php echo htmlspecialchars($offre['secteur']); ?></span><?php endif; ?>
            <?php if ($offre['niveau_etudes_min']): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">school</span><?php echo htmlspecialchars($offre['niveau_etudes_min']); ?></span><?php endif; ?>
            <?php if ($offre['experience_min_annees']): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">work_history</span><?php echo (int) $offre['experience_min_annees']; ?> an(s) d'expérience</span><?php endif; ?>
            <?php if ($offre['teletravail'] !== 'non'): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">home_work</span><?php echo $offre['teletravail'] === 'total' ? 'Télétravail total' : 'Télétravail partiel'; ?></span><?php endif; ?>
          </div>

          <div class="flex flex-wrap gap-space-xs">
            <a href="classic-postuler-opportunite.php?offre=<?php echo $offre['id']; ?>" class="h-12 px-space-lg rounded-full text-white font-label-lg text-label-lg font-bold flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-150" style="background:linear-gradient(135deg,#2563EB,#13203F)"><span class="material-symbols-outlined text-[18px]">send</span>Postuler via NexRaiz</a>
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="opportunite_id" value="<?php echo $offre['id']; ?>">
              <input type="hidden" name="action" value="masquer">
              <button type="submit" class="h-12 px-space-md rounded-full border border-outline-variant font-label-lg text-label-lg text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">thumb_down</span>Pas pour moi</button>
            </form>
          </div>

          <div class="rounded-2xl p-space-md flex items-center gap-space-md" style="background:linear-gradient(135deg,#F8F9FF,#EAF1FF)">
            <div class="w-16 h-16 rounded-full flex items-center justify-center shrink-0" style="background:conic-gradient(#2563EB <?php echo $offreActive['score'] * 3.6; ?>deg, #E2E8F0 0deg)">
              <span class="w-12 h-12 rounded-full bg-surface-container-lowest flex items-center justify-center font-label-lg text-label-lg font-bold text-[#13203F]"><?php echo $offreActive['score']; ?>%</span>
            </div>
            <div>
              <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars(opportunites_libelle_score($offreActive['score'])); ?></p>
              <p class="font-body-sm text-[11px] text-secondary">Score calculé automatiquement à partir de votre profil</p>
            </div>
          </div>

          <div class="flex flex-col gap-space-sm">
            <p class="font-label-md text-label-md text-[#13203F] font-bold uppercase tracking-wide flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-[#2563EB]">work</span>Le poste</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-space-md gap-y-1">
              <?php if ($offre['niveau_etudes_min']): ?><p class="font-body-sm text-body-sm text-on-surface flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[16px] text-secondary">school</span>Niveau demandé : <strong><?php echo htmlspecialchars($offre['niveau_etudes_min']); ?></strong></p><?php endif; ?>
              <?php if ($offre['date_limite']): ?><p class="font-body-sm text-body-sm text-on-surface flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[16px] text-secondary">event</span>Date limite : <strong><?php echo htmlspecialchars(date('d/m/Y', strtotime($offre['date_limite']))); ?></strong></p><?php endif; ?>
              <?php if ($offre['salaire_indicatif']): ?><p class="font-body-sm text-body-sm text-on-surface flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[16px] text-secondary">payments</span><strong><?php echo htmlspecialchars($offre['salaire_indicatif']); ?></strong></p><?php endif; ?>
            </div>
            <p class="font-body-sm text-body-sm text-on-surface whitespace-pre-line pt-space-2xs"><?php echo nl2br(htmlspecialchars($offre['description'])); ?></p>
            <?php if (!empty($offre['competences_requises'])): ?>
            <div class="flex flex-wrap gap-1 pt-space-2xs">
              <?php foreach ($offre['competences_requises'] as $comp): ?>
              <span class="font-body-sm text-[11px] text-primary bg-primary-container/10 border border-primary-container/30 px-space-xs py-0.5 rounded-full"><?php echo htmlspecialchars($comp); ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <details class="font-body-sm text-[11px] text-secondary border-t border-outline-variant/40 pt-space-sm">
            <summary class="cursor-pointer text-primary font-label-md text-[12px] font-bold">Détail du score</summary>
            <ul class="pt-1 flex flex-col gap-0.5">
              <?php foreach ($offreActive['details'] as $d): ?>
              <li><?php echo htmlspecialchars($d['label']); ?> : <?php echo $d['obtenu']; ?>/<?php echo $d['sur']; ?></li>
              <?php endforeach; ?>
            </ul>
          </details>

          <div class="flex flex-wrap gap-space-xs pt-space-2xs pb-space-lg border-t border-outline-variant/40">
            <?php if ($offre['lien_candidature']): ?>
            <a href="<?php echo htmlspecialchars($offre['lien_candidature']); ?>" target="_blank" rel="noopener noreferrer" class="h-9 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 mt-space-sm"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Voir l'offre (site externe)</a>
            <?php endif; ?>
            <form method="post" class="inline mt-space-sm">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="opportunite_id" value="<?php echo $offre['id']; ?>">
              <?php if ($interaction && $interaction['enregistre']): ?>
              <input type="hidden" name="action" value="marquer_postule">
              <button type="submit" class="h-9 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">task_alt</span>J'ai postulé</button>
              <?php else: ?>
              <input type="hidden" name="action" value="enregistrer">
              <button type="submit" class="h-9 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">bookmark</span>Enregistrer</button>
              <?php endif; ?>
            </form>
          </div>

          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php else: // vue = suivi ?>

      <div class="flex flex-col gap-space-sm">
        <?php if (empty($candidatures)): ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg text-center">
          <p class="font-body-sm text-body-sm text-secondary">Aucune offre enregistrée ou suivie pour le moment. Enregistrez une offre recommandée pour la retrouver ici.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($candidatures as $c): ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <div class="flex items-start justify-between gap-space-sm flex-wrap">
            <div>
              <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($c['titre']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($c['entreprise'] ?: 'Entreprise non communiquée'); ?><?php echo $c['ville'] ? ' · ' . htmlspecialchars($c['ville']) : ''; ?><?php echo $c['statut_offre'] === 'expire' ? ' · Offre expirée' : ''; ?></p>
            </div>
            <?php if ($c['masquee']): ?>
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="opportunite_id" value="<?php echo $c['opportunite_id']; ?>">
              <input type="hidden" name="action" value="demasquer">
              <button type="submit" class="font-label-md text-[11px] text-primary underline">Réafficher cette offre</button>
            </form>
            <?php endif; ?>
          </div>
          <form method="post" class="flex flex-wrap items-end gap-space-sm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="opportunite_id" value="<?php echo $c['opportunite_id']; ?>">
            <input type="hidden" name="action" value="maj_statut">
            <div class="flex flex-col gap-1">
              <label class="font-label-md text-[11px] text-secondary">Statut</label>
              <select name="statut" class="h-10 px-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px]">
                <?php foreach (['non_postule' => 'Non postulé', 'postule' => 'Postulé', 'entretien' => 'Entretien', 'refus' => 'Refus', 'acceptee' => 'Acceptée'] as $val => $label): ?>
                <option value="<?php echo $val; ?>" <?php echo $c['statut'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex flex-col gap-1">
              <label class="font-label-md text-[11px] text-secondary">Date</label>
              <input type="date" name="statut_le" value="<?php echo htmlspecialchars($c['statut_le'] ?? ''); ?>" class="h-10 px-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px]">
            </div>
            <div class="flex-1 min-w-[180px] flex flex-col gap-1">
              <label class="font-label-md text-[11px] text-secondary">Notes</label>
              <input type="text" name="notes" value="<?php echo htmlspecialchars($c['notes'] ?? ''); ?>" class="h-10 px-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px] w-full">
            </div>
            <button type="submit" class="h-10 px-space-md rounded-full bg-primary-container text-white font-label-md text-label-md">Mettre à jour</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>

      <?php endif; ?>
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
