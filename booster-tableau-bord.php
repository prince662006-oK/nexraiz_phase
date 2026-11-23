<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/documents.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');
$dossierId = (int) $dossier['id'];
$client = current_user();

$coach = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    $coach = $stmt->fetch();
}

$stmt = db()->prepare('SELECT 1 FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualificationFaite = (bool) $stmt->fetchColumn();

// Tous les rendez-vous (à venir ET passés) : nécessaires pour calculer le
// vrai statut de confirmation et proposer un historique, pas seulement le
// tout prochain.
$stmt = db()->prepare('SELECT id, date_heure, format, statut FROM rendezvous WHERE dossier_id = ? ORDER BY date_heure ASC');
$stmt->execute([$dossierId]);
$tousLesRdv = $stmt->fetchAll();
$maintenant = time();
$rdv = null;
$rdvPasses = [];
foreach ($tousLesRdv as $r) {
    if ($rdv === null && strtotime($r['date_heure']) >= $maintenant && $r['statut'] !== 'annule') {
        $rdv = $r;
    } elseif (strtotime($r['date_heure']) < $maintenant || $r['statut'] === 'termine' || $r['statut'] === 'annule') {
        $rdvPasses[] = $r;
    }
}
$rdvPasses = array_reverse($rdvPasses); // du plus récent au plus ancien
$rdvStatutLabels = [
    'planifie' => ['En attente de confirmation', 'bg-orange-100 text-orange-700'],
    'confirme' => ['Confirmé', 'bg-green-100 text-green-700'],
    'annule'   => ['Annulé', 'bg-surface-container-high text-secondary line-through'],
    'termine'  => ['Terminé', 'bg-surface-container-high text-secondary'],
];
$formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir', 'hybride' => 'Hybride'];

$stmt = db()->prepare('SELECT accepte FROM consentements WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$consentementValide = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT id, statut, methode, justificatif_nom FROM paiements WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossierId]);
$paiement = $stmt->fetch();
$paiementFait = ($paiement['statut'] ?? '') === 'paye';

$erreurJustificatif = '';
$justificatifEnvoye = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_justificatif_virement') {
    require_csrf();
    if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente') {
        $erreurJustificatif = nx_enregistrer_justificatif_virement((int) $paiement['id'], $dossierId, 'booster');
        if ($erreurJustificatif === '') {
            $justificatifEnvoye = true;
            $paiement['justificatif_nom'] = $_FILES['justificatif']['name'] ?? $paiement['justificatif_nom'];
        }
    }
}

$dossierCloture = $dossier['statut'] === 'cloture';
$rdvConfirme = $rdv !== null && $rdv['statut'] === 'confirme';

