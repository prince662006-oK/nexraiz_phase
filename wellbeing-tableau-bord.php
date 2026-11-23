<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

$dossier = require_dossier('wellbeing', 'wellbeing-espace-client.php');
$dossierId = (int) $dossier['id'];
$utilisateur = current_user();

$stmt = db()->prepare('SELECT 1 FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualificationFaite = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY date_heure ASC LIMIT 1');
$stmt->execute([$dossierId]);
$rdv = $stmt->fetch();

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
        $erreurJustificatif = nx_enregistrer_justificatif_virement((int) $paiement['id'], $dossierId, 'wellbeing');
        if ($erreurJustificatif === '') {
            $justificatifEnvoye = true;
            $paiement['justificatif_nom'] = $_FILES['justificatif']['name'] ?? $paiement['justificatif_nom'];
        }
    }
}

$dossierCloture = $dossier['statut'] === 'cloture';

$avancement = [
    ['titre' => 'Demande reçue', 'date' => date('d/m', strtotime($dossier['created_at'])), 'statut' => 'done'],
    ['titre' => 'Situation qualifiée', 'date' => $qualificationFaite ? '✓' : '—', 'statut' => $qualificationFaite ? 'done' : 'current'],
    ['titre' => 'Rendez-vous confirmé', 'date' => $rdv ? date('d/m', strtotime($rdv['date_heure'])) : '—', 'statut' => $rdv ? 'done' : ($qualificationFaite ? 'current' : 'pending')],
    ['titre' => 'Consentement validé', 'date' => $consentementValide ? '✓' : '—', 'statut' => $consentementValide ? 'done' : ($rdv ? 'current' : 'pending')],
    ['titre' => 'Paiement effectué', 'date' => $paiementFait ? '✓' : '—', 'statut' => $paiementFait ? 'done' : ($consentementValide ? 'current' : 'pending')],
    ['titre' => 'Accompagnement en cours', 'date' => '—', 'statut' => $dossierCloture ? 'done' : ($paiementFait ? 'current' : 'pending')],
    ['titre' => 'Évaluation de clôture', 'date' => '—', 'statut' => $dossierCloture ? 'done' : 'pending'],
];

$actions = db()->prepare('SELECT titre, proprietaire, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC');
$actions->execute([$dossierId]);
$actions = $actions->fetchAll();
$actionStatutLabels = ['a_faire' => ['À faire', 'text-secondary'], 'en_cours' => ['En cours', 'text-primary'], 'termine' => ['Terminé', 'text-green-700'], 'a_venir' => ['À venir', 'text-secondary']];

$docs = db()->prepare('SELECT nom_original, uploaded_at FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC');
$docs->execute([$dossierId]);
$docs = $docs->fetchAll();

$stmt = db()->prepare('SELECT texte, created_at FROM activites WHERE dossier_id = ? ORDER BY id DESC LIMIT 8');
$stmt->execute([$dossierId]);
$activitesBrutes = $stmt->fetchAll();

$stmt = db()->prepare('SELECT titre, chemin, statut, created_at FROM livrables WHERE dossier_id = ? ORDER BY created_at DESC');
$stmt->execute([$dossierId]);
$livrables = $stmt->fetchAll();

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNavWellbeing = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'wellbeing-tableau-bord.php', 'actif' => true],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'wellbeing-tableau-bord.php#mes-actions'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'wellbeing-tableau-bord.php#mes-documents'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'wellbeing-tableau-bord.php#mes-livrables'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'wellbeing-confirmation.php'],
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

