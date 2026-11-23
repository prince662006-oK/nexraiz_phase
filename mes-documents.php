<?php
/**
 * NexRaiz — « Mes documents », Pack Assistance. Reprend exactement la
 * logique déjà en place dans tableau-bord.php (dépôt + liste), sur sa propre
 * page dédiée avec filtre/recherche. Le Pack Assistance ne suit pas de
 * statut de validation par document (contrairement à Pack Classic/Booster) :
 * aucun consultant-côté n'exploite ce workflow pour ce pack (vérifié dans
 * consultant-dossier.php), donc on ne l'invente pas ici.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';

$dossier = require_dossier('assistance', 'espace-client.php');
$dossierId = (int) $dossier['id'];
$client = current_user();

$stmt = db()->prepare('SELECT documents_disponibles FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$documentsDeclares = json_decode((string) ($stmt->fetchColumn() ?: ''), true) ?: [];

$erreurDocument = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_document') {
    require_csrf();
    $erreurDocument = nx_enregistrer_document_client($dossierId, 'assistance', trim($_POST['categorie_document'] ?? ''), 'consultant-dossier.php?dossier=' . $dossierId);
    if ($erreurDocument === '') {
        $parametresConserves = $_GET;
        $parametresConserves['succes'] = '1';
        header('Location: mes-documents.php?' . http_build_query($parametresConserves));
        exit;
    }
}
$documentEnvoye = isset($_GET['succes']) && $_GET['succes'] !== '';

$stmt = db()->prepare('SELECT * FROM documents WHERE dossier_id = ? ORDER BY uploaded_at DESC, id DESC');
$stmt->execute([$dossierId]);
$docs = $stmt->fetchAll();
$categoriesTransmises = array_filter(array_unique(array_column($docs, 'categorie')));
$documentsManquants = array_values(array_diff($documentsDeclares, $categoriesTransmises));

$filtre = $_GET['filtre'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? '';

$docsRecherches = array_values(array_filter($docs, function ($d) use ($recherche) {
    if ($recherche === '') return true;
    return stripos($d['nom_original'] . ' ' . ($d['categorie'] ?? ''), $recherche) !== false;
}));
$docsAffiches = $filtre === '' ? $docsRecherches : array_values(array_filter($docsRecherches, fn ($d) => $d['categorie'] === $filtre));

if ($tri === 'nom') {
    usort($docsAffiches, fn ($a, $b) => strcasecmp($a['nom_original'], $b['nom_original']));
} else {
    usort($docsAffiches, fn ($a, $b) => strtotime($b['uploaded_at']) - strtotime($a['uploaded_at']));
}

$lienFiltre = function (string $f) use ($recherche, $tri) {
    $params = [];
    if ($recherche !== '') { $params['q'] = $recherche; }
    if ($tri !== '') { $params['tri'] = $tri; }
    if ($f !== '') { $params['filtre'] = $f; }
    return 'mes-documents.php' . ($params ? '?' . http_build_query($params) : '');
};

$categoriesDocumentsForm = $documentsDeclares ?: ['Contrat de travail', 'Captures (messages, outils internes)', 'Emails', 'Témoignages', 'Échanges RH', 'Autres documents'];

$messagesNonLus = 0;
$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNavAssistance = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'tableau-bord.php'],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'mes-documents.php', 'actif' => true],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'mes-livrables.php'],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'confirmation.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

render_head('Pack Assistance — Mes documents');
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
        <span class="text-on-surface font-semibold">Mes documents</span>
      </nav>

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mes documents</h1>
        <p class="font-body-sm text-body-sm text-secondary max-w-xl">Transmettez vos pièces à votre consultant et retrouvez tout ce que vous avez déjà envoyé.</p>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-space-sm">
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-0.5">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center mb-0.5"><span class="material-symbols-outlined text-[16px]">folder</span></div>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo count($docs); ?></span>
          <span class="font-body-sm text-[12px] text-secondary">Document<?php echo count($docs) > 1 ? 's' : ''; ?> transmis</span>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-0.5">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-white flex items-center justify-center mb-0.5"><span class="material-symbols-outlined text-[16px]">schedule</span></div>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo count($documentsManquants); ?></span>
          <span class="font-body-sm text-[12px] text-secondary">Encore attendu<?php echo count($documentsManquants) > 1 ? 's' : ''; ?></span>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-0.5">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center mb-0.5"><span class="material-symbols-outlined text-[16px]">category</span></div>
          <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo count($categoriesTransmises); ?></span>
          <span class="font-body-sm text-[12px] text-secondary">Catégorie<?php echo count($categoriesTransmises) > 1 ? 's' : ''; ?> couverte<?php echo count($categoriesTransmises) > 1 ? 's' : ''; ?></span>
        </div>
      </div>

      <?php if ($documentsManquants): ?>
      <div class="bg-orange-50 border border-orange-200 rounded-2xl p-space-md flex items-start gap-space-xs">
        <span class="material-symbols-outlined text-orange-600 text-[20px] mt-0.5">info</span>
        <p class="font-body-sm text-[13px] text-on-surface">Documents encore attendus : <strong><?php echo htmlspecialchars(implode(', ', $documentsManquants)); ?></strong> (d'après ce que vous avez indiqué à la qualification).</p>
      </div>
      <?php endif; ?>

      <?php if ($documentEnvoye): ?>
      <div class="bg-green-50 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">check_circle</span>Document transmis à votre consultant.</div>
      <?php endif; ?>
      <?php if ($erreurDocument !== ''): ?>
      <div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreurDocument); ?></div>
      <?php endif; ?>

      <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex flex-col gap-space-xs">
        <h2 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="material-symbols-outlined text-[20px] text-[#2563EB]">upload_file</span>Transmettre un document</h2>
        <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-space-xs" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="deposer_document">
          <select name="categorie_document" class="h-10 px-space-sm rounded-lg border border-outline-variant bg-surface font-body-sm text-[12px] focus:outline-none focus:border-primary">
            <option value="">Catégorie (optionnel)</option>
            <?php foreach ($categoriesDocumentsForm as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
          </select>
          <input required type="file" name="document" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="flex-1 font-body-sm text-[12px]">
          <button type="submit" class="shrink-0 h-10 px-space-md rounded-lg bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white font-label-md text-[12px] font-bold flex items-center gap-1 hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[16px]">upload_file</span>Envoyer</button>
        </form>
        <p class="font-body-sm text-[10px] text-secondary">PDF, DOC, DOCX, JPG ou PNG — 10 Mo max. Vos documents sont confidentiels et visibles uniquement par votre consultant.</p>
      </div>

      <form method="get" class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-sm flex flex-wrap items-center gap-space-sm">
        <input type="hidden" name="filtre" value="<?php echo htmlspecialchars($filtre); ?>">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-outlined text-[16px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Rechercher par nom ou catégorie..." class="w-full h-9 pl-7 pr-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
        </div>
        <?php if (count($categoriesTransmises) > 1): ?>
        <div class="flex items-center flex-wrap gap-1 bg-surface p-1 rounded-lg border border-outline-variant/40">
          <a href="<?php echo htmlspecialchars($lienFiltre('')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === '' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Toutes</a>
          <?php foreach ($categoriesTransmises as $cat): ?>
          <a href="<?php echo htmlspecialchars($lienFiltre($cat)); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === $cat ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>"><?php echo htmlspecialchars($cat); ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="flex items-center gap-1">
          <label class="font-body-sm text-[12px] text-secondary">Trier par</label>
          <select name="tri" onchange="this.form.submit()" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="" <?php echo $tri === '' ? 'selected' : ''; ?>>Plus récents</option>
            <option value="nom" <?php echo $tri === 'nom' ? 'selected' : ''; ?>>Nom</option>
          </select>
        </div>
        <button type="submit" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-secondary">Rechercher</button>
        <?php if ($recherche || $filtre || $tri): ?>
        <a href="mes-documents.php" class="h-9 px-space-sm rounded-lg font-label-md text-[12px] text-secondary flex items-center">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="flex flex-col gap-space-sm">
        <?php if (!$docs): ?>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-2xl flex flex-col items-center text-center gap-space-xs">
          <span class="material-symbols-outlined text-[36px] text-outline-variant">folder_off</span>
          <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Aucun document déposé pour le moment</p>
          <p class="font-body-sm text-body-sm text-secondary max-w-sm">Utilisez le formulaire ci-dessus pour transmettre vos premiers fichiers à votre consultant.</p>
        </div>
        <?php elseif (!$docsAffiches): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucun document ne correspond à ces critères.</p>
        <?php endif; ?>

        <?php foreach ($docsAffiches as $d): [$icone, $couleur] = nx_icone_fichier($d['nom_original']); ?>
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-md flex items-center justify-between gap-space-sm flex-wrap">
          <div class="flex items-center gap-space-sm min-w-0">
            <div class="w-11 h-11 rounded-xl bg-primary-container/10 <?php echo $couleur; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo $icone; ?></span></div>
            <div class="min-w-0">
              <p class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($d['nom_original']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo $d['categorie'] ? htmlspecialchars($d['categorie']) . ' · ' : ''; ?><?php echo (int) $d['taille_ko']; ?> Ko · reçu le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($d['uploaded_at']))); ?></p>
            </div>
          </div>
          <a href="document-telecharger.php?id=<?php echo $d['id']; ?>" target="_blank" rel="noopener" class="shrink-0 h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir</a>
        </div>
        <?php endforeach; ?>
      </div>
<?php pack_header_fin($sidebarNavAssistance); ?>
