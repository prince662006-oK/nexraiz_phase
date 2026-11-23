<?php
/**
 * NexRaiz — « Mes livrables », Pack Assistance. Documents remis par le
 * consultant (compte rendu, plan d'action...). Un livrable "en_preparation"
 * reste invisible en téléchargement pour le client (voir includes/livrables.php
 * et consultant-dossier.php::marquer_livrable_disponible()) — seul son titre
 * apparaît, avec un badge "En préparation", jamais de lien de téléchargement.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php'; // fournit nx_icone_fichier()

$dossier = require_dossier('assistance', 'espace-client.php');
$dossierId = (int) $dossier['id'];
$client = current_user();

$stmt = db()->prepare('SELECT * FROM livrables WHERE dossier_id = ? ORDER BY created_at DESC');
$stmt->execute([$dossierId]);
$livrables = $stmt->fetchAll();

$nbDisponibles = count(array_filter($livrables, fn ($l) => $l['statut'] === 'disponible'));
$nbEnPreparation = count(array_filter($livrables, fn ($l) => $l['statut'] === 'en_preparation'));

$filtre = $_GET['filtre'] ?? '';
$recherche = trim($_GET['q'] ?? '');

$livrablesRecherches = array_values(array_filter($livrables, fn ($l) => $recherche === '' || stripos($l['titre'], $recherche) !== false));
$livrablesAffiches = array_values(array_filter($livrablesRecherches, fn ($l) => $filtre === '' || $l['statut'] === $filtre));

$lienFiltre = function (string $f) use ($recherche) {
    $params = [];
    if ($recherche !== '') { $params['q'] = $recherche; }
    if ($f !== '') { $params['filtre'] = $f; }
    return 'mes-livrables.php' . ($params ? '?' . http_build_query($params) : '');
};

$messagesNonLus = 0;
$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNavAssistance = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'tableau-bord.php'],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'mes-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'mes-livrables.php', 'actif' => true],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'confirmation.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

render_head('Pack Assistance — Mes livrables');
require __DIR__ . '/includes/pack-shell.php';
pack_header_debut($client, $sidebarNavAssistance, [
    'sidebar_titre' => 'Pack Assistance', 'icone' => 'support_agent', 'titre' => 'Pack Assistance',
    'texte' => 'Un accompagnement à votre écoute', 'bouton_label' => 'En savoir plus', 'bouton_href' => 'decouverte.php',
]);
?>
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="tableau-bord.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <a href="tableau-bord.php" class="hover:text-primary transition-colors">Tableau de bord</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Mes livrables</span>
      </nav>

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mes livrables</h1>
        <p class="font-body-sm text-body-sm text-secondary max-w-xl">Les documents préparés par votre consultant (compte rendu, plan d'action...) apparaissent ici dès qu'ils sont prêts.</p>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-space-sm">
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-0.5">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center mb-0.5"><span class="material-symbols-outlined text-[16px]">card_giftcard</span></div>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo count($livrables); ?></span>
          <span class="font-body-sm text-[12px] text-secondary">Livrable<?php echo count($livrables) > 1 ? 's' : ''; ?> au total</span>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-0.5">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center mb-0.5"><span class="material-symbols-outlined text-[16px]">task_alt</span></div>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $nbDisponibles; ?></span>
          <span class="font-body-sm text-[12px] text-secondary">Disponible<?php echo $nbDisponibles > 1 ? 's' : ''; ?></span>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-0.5">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-white flex items-center justify-center mb-0.5"><span class="material-symbols-outlined text-[16px]">hourglass_top</span></div>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $nbEnPreparation; ?></span>
          <span class="font-body-sm text-[12px] text-secondary">En préparation</span>
        </div>
      </div>

      <form method="get" class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-sm flex flex-wrap items-center gap-space-sm">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-outlined text-[16px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Rechercher un livrable..." class="w-full h-9 pl-7 pr-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
        </div>
        <div class="flex items-center flex-wrap gap-1 bg-surface p-1 rounded-lg border border-outline-variant/40">
          <a href="<?php echo htmlspecialchars($lienFiltre('')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === '' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Tous</a>
          <a href="<?php echo htmlspecialchars($lienFiltre('disponible')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'disponible' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Disponibles</a>
          <a href="<?php echo htmlspecialchars($lienFiltre('en_preparation')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'en_preparation' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">En préparation</a>
        </div>
        <button type="submit" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-secondary">Rechercher</button>
        <?php if ($recherche || $filtre): ?>
        <a href="mes-livrables.php" class="h-9 px-space-sm rounded-lg font-label-md text-[12px] text-secondary flex items-center">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="flex flex-col gap-space-sm">
        <?php if (!$livrables): ?>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-2xl flex flex-col items-center text-center gap-space-xs">
          <span class="material-symbols-outlined text-[36px] text-outline-variant">card_giftcard</span>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Aucun livrable pour le moment</p>
          <p class="font-body-sm text-body-sm text-secondary max-w-sm">Vos livrables (compte rendu, plan d'action...) apparaîtront ici une fois générés par votre consultant.</p>
        </div>
        <?php elseif (!$livrablesAffiches): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucun livrable ne correspond à ces critères.</p>
        <?php endif; ?>

        <?php foreach ($livrablesAffiches as $l): [$icone, ] = nx_icone_fichier($l['chemin'] ?: $l['titre']); ?>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex items-center justify-between gap-space-sm flex-wrap">
          <div class="flex items-center gap-space-sm min-w-0">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 <?php echo $l['statut'] === 'disponible' ? 'bg-gradient-to-br from-[#8FB2FF] to-[#2563EB] text-white' : 'bg-surface-container text-secondary'; ?>"><span class="material-symbols-outlined text-[22px]"><?php echo $l['statut'] === 'disponible' ? $icone : 'hourglass_top'; ?></span></div>
            <div class="min-w-0">
              <p class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($l['titre']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo $l['statut'] === 'disponible' ? 'Reçu le ' . htmlspecialchars(date('d/m/Y', strtotime($l['envoye_le'] ?: $l['created_at']))) : 'En préparation par votre consultant'; ?></p>
            </div>
          </div>
          <?php if ($l['statut'] === 'disponible'): ?>
          <a href="<?php echo htmlspecialchars($l['chemin']); ?>" target="_blank" rel="noopener" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir</a>
          <?php else: ?>
          <span class="shrink-0 font-label-md text-[11px] font-bold px-space-sm py-1 rounded-full bg-orange-100 text-orange-700">En préparation</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($livrables): ?>
      <div class="bg-primary-container/5 border border-primary-container/20 rounded-2xl p-space-lg flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-sm">
        <div class="flex items-start gap-space-sm">
          <span class="material-symbols-outlined text-[20px] text-primary mt-0.5">support_agent</span>
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Une question sur un livrable ?</p>
            <p class="font-body-sm text-[13px] text-secondary">Votre consultant est disponible par messagerie.</p>
          </div>
        </div>
        <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="shrink-0 h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1">Contacter mon consultant</a>
      </div>
      <?php endif; ?>
<?php pack_header_fin($sidebarNavAssistance); ?>