$activites = array_map(fn ($a) => ['texte' => $a['texte'], 'quand' => nx_il_y_a($a['created_at'])], $activitesBrutes);
$formatLabels = ['ligne' => 'Visioconférence', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir'];

render_head('Pack Wellbeing — Tableau de bord client');
require __DIR__ . '/includes/pack-shell.php';
pack_header_debut($utilisateur, $sidebarNavWellbeing, [
    'sidebar_titre' => 'Pack Wellbeing', 'icone' => 'eco', 'titre' => 'Pack Wellbeing',
    'texte' => 'Votre bien-être au travail', 'bouton_label' => 'En savoir plus', 'bouton_href' => 'wellbeing-decouverte.php',
]);
?>
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="wellbeing-tableau-bord.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Tableau de bord</span>
      </nav>

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

      <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0B192C] via-[#13203F] to-[#1E3E62] p-space-lg md:p-space-xl flex flex-col md:flex-row md:items-center justify-between gap-space-md">
        <div class="absolute -top-16 -right-16 w-64 h-64 rounded-full bg-[#FFC700]/10 blur-3xl pointer-events-none"></div>
        <div class="relative z-10 max-w-md flex flex-col gap-space-sm">
          <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-white font-bold">Bienvenue, <?php echo htmlspecialchars($utilisateur['prenom']); ?> 👋</h1>
          <p class="font-body-md text-body-md text-white/80">Votre Pack Wellbeing est en cours. Continuez vos actions et profitez pleinement des ressources mises à votre disposition.</p>
          <div class="inline-flex items-center gap-space-xs bg-white/10 rounded-xl px-space-sm py-space-xs w-fit">
            <span class="material-symbols-outlined text-[18px] text-[#FFC700]">eco</span>
            <span class="font-body-sm text-[12px] text-white">Nous sommes là pour vous accompagner à chaque étape.</span>
          </div>
        </div>
        <div class="relative z-10 flex flex-col gap-space-sm max-w-xs w-full">
          <div class="bg-white/10 backdrop-blur rounded-xl p-space-sm flex items-center gap-space-sm">
            <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">event</span></div>
            <div><p class="font-body-sm text-[11px] text-white/70">Prochain rendez-vous</p><p class="font-label-lg text-label-lg text-white font-bold"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p></div>
          </div>
          <div class="bg-white/10 backdrop-blur rounded-xl p-space-sm flex items-center gap-space-sm">
            <div class="w-10 h-10 rounded-full bg-[#FFC700] text-[#13203F] flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">folder</span></div>
            <div><p class="font-body-sm text-[11px] text-white/70">Documents disponibles</p><p class="font-label-lg text-label-lg text-white font-bold"><?php echo count($docs); ?> document<?php echo count($docs) > 1 ? 's' : ''; ?></p></div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#7C3AED] to-[#4C1D95] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">description</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Statut du dossier</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $dossierCloture ? 'Clôturé' : ($paiementFait ? 'En cours' : 'En attente'); ?></p>
          <p class="font-body-sm text-[11px] text-secondary">N° <?php echo htmlspecialchars($dossier['numero']); ?></p>
        </div>
        <a href="#mes-actions" class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">checklist</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Actions à réaliser</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo count($actions); ?></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir mes actions <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
        <a href="#mes-documents" class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">description</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Documents transmis</p>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo count($docs); ?></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir mes documents <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
        <a href="wellbeing-confirmation.php" class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-space-xs">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">schedule</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Prochain rendez-vous</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $rdv ? htmlspecialchars(date('d/m à H\hi', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p>
          <p class="font-body-sm text-[11px] text-secondary"><?php echo $rdv ? htmlspecialchars($formatLabels[$rdv['format']] ?? '') : ''; ?></p>
          <span class="font-label-md text-label-md text-[#2563EB] flex items-center gap-1">Voir les détails <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
        </a>
      </div>

      <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Avancement de mon Pack Wellbeing</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-space-sm gap-y-space-lg">
          <?php foreach ($avancement as $i => $e): ?>
          <div class="flex flex-col items-center gap-space-2xs text-center">
            <div class="w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0
              <?php
              if ($e['statut'] === 'done') echo 'bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white';
              elseif ($e['statut'] === 'current') echo 'bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F] ring-4 ring-[#FFC700]/20';
              else echo 'bg-surface-container border-2 border-dashed border-outline-variant text-secondary';
              ?>">
              <?php echo $e['statut'] === 'done' ? '<span class="material-symbols-outlined text-[18px]">check</span>' : ($e['statut'] === 'current' ? '<span class="material-symbols-outlined text-[14px]">radio_button_checked</span>' : ($i + 1)); ?>
            </div>
            <span class="font-label-md text-[12px] text-center leading-tight <?php echo $e['statut'] === 'current' ? 'text-on-surface font-bold' : 'text-secondary'; ?>"><?php echo htmlspecialchars($e['titre']); ?></span>
            <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($e['date']); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-4 gap-space-sm items-start">

        <div id="mes-actions" class="scroll-mt-20 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F] flex items-center justify-center"><span class="material-symbols-outlined text-[14px]">checklist</span></span>Mes actions</h3>
          <?php if (!$actions): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune action pour le moment. Votre coach vous en proposera après votre premier rendez-vous.</p>
          <?php endif; ?>
          <?php foreach ($actions as $a): [$label, $class] = $actionStatutLabels[$a['statut']] ?? ['—', 'text-secondary']; ?>
          <div class="flex flex-col gap-space-2xs pb-space-xs border-b border-outline-variant/40 last:border-0 last:pb-0">
            <span class="font-label-md text-[10px] font-bold uppercase tracking-wider <?php echo $class; ?>"><?php echo $label; ?></span>
            <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo $a['echeance'] ? 'À faire avant le ' . htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : htmlspecialchars($a['proprietaire'] ?? ''); ?></p>
          </div>
          <?php endforeach; ?>
        </div>

        <div id="mes-documents" class="scroll-mt-20 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[14px]">description</span></span>Mes documents</h3>
          <?php if (!$docs): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucun document déposé pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($docs as $d): ?>
          <div class="flex items-center gap-space-xs">
            <div class="w-8 h-8 rounded bg-error/10 text-error flex items-center justify-center font-label-md text-[9px] font-bold shrink-0">PDF</div>
            <div class="flex-1 min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y', strtotime($d['uploaded_at']))); ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>

        <div id="mes-livrables" class="scroll-mt-20 bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[14px]">card_giftcard</span></span>Mes livrables</h3>
      <?php if (!$livrables): ?>
      <p class="font-body-sm text-body-sm text-secondary">Vos livrables (plan d'action, comptes rendus de séance, synthèses...) apparaîtront ici une fois générés par votre coach.</p>
      <?php endif; ?>
      <?php foreach ($livrables as $l): ?>
      <?php if ($l['statut'] === 'disponible'): ?>
      <a href="<?php echo htmlspecialchars($l['chemin']); ?>" target="_blank" class="flex items-center gap-space-xs hover:opacity-80 transition-opacity">
        <div class="w-8 h-8 rounded bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]">description</span></div>
        <div class="flex-1 min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('d/m/Y', strtotime($l['created_at']))); ?></p></div>
      </a>
      <?php else: ?>
      <div class="flex items-center gap-space-xs">
        <div class="w-8 h-8 rounded bg-surface-container-high text-secondary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]">hourglass_empty</span></div>
        <div class="flex-1 min-w-0"><p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($l['titre']); ?></p><p class="font-body-sm text-[11px] text-orange-600 font-semibold">En préparation par votre coach</p></div>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#7C3AED] to-[#4C1D95] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[14px]">history</span></span>Activité récente</h3>
          <?php if (!$activites): ?>
          <p class="font-body-sm text-body-sm text-secondary">Aucune activité pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($activites as $act): ?>
          <div class="flex items-start gap-space-xs">
            <span class="w-1.5 h-1.5 rounded-full bg-[#2563EB] mt-1.5 shrink-0"></span>
            <div><p class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($act['texte']); ?></p><p class="font-body-sm text-[10px] text-secondary"><?php echo htmlspecialchars($act['quand']); ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="rounded-2xl bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white p-space-lg flex flex-col sm:flex-row sm:items-center justify-between gap-space-md">
        <div class="flex items-center gap-space-sm">
          <div class="w-11 h-11 rounded-full bg-white/10 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px] text-[#FFC700]">mail</span></div>
          <div>
            <span class="font-label-badge text-label-badge uppercase tracking-wider text-[#FFC700] font-bold">Messages</span>
            <p class="font-label-lg text-label-lg font-bold">Échangez directement avec votre coach Wellbeing.</p>
          </div>
        </div>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="relative h-11 px-space-lg rounded-xl bg-[#FFC700] text-[#13203F] font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shrink-0 hover:opacity-90 transition-opacity">
          <?php echo $messagesNonLus > 0 ? $messagesNonLus . ' nouveau(x) message(s)' : 'Voir mes messages'; ?> <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </a>
      </div>
<?php pack_header_fin($sidebarNavWellbeing); ?>
