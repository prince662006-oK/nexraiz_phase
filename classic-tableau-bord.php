<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/documents-classic.php';
require_once __DIR__ . '/includes/livrables-classic.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];
$utilisateur = current_user();

// Le prochain rendez-vous doit être réellement à venir et non annulé — pas
// simplement le premier créé (bug corrigé : un RDV passé ou annulé pouvait
// s'afficher comme "prochain").
$stmt = db()->prepare(
    "SELECT date_heure, format FROM rendezvous
     WHERE dossier_id = ? AND date_heure >= NOW() AND statut IN ('planifie', 'confirme')
     ORDER BY date_heure ASC LIMIT 1"
);
$stmt->execute([$dossierId]);
$rdv = $stmt->fetch();
$stmt = db()->prepare("SELECT COUNT(*) FROM rendezvous WHERE dossier_id = ? AND statut = 'annule'");
$stmt->execute([$dossierId]);
$rdvAnnulesCount = (int) $stmt->fetchColumn();
// "Prise de rendez-vous" est acquise dès qu'un rendez-vous non annulé existe
// (passé ou à venir) — distinct de $rdv qui ne retient que le prochain RDV
// à venir, utilisé pour la carte statistique.
$stmt = db()->prepare("SELECT 1 FROM rendezvous WHERE dossier_id = ? AND statut != 'annule'");
$stmt->execute([$dossierId]);
$aEuRdvValide = (bool) $stmt->fetchColumn();
$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir'];

// Documents attendus pour le Pack Classic : même configuration que
// classic-depot-documents.php (includes/documents-classic.php), seule
// source de vérité — plus de détection par motif de chemin. "Documents
// transmis" compte les slots ayant une version courante, jamais les
// versions successives d'une même pièce (pas de double comptage).
$slotsFichiersLabels = array_map(fn ($s) => $s['label'], classic_slots_documents());
$slotsObligatoires = array_keys(array_filter(classic_slots_documents(), fn ($s) => $s['obligatoire']));
$documentsParSlot = classic_documents_par_slot($dossierId);
$documentsManquants = array_values(array_filter($slotsObligatoires, fn ($s) => $documentsParSlot[$s]['actuel'] === null));
$documentsObligatoiresComplets = !$documentsManquants;
$docsTransmis = array_values(array_filter(array_map(fn ($d) => $d['actuel'], $documentsParSlot)));
usort($docsTransmis, fn ($a, $b) => strtotime($b['uploaded_at']) - strtotime($a['uploaded_at']));
$documentsACorrigerCount = count(array_filter($docsTransmis, fn ($d) => $d['statut'] === 'a_corriger'));

$actions = db()->prepare('SELECT titre, proprietaire, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC');
$actions->execute([$dossierId]);
$actions = $actions->fetchAll();
$actionsEnCours = array_values(array_filter($actions, fn ($a) => $a['statut'] !== 'termine'));

function nx_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

// Le client ne voit jamais un livrable "en_preparation" — même son titre
// reste invisible tant qu'il n'est pas explicitement envoyé par le
// consultant. Même source que classic-mes-livrables.php, pour des
// compteurs cohérents entre les deux pages.
$livrablesDisponibles = classic_livrables_du_dossier($dossierId, ['disponible']);

// Liste blanche des événements que le client est autorisé à voir —
// mécanisme identique à celui du Pack Assistance (tableau-bord.php),
// adapté aux textes réellement écrits par log_activite() côté Pack Classic.
// Tout le reste (notes internes, changements de statut technique...) reste
// invisible pour le client même si l'activité existe déjà en base.
$prefixesActivitesClient = [
    'Dossier créé', 'Qualification du besoin complétée', 'Document transmis',
    'Rendez-vous planifié', 'Simulation d\'entretien planifiée', 'Charte de consentement approuvée',
    'Virement bancaire choisi', 'Justificatif de virement déposé', 'Paiement confirmé',
    'Nouvelle action ajoutée', 'Action validée par le consultant', 'Action renvoyée en correction',
    'Livrable créé (en préparation)', 'Livrable mis à jour',
    'Livrable envoyé au candidat', 'Corrections validées par le consultant',
];
$stmt = db()->prepare('SELECT texte, created_at FROM activites WHERE dossier_id = ? ORDER BY id DESC LIMIT 30');
$stmt->execute([$dossierId]);
$activitesBrutes = array_filter($stmt->fetchAll(), function ($a) use ($prefixesActivitesClient) {
    foreach ($prefixesActivitesClient as $prefixe) {
        if (str_starts_with($a['texte'], $prefixe)) return true;
    }
    return false;
});
$activitesBrutes = array_slice($activitesBrutes, 0, 8);
$activites = array_map(fn ($a) => ['icone' => 'circle_notifications', 'texte' => $a['texte'], 'sous_texte' => '', 'quand' => nx_il_y_a($a['created_at'])], $activitesBrutes);

