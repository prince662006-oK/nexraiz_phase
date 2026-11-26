<?php
/**
 * NexRaiz — Tableau de bord client, Pack Assistance. Espace permanent après
 * paiement (étape 8/8). Tout est recalculé à chaque chargement depuis les
 * vraies tables (dossiers, qualifications, rendezvous, paiements, documents,
 * livrables, actions_plan, messages, activites) — rien n'est stocké en dur
 * ni inventé. Le statut affiché au client est une étiquette dérivée des
 * données réelles, jamais une nouvelle valeur ajoutée à l'ENUM en base.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/documents.php';

$dossier = require_dossier('assistance', 'espace-client.php');
$dossierId = (int) $dossier['id'];
$client = current_user(); // prenom/nom ne sont pas sur la table dossiers

$stmt = db()->prepare('SELECT situation_type, urgence, documents_disponibles, diagnostic_valide_le FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = $stmt->fetch() ?: null;
$qualificationFaite = $qualification !== null;
$documentsDeclares = $qualification ? (json_decode((string) ($qualification['documents_disponibles'] ?? ''), true) ?: []) : [];

$urgenceLabels = [
    'faible'   => ['label' => 'Faible', 'couleur' => 'bg-surface-container-high text-secondary'],
    'moyen'    => ['label' => 'Moyen', 'couleur' => 'bg-yellow-100 text-yellow-700'],
    'eleve'    => ['label' => 'Élevé', 'couleur' => 'bg-orange-100 text-orange-700'],
    'critique' => ['label' => 'Critique', 'couleur' => 'bg-error-container text-error'],
];

$stmt = db()->prepare('SELECT date_heure, format, statut FROM rendezvous WHERE dossier_id = ? ORDER BY date_heure ASC LIMIT 1');
$stmt->execute([$dossierId]);
$rdv = $stmt->fetch();
$rdvStatutLabels = ['planifie' => 'Planifié', 'confirme' => 'Confirmé', 'annule' => 'Annulé', 'termine' => 'Terminé'];

$stmt = db()->prepare('SELECT accepte FROM consentements WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$consentementValide = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT id, statut, methode, justificatif_nom, montant, reference_externe, updated_at FROM paiements WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId]);
$paiement = $stmt->fetch();
$paiementFait = ($paiement['statut'] ?? '') === 'paye';

$erreurJustificatif = '';
$justificatifEnvoye = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_justificatif_virement') {
    require_csrf();
    if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente') {
        $erreurJustificatif = nx_enregistrer_justificatif_virement((int) $paiement['id'], $dossierId, 'assistance');
        if ($erreurJustificatif === '') {
            $justificatifEnvoye = true;
            $paiement['justificatif_nom'] = $_FILES['justificatif']['name'] ?? $paiement['justificatif_nom'];
        }
    }
}

$erreurDocument = '';
$documentEnvoye = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_document') {
    require_csrf();
    $erreurDocument = nx_enregistrer_document_client($dossierId, 'assistance', trim($_POST['categorie_document'] ?? ''), 'consultant-dossier.php?dossier=' . $dossierId);
    if ($erreurDocument === '') {
        $documentEnvoye = true;
    }
}

$dossierCloture = $dossier['statut'] === 'cloture';

$consultantNom = null;
$consultantEmail = null;
$consultantTelephone = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom, email, telephone FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    if ($c = $stmt->fetch()) {
        $consultantNom = $c['prenom'] . ' ' . $c['nom'];
        $consultantEmail = $c['email'];
        $consultantTelephone = $c['telephone'];
    }
}

$actions = db()->prepare('SELECT id, titre, proprietaire, echeance, statut, created_at FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC');
$actions->execute([$dossierId]);
$actions = $actions->fetchAll();
$actionsOuvertes = count(array_filter($actions, fn ($a) => $a['statut'] !== 'termine'));
$actionStatutLabels = ['a_faire' => ['À faire', 'bg-orange-100 text-orange-700'], 'en_cours' => ['En cours', 'bg-blue-100 text-blue-700'], 'termine' => ['Terminée', 'bg-green-100 text-green-700'], 'a_venir' => ['À venir', 'bg-surface-container-high text-secondary']];

$docs = db()->prepare('SELECT id, nom_original, categorie, statut, chemin, taille_ko, uploaded_at FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$docs->execute([$dossierId]);
$docs = $docs->fetchAll();
$categoriesTransmises = array_filter(array_unique(array_column($docs, 'categorie')));
$documentsManquants = array_values(array_diff($documentsDeclares, $categoriesTransmises));

$livrables = db()->prepare('SELECT titre, chemin, statut, created_at FROM livrables WHERE dossier_id = ? ORDER BY created_at DESC');
$livrables->execute([$dossierId]);
$livrables = $livrables->fetchAll();
$nbLivrablesDisponibles = count(array_filter($livrables, fn ($l) => $l['statut'] === 'disponible'));

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT expediteur_type, corps, created_at FROM messages WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId]);
$dernierMessage = $stmt->fetch();

// --- Activité récente : seules les entrées destinées au client (liste
// blanche de préfixes). Les notes internes du consultant (points de
// vigilance, notes de session, changements techniques de statut) ne sont
// jamais envoyées au client — à filtrer même si elles existent déjà en base.
$prefixesActivitesClient = [
    'Dossier créé', 'Qualification du besoin complétée', 'Rendez-vous planifié',
    'Charte de consentement approuvée', 'Virement bancaire choisi', 'Document transmis',
    'Diagnostic validé', "Plan d'action envoyé", 'Action ajoutée au plan',
    'Livrable envoyé au candidat', 'Paiement confirmé',
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

function nx_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

$activites = array_map(fn ($a) => ['texte' => $a['texte'], 'quand' => nx_il_y_a($a['created_at']), 'brut' => $a['created_at']], $activitesBrutes);

// nx_icone_fichier() fournie par includes/documents.php (requis en haut de
// ce fichier) — ne pas la redéfinir ici.

$avancement = [
    ['titre' => 'Demande reçue', 'date' => date('d/m', strtotime($dossier['created_at'])), 'statut' => 'done'],
    ['titre' => 'Besoin qualifié', 'date' => $qualificationFaite ? '✓' : '—', 'statut' => $qualificationFaite ? 'done' : 'current'],
    ['titre' => 'Rendez-vous planifié', 'date' => $rdv ? date('d/m', strtotime($rdv['date_heure'])) : '—', 'statut' => $rdv ? 'done' : ($qualificationFaite ? 'current' : 'pending')],
    ['titre' => 'Consentement validé', 'date' => $consentementValide ? '✓' : '—', 'statut' => $consentementValide ? 'done' : ($rdv ? 'current' : 'pending')],
    ['titre' => 'Paiement effectué', 'date' => $paiementFait ? '✓' : '—', 'statut' => $paiementFait ? 'done' : ($consentementValide ? 'current' : 'pending')],
    ['titre' => 'Dossier en traitement', 'date' => '—', 'statut' => $dossierCloture ? 'done' : ($paiementFait ? 'current' : 'pending')],
    ['titre' => 'Accompagnement clôturé', 'date' => '—', 'statut' => $dossierCloture ? 'done' : 'pending'],
];
$nbEtapesFaites = count(array_filter($avancement, fn ($e) => $e['statut'] === 'done'));
$pourcentageAvancement = (int) round($nbEtapesFaites / count($avancement) * 100);

/**
 * Statut affiché au client, dérivé des données réelles (jamais une
 * nouvelle valeur ajoutée à dossiers.statut, qui reste à 3 valeurs en
 * base). Même principe que nx_statut_detail() côté admin, adapté à un
 * langage destiné au client.
 */
