<?php
/**
 * NexRaiz — « Mes documents », Pack Booster. Reprend le principe déjà
 * éprouvé par Pack Classic (includes/documents-classic.php) : les dépôts
 * successifs d'une même catégorie forment une seule « fiche » (version
 * courante + historique), sans colonne de versionnage supplémentaire — voir
 * includes/documents.php::nx_documents_par_categorie().
 *
 * « Documents attendus » : Booster n'a pas de liste de pièces fixe comme
 * Classic (CV/lettre/offre) — les besoins dépendent du dossier. On réutilise
 * donc les actions ouvertes du coach (`actions_plan`) dont le titre ou la
 * description évoque un document (mots-clés) comme source de vérité des
 * demandes, sans table ni colonne de liaison supplémentaire : le
 * rapprochement avec un document déposé se fait par correspondance de
 * catégorie + date de dépôt postérieure à la création de l'action. Le dépôt
 * ne fait jamais basculer automatiquement le statut de l'action : le client
 * reste maître de clôturer son action depuis booster-mes-actions.php.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');
$dossierId = (int) $dossier['id'];

/**
 * Type canonique détecté dans le texte d'une action, ou null si l'action ne
 * semble pas concerner un document. Les libellés retournés sont aussi les
 * valeurs de `documents.categorie` utilisées lors d'un dépôt déclenché
 * depuis une fiche « document attendu ».
 */
function booster_type_document_action(string $texte): ?string
{
    $texte = mb_strtolower($texte, 'UTF-8');
    if (preg_match('/\bcv\b/u', $texte)) { return 'CV'; }
    if (preg_match('/lettre/u', $texte)) { return 'Lettre de motivation'; }
    if (preg_match('/diplome|diplôme|attestation|certificat/u', $texte)) { return 'Diplôme ou attestation'; }
    if (preg_match('/pi[eè]ce|justificatif|scan|photo/u', $texte)) { return 'Pièce justificative'; }
    if (preg_match('/document|fichier/u', $texte)) { return 'Document complémentaire'; }
    return null;
}

$explicationsType = [
    'CV'                      => 'Votre CV à jour, pour que votre coach prépare au mieux votre accompagnement.',
    'Lettre de motivation'    => 'Votre lettre de motivation, pour harmoniser le ton avec le reste de votre dossier.',
    'Diplôme ou attestation'  => 'Un diplôme ou une attestation demandée par votre coach.',
    'Pièce justificative'     => 'Une pièce justificative demandée par votre coach.',
    'Document complémentaire' => 'Un document complémentaire demandé par votre coach.',
];

$erreurDocument = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer_document') {
    require_csrf();
    $categorie = trim($_POST['categorie_document'] ?? '');
    $erreurDocument = nx_enregistrer_document_client($dossierId, 'booster', $categorie, 'consultant-dossier-booster.php?dossier=' . $dossierId);
    if ($erreurDocument === '') {
        $parametresConserves = $_GET;
        $parametresConserves['succes'] = '1';
        header('Location: booster-mes-documents.php?' . http_build_query($parametresConserves));
        exit;
    }
}
$documentEnvoye = isset($_GET['succes']) && $_GET['succes'] !== '';

// --- Documents attendus : dérivés des actions ouvertes du coach, jamais
// d'une liste figée ni d'une table inventée. ---
$stmt = db()->prepare("SELECT id, titre, description, echeance, created_at FROM actions_plan WHERE dossier_id = ? AND statut IN ('a_faire', 'en_cours', 'a_venir') ORDER BY created_at ASC");
$stmt->execute([$dossierId]);
$actionsOuvertes = $stmt->fetchAll();

$groupes = nx_documents_par_categorie($dossierId);

$demandesParType = [];
foreach ($actionsOuvertes as $a) {
    $type = booster_type_document_action($a['titre'] . ' ' . ($a['description'] ?? ''));
    if ($type === null) {
        continue;
    }
    // Deux actions ouvertes demandant le même type de document : on ne
    // garde qu'une seule fiche, celle à l'échéance la plus proche.
    if (isset($demandesParType[$type])) {
        $dejaEcheance = $demandesParType[$type]['echeance'];
        if ($dejaEcheance && (!$a['echeance'] || strtotime($a['echeance']) >= strtotime($dejaEcheance))) {
            continue;
        }
    }
    $demandesParType[$type] = [
        'type' => $type,
        'action_titre' => $a['titre'],
        'action_description' => $a['description'],
        'echeance' => $a['echeance'],
        'action_created_at' => $a['created_at'],
    ];
}