$stmt = db()->prepare('SELECT 1 FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualificationFaite = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT accepte FROM consentements WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$consentementValide = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT id, statut, methode, justificatif_nom, justificatif_chemin FROM paiements WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId]);
$paiement = $stmt->fetch();
$paiementFait = ($paiement['statut'] ?? '') === 'paye';
$parcoursTermine = $dossier['statut'] === 'cloture';

$erreurJustificatif = '';
$justificatifEnvoye = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_justificatif_virement') {
    require_csrf();
    if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente') {
        $erreurJustificatif = nx_enregistrer_justificatif_virement((int) $paiement['id'], $dossierId, 'classic');
        if ($erreurJustificatif === '') {
            $justificatifEnvoye = true;
            $paiement['justificatif_nom'] = $_FILES['justificatif']['name'] ?? $paiement['justificatif_nom'];
            $paiement['justificatif_chemin'] = $paiement['justificatif_chemin'] ?: 'envoye';
        }
    }
}

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

// --- Prochaine étape : calculée en cascade sur l'état réel du dossier, pas
// sur une liste codée en dur. On ne redemande jamais une action déjà faite.
$prochaineEtape = null;
if (!$qualificationFaite) {
    $prochaineEtape = ['titre' => 'Compléter votre qualification', 'texte' => 'Décrivez votre besoin pour que nous puissions démarrer votre accompagnement.', 'url' => 'classic-qualification-besoin.php', 'bouton' => 'Compléter', 'priorite' => true];
} elseif (!$documentsObligatoiresComplets) {
    $labelsManquants = array_map(fn ($s) => $slotsFichiersLabels[$s], $documentsManquants);
    $prochaineEtape = ['titre' => 'Déposer vos documents manquants', 'texte' => 'Il manque : ' . implode(', ', $labelsManquants) . '.', 'url' => 'classic-depot-documents.php', 'bouton' => 'Déposer', 'priorite' => true];
} elseif ($documentsACorrigerCount > 0) {
    $prochaineEtape = ['titre' => 'Corriger ' . ($documentsACorrigerCount > 1 ? 'des documents' : 'un document'), 'texte' => 'Votre consultant a demandé une correction sur ' . $documentsACorrigerCount . ' document(s).', 'url' => 'classic-depot-documents.php?vue=a_corriger', 'bouton' => 'Corriger', 'priorite' => true];
} elseif (!$consentementValide) {
    $prochaineEtape = ['titre' => 'Donner votre consentement', 'texte' => 'Validez la charte de consentement et de confidentialité pour continuer.', 'url' => 'classic-consentement.php', 'bouton' => 'Consulter', 'priorite' => true];
} elseif (!$aEuRdvValide && !$parcoursTermine) {
    $prochaineEtape = ['titre' => $rdvAnnulesCount > 0 ? 'Reprogrammer votre rendez-vous' : 'Planifier votre rendez-vous', 'texte' => $rdvAnnulesCount > 0 ? 'Votre rendez-vous précédent a été annulé — choisissez un nouveau créneau.' : 'Choisissez un créneau pour votre premier échange avec votre consultant.', 'url' => 'classic-rendez-vous.php', 'bouton' => 'Planifier', 'priorite' => true];
} elseif (!$paiementFait && !$parcoursTermine) {
    if (($paiement['statut'] ?? '') === 'echoue') {
        $prochaineEtape = ['titre' => 'Votre paiement a échoué', 'texte' => 'Merci de réessayer avec un autre moyen de paiement.', 'url' => 'classic-paiement.php', 'bouton' => 'Réessayer', 'priorite' => true];
    } elseif (($paiement['methode'] ?? '') === 'virement' && !empty($paiement['justificatif_chemin'])) {
        $prochaineEtape = ['titre' => 'Justificatif reçu', 'texte' => 'Votre justificatif de virement est en attente de validation par notre équipe. Aucune action de votre part n\'est nécessaire.', 'url' => null, 'bouton' => null, 'priorite' => false];
    } elseif (($paiement['methode'] ?? '') === 'virement') {
        $prochaineEtape = ['titre' => 'Virement en attente', 'texte' => 'Déposez votre justificatif de virement ci-dessous pour accélérer la validation.', 'url' => null, 'bouton' => null, 'priorite' => true];
    } elseif ($paiement) {
        $prochaineEtape = ['titre' => 'Paiement en attente de confirmation', 'texte' => 'Votre paiement est en cours de traitement par notre prestataire. Cela peut prendre quelques minutes.', 'url' => null, 'bouton' => null, 'priorite' => false];
    } else {
        $prochaineEtape = ['titre' => 'Effectuer le paiement', 'texte' => 'Finalisez votre commande pour démarrer le traitement de votre dossier.', 'url' => 'classic-paiement.php', 'bouton' => 'Payer', 'priorite' => true];
    }
} elseif (!$parcoursTermine && $livrablesDisponibles) {
    $prochaineEtape = ['titre' => 'Consultez vos livrables', 'texte' => count($livrablesDisponibles) . ' livrable(s) disponible(s) à télécharger.', 'url' => 'classic-mes-livrables.php', 'bouton' => 'Voir', 'priorite' => false];
} elseif (!$parcoursTermine) {
    $prochaineEtape = ['titre' => 'Votre dossier est en cours de traitement', 'texte' => 'Votre consultant travaille sur vos documents. Vous serez notifié dès qu\'un livrable sera disponible.', 'url' => null, 'bouton' => null, 'priorite' => false];
} else {
    $prochaineEtape = ['titre' => 'Votre accompagnement est terminé', 'texte' => 'Tous vos livrables restent accessibles à tout moment.', 'url' => 'classic-mes-livrables.php', 'bouton' => 'Voir mes livrables', 'priorite' => false];
}