function nx_statut_client(bool $cloture, bool $qualificationFaite, ?array $rdv, bool $consentementValide, bool $paiementFait, ?string $consultantNom, int $actionsOuvertes, int $nbLivrablesDisponibles): array
{
    if ($cloture) {
        return ['label' => 'Clôturé', 'classe' => 'bg-green-100 text-green-700', 'message' => 'Votre accompagnement est terminé. Merci de votre confiance.'];
    }
    if (!$paiementFait) {
        if (!$qualificationFaite || !$rdv || !$consentementValide) {
            return ['label' => 'En attente', 'classe' => 'bg-surface-container-high text-secondary', 'message' => 'Complétez les étapes restantes pour démarrer votre accompagnement.'];
        }
        return ['label' => 'En attente de paiement', 'classe' => 'bg-orange-100 text-orange-700', 'message' => 'Votre dossier est prêt : il ne manque plus que le règlement pour démarrer le traitement.'];
    }
    if (!$consultantNom) {
        return ['label' => 'En cours de traitement', 'classe' => 'bg-blue-100 text-blue-700', 'message' => "Votre dossier a bien été reçu et est en cours d'attribution à un consultant."];
    }
    if ($actionsOuvertes > 0) {
        return ['label' => 'En attente de votre part', 'classe' => 'bg-orange-100 text-orange-700', 'message' => 'Une action de votre part est nécessaire pour poursuivre le traitement.'];
    }
    if ($nbLivrablesDisponibles > 0) {
        return ['label' => 'Livrable disponible', 'classe' => 'bg-green-100 text-green-700', 'message' => 'Vos livrables sont disponibles dans votre espace.'];
    }
    return ['label' => 'En cours de traitement', 'classe' => 'bg-blue-100 text-blue-700', 'message' => 'Votre consultant travaille actuellement sur votre dossier.'];
}