// --- Avancement : étapes métier réelles du Pack Booster, chacune avec une
// explication pour le client et, si nécessaire, l'action suivante et qui en
// a la responsabilité (jamais présenter au client une tâche qui dépend du
// coach ou d'une vérification interne). ---
$avancement = [
    [
        'titre' => 'Demande reçue', 'date' => date('d/m', strtotime($dossier['created_at'])), 'statut' => 'done',
        'explication' => 'Votre demande d\'accompagnement Pack Booster a bien été enregistrée.',
    ],
    [
        'titre' => 'Projet clarifié', 'date' => $qualificationFaite ? '✓' : '—',
        'statut' => $qualificationFaite ? 'done' : 'current',
        'explication' => $qualificationFaite ? 'Vous avez précisé votre projet et vos attentes.' : 'Quelques questions pour que votre coach prépare au mieux votre accompagnement.',
        'action' => $qualificationFaite ? null : ['label' => 'Clarifier mon projet', 'href' => 'booster-qualification-projet.php'],
    ],
    [
        'titre' => 'Rendez-vous confirmé', 'date' => $rdv ? date('d/m', strtotime($rdv['date_heure'])) : '—',
        'statut' => $rdvConfirme ? 'done' : ($qualificationFaite ? 'current' : 'pending'),
        'explication' => $rdvConfirme ? 'Votre rendez-vous est confirmé.' : ($rdv ? 'Votre rendez-vous est en attente de confirmation par votre coach.' : 'Planifiez votre premier rendez-vous avec votre coach.'),
        'action' => (!$rdv && $qualificationFaite) ? ['label' => 'Planifier mon rendez-vous', 'href' => 'booster-rendez-vous.php'] : null,
        'qui' => ($rdv && !$rdvConfirme) ? 'coach' : 'client',
    ],
    [
        'titre' => 'Consentement validé', 'date' => $consentementValide ? '✓' : '—',
        'statut' => $consentementValide ? 'done' : ($rdvConfirme ? 'current' : 'pending'),
        'explication' => $consentementValide ? 'Vous avez validé les conditions de votre accompagnement.' : 'Prenez connaissance du cadre de votre accompagnement et validez-le.',
        'action' => (!$consentementValide && $rdvConfirme) ? ['label' => 'Donner mon consentement', 'href' => 'booster-consentement.php'] : null,
    ],
    [
        'titre' => 'Paiement effectué', 'date' => $paiementFait ? '✓' : '—',
        'statut' => $paiementFait ? 'done' : ($consentementValide ? 'current' : 'pending'),
        'explication' => $paiementFait ? 'Votre paiement est enregistré.' : (($paiement['methode'] ?? '') === 'virement' && ($paiement['statut'] ?? '') === 'en_attente' ? 'Votre virement est en cours de vérification par notre équipe.' : 'Réglez votre accompagnement pour le démarrer.'),
        'action' => (!$paiementFait && $consentementValide && ($paiement['methode'] ?? '') !== 'virement') ? ['label' => 'Procéder au paiement', 'href' => 'booster-paiement.php'] : null,
        'qui' => (($paiement['methode'] ?? '') === 'virement' && ($paiement['statut'] ?? '') === 'en_attente') ? 'coach' : 'client',
    ],
    [
        'titre' => 'Accompagnement en cours', 'date' => '—',
        'statut' => $dossierCloture ? 'done' : ($paiementFait ? 'current' : 'pending'),
        'explication' => 'Votre coach vous accompagne : vos actions, documents et livrables apparaissent ci-dessous au fil de l\'accompagnement.',
    ],
    [
        'titre' => 'Parcours clôturé', 'date' => '—', 'statut' => $dossierCloture ? 'done' : 'pending',
        'explication' => $dossierCloture ? 'Votre accompagnement Pack Booster est terminé.' : 'Votre accompagnement est encore en cours.',
    ],
];

$actions = db()->prepare('SELECT titre, proprietaire, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC');
$actions->execute([$dossierId]);
$actions = $actions->fetchAll();
$actionStatutLabels = ['a_faire' => ['À faire', 'bg-orange-100 text-orange-700'], 'en_cours' => ['En cours', 'bg-primary-container/10 text-primary'], 'termine' => ['Terminé', 'bg-green-100 text-green-700'], 'a_venir' => ['À venir', 'bg-surface-container-high text-secondary']];
$nbActionsOuvertes = count(array_filter($actions, fn ($a) => in_array($a['statut'], ['a_faire', 'en_cours'], true)));

// Regroupées par catégorie (version courante + historique, voir
// nx_documents_par_categorie) — la même logique que booster-mes-documents.php,
// pour que ce résumé ne compte jamais une ancienne version comme un document
// distinct ni comme « à corriger » si une version plus récente existe déjà.
$groupesDocuments = nx_documents_par_categorie($dossierId);
$docsActuels = array_column($groupesDocuments, 'actuel');
$nbDocumentsTransmis = count($docsActuels);
$docStatutLabels = [
    'en_verification' => ['En cours de vérification', 'bg-orange-100 text-orange-700'],
    'valide'           => ['Validé', 'bg-green-100 text-green-700'],
    'a_corriger'       => ['À corriger', 'bg-error-container/40 text-error'],
];

$stmt = db()->prepare('SELECT texte, created_at FROM activites WHERE dossier_id = ? ORDER BY id DESC LIMIT 8');
$stmt->execute([$dossierId]);
$activitesBrutes = $stmt->fetchAll();