$sidebarNav = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'classic-tableau-bord.php', 'actif' => true],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'classic-mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'classic-depot-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'classic-mes-livrables.php'],
    ['icone' => 'work', 'label' => 'Mes opportunités', 'url' => 'classic-mes-opportunites.php'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'classic-rendez-vous.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

// Chaque étape a une condition métier réelle et explicite. Les deux
// premières sont vraies par construction : on ne peut pas afficher cette
// page sans un compte authentifié propriétaire d'un dossier Pack Classic
// (require_dossier() plus haut). "Dépôt des documents" exige le CV (seul
// document obligatoire) ET qu'aucune correction ne soit en attente. "Prise
// de rendez-vous" exige un RDV déjà passé/confirmé/à venir (pas seulement un
// RDV annulé). L'étape finale reflète dossiers.statut = 'cloture', le même
// signal que celui posé par le consultant pour clôturer un dossier Pack
// Classic (consultant-corrections-classic.php, action "valider_corrections").
$etapesBase = [
    ['titre' => 'Découverte du Pack Classic', 'fait' => true],
    ['titre' => "Création de l'espace candidat", 'fait' => true],
    ['titre' => 'Qualification du besoin', 'fait' => $qualificationFaite],
    ['titre' => 'Dépôt des documents', 'fait' => $documentsObligatoiresComplets && $documentsACorrigerCount === 0],
    ['titre' => 'Prise de rendez-vous', 'fait' => $aEuRdvValide],
    ['titre' => 'Consentement et confidentialité', 'fait' => $consentementValide],
    ['titre' => 'Paiement', 'fait' => $paiementFait],
    ['titre' => 'Parcours terminé', 'fait' => $parcoursTermine],
];
$premierePasFaite = false;
$avancement = [];
foreach ($etapesBase as $e) {
    if ($e['fait']) {
        $statut = 'done';
    } elseif (!$premierePasFaite) {
        $statut = 'current';
        $premierePasFaite = true;
    } else {
        $statut = 'pending';
    }
    $avancement[] = ['titre' => $e['titre'], 'statut' => $statut];
}
$nbEtapesFaites = count(array_filter($avancement, fn ($e) => $e['statut'] === 'done'));
$libelleEtapeBadge = $parcoursTermine ? 'Parcours terminé' : 'Étape ' . min($nbEtapesFaites + 1, 8) . ' sur 8';

render_head('Pack Classic — Tableau de bord candidat');
?>
<style>
main{overflow-x:hidden;}
/* Tableau de bord : contenu visible immédiatement, pas d'animation
   d'apparition au scroll (gênante sur une page qu'on consulte pour ses
   données, pas pour l'expérience — et source d'un rendu "vide/délavé"
   quand la transition n'a pas eu le temps de se terminer). */
.reveal-up,.reveal-scale,.reveal-left,.reveal-right{opacity:1;transform:none;}
@keyframes dash-pulse{0%,100%{box-shadow:0 0 0 0 rgba(37,99,235,.3);}50%{box-shadow:0 0 0 6px rgba(37,99,235,0);}}
.dash-pulse{animation:dash-pulse 2.4s ease-in-out infinite;}
@keyframes classic-float{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
.classic-float{animation:classic-float 4.5s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.dash-pulse,.classic-float{animation:none;}}
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
    <?php if ($item['url'] === null): ?>
    <span class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl text-inverse-on-surface/40 cursor-not-allowed" title="Bientôt disponible">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
    </span>
    <?php else: ?>
    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo !empty($item['actif']) ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
      <?php if (!empty($item['badge'])): ?>
      <span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $item['badge']; ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
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
      <a href="contact.php" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Nous contacter</a>
      <a href="logout.php" class="mx-space-xs h-10 rounded-xl flex items-center justify-center gap-1 font-label-md text-label-md text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px]">logout</span>Déconnexion</a>
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
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="flex flex-col gap-space-md">
        <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
          <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
          <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
          <a href="classic-decouverte.php" class="hover:text-primary transition-colors">Pack Classic</a>
          <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
          <span class="text-on-surface font-semibold">Parcours client</span>
        </nav>
        <div class="reveal-up is-visible relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0B192C] via-[#13203F] to-[#1E3E62] p-space-lg md:p-space-xl grid grid-cols-1 lg:grid-cols-[1.3fr_1fr] gap-space-md items-center">
          <div class="absolute -top-16 -right-16 w-72 h-72 rounded-full bg-[#FFC700]/10 blur-3xl pointer-events-none"></div>
          <div class="relative z-10 flex flex-col gap-space-xs">
            <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-bold"><?php echo htmlspecialchars($libelleEtapeBadge); ?></span>
            <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-white font-bold tracking-tight">Bonjour, <?php echo htmlspecialchars($utilisateur['prenom']); ?> 👋</h1>
            <p class="font-body-lg text-body-lg text-white/70 max-w-md">Gérez facilement votre parcours et accédez à tous vos services NexRaiz.</p>
            <div class="flex flex-wrap items-center gap-space-sm pt-space-xs">
              <?php if ($prochaineEtape['url']): ?>
              <a href="<?php echo htmlspecialchars($prochaineEtape['url']); ?>" class="h-11 px-space-lg rounded-xl bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg flex items-center justify-center gap-1 hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200"><?php echo htmlspecialchars($prochaineEtape['bouton']); ?> <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
              <?php endif; ?>
              <a href="classic-decouverte.php" class="h-11 px-space-lg rounded-xl border border-white/30 text-white font-label-lg text-label-lg flex items-center justify-center gap-2 hover:bg-white/10 transition-colors">Revoir le Pack Classic</a>
            </div>
          </div>
          <div class="relative hidden lg:flex items-end justify-center h-56">
            <span class="absolute top-2 right-4 font-headline-sm text-headline-sm text-white/30 italic text-right leading-snug" style="font-family:Georgia,serif;">Ensemble,<br>vers plus de succès !</span>
            <img src="img_classic/full-body-cutout-transparent.png" alt="Candidate accompagnée par NexRaiz" class="classic-float relative z-10 h-full w-auto object-contain drop-shadow-2xl">
          </div>
        </div>
      </div>
    </section>

    <?php if ($paiement && $paiement['statut'] === 'echoue'): ?>
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="bg-error-container/30 border border-error/30 rounded-xl p-space-md flex flex-col sm:flex-row items-start gap-space-sm">
        <div class="flex items-start gap-space-sm flex-1 w-full">
          <span class="material-symbols-outlined text-error text-[22px]">error</span>
          <div class="flex-1">
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Votre paiement a échoué</p>
            <p class="font-body-sm text-body-sm text-secondary">Aucun montant n'a été débité. Merci de réessayer avec le même moyen de paiement ou un autre.</p>
          </div>
        </div>
        <a href="classic-paiement.php" class="shrink-0 w-full sm:w-auto h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center">Réessayer</a>
      </div>
    </section>
    <?php elseif ($paiement && $paiement['statut'] === 'rembourse'): ?>
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="bg-surface-container-low border border-outline-variant/60 rounded-xl p-space-md flex items-start gap-space-sm">
        <span class="material-symbols-outlined text-secondary text-[22px]">undo</span>
        <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Paiement remboursé</p><p class="font-body-sm text-body-sm text-secondary">Ce paiement a été remboursé par notre équipe.</p></div>
      </div>
    </section>
    <?php elseif ($paiement && $paiement['statut'] === 'en_attente' && $paiement['methode'] !== 'virement'): ?>
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="bg-orange-50 border border-orange-200 rounded-xl p-space-md flex items-start gap-space-sm">
        <span class="material-symbols-outlined text-orange-600 text-[22px]">hourglass_top</span>
        <div><p class="font-label-lg text-label-lg text-on-surface font-bold">Paiement en attente de confirmation</p><p class="font-body-sm text-body-sm text-secondary">Votre paiement est en cours de traitement par notre prestataire. Cela peut prendre quelques minutes — rechargez cette page pour vérifier.</p></div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente'): ?>
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="bg-orange-50 border border-orange-200 rounded-xl p-space-md flex flex-col gap-space-sm">
        <div class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-orange-600 text-[22px]">account_balance</span>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Virement bancaire en attente de confirmation</p>
            <p class="font-body-sm text-body-sm text-secondary">Une fois votre virement effectué, déposez votre justificatif (reçu ou capture d'écran) pour accélérer sa validation par notre équipe.</p>
          </div>
        </div>
        <?php if ($justificatifEnvoye): ?>
        <p class="font-body-sm text-body-sm text-green-700 flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px]">check_circle</span>Justificatif envoyé, en attente de validation.</p>
        <?php elseif ($paiement['justificatif_nom']): ?>
        <p class="font-body-sm text-body-sm text-on-surface flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px] text-orange-600">description</span>Justificatif déjà envoyé (<?php echo htmlspecialchars($paiement['justificatif_nom']); ?>), en attente de validation. Vous pouvez en envoyer un autre si besoin.</p>
        <?php endif; ?>
        <?php if ($erreurJustificatif !== ''): ?>
        <p class="font-body-sm text-body-sm text-error"><?php echo htmlspecialchars($erreurJustificatif); ?></p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-space-sm">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="deposer_justificatif_virement">
          <label class="flex flex-col gap-space-2xs flex-1">
            <span class="font-label-md text-label-md text-secondary">Justificatif (PDF, JPG ou PNG — 10 Mo max)</span>
            <input required type="file" name="justificatif" accept=".pdf,.jpg,.jpeg,.png" class="font-body-sm text-body-sm">
          </label>
          <button type="submit" class="h-11 px-space-md rounded-xl bg-primary-container text-white font-label-lg text-label-lg shrink-0">Envoyer le justificatif</button>
        </form>
      </div>
    </section>
    <?php endif; ?>

    <section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-lg flex flex-col gap-space-lg">

    <div class="reveal-up is-visible rounded-2xl p-space-lg shadow-sm flex flex-col sm:flex-row items-start gap-space-md <?php echo $prochaineEtape['priorite'] ? 'bg-gradient-to-br from-[#0B192C] to-[#1E3E62]' : 'bg-surface-container-lowest'; ?>">
      <div class="flex items-start gap-space-sm flex-1 min-w-0 w-full">
        <div class="dash-pulse w-12 h-12 rounded-full flex items-center justify-center shrink-0 <?php echo $prochaineEtape['priorite'] ? 'bg-[#F0A83E] text-[#0B192C]' : 'bg-primary-container/15 text-primary'; ?>"><span class="material-symbols-outlined text-[24px]"><?php echo $prochaineEtape['priorite'] ? 'priority_high' : 'task_alt'; ?></span></div>
        <div class="flex-1 min-w-0">
          <p class="font-label-md text-[11px] uppercase tracking-wider font-bold <?php echo $prochaineEtape['priorite'] ? 'text-[#F0A83E]' : 'text-secondary'; ?>">Prochaine étape</p>
          <p class="font-headline-sm text-headline-sm font-bold <?php echo $prochaineEtape['priorite'] ? 'text-white' : 'text-on-surface'; ?>"><?php echo htmlspecialchars($prochaineEtape['titre']); ?></p>
          <p class="font-body-sm text-body-sm <?php echo $prochaineEtape['priorite'] ? 'text-white/70' : 'text-secondary'; ?>"><?php echo htmlspecialchars($prochaineEtape['texte']); ?></p>
        </div>
      </div>
      <?php if ($prochaineEtape['url']): ?>
      <a href="<?php echo htmlspecialchars($prochaineEtape['url']); ?>" class="shrink-0 w-full sm:w-auto h-11 px-space-lg rounded-xl font-label-md text-label-md flex items-center justify-center gap-1 sm:self-center hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 <?php echo $prochaineEtape['priorite'] ? 'bg-[#F0A83E] text-[#0B192C]' : 'bg-primary-container text-white hover:bg-primary'; ?>"><?php echo htmlspecialchars($prochaineEtape['bouton']); ?> <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-md">
      <div class="reveal-up group bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-xs hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center group-hover:scale-110 transition-transform duration-300"><span class="material-symbols-outlined text-[20px]">description</span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold pt-space-2xs"><?php echo count($docsTransmis); ?></p>
        <p class="font-label-md text-label-md text-on-surface font-semibold">Documents transmis</p>
        <p class="font-body-sm text-[11px] <?php echo $documentsACorrigerCount ? 'text-error font-semibold' : 'text-secondary'; ?>"><?php echo $documentsACorrigerCount ? $documentsACorrigerCount . ' à corriger' : ($docsTransmis ? 'Dernier ajout : ' . htmlspecialchars(nx_il_y_a($docsTransmis[0]['uploaded_at'])) : 'Aucun document pour le moment'); ?></p>
        <a href="classic-depot-documents.php" class="font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir mes documents <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>
      <div class="reveal-up group bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-xs hover:shadow-lg hover:-translate-y-1 transition-all duration-300" style="transition-delay:70ms">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center group-hover:scale-110 transition-transform duration-300"><span class="material-symbols-outlined text-[20px]">checklist</span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold pt-space-2xs"><?php echo count($actionsEnCours); ?></p>
        <p class="font-label-md text-label-md text-on-surface font-semibold">Actions à réaliser</p>
        <p class="font-body-sm text-[11px] text-secondary"><?php echo $actionsEnCours ? count($actionsEnCours) . ' en cours' : 'Aucune action en attente'; ?></p>
        <a href="classic-mes-actions.php" class="font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir mes actions <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>
      <div class="reveal-up group bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-xs hover:shadow-lg hover:-translate-y-1 transition-all duration-300" style="transition-delay:140ms">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-[#7C3AED] to-[#4C1D95] text-white flex items-center justify-center group-hover:scale-110 transition-transform duration-300"><span class="material-symbols-outlined text-[20px]">event</span></div>
        <p class="font-label-lg text-label-lg text-on-surface font-bold pt-space-2xs">Prochain rendez-vous</p>
        <p class="font-body-sm text-[12px] text-secondary"><?php
          if ($rdv) {
              echo htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure'])) . ' · ' . ($formatLabels[$rdv['format']] ?? ''));
          } elseif ($rdvAnnulesCount > 0) {
              echo 'Rendez-vous annulé — à reprogrammer';
          } else {
              echo 'Non planifié';
          }
        ?></p>
        <a href="classic-rendez-vous.php" class="font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir l'agenda <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>
      <div class="reveal-up group bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-xs hover:shadow-lg hover:-translate-y-1 transition-all duration-300" style="transition-delay:210ms">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center group-hover:scale-110 transition-transform duration-300"><span class="material-symbols-outlined text-[20px]">card_giftcard</span></div>
        <p class="font-headline-lg text-headline-lg text-on-surface font-bold pt-space-2xs"><?php echo count($livrablesDisponibles); ?></p>
        <p class="font-label-md text-label-md text-on-surface font-semibold">Livrables disponibles</p>
        <p class="font-body-sm text-[11px] text-secondary"><?php echo $livrablesDisponibles ? 'Dernier ajout : ' . htmlspecialchars(nx_il_y_a($livrablesDisponibles[0]['created_at'])) : 'Vos livrables apparaîtront ici une fois prêts'; ?></p>
        <a href="classic-mes-livrables.php" class="font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir mes livrables <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>
    </div>

    <?php if ($documentsManquants): ?>
    <div class="reveal-up bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col sm:flex-row items-start gap-space-md">
      <div class="flex items-start gap-space-sm flex-1 w-full">
        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">folder_off</span></div>
        <div class="flex-1">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Documents manquants</p>
          <p class="font-body-sm text-body-sm text-secondary">Obligatoires non encore transmis : <?php echo htmlspecialchars(implode(', ', array_map(fn ($s) => $slotsFichiersLabels[$s], $documentsManquants))); ?>.</p>
        </div>
      </div>
      <a href="classic-depot-documents.php" class="shrink-0 w-full sm:w-auto h-10 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface flex items-center justify-center sm:self-center hover:bg-surface-container-low transition-colors">Compléter</a>
    </div>
    <?php endif; ?>

    <?php if ($documentsACorrigerCount > 0): ?>
    <div class="reveal-up bg-error-container/20 border border-error/20 rounded-2xl p-space-lg flex flex-col sm:flex-row items-start gap-space-md">
      <div class="flex items-start gap-space-sm flex-1 w-full">
        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-red-400 to-error text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">error</span></div>
        <div class="flex-1">
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $documentsACorrigerCount; ?> document(s) à corriger</p>
          <p class="font-body-sm text-body-sm text-secondary">Votre consultant a demandé une correction. Consultez le motif et déposez une nouvelle version.</p>
        </div>
      </div>
      <a href="classic-depot-documents.php?vue=a_corriger" class="shrink-0 w-full sm:w-auto h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center sm:self-center hover:bg-primary hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">Corriger</a>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-md items-start">
      <div class="reveal-up bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-md">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Suivi de votre dossier</h3>
        <div class="grid grid-cols-4 sm:grid-cols-8 gap-x-space-xs gap-y-space-md">
          <?php foreach ($avancement as $i => $e): ?>
          <div class="flex flex-col items-center gap-space-2xs">
            <div class="w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0
              <?php
              if ($e['statut'] === 'done') echo 'bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white';
              elseif ($e['statut'] === 'current') echo 'dash-pulse bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F]';
              else echo 'bg-surface-container text-secondary';
              ?>">
              <?php echo $e['statut'] === 'done' ? '<span class="material-symbols-outlined text-[18px]">check</span>' : ($i + 1); ?>
            </div>
            <span class="font-label-md text-[11px] text-center leading-tight <?php echo $e['statut'] === 'current' ? 'text-on-surface font-bold' : 'text-secondary'; ?>"><?php echo htmlspecialchars($e['titre']); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($prochaineEtape['titre']): ?>
        <p class="font-body-sm text-body-sm text-primary bg-primary-container/10 rounded-xl px-space-sm py-space-xs flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[18px]">bolt</span>Prochaine étape : <?php echo htmlspecialchars($prochaineEtape['titre']); ?>.</p>
        <?php endif; ?>
      </div>

      <div class="reveal-right flex flex-col gap-space-md">
        <div class="bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm">
          <div class="flex items-center justify-between">
            <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Vos prochaines actions</h3>
            <span class="w-6 h-6 rounded-full bg-primary-container/15 text-primary flex items-center justify-center font-label-md text-[11px] font-bold"><?php echo count($actionsEnCours) + ($messagesNonLus ? 1 : 0) + ($documentsACorrigerCount ? 1 : 0); ?></span>
          </div>
          <?php
          $digest = [];
          if ($documentsACorrigerCount > 0) {
              $digest[] = ['icone' => 'description', 'titre' => $documentsACorrigerCount . ' document(s) à corriger', 'texte' => 'Votre consultant attend une nouvelle version.', 'url' => 'classic-depot-documents.php?vue=a_corriger'];
          }
          if ($rdv) {
              $digest[] = ['icone' => 'event', 'titre' => 'Rendez-vous à venir', 'texte' => htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))), 'url' => 'classic-rendez-vous.php'];
          }
          if ($messagesNonLus > 0) {
              $digest[] = ['icone' => 'chat', 'titre' => $messagesNonLus . ' nouveau(x) message(s)', 'texte' => 'De votre consultant.', 'url' => 'messages.php?dossier=' . $dossierId];
          }
          foreach (array_slice($actionsEnCours, 0, 2) as $a) {
              $digest[] = ['icone' => 'checklist', 'titre' => $a['titre'], 'texte' => $a['echeance'] ? 'À faire avant le ' . date('d/m/Y', strtotime($a['echeance'])) : 'Sans échéance', 'url' => 'classic-mes-actions.php'];
          }
          ?>
          <?php if (!$digest): ?>
          <p class="font-body-sm text-body-sm text-secondary">Tout est à jour, aucune action immédiate de votre part.</p>
          <?php endif; ?>
          <?php foreach (array_slice($digest, 0, 3) as $d): ?>
          <a href="<?php echo htmlspecialchars($d['url']); ?>" class="flex items-center gap-space-sm p-space-2xs rounded-xl hover:bg-surface-container-low transition-colors duration-200">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]"><?php echo $d['icone']; ?></span></div>
            <div class="flex-1 min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary truncate"><?php echo $d['texte']; ?></p></div>
            <span class="material-symbols-outlined text-[16px] text-secondary shrink-0">chevron_right</span>
          </a>
          <?php endforeach; ?>
        </div>

        <div class="rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm text-white bg-gradient-to-br from-[#4C1D95] to-[#2563EB]">
          <div class="w-11 h-11 rounded-full bg-white/15 flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">smart_toy</span></div>
          <p class="font-label-lg text-label-lg font-bold">Votre assistant NexRaiz</p>
          <div class="bg-white/10 rounded-xl p-space-sm font-body-sm text-body-sm text-white/90">Bonjour ! Comment puis-je vous aider aujourd'hui ?</div>
          <button type="button" id="openAssistantBtn" class="h-11 rounded-xl bg-white text-[#2563EB] font-label-md text-label-md font-bold flex items-center justify-center gap-1 hover:opacity-90 transition-opacity">Poser une question <span class="material-symbols-outlined text-[16px]">arrow_forward</span></button>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-md items-start">
      <div id="mes-actions" class="reveal-left bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm scroll-mt-20">
        <div class="flex items-center justify-between">
          <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">checklist</span></span>Mes actions</h3>
          <a href="classic-mes-actions.php" class="font-label-md text-[12px] text-primary">Voir tout</a>
        </div>
        <?php if (!$actions): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune action pour le moment. Votre consultant vous en assignera au fil de votre accompagnement.</p>
        <?php endif; ?>
        <?php
        $actionLabels = ['a_faire' => ['À faire', 'bg-orange-100 text-orange-700'], 'en_cours' => ['En cours', 'bg-primary-container/10 text-primary'], 'a_venir' => ['À venir', 'bg-surface-container-high text-secondary'], 'a_verifier' => ['À vérifier', 'bg-blue-100 text-blue-700'], 'termine' => ['Terminé', 'bg-green-100 text-green-700']];
        foreach (array_slice($actions, 0, 5) as $a):
          $enRetard = in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true) && $a['echeance'] && strtotime($a['echeance']) < strtotime('today');
        ?>
        <div class="flex items-center gap-space-sm p-space-2xs rounded-xl hover:bg-surface-container-low transition-colors duration-200">
          <div class="w-10 h-10 rounded-xl <?php echo $enRetard ? 'bg-gradient-to-br from-red-400 to-error' : 'bg-gradient-to-br from-[#2563EB] to-[#1E3E62]'; ?> text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $enRetard ? 'warning' : 'task_alt'; ?></span></div>
          <div class="flex-1 min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($a['titre']); ?></p>
            <p class="font-body-sm text-[11px] <?php echo $enRetard ? 'text-error font-semibold' : 'text-secondary'; ?>"><?php echo $a['echeance'] ? ($enRetard ? 'En retard depuis le ' : 'À faire avant le ') . htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : 'Sans échéance'; ?></p>
          </div>
          <?php [$label, $class] = $actionLabels[$a['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
          <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $class; ?>"><?php echo $label; ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="reveal-right bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <div class="flex items-center justify-between">
          <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#1E3E62] to-[#0B192C] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">card_giftcard</span></span>Mes livrables</h3>
          <a href="classic-mes-livrables.php" class="font-label-md text-[12px] text-primary">Voir tout</a>
        </div>
        <?php if (!$livrablesDisponibles): ?>
        <p class="font-body-sm text-body-sm text-secondary">Vos livrables (CV corrigé, lettre améliorée, recommandations LinkedIn, préparation entretien...) apparaîtront ici une fois remis par votre consultant.</p>
        <?php endif; ?>
        <?php foreach (array_slice($livrablesDisponibles, 0, 4) as $l): ?>
        <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:shadow-sm transition-shadow duration-200">
          <div class="flex items-center gap-space-sm min-w-0">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#8FB2FF] to-[#2563EB] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">description</span></div>
            <div class="min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary">Reçu le <?php echo htmlspecialchars(date('d/m/Y', strtotime($l['envoye_le'] ?: $l['created_at']))); ?></p></div>
          </div>
          <a href="classic-livrable-telecharger.php?id=<?php echo $l['id']; ?>" target="_blank" rel="noopener" class="shrink-0 h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">download</span>Ouvrir</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="reveal-up bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-md">
      <div>
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Nos experts à votre service</h3>
        <p class="font-body-sm text-body-sm text-secondary">Des professionnels engagés pour votre réussite.</p>
      </div>
      <div class="flex flex-col sm:flex-row items-center gap-space-lg">
        <div class="flex -space-x-4 shrink-0">
          <?php foreach (['img_classic/equipe-2.png', 'img_classic/equipe-1.png', 'img_classic/equipe-3.png'] as $i => $photo): ?>
          <img src="<?php echo $photo; ?>" alt="Coach NexRaiz" class="w-16 h-16 rounded-full object-cover border-4 border-surface-container-lowest shadow-md" style="z-index:<?php echo 10 - $i; ?>">
          <?php endforeach; ?>
        </div>
        <div class="flex-1 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-sm w-full">
          <div class="flex flex-col gap-space-2xs">
            <p class="font-body-sm text-body-sm text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-primary">check_circle</span>Conseils personnalisés</p>
            <p class="font-body-sm text-body-sm text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-primary">check_circle</span>Accompagnement sur mesure</p>
          </div>
          <a href="classic-decouverte.php#equipe" class="shrink-0 h-11 px-space-lg rounded-xl bg-[#13203F] text-white font-label-md text-label-md flex items-center justify-center gap-1 hover:opacity-90 transition-opacity">Découvrir l'équipe <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1.3fr_1fr] gap-space-md items-start">
      <div class="reveal-left bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Activité récente</h3>
        <?php if (!$activites): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
        <?php endif; ?>
        <?php foreach ($activites as $act): ?>
        <div class="flex items-start gap-space-sm p-space-2xs rounded-xl hover:bg-surface-container-low transition-colors duration-200">
          <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]"><?php echo $act['icone']; ?></span></div>
          <div class="flex-1"><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($act['texte']); ?></p></div>
          <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo htmlspecialchars($act['quand']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="reveal-right bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <div class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center text-[#F0A83E]"><span class="material-symbols-outlined text-[24px]">groups</span></div>
        <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#F0A83E] font-bold">Votre accompagnement</span>
        <h3 class="font-label-lg text-label-lg font-bold text-white">Votre accompagnement à chaque étape</h3>
        <p class="font-body-sm text-body-sm text-white/70">Nos experts sont là pour vous guider et vous aider à atteindre vos objectifs professionnels.</p>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="h-11 rounded-xl bg-[#F0A83E] text-[#0B192C] font-label-md text-label-md flex items-center justify-center gap-1 hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">Contacter mon consultant <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      </div>
    </div>
    </section>
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

  var assistantBtn = document.getElementById('openAssistantBtn');
  if (assistantBtn) {
    assistantBtn.addEventListener('click', function () {
      var launcher = document.getElementById('nx-chatbot-launcher');
      if (launcher) { launcher.click(); }
    });
  }
})();
</script>
<?php render_footer(); ?>