$statutClient = nx_statut_client($dossierCloture, $qualificationFaite, $rdv, $consentementValide, $paiementFait, $consultantNom, $actionsOuvertes, $nbLivrablesDisponibles);

/**
 * Prochaine étape concrète et bouton d'action principal — calculés, jamais
 * codés en dur, par ordre de priorité réel du parcours.
 */
if (!$qualificationFaite) {
    $prochaineEtape = ['texte' => 'Compléter la qualification de votre besoin.', 'lien' => 'qualification-besoin.php', 'bouton' => 'Compléter la qualification'];
} elseif (!$rdv) {
    $prochaineEtape = ['texte' => 'Planifier votre rendez-vous avec un consultant.', 'lien' => 'rendez-vous.php', 'bouton' => 'Planifier mon rendez-vous'];
} elseif (!$consentementValide) {
    $prochaineEtape = ['texte' => 'Valider la charte de consentement.', 'lien' => 'consentement.php', 'bouton' => 'Valider la charte'];
} elseif (!$paiementFait) {
    $prochaineEtape = ['texte' => 'Finaliser le paiement pour démarrer votre accompagnement.', 'lien' => 'paiement.php', 'bouton' => 'Procéder au paiement'];
} elseif ($documentsManquants) {
    $prochaineEtape = ['texte' => 'Transmettre les documents encore attendus (' . implode(', ', $documentsManquants) . ').', 'lien' => '#mes-documents', 'bouton' => 'Transmettre mes documents'];
} elseif ($actionsOuvertes > 0) {
    $prochaineEtape = ['texte' => 'Traiter votre prochaine action en attente.', 'lien' => '#mes-actions', 'bouton' => 'Voir mes actions'];
} elseif ($nbLivrablesDisponibles > 0) {
    $prochaineEtape = ['texte' => 'Consulter le document transmis par votre consultant.', 'lien' => '#mes-livrables', 'bouton' => 'Voir mes livrables'];
} elseif ($messagesNonLus > 0) {
    $prochaineEtape = ['texte' => 'Lire le message de votre consultant.', 'lien' => 'messages.php?dossier=' . $dossierId, 'bouton' => 'Lire le message'];
} elseif (!$dossierCloture) {
    $prochaineEtape = ['texte' => 'Vous êtes à jour. Aucune action n\'est requise pour le moment.', 'lien' => null, 'bouton' => null];
} else {
    $prochaineEtape = ['texte' => 'Votre accompagnement est clôturé.', 'lien' => null, 'bouton' => null];
}