// Une ligne par livrable logique (version courante uniquement) — cohérent
// avec le même principe que le Pack Classic : jamais une ancienne version
// affichée comme un livrable à part entière.
$livrables = db()->prepare("SELECT id, titre, categorie, description, chemin, statut, created_at, envoye_le FROM livrables WHERE dossier_id = ? AND est_version_courante = 1 ORDER BY created_at DESC");
$livrables->execute([$dossierId]);
$livrables = $livrables->fetchAll();

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNavBooster = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'booster-tableau-bord.php', 'actif' => true],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'booster-mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'booster-mes-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'booster-tableau-bord.php#mes-livrables'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'booster-tableau-bord.php#rendez-vous'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

function nx_il_y_a(string $dateTime): string
{
    $diff = time() - strtotime($dateTime);
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . 'h';
    return 'il y a ' . floor($diff / 86400) . 'j';
}

$activites = array_map(fn ($a) => ['texte' => $a['texte'], 'quand' => nx_il_y_a($a['created_at']), 'brut' => $a['created_at']], $activitesBrutes);

$nbEtapesFaites = count(array_filter($avancement, fn ($e) => $e['statut'] === 'done'));
$pourcentageAvancement = (int) round($nbEtapesFaites / count($avancement) * 100);

// --- Votre priorité : une seule action mise en avant, dans l'ordre réel du
// parcours métier (le même ordre que la frise ci-dessus) — jamais deux
// priorités contradictoires affichées en même temps. ---
$documentsACorriger = array_filter($docsActuels, fn ($d) => $d['statut'] === 'a_corriger');
if ($dossierCloture) {
    $priorite = ['texte' => 'Votre accompagnement Pack Booster est terminé. Merci pour votre confiance.', 'action' => null, 'icone' => 'task_alt'];
} elseif (!$qualificationFaite) {
    $priorite = ['texte' => 'Précisez votre projet pour que votre coach prépare votre accompagnement.', 'action' => ['label' => 'Clarifier mon projet', 'href' => 'booster-qualification-projet.php'], 'icone' => 'edit_note'];
} elseif (!$rdv) {
    $texteRdv = $tousLesRdv ? 'Planifiez votre prochain rendez-vous avec votre coach.' : 'Planifiez votre premier rendez-vous avec votre coach.';
    $priorite = ['texte' => $texteRdv, 'action' => ['label' => 'Planifier mon rendez-vous', 'href' => 'booster-rendez-vous.php'], 'icone' => 'event'];
} elseif (!$rdvConfirme) {
    $priorite = ['texte' => 'Votre rendez-vous du ' . date('d/m/Y à H:i', strtotime($rdv['date_heure'])) . ' est en attente de confirmation par votre coach.', 'action' => null, 'icone' => 'hourglass_top'];
} elseif (!$consentementValide) {
    $priorite = ['texte' => 'Votre rendez-vous est confirmé pour le ' . date('d/m/Y à H:i', strtotime($rdv['date_heure'])) . '. Validez le cadre de votre accompagnement pour continuer.', 'action' => ['label' => 'Donner mon consentement', 'href' => 'booster-consentement.php'], 'icone' => 'verified_user'];
} elseif (!$paiementFait && ($paiement['methode'] ?? '') === 'virement' && ($paiement['statut'] ?? '') === 'en_attente') {
    $priorite = ['texte' => 'Votre virement est en cours de vérification par notre équipe.', 'action' => $paiement['justificatif_nom'] ? null : ['label' => 'Déposer mon justificatif', 'href' => '#'], 'icone' => 'hourglass_top'];
} elseif (!$paiementFait) {
    $priorite = ['texte' => 'Réglez votre accompagnement pour le démarrer.', 'action' => ['label' => 'Procéder au paiement', 'href' => 'booster-paiement.php'], 'icone' => 'payments'];
} elseif ($documentsACorriger) {
    $premier = reset($documentsACorriger);
    $priorite = ['texte' => 'Votre coach vous demande de corriger un document : « ' . htmlspecialchars($premier['nom_original']) . ' ».', 'action' => ['label' => 'Voir mes documents', 'href' => 'booster-mes-documents.php'], 'icone' => 'error'];
} elseif ($nbActionsOuvertes > 0) {
    $priorite = ['texte' => 'Vous avez ' . $nbActionsOuvertes . ' action' . ($nbActionsOuvertes > 1 ? 's' : '') . ' à réaliser.', 'action' => ['label' => 'Voir mes actions', 'href' => 'booster-mes-actions.php'], 'icone' => 'checklist'];
} elseif (array_filter($livrables, fn ($l) => $l['statut'] === 'disponible')) {
    $priorite = ['texte' => 'Un document préparé par votre coach est disponible.', 'action' => ['label' => 'Voir mes livrables', 'href' => '#mes-livrables'], 'icone' => 'card_giftcard'];
} else {
    $priorite = ['texte' => "Aucune action n'est attendue de votre part pour le moment.", 'action' => null, 'icone' => 'self_improvement'];
}