$demandesAffichees = [];
foreach ($demandesParType as $type => $d) {
    $actuel = $groupes[$type]['actuel'] ?? null;
    $satisfaitPar = ($actuel && strtotime($actuel['uploaded_at']) >= strtotime($d['action_created_at'])) ? $actuel : null;
    if ($satisfaitPar && $satisfaitPar['statut'] === 'valide') {
        continue; // demande résolue : le document apparaît normalement dans la liste des documents transmis.
    }
    $d['document'] = $satisfaitPar;
    $d['statut_demande'] = $satisfaitPar ? ($satisfaitPar['statut'] === 'a_corriger' ? 'a_corriger' : 'en_verification') : 'a_transmettre';
    $demandesAffichees[] = $d;
}
usort($demandesAffichees, function ($a, $b) {
    $rang = fn ($d) => $d['statut_demande'] === 'a_corriger' ? 0 : ($d['statut_demande'] === 'a_transmettre' ? 1 : 2);
    $diff = $rang($a) - $rang($b);
    if ($diff !== 0) { return $diff; }
    if ($a['echeance'] && $b['echeance']) { return strtotime($a['echeance']) - strtotime($b['echeance']); }
    return $a['echeance'] ? -1 : ($b['echeance'] ? 1 : 0);
});

// --- Compteurs : sur les données réelles, chaque document compté une seule
// fois (via "actuel", jamais les versions d'historique). ---
$groupesListe = [];
foreach ($groupes as $cle => $g) {
    $groupesListe[] = ['cle' => $cle, 'actuel' => $g['actuel'], 'historique' => $g['historique']];
}
$nbAttendus = count(array_filter($demandesAffichees, fn ($d) => $d['statut_demande'] === 'a_transmettre'));
$nbTransmis = count($groupesListe);
$nbEnVerification = count(array_filter($groupesListe, fn ($g) => $g['actuel']['statut'] === 'en_verification'));
$nbValides = count(array_filter($groupesListe, fn ($g) => $g['actuel']['statut'] === 'valide'));
$nbACorriger = count(array_filter($groupesListe, fn ($g) => $g['actuel']['statut'] === 'a_corriger'));

$statutsLabels = nx_documents_statuts();

// --- Filtres, recherche, tri (GET, aucune action destructrice) — opèrent
// sur les fiches (versions courantes), pas sur l'historique. ---
$filtre = $_GET['filtre'] ?? '';
$recherche = trim($_GET['q'] ?? '');
$tri = $_GET['tri'] ?? '';

$groupesRecherches = array_values(array_filter($groupesListe, function ($g) use ($recherche) {
    if ($recherche === '') { return true; }
    $texte = $g['actuel']['nom_original'] . ' ' . ($g['actuel']['categorie'] ?? '');
    return stripos($texte, $recherche) !== false;
}));

$compteOnglets = ['' => count($groupesRecherches), 'en_verification' => 0, 'valide' => 0, 'a_corriger' => 0];
foreach ($groupesRecherches as $g) {
    $compteOnglets[$g['actuel']['statut']]++;
}

$groupesAffiches = array_values(array_filter($groupesRecherches, fn ($g) => $filtre === '' || $g['actuel']['statut'] === $filtre));

if ($tri === 'nom') {
    usort($groupesAffiches, fn ($a, $b) => strcasecmp($a['actuel']['nom_original'], $b['actuel']['nom_original']));
} else {
    usort($groupesAffiches, fn ($a, $b) => strtotime($b['actuel']['uploaded_at']) - strtotime($a['actuel']['uploaded_at']));
}

$lienFiltre = function (string $f) use ($recherche, $tri) {
    $params = [];
    if ($recherche !== '') { $params['q'] = $recherche; }
    if ($tri !== '') { $params['tri'] = $tri; }
    if ($f !== '') { $params['filtre'] = $f; }
    return 'booster-mes-documents.php' . ($params ? '?' . http_build_query($params) : '');
};