// Catégories de documents proposées dans le formulaire de dépôt : celles
// déclarées par le client à la qualification en priorité, sinon la liste
// générique du Pack Assistance (même liste que qualification-besoin.php).
$categoriesDocumentsForm = $documentsDeclares ?: ['Contrat de travail', 'Captures (messages, outils internes)', 'Emails', 'Témoignages', 'Échanges RH', 'Autres documents'];

$sidebarNavAssistance = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'tableau-bord.php', 'actif' => true],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'mes-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'mes-livrables.php'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'confirmation.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

render_head('Pack Assistance — Tableau de bord client');
?>
<style>
  .nx-card-hover{transition:box-shadow .2s ease, transform .2s ease;}
  .nx-card-hover:hover{box-shadow:0 6px 20px rgba(11,28,48,.08);transform:translateY(-2px);}
  #dashSearchResults{display:none;}
  #dashSearchResults.is-open{display:flex;}
  #avatarMenu{display:none;}
  #avatarMenu.is-open{display:flex;}
</style>

<div class="flex w-full min-h-screen bg-surface" id="top">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs overflow-y-auto min-h-0">
    <a href="index.php" class="flex items-center gap-1.5 pb-space-xs">
      <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
    </a>
    <h2 class="font-headline-sm text-headline-sm font-bold text-inverse-on-surface px-space-xs pb-space-sm">Pack Assistance</h2>
    <?php foreach ($sidebarNavAssistance as $item): ?>
    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo !empty($item['actif']) ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
      <?php if (!empty($item['badge'])): ?>
      <span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $item['badge']; ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <div class="mt-space-md rounded-2xl bg-gradient-to-br from-[#2563EB] to-[#13203F] p-space-md flex flex-col gap-space-2xs">
      <span class="material-symbols-outlined text-[#FFC700] text-[26px]">support_agent</span>
      <p class="font-label-lg text-label-lg font-bold text-white">Pack Assistance</p>
      <p class="font-body-sm text-[11px] text-white/70">Un accompagnement à votre écoute</p>
      <a href="decouverte.php" class="mt-space-2xs h-9 rounded-lg bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-bold flex items-center justify-center gap-1 hover:opacity-90 transition-opacity"><span class="flex-1 text-center">En savoir plus</span> <span class="material-symbols-outlined text-[14px] shrink-0">arrow_forward</span></a>
    </div>
    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <p class="font-label-lg text-label-lg font-bold px-space-xs">Besoin d'aide ?</p>
      <p class="font-body-sm text-[12px] text-inverse-on-surface/70 px-space-xs">Notre équipe est là pour vous accompagner.</p>
      <a href="contact.php" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Nous contacter</a>
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
        <?php render_notification_bell((int) $client['id']); ?>
        <div class="relative" id="avatarMenuWrapper">
          <button id="avatarMenuBtn" type="button" class="flex items-center gap-space-2xs pl-1 pr-space-xs py-1 rounded-full hover:bg-surface-container-low transition-colors">
            <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[13px] font-bold shrink-0"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($client['prenom'] ?? '?', 0, 1) . mb_substr($client['nom'] ?? '', 0, 1))); ?></span>
            <span class="hidden md:flex flex-col items-start leading-none">
              <span class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars(trim(($client['prenom'] ?? '') . ' ' . ($client['nom'] ?? ''))); ?></span>
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
    <main class="flex-1 flex flex-col">

    <!-- Accueil personnalisé -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="flex flex-col gap-space-md">
        <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
          <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
          <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
          <a href="decouverte.php" class="hover:text-primary transition-colors">Pack Assistance</a>
          <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
          <span class="text-on-surface font-semibold">Tableau de bord</span>
        </nav>

        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0B192C] via-[#13203F] to-[#1E3E62] p-space-lg md:p-space-xl flex flex-col lg:flex-row lg:items-center gap-space-md">
          <div class="absolute -top-16 -right-16 w-72 h-72 rounded-full bg-[#FFC700]/10 blur-3xl pointer-events-none"></div>
          <div class="relative z-10 flex-1 flex flex-col gap-space-xs">
            <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-white font-bold tracking-tight">Bonjour <?php echo htmlspecialchars($client['prenom'] ?? ''); ?> 👋</h1>
            <div class="flex flex-wrap items-center gap-space-xs">
              <span class="font-body-md text-body-md text-white/70">Pack Assistance · Dossier <?php echo htmlspecialchars($dossier['numero']); ?></span>
              <span class="px-space-xs py-0.5 rounded-full bg-[#FFC700] text-[#13203F] font-label-md text-[11px] font-bold"><?php echo htmlspecialchars($statutClient['label']); ?></span>
            </div>
            <p class="font-body-md text-body-md text-white/90"><?php echo htmlspecialchars($statutClient['message']); ?></p>
            <?php if ($consultantNom): ?>
            <p class="font-body-sm text-[12px] text-white/70 flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-[#FFC700]">support_agent</span>Votre consultant : <strong class="text-white"><?php echo htmlspecialchars($consultantNom); ?></strong><?php echo $consultantEmail ? ' · ' . htmlspecialchars($consultantEmail) : ''; ?></p>
            <?php endif; ?>
          </div>
          <div class="relative z-10 flex flex-col gap-space-2xs shrink-0 w-full lg:w-auto lg:max-w-xs bg-white/10 backdrop-blur rounded-2xl p-space-sm">
            <span class="font-label-md text-[11px] uppercase tracking-wider text-[#FFC700] font-bold">Prochaine étape</span>
            <p class="font-body-sm text-body-sm text-white"><?php echo htmlspecialchars($prochaineEtape['texte']); ?></p>
            <?php if ($prochaineEtape['bouton']): ?>
            <a href="<?php echo htmlspecialchars($prochaineEtape['lien']); ?>" class="h-10 px-space-md rounded-xl bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg flex items-center justify-center gap-1 hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200"><?php echo htmlspecialchars($prochaineEtape['bouton']); ?><span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <?php if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente'): ?>
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
      <div class="bg-orange-50 border border-orange-200 rounded-xl p-space-md flex flex-col gap-space-sm">
        <div class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-orange-600 text-[22px]">account_balance</span>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Virement bancaire en attente de confirmation</p>
            <p class="font-body-sm text-body-sm text-secondary">Montant à régler : <strong><?php echo number_format((float) $paiement['montant'], 0, ',', ' '); ?> FCFA</strong><?php echo $paiement['reference_externe'] ? ' · Référence : ' . htmlspecialchars($paiement['reference_externe']) : ''; ?></p>
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
        <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-space-sm" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
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

    <!-- Statistiques -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <a href="mes-documents.php" class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">folder</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Documents transmis</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo count($docs); ?> <span class="font-body-sm text-[12px] text-secondary font-normal">document<?php echo count($docs) > 1 ? 's' : ''; ?></span></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1"><?php echo $documentsManquants ? count($documentsManquants) . ' encore attendu(s)' : 'Voir mes documents'; ?> <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
        <a href="mes-actions.php" class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">checklist</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Actions à réaliser</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $actionsOuvertes; ?> <span class="font-body-sm text-[12px] text-secondary font-normal">en attente</span></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir mes actions <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
        <div class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#7C3AED] to-[#4C1D95] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">description</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Statut du dossier</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo htmlspecialchars($statutClient['label']); ?></p>
          <span class="font-body-sm text-[12px] text-secondary">N° <?php echo htmlspecialchars($dossier['numero']); ?></span>
        </div>
        <a href="confirmation.php" class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">event</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Prochain rendez-vous</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $rdv ? htmlspecialchars(date('d/m à H\hi', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1"><?php echo $rdv ? htmlspecialchars($rdvStatutLabels[$rdv['statut']] ?? '') : 'Planifier'; ?> <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
      </div>
    </section>

    <!-- Frise d'avancement -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-md">
        <div class="flex items-center justify-between flex-wrap gap-space-xs">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Avancement de votre dossier</h2>
          <span class="font-label-md text-[11px] text-secondary"><?php echo $pourcentageAvancement; ?>% complété</span>
        </div>
        <div class="grid grid-cols-4 sm:grid-cols-7 gap-x-space-xs gap-y-space-md">
          <?php foreach ($avancement as $i => $e): ?>
          <div class="flex flex-col items-center gap-space-2xs">
            <div class="w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0
              <?php
              if ($e['statut'] === 'done') echo 'bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white';
              elseif ($e['statut'] === 'current') echo 'bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F] ring-4 ring-[#FFC700]/20';
              else echo 'bg-surface-container text-secondary';
              ?>">
              <?php echo $e['statut'] === 'done' ? '<span class="material-symbols-outlined text-[18px]">check</span>' : ($i + 1); ?>
            </div>
            <span class="font-label-md text-[11px] text-center leading-tight <?php echo $e['statut'] === 'current' ? 'text-on-surface font-bold' : 'text-secondary'; ?>"><?php echo htmlspecialchars($e['titre']); ?></span>
            <span class="font-body-sm text-[10px] text-secondary"><?php echo $e['date']; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-2xs bg-primary-container/5 rounded-xl p-space-sm">
          <span class="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">visibility</span><?php echo htmlspecialchars($statutClient['message']); ?></span>
          <span class="font-body-sm text-[11px] text-secondary">Dernière mise à jour : <?php echo htmlspecialchars(nx_il_y_a($dossier['updated_at'])); ?></span>
        </div>
      </div>
    </section>

    <!-- Mes actions -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">
        <div class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm scroll-mt-20 lg:col-span-2" id="mes-actions">
          <div class="flex items-center justify-between gap-space-xs"><h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F] flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">checklist</span></span>Mes actions</h3><span class="flex items-center gap-space-sm shrink-0"><span class="font-label-md text-[11px] text-secondary"><?php echo $actionsOuvertes; ?> en attente</span><a href="mes-actions.php" class="font-label-md text-[11px] text-[#2563EB] font-bold flex items-center gap-1">Voir tout <span class="material-symbols-outlined text-[13px]">arrow_forward</span></a></span></div>
          <?php if (!$actions): ?>
          <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
            <span class="material-symbols-outlined text-[32px] text-green-600">task_alt</span>
            <p class="font-body-sm text-body-sm text-secondary">Vous êtes à jour. Aucune action n'est requise pour le moment.<br>Votre consultant vous en proposera après votre premier rendez-vous.</p>
          </div>
          <?php endif; ?>
          <?php foreach ($actions as $a): [$label, $class] = $actionStatutLabels[$a['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
          <div class="flex items-center gap-space-xs">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $a['statut'] === 'termine' ? 'check_circle' : 'task_alt'; ?></span></div>
            <div class="flex-1 min-w-0">
              <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($a['titre']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo $a['echeance'] ? 'À faire avant le ' . htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : htmlspecialchars($a['proprietaire'] ?? ''); ?></p>
            </div>
            <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $class; ?>"><?php echo $label; ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Résumé de mon dossier (remplace l'ancien bloc "Voir mon dossier") -->
        <div class="nx-card-hover bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
          <div class="w-11 h-11 rounded-full bg-white/10 flex items-center justify-center text-[#FFC700]"><span class="material-symbols-outlined text-[22px]">folder_shared</span></div>
          <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#FFC700] font-bold">Résumé de mon dossier</span>
          <div class="flex flex-col gap-space-2xs font-body-sm text-[12px] text-white/80">
            <p>N° dossier : <strong class="text-white"><?php echo htmlspecialchars($dossier['numero']); ?></strong></p>
            <p>Pack : <strong class="text-white">Assistance</strong></p>
            <p>Statut : <strong class="text-white"><?php echo htmlspecialchars($statutClient['label']); ?></strong></p>
            <p>Créé le : <strong class="text-white"><?php echo htmlspecialchars(date('d/m/Y', strtotime($dossier['created_at']))); ?></strong></p>
            <?php if ($consultantNom): ?><p>Consultant : <strong class="text-white"><?php echo htmlspecialchars($consultantNom); ?></strong></p><?php endif; ?>
            <?php if (!empty($qualification['urgence']) && isset($urgenceLabels[$qualification['urgence']])): ?>
            <p class="flex items-center gap-1">Urgence déclarée : <span class="px-space-xs py-0.5 rounded-full <?php echo $urgenceLabels[$qualification['urgence']]['couleur']; ?> font-label-md text-[10px] font-bold"><?php echo $urgenceLabels[$qualification['urgence']]['label']; ?></span></p>
            <?php endif; ?>
          </div>
          <div class="flex flex-col gap-space-2xs pt-space-xs border-t border-white/10">
            <a href="confirmation.php" class="h-9 rounded-xl bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-bold flex items-center justify-center gap-1 hover:opacity-90 transition-opacity">Consulter les détails <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
            <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="h-9 rounded-xl border border-white/20 text-white font-label-md text-label-md flex items-center justify-center gap-1 hover:bg-white/5 transition-colors">Contacter mon consultant</a>
          </div>
        </div>
      </div>
    </section>

    <!-- Mes documents -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm scroll-mt-20 max-w-max-width-content mx-auto" id="mes-documents">
        <div class="flex items-center justify-between gap-space-xs">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">description</span></span>Mes documents</h3>
          <a href="mes-documents.php" class="font-label-md text-[11px] text-[#2563EB] font-bold flex items-center gap-1 shrink-0">Voir tout <span class="material-symbols-outlined text-[13px]">arrow_forward</span></a>
        </div>

        <?php if ($documentsManquants): ?>
        <div class="bg-orange-50 border border-orange-200 rounded-xl p-space-sm flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-orange-600 text-[18px] mt-0.5">info</span>
          <p class="font-body-sm text-[12px] text-on-surface">Documents encore attendus : <strong><?php echo htmlspecialchars(implode(', ', $documentsManquants)); ?></strong> (d'après ce que vous avez indiqué à la qualification).</p>
        </div>
        <?php endif; ?>

        <?php if (!$docs): ?>
        <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
          <span class="material-symbols-outlined text-[32px] text-outline-variant">folder_off</span>
          <p class="font-body-sm text-body-sm text-secondary">Aucun document déposé pour le moment. Utilisez le formulaire ci-dessous pour transmettre vos documents à votre consultant.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($docs as $d): [$icone, $couleur] = nx_icone_fichier($d['nom_original']); ?>
        <a href="document-telecharger.php?id=<?php echo $d['id']; ?>" target="_blank" rel="noopener" class="flex items-center gap-space-xs hover:bg-surface-container-low rounded-lg -mx-space-2xs px-space-2xs py-space-2xs transition-colors">
          <span class="material-symbols-outlined text-[18px] <?php echo $couleur; ?> shrink-0"><?php echo $icone; ?></span>
          <div class="flex-1 min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo $d['categorie'] ? htmlspecialchars($d['categorie']) . ' · ' : ''; ?><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['uploaded_at']))); ?></p>
          </div>
          <span class="material-symbols-outlined text-[16px] text-secondary shrink-0">open_in_new</span>
        </a>
        <?php endforeach; ?>

        <?php if ($erreurDocument !== ''): ?>
        <p class="font-body-sm text-[12px] text-error"><?php echo htmlspecialchars($erreurDocument); ?></p>
        <?php elseif ($documentEnvoye): ?>
        <p class="font-body-sm text-[12px] text-green-700 flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[16px]">check_circle</span>Document transmis à votre consultant.</p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/40" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="deposer_document">
          <div class="flex flex-col sm:flex-row gap-space-xs">
            <label class="sr-only" for="categorieDocument">Catégorie du document</label>
            <select id="categorieDocument" name="categorie_document" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
              <option value="">Catégorie (optionnel)</option>
              <?php foreach ($categoriesDocumentsForm as $cat): ?>
              <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
              <?php endforeach; ?>
            </select>
            <input required type="file" name="document" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="flex-1 font-body-sm text-[12px]">
          </div>
          <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[16px]">upload_file</span>Déposer un document</button>
          <p class="font-body-sm text-[10px] text-secondary">PDF, DOC, DOCX, JPG ou PNG — 10 Mo max. Vos documents sont confidentiels et visibles uniquement par votre consultant.</p>
        </form>
      </div>
    </section>

    <!-- Mes livrables / Activité récente -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-sm items-start">
        <div class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm scroll-mt-20" id="mes-livrables">
          <div class="flex items-center justify-between gap-space-xs">
            <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">card_giftcard</span></span>Mes livrables</h3>
            <a href="mes-livrables.php" class="font-label-md text-[11px] text-[#2563EB] font-bold flex items-center gap-1 shrink-0">Voir tout <span class="material-symbols-outlined text-[13px]">arrow_forward</span></a>
          </div>
          <?php if (!$livrables): ?>
          <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
            <span class="material-symbols-outlined text-[32px] text-outline-variant">card_giftcard</span>
            <p class="font-body-sm text-body-sm text-secondary">Vos livrables (compte rendu, plan d'action...) apparaîtront ici une fois générés par votre consultant.</p>
          </div>
          <?php endif; ?>
          <?php foreach ($livrables as $l): [$icone, ] = nx_icone_fichier($l['chemin']); ?>
          <div class="flex items-center justify-between gap-space-xs">
            <div class="flex items-center gap-space-xs min-w-0">
              <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#8FB2FF] to-[#2563EB] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $l['statut'] === 'disponible' ? $icone : 'hourglass_top'; ?></span></div>
              <div class="min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo $l['statut'] === 'disponible' ? 'Reçu le ' . htmlspecialchars(date('d/m/Y', strtotime($l['created_at']))) : 'En préparation par votre consultant'; ?></p></div>
            </div>
            <?php if ($l['statut'] === 'disponible'): ?>
            <a href="<?php echo htmlspecialchars($l['chemin']); ?>" target="_blank" rel="noopener" class="shrink-0 h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir</a>
            <?php else: ?>
            <span class="shrink-0 font-label-md text-[11px] font-bold px-space-sm py-1 rounded-full bg-orange-100 text-orange-700">En préparation</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Activité récente</h3>
          <div class="flex flex-col gap-space-sm">
            <?php if (!$activites): ?>
            <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
            <?php endif; ?>
            <?php foreach ($activites as $act): ?>
            <div class="flex items-start gap-space-xs">
              <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">circle_notifications</span>
              <div><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($act['texte']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($act['quand']); ?></p></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Messages (aperçu) -->
    <section class="w-full px-gutter-mobile lg:px-gutter-desktop pb-space-lg">
      <div class="nx-card-hover bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col sm:flex-row sm:items-center gap-space-sm max-w-max-width-content mx-auto">
        <div class="w-11 h-11 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]">chat</span></div>
        <div class="flex-1 min-w-0">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Messagerie</p>
          <?php if ($dernierMessage): ?>
          <p class="font-body-sm text-[12px] text-secondary truncate">
            <strong class="text-on-surface"><?php echo $dernierMessage['expediteur_type'] === 'consultant' ? 'Votre consultant' : 'Vous'; ?></strong>
            — <?php echo htmlspecialchars(mb_strimwidth($dernierMessage['corps'], 0, 90, '…')); ?>
            · <?php echo htmlspecialchars(nx_il_y_a($dernierMessage['created_at'])); ?>
          </p>
          <?php else: ?>
          <p class="font-body-sm text-[12px] text-secondary">Aucun message pour le moment.</p>
          <?php endif; ?>
        </div>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="shrink-0 h-10 px-space-md rounded-xl bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white font-label-md text-label-md flex items-center justify-center gap-1 hover:opacity-90 transition-opacity">Ouvrir la messagerie<?php if ($messagesNonLus > 0): ?><span class="w-5 h-5 rounded-full bg-white text-[#2563EB] flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $messagesNonLus; ?></span><?php endif; ?></a>
      </div>
    </section>

  </div>
</div>
<script>
(function () {
  var navItems = <?php echo json_encode(array_map(fn ($i) => ['label' => $i['label'], 'url' => $i['url']], $sidebarNavAssistance), JSON_UNESCAPED_UNICODE); ?>;
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
<?php render_footer(); ?>