render_head('Pack Booster — Tableau de bord client');
require __DIR__ . '/includes/pack-shell.php';
?>
<style>
  @keyframes nx-fade-in-up{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
  .nx-fade-in{animation:nx-fade-in-up .5s ease both;}
  .nx-fade-1{animation-delay:.02s;} .nx-fade-2{animation-delay:.08s;} .nx-fade-3{animation-delay:.14s;} .nx-fade-4{animation-delay:.2s;}
  .nx-card-hover{transition:box-shadow .2s ease, transform .2s ease;}
  .nx-card-hover:hover{box-shadow:0 6px 20px rgba(11,28,48,.08);transform:translateY(-2px);}
  .nx-progress-ring{transform:rotate(-90deg);}
  .nx-ring-value{stroke-dasharray:0,100;transition:stroke-dasharray 1s ease .2s;}
  @media (prefers-reduced-motion: reduce){.nx-fade-in{animation:none;} .nx-card-hover:hover{transform:none;} .nx-ring-value{transition:none;}}
</style>
<?php
pack_header_debut($client, $sidebarNavBooster, [
    'sidebar_titre' => 'Pack Booster', 'icone' => 'rocket_launch', 'titre' => 'Pack Booster',
    'texte' => 'Boostez votre carrière avec NexRaiz', 'bouton_label' => 'En savoir plus', 'bouton_href' => 'booster-decouverte.php',
]);
?>
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="booster-tableau-bord.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Tableau de bord</span>
      </nav>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Votre accompagnement Booster</h1>
          <p class="font-body-sm text-body-sm text-secondary">
            <?php echo htmlspecialchars($client['prenom'] . ' ' . $client['nom']); ?> · Dossier <?php echo htmlspecialchars($dossier['numero']); ?>
            <?php if ($coach): ?> · Coach : <?php echo htmlspecialchars($coach['prenom'] . ' ' . $coach['nom']); ?><?php endif; ?>
          </p>
        </div>
        <div class="flex items-center gap-space-xs shrink-0">
          <span class="px-space-sm py-1.5 rounded-full font-label-md text-label-md font-bold <?php echo $dossierCloture ? 'bg-green-100 text-green-700' : ($paiementFait ? 'bg-blue-100 text-[#2563EB]' : 'bg-orange-100 text-orange-700'); ?>"><?php echo $dossierCloture ? 'Clôturé' : ($paiementFait ? 'En cours' : 'En attente'); ?></span>
        </div>
      </div>

      <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0B192C] via-[#13203F] to-[#1E3E62] p-space-lg flex items-start gap-space-sm nx-fade-in">
        <div class="absolute -top-16 -right-16 w-64 h-64 rounded-full bg-[#FFC700]/10 blur-3xl pointer-events-none"></div>
        <div class="relative z-10 w-11 h-11 rounded-full bg-[#FFC700] text-[#13203F] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo $priorite['icone']; ?></span></div>
        <div class="relative z-10 flex-1 min-w-0">
          <p class="font-label-md text-[11px] uppercase tracking-wider text-[#FFC700] font-bold">Votre priorité</p>
          <p class="font-label-lg text-label-lg text-white font-semibold"><?php echo $priorite['texte']; ?></p>
        </div>
        <?php if ($priorite['action']): ?>
        <a href="<?php echo htmlspecialchars($priorite['action']['href']); ?>" class="relative z-10 shrink-0 h-10 px-space-md rounded-xl bg-[#FFC700] text-[#13203F] font-label-md text-label-md flex items-center gap-1 hover:opacity-90 transition-opacity"><?php echo htmlspecialchars($priorite['action']['label']); ?> <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
        <?php endif; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col sm:flex-row items-center gap-space-lg nx-fade-in nx-fade-1">
        <div class="relative w-20 h-20 shrink-0 flex items-center justify-center">
          <svg class="w-full h-full nx-progress-ring" viewbox="0 0 36 36">
            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e4e7ee" stroke-width="3.2"></path>
            <path class="nx-ring-value" id="nxRingValue" data-cible="<?php echo $pourcentageAvancement; ?>" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#2563EB" stroke-linecap="round" stroke-width="3.2"></path>
          </svg>
          <div class="absolute flex flex-col items-center">
            <span class="font-headline-sm text-headline-sm font-black text-on-surface leading-none"><?php echo $pourcentageAvancement; ?>%</span>
          </div>
        </div>
        <div class="flex-1 flex flex-col gap-space-2xs text-center sm:text-left">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Votre parcours Pack Booster est avancé à <?php echo $pourcentageAvancement; ?>%</p>
          <p class="font-body-sm text-body-sm text-secondary"><?php echo $nbEtapesFaites; ?> étape<?php echo $nbEtapesFaites > 1 ? 's' : ''; ?> sur <?php echo count($avancement); ?> terminée<?php echo $nbEtapesFaites > 1 ? 's' : ''; ?>.</p>
        </div>
        <?php if ($rdv && strtotime($rdv['date_heure']) > time()): ?>
        <div class="flex flex-col items-center gap-space-2xs px-space-lg py-space-sm rounded-xl bg-[#E5EEFF] shrink-0">
          <span class="font-label-md text-[11px] text-secondary uppercase tracking-wider font-bold">Prochain rendez-vous dans</span>
          <span id="nxCompteARebours" data-datetime="<?php echo htmlspecialchars($rdv['date_heure']); ?>" class="font-headline-sm text-headline-sm text-[#2563EB] font-black">…</span>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($paiement && $paiement['methode'] === 'virement' && $paiement['statut'] === 'en_attente'): ?>
      <div class="bg-orange-50 border border-orange-200 rounded-2xl p-space-md flex flex-col gap-space-sm">
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
          <button type="submit" class="h-11 px-space-md rounded-xl bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white font-label-lg text-label-lg shrink-0">Envoyer le justificatif</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <div class="nx-card-hover nx-fade-in nx-fade-1 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#7C3AED] to-[#4C1D95] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">description</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Statut du dossier</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $dossierCloture ? 'Clôturé' : ($paiementFait ? 'En cours' : 'En attente'); ?></p>
          <span class="font-body-sm text-[12px] text-secondary">N° <?php echo htmlspecialchars($dossier['numero']); ?></span>
        </div>
        <a href="booster-mes-actions.php" class="nx-card-hover nx-fade-in nx-fade-2 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">checklist</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Actions à réaliser</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $nbActionsOuvertes; ?> <span class="font-body-sm text-[12px] text-secondary font-normal">ouverte<?php echo $nbActionsOuvertes > 1 ? 's' : ''; ?></span></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir mes actions <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
        <a href="booster-mes-documents.php" class="nx-card-hover nx-fade-in nx-fade-3 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">folder</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Documents transmis</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $nbDocumentsTransmis; ?> <span class="font-body-sm text-[12px] text-secondary font-normal">document<?php echo $nbDocumentsTransmis > 1 ? 's' : ''; ?></span></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir mes documents <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
        <a href="#rendez-vous" class="nx-card-hover nx-fade-in nx-fade-4 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">schedule</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Prochain rendez-vous</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $rdv ? htmlspecialchars(date('d/m à H\hi', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir le détail <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
      </div>

      <div class="nx-fade-in nx-fade-2 bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-md">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Avancement de mon Pack Booster</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-space-sm gap-y-space-lg">
          <?php foreach ($avancement as $i => $e): ?>
          <div class="flex flex-col items-center gap-space-2xs text-center">
            <div class="w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0
              <?php
              if ($e['statut'] === 'done') echo 'bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white';
              elseif ($e['statut'] === 'current') echo 'bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F] ring-4 ring-[#FFC700]/20';
              else echo 'bg-surface-container text-secondary border-2 border-dashed border-outline-variant';
              ?>">
              <?php echo $e['statut'] === 'done' ? '<span class="material-symbols-outlined text-[18px]">check</span>' : ($e['statut'] === 'current' ? '<span class="material-symbols-outlined text-[16px]">radio_button_checked</span>' : ($i + 1)); ?>
            </div>
            <span class="font-label-md text-[12px] text-center leading-tight <?php echo $e['statut'] === 'current' ? 'text-on-surface font-bold' : 'text-secondary'; ?>"><?php echo htmlspecialchars($e['titre']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo $e['date']; ?></span>
            <span class="font-body-sm text-[10px] text-secondary text-center leading-tight max-w-[8rem]"><?php echo htmlspecialchars($e['explication']); ?></span>
            <?php if (!empty($e['action'])): ?>
            <a href="<?php echo htmlspecialchars($e['action']['href']); ?>" class="font-label-md text-[10px] text-[#2563EB] font-bold underline"><?php echo htmlspecialchars($e['action']['label']); ?></a>
            <?php elseif (($e['qui'] ?? null) === 'coach'): ?>
            <span class="font-label-md text-[10px] text-orange-600 font-bold">Action de votre coach</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">

        <div class="flex flex-col gap-space-sm">
          <div class="nx-card-hover nx-fade-in nx-fade-1 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm scroll-mt-20" id="mes-actions">
            <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Mes actions</h3>
        <?php if (!$actions): ?>
        <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
          <span class="material-symbols-outlined text-[32px] text-outline-variant">checklist</span>
          <p class="font-body-sm text-body-sm text-secondary">Aucune action pour le moment.<br>Votre coach vous en proposera après votre premier rendez-vous.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($actions as $a): [$label, $class] = $actionStatutLabels[$a['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]">task_alt</span></div>
          <div class="flex-1 min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($a['titre']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo $a['echeance'] ? 'À faire avant le ' . htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : htmlspecialchars($a['proprietaire'] ?? ''); ?></p>
          </div>
          <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $class; ?>"><?php echo $label; ?></span>
        </div>
        <?php endforeach; ?>
        <a href="booster-mes-actions.php" class="self-start font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir toutes mes actions <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>

      <div class="nx-card-hover bg-inverse-surface text-inverse-on-surface rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <div class="w-11 h-11 rounded-full bg-white/10 flex items-center justify-center text-tertiary-fixed"><span class="material-symbols-outlined text-[22px]">chat</span></div>
        <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#F0A83E] font-bold">Messagerie</span>
        <h3 class="font-label-lg text-label-lg font-bold">Messages</h3>
        <p class="font-body-sm text-body-sm text-inverse-on-surface/80">Échangez directement avec votre coach au sujet de votre accompagnement.</p>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="h-10 rounded-xl bg-[#2563EB] text-white font-label-md text-label-md flex items-center justify-center gap-1 hover:opacity-90 transition-opacity">Ouvrir la messagerie <?php if ($messagesNonLus > 0): ?><span class="w-5 h-5 rounded-full bg-white text-[#2563EB] flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $messagesNonLus; ?></span><?php else: ?><span class="material-symbols-outlined text-[16px]">arrow_forward</span><?php endif; ?></a>
        <div class="flex items-center gap-space-xs pt-space-xs border-t border-white/10">
          <span class="material-symbols-outlined text-[14px] text-inverse-on-surface/70">lock</span>
          <span class="font-body-sm text-[11px] text-inverse-on-surface/70">Dossier sécurisé – Données confidentielles</span>
        </div>
        <a href="contact.php" class="font-label-md text-[11px] text-inverse-on-surface/70 underline">Besoin d'aide ? Contactez-nous</a>
      </div>
    </div>

    <div class="flex flex-col gap-space-sm">
      <div class="nx-card-hover nx-fade-in nx-fade-2 bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Mes documents</h3>
        <?php if (!$docsActuels): ?>
        <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
          <span class="material-symbols-outlined text-[32px] text-outline-variant">folder_off</span>
          <p class="font-body-sm text-body-sm text-secondary">Aucun document déposé pour le moment.</p>
        </div>
        <?php else: ?>
        <?php foreach (array_slice($docsActuels, 0, 3) as $d): [$icone, $couleur] = nx_icone_fichier($d['nom_original']); [$labelStatutDoc, $classeStatutDoc] = $docStatutLabels[$d['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
        <a href="document-telecharger.php?id=<?php echo $d['id']; ?>" target="_blank" rel="noopener" class="flex items-center gap-space-xs hover:bg-surface-container-low rounded-lg -mx-space-2xs px-space-2xs py-space-2xs transition-colors">
          <span class="material-symbols-outlined text-[18px] <?php echo $couleur; ?> shrink-0"><?php echo $icone; ?></span>
          <div class="flex-1 min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo $d['categorie'] ? htmlspecialchars($d['categorie']) . ' · ' : ''; ?><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['uploaded_at']))); ?></p>
          </div>
          <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeStatutDoc; ?>"><?php echo htmlspecialchars($labelStatutDoc); ?></span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
        <a href="booster-mes-documents.php" class="self-start font-label-md text-label-md text-primary flex items-center gap-1 pt-space-2xs">Voir tous mes documents <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>

      <div class="nx-card-hover nx-fade-in nx-fade-3 bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Activité récente</h3>
        <?php if (!$activites): ?>
        <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
          <span class="material-symbols-outlined text-[32px] text-outline-variant">history</span>
          <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($activites as $act): ?>
        <div class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check_circle</span>
          <div><p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($act['texte']); ?></p><p class="font-body-sm text-[11px] text-secondary nx-temps-relatif" data-brut="<?php echo htmlspecialchars($act['brut']); ?>"><?php echo htmlspecialchars($act['quand']); ?></p></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex flex-col gap-space-sm">
      <div class="nx-card-hover nx-fade-in nx-fade-4 bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm scroll-mt-20" id="mes-livrables">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Mes livrables</h3>
        <?php if (!$livrables): ?>
        <div class="flex flex-col items-center text-center gap-space-xs py-space-md">
          <span class="material-symbols-outlined text-[32px] text-outline-variant">card_giftcard</span>
          <p class="font-body-sm text-body-sm text-secondary">Vos livrables (bilan, plan de carrière, recommandations...) apparaîtront ici une fois générés par votre coach.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($livrables as $l): ?>
        <div class="flex items-center justify-between gap-space-xs">
          <div class="flex items-center gap-space-xs min-w-0">
            <span class="material-symbols-outlined text-[18px] text-primary shrink-0">card_giftcard</span>
            <div class="min-w-0">
              <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo $l['statut'] === 'disponible' ? 'Reçu le ' . htmlspecialchars(date('d/m/Y', strtotime($l['envoye_le'] ?: $l['created_at']))) : 'En préparation par votre coach'; ?><?php if ($l['description']): ?> · <?php echo htmlspecialchars($l['description']); ?><?php endif; ?></p>
            </div>
          </div>
          <?php if ($l['statut'] === 'disponible'): ?>
          <a href="<?php echo htmlspecialchars($l['chemin']); ?>" target="_blank" rel="noopener" class="shrink-0 h-8 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Ouvrir</a>
          <?php else: ?>
          <span class="shrink-0 font-label-md text-[11px] font-bold px-space-sm py-1 rounded-full bg-orange-100 text-orange-700">En préparation</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="nx-card-hover nx-fade-in bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm scroll-mt-20" id="rendez-vous">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Mes rendez-vous</h3>
        <?php if ($rdv): [$labelStatutRdv, $classeStatutRdv] = $rdvStatutLabels[$rdv['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
        <div class="flex items-center justify-between gap-space-xs bg-primary-container/5 rounded-xl p-space-sm">
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($formatLabels[$rdv['format']] ?? $rdv['format']); ?></p>
          </div>
          <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeStatutRdv; ?>"><?php echo htmlspecialchars($labelStatutRdv); ?></span>
        </div>
        <?php else: ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucun rendez-vous à venir.</p>
        <?php endif; ?>
        <?php if ($rdvPasses): ?>
        <button type="button" onclick="document.getElementById('nxHistoriqueRdv').classList.toggle('hidden')" class="self-start font-label-md text-[11px] text-primary underline">Voir l'historique (<?php echo count($rdvPasses); ?>)</button>
        <div id="nxHistoriqueRdv" class="hidden flex-col gap-space-2xs">
          <?php foreach ($rdvPasses as $rp): [$labelP, $classeP] = $rdvStatutLabels[$rp['statut']] ?? ['—', 'bg-surface-container-high text-secondary']; ?>
          <div class="flex items-center justify-between gap-space-xs px-space-sm py-space-2xs">
            <span class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($rp['date_heure']))); ?></span>
            <span class="shrink-0 px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeP; ?>"><?php echo htmlspecialchars($labelP); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <a href="booster-confirmation.php" class="self-start font-label-md text-label-md text-[#2563EB] flex items-center gap-1 pt-space-2xs">Voir le détail <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
      </div>
    </div>
  </div>

<script>
(function () {
  // Anneau de progression : remplissage animé jusqu'au vrai pourcentage.
  var anneau = document.getElementById('nxRingValue');
  if (anneau) {
    requestAnimationFrame(function () {
      anneau.setAttribute('stroke-dasharray', anneau.dataset.cible + ', 100');
    });
  }

  // Compte à rebours en direct avant le prochain rendez-vous.
  var compteur = document.getElementById('nxCompteARebours');
  if (compteur) {
    var cible = new Date(compteur.dataset.datetime.replace(' ', 'T')).getTime();
    function majCompteur() {
      var diff = cible - Date.now();
      if (diff <= 0) { compteur.textContent = "C'est maintenant"; return; }
      var jours = Math.floor(diff / 86400000);
      var heures = Math.floor((diff % 86400000) / 3600000);
      var minutes = Math.floor((diff % 3600000) / 60000);
      compteur.textContent = jours > 0 ? (jours + 'j ' + heures + 'h') : (heures > 0 ? (heures + 'h ' + minutes + 'min') : (minutes + ' min'));
    }
    majCompteur();
    setInterval(majCompteur, 30000);
  }

  // Rafraîchit "il y a Xmin" dans l'activité récente sans recharger la page.
  var elementsTemps = document.querySelectorAll('.nx-temps-relatif');
  function texteRelatif(secondes) {
    if (secondes < 60) return "à l'instant";
    if (secondes < 3600) return 'il y a ' + Math.floor(secondes / 60) + ' min';
    if (secondes < 86400) return 'il y a ' + Math.floor(secondes / 3600) + 'h';
    return 'il y a ' + Math.floor(secondes / 86400) + 'j';
  }
  function majTempsRelatifs() {
    elementsTemps.forEach(function (el) {
      var brut = new Date(el.dataset.brut.replace(' ', 'T')).getTime();
      el.textContent = texteRelatif(Math.floor((Date.now() - brut) / 1000));
    });
  }
  if (elementsTemps.length) setInterval(majTempsRelatifs, 30000);
})();
</script>

<?php pack_header_fin($sidebarNavBooster); ?>