render_head('Pack Booster — Mes documents');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-lg">
  <div class="max-w-max-width-content mx-auto">
    <a href="booster-tableau-bord.php" class="font-label-md text-label-md text-secondary hover:text-primary transition-colors flex items-center gap-1 mb-space-2xs"><span class="material-symbols-outlined text-[16px]">arrow_back</span>Tableau de bord</a>
    <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mes documents</h1>
    <p class="font-body-sm text-body-sm text-secondary max-w-xl">Retrouvez vos fichiers et suivez leur validation par votre coach. Vos livrables (bilan, plan de carrière...) restent dans <a href="booster-tableau-bord.php#mes-livrables" class="text-primary hover:underline">Mes livrables</a>.</p>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-2 sm:grid-cols-5 gap-space-sm">
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <span class="font-label-md text-[10px] uppercase tracking-wider text-orange-600 font-bold">Attendus</span>
      <span class="font-headline-sm text-headline-sm font-bold text-orange-600"><?php echo $nbAttendus; ?></span>
      <span class="font-body-sm text-[11px] text-secondary">À transmettre</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary font-bold">Transmis</span>
      <span class="font-headline-sm text-headline-sm font-bold text-on-surface"><?php echo $nbTransmis; ?></span>
      <span class="font-body-sm text-[11px] text-secondary">Au total</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <span class="font-label-md text-[10px] uppercase tracking-wider text-blue-600 font-bold">Vérification</span>
      <span class="font-headline-sm text-headline-sm font-bold text-blue-600"><?php echo $nbEnVerification; ?></span>
      <span class="font-body-sm text-[11px] text-secondary">En cours</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <span class="font-label-md text-[10px] uppercase tracking-wider text-green-600 font-bold">Validés</span>
      <span class="font-headline-sm text-headline-sm font-bold text-green-600"><?php echo $nbValides; ?></span>
      <span class="font-body-sm text-[11px] text-secondary">Acceptés</span>
    </div>
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-0.5">
      <span class="font-label-md text-[10px] uppercase tracking-wider text-error font-bold">À corriger</span>
      <span class="font-headline-sm text-headline-sm font-bold text-error"><?php echo $nbACorriger; ?></span>
      <span class="font-body-sm text-[11px] text-secondary">À compléter</span>
    </div>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <?php if ($documentEnvoye): ?>
    <div class="bg-green-50 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">check_circle</span>Document transmis à votre coach.</div>
    <?php endif; ?>

    <?php if ($demandesAffichees): ?>
    <div class="flex flex-col gap-space-sm">
      <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Documents attendus</h2>
      <?php foreach ($demandesAffichees as $i => $d): $zoneId = 'demande-' . $i; ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-space-xs <?php echo $d['statut_demande'] === 'a_corriger' ? 'border-l-4 border-error' : 'border-l-4 border-orange-300'; ?>">
        <div class="flex items-start justify-between gap-space-sm flex-wrap">
          <div class="min-w-0 flex-1">
            <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($d['type']); ?></p>
            <p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($explicationsType[$d['type']] ?? $d['action_titre']); ?></p>
            <?php if ($d['echeance']): ?><p class="font-body-sm text-[11px] text-secondary mt-0.5 flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">event</span>À transmettre avant le <?php echo htmlspecialchars(date('d/m/Y', strtotime($d['echeance']))); ?></p><?php endif; ?>
          </div>
          <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $d['statut_demande'] === 'a_corriger' ? $statutsLabels['a_corriger'][1] : ($d['statut_demande'] === 'en_verification' ? $statutsLabels['en_verification'][1] : 'bg-surface-container-high text-secondary'); ?>"><?php echo $d['statut_demande'] === 'a_transmettre' ? 'À transmettre' : ($d['statut_demande'] === 'a_corriger' ? 'À corriger' : 'En vérification'); ?></span>
        </div>

        <?php if ($d['statut_demande'] === 'a_corriger' && $d['document']['motif_correction']): ?>
        <div class="bg-error-container/20 border border-error/20 rounded-lg p-space-sm flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-error text-[16px] mt-0.5">error</span>
          <p class="font-body-sm text-[12px] text-on-surface">« <?php echo htmlspecialchars($d['document']['motif_correction']); ?> »</p>
        </div>
        <?php endif; ?>

        <?php if ($d['statut_demande'] === 'en_verification'): ?>
        <p class="font-body-sm text-[12px] text-blue-700 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">hourglass_top</span>Transmis le <?php echo htmlspecialchars(date('d/m/Y', strtotime($d['document']['uploaded_at']))); ?> — en attente de vérification par votre coach.</p>
        <?php else: ?>
        <button type="button" onclick="document.getElementById('zone-<?php echo $zoneId; ?>').classList.toggle('hidden');" class="self-start h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">upload_file</span><?php echo $d['statut_demande'] === 'a_corriger' ? 'Transmettre une nouvelle version' : 'Transmettre le document'; ?></button>
        <?php endif; ?>

        <div id="zone-<?php echo $zoneId; ?>" class="hidden flex-col gap-space-xs pt-space-xs border-t border-outline-variant/40">
          <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-space-xs items-start sm:items-center">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="deposer_document">
            <input type="hidden" name="categorie_document" value="<?php echo htmlspecialchars($d['type']); ?>">
            <input required type="file" name="document" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="flex-1 font-body-sm text-[12px]">
            <button type="submit" class="shrink-0 h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px]">Envoyer</button>
          </form>
          <p class="font-body-sm text-[10px] text-secondary">PDF, DOC, DOCX, JPG ou PNG — 10 Mo max.</p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($erreurDocument !== ''): ?>
    <div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreurDocument); ?></div>
    <?php endif; ?>

    <div class="flex flex-col gap-space-sm">
      <div class="flex items-center justify-between gap-space-sm flex-wrap">
        <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Documents transmis</h2>
        <button type="button" onclick="document.getElementById('zone-generale').classList.toggle('hidden');" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md font-bold flex items-center gap-1 hover:bg-primary transition-colors"><span class="material-symbols-outlined text-[18px]">upload_file</span>Transmettre un document</button>
      </div>

      <div id="zone-generale" class="hidden flex-col gap-space-xs bg-surface-container-lowest rounded-xl shadow-sm p-space-md">
        <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-space-xs">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="deposer_document">
          <input type="text" name="categorie_document" value="<?php echo htmlspecialchars($_POST['categorie_document'] ?? ''); ?>" placeholder="Type de document (CV, diplôme, bilan...) — optionnel" class="flex-1 h-10 px-space-sm rounded-lg border border-outline-variant bg-surface font-body-sm text-[12px] focus:outline-none focus:border-primary">
          <input required type="file" name="document" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="flex-1 font-body-sm text-[12px]">
          <button type="submit" class="shrink-0 h-10 px-space-md rounded-lg bg-on-surface text-white font-label-md text-[12px] font-bold">Envoyer</button>
        </form>
        <p class="font-body-sm text-[10px] text-secondary">PDF, DOC, DOCX, JPG ou PNG — 10 Mo max.</p>
      </div>

      <form method="get" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-wrap items-center gap-space-sm">
        <input type="hidden" name="filtre" value="<?php echo htmlspecialchars($filtre); ?>">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-outlined text-[16px] text-secondary absolute left-space-xs top-1/2 -translate-y-1/2">search</span>
          <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Rechercher par nom ou catégorie..." class="w-full h-9 pl-7 pr-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
        </div>
        <div class="flex items-center flex-wrap gap-1 bg-surface p-1 rounded-lg border border-outline-variant/40">
          <a href="<?php echo htmlspecialchars($lienFiltre('')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === '' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Tous (<?php echo $compteOnglets['']; ?>)</a>
          <a href="<?php echo htmlspecialchars($lienFiltre('en_verification')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'en_verification' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">En vérification (<?php echo $compteOnglets['en_verification']; ?>)</a>
          <a href="<?php echo htmlspecialchars($lienFiltre('valide')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'valide' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">Validés (<?php echo $compteOnglets['valide']; ?>)</a>
          <a href="<?php echo htmlspecialchars($lienFiltre('a_corriger')); ?>" class="px-space-sm py-1 whitespace-nowrap rounded-md font-label-md text-[12px] flex items-center <?php echo $filtre === 'a_corriger' ? 'bg-on-surface text-white' : 'text-secondary hover:text-on-surface'; ?>">À corriger (<?php echo $compteOnglets['a_corriger']; ?>)</a>
        </div>
        <div class="flex items-center gap-1">
          <label class="font-body-sm text-[12px] text-secondary">Trier par</label>
          <select name="tri" onchange="this.form.submit()" class="h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="" <?php echo $tri === '' ? 'selected' : ''; ?>>Plus récents</option>
            <option value="nom" <?php echo $tri === 'nom' ? 'selected' : ''; ?>>Nom</option>
          </select>
        </div>
        <button type="submit" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-secondary">Rechercher</button>
        <?php if ($recherche || $filtre || $tri): ?>
        <a href="booster-mes-documents.php" class="h-9 px-space-sm rounded-lg font-label-md text-[12px] text-secondary flex items-center">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <?php if (!$groupesListe): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-2xl flex flex-col items-center text-center gap-space-xs">
        <span class="material-symbols-outlined text-[36px] text-outline-variant">folder_off</span>
        <p class="font-headline-sm text-headline-sm text-on-surface font-bold">Aucun document transmis pour le moment</p>
        <p class="font-body-sm text-body-sm text-secondary max-w-sm">Utilisez le bouton « Transmettre un document » pour envoyer vos premiers fichiers à votre coach.</p>
      </div>
      <?php elseif (!$groupesAffiches): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucun document ne correspond à ces critères.</p>
      <?php endif; ?>

      <?php foreach ($groupesAffiches as $gi => $g):
        $actuel = $g['actuel'];
        $historique = $g['historique'];
        $estSansCategorie = str_starts_with($g['cle'], '__sans_categorie_');
        $categorieAffichee = $estSansCategorie ? 'Sans catégorie' : $g['cle'];
        [$icone, $couleur] = nx_icone_fichier($actuel['nom_original']);
        [$labelStatut, $classeStatut] = $statutsLabels[$actuel['statut']];
        $zoneId = 'maj-' . $gi;
      ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex flex-col gap-space-sm">
        <div class="flex items-start justify-between gap-space-sm flex-wrap">
          <div class="flex items-center gap-space-sm min-w-0">
            <div class="w-11 h-11 rounded-full bg-primary-container/10 <?php echo $couleur; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo $icone; ?></span></div>
            <div class="min-w-0">
              <p class="font-label-lg text-label-lg text-on-surface font-bold truncate"><?php echo htmlspecialchars($actuel['nom_original']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($categorieAffichee); ?> · <?php echo $actuel['taille_ko']; ?> Ko · reçu le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($actuel['uploaded_at']))); ?><?php echo $actuel['valide_le'] ? ' · validé le ' . htmlspecialchars(date('d/m/Y', strtotime($actuel['valide_le']))) : ''; ?></p>
            </div>
          </div>
          <span class="shrink-0 px-space-sm py-1 rounded-full font-label-md text-[11px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
        </div>

        <?php if ($actuel['statut'] === 'a_corriger' && $actuel['motif_correction']): ?>
        <div class="bg-error-container/20 border border-error/20 rounded-lg p-space-sm flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-error text-[16px] mt-0.5">error</span>
          <p class="font-body-sm text-[12px] text-on-surface">« <?php echo htmlspecialchars($actuel['motif_correction']); ?> »</p>
        </div>
        <?php endif; ?>

        <div class="flex items-center gap-space-xs flex-wrap">
          <a href="document-telecharger.php?id=<?php echo $actuel['id']; ?>" target="_blank" rel="noopener" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Ouvrir</a>
          <?php if ($actuel['statut'] === 'a_corriger'): ?>
          <button type="button" onclick="document.getElementById('zone-<?php echo $zoneId; ?>').classList.toggle('hidden');" class="h-9 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[12px] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">upload_file</span>Transmettre une nouvelle version</button>
          <?php endif; ?>
          <?php if ($historique): ?>
          <button type="button" onclick="document.getElementById('hist-<?php echo $zoneId; ?>').classList.toggle('hidden');" class="h-9 px-space-sm rounded-lg font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">history</span>Historique (<?php echo count($historique); ?>)</button>
          <?php endif; ?>
        </div>

        <?php if ($actuel['statut'] === 'a_corriger'): ?>
        <div id="zone-<?php echo $zoneId; ?>" class="hidden flex-col gap-space-xs pt-space-xs border-t border-outline-variant/40">
          <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-space-xs">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="deposer_document">
            <input type="hidden" name="categorie_document" value="<?php echo $estSansCategorie ? '' : htmlspecialchars($g['cle']); ?>">
            <input required type="file" name="document" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="flex-1 font-body-sm text-[12px]">
            <button type="submit" class="shrink-0 h-9 px-space-sm rounded-lg bg-on-surface text-white font-label-md text-[12px]">Envoyer</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($historique): ?>
        <div id="hist-<?php echo $zoneId; ?>" class="hidden flex-col gap-space-xs">
          <?php foreach ($historique as $v): [$labelV, $classeV] = $statutsLabels[$v['statut']]; ?>
          <div class="flex items-center justify-between gap-space-xs border border-outline-variant/50 rounded-lg p-space-sm flex-wrap">
            <div class="min-w-0"><p class="font-body-sm text-[12px] text-on-surface truncate"><?php echo htmlspecialchars($v['nom_original']); ?></p><p class="font-body-sm text-[11px] text-secondary">Déposé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($v['uploaded_at']))); ?><?php echo $v['motif_correction'] ? ' — « ' . htmlspecialchars($v['motif_correction']) . ' »' : ''; ?></p></div>
            <div class="shrink-0 flex items-center gap-1">
              <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-bold <?php echo $classeV; ?>"><?php echo htmlspecialchars($labelV); ?></span>
              <a href="document-telecharger.php?id=<?php echo $v['id']; ?>" target="_blank" rel="noopener" class="font-label-md text-[11px] text-primary">Ouvrir</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

</main>
<?php render_footer(); ?>
