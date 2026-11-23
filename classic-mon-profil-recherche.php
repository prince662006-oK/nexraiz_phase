<?php
/**
 * NexRaiz — Activation de « Mes opportunités » (Pack Classic). Le profil de
 * recherche (types, métiers, secteurs, niveau d'études, expérience,
 * compétences, langues, villes, mobilité, télétravail) n'est plus saisi à
 * la main par le client : il est extrait automatiquement de son CV par
 * l'IA (includes/opportunites-ia.php::opportunites_extraire_profil_cv()),
 * jamais deviné au-delà de ce que le CV indique réellement. Le client ne
 * fait que déposer son CV et donner (ou retirer) son consentement — les
 * deux consentements distincts et révocables restent inchangés.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/opportunites.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];

$erreurs = [];
$sourceExtraction = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'revoquer') {
        opportunites_revoquer_consentement($dossierId, $_POST['type'] === 'recruteurs' ? 'recruteurs' : 'matching');
        header('Location: classic-mon-profil-recherche.php?revoque=1');
        exit;
    }

    $consentMatching = isset($_POST['consent_matching']);
    if (!$consentMatching) {
        $erreurs[] = "Vous devez accepter que NexRaiz utilise votre profil pour vous proposer des offres afin d'activer « Mes opportunités ».";
    }
    $texteCv = opportunites_texte_cv_dossier($dossierId);
    if ($texteCv === '') {
        $erreurs[] = "Déposez d'abord votre CV dans « Mes documents » : l'IA en a besoin pour construire votre profil de recherche.";
    }

    if (empty($erreurs)) {
        $cvUtilise = classic_documents_par_slot($dossierId)['cv']['actuel'] ?? null;
        $extraction = opportunites_extraire_profil_cv($texteCv);
        $sourceExtraction = $extraction['source'];
        $erreurs = opportunites_enregistrer_profil($dossierId, array_merge($extraction['donnees'], [
            'cv_document_id' => $cvUtilise['id'] ?? null,
            'consent_matching' => $consentMatching,
            'consent_recruteurs' => isset($_POST['consent_recruteurs']),
        ]));
        if (empty($erreurs)) {
            header('Location: classic-mes-opportunites.php?profil_enregistre=1');
            exit;
        }
    }
}

$profil = opportunites_profil_dossier($dossierId);
$cvActuel = classic_documents_par_slot($dossierId)['cv']['actuel'] ?? null;

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

$niveauxLabels = ['BAC' => 'BAC', 'BAC+2' => 'BAC+2', 'BAC+3' => 'BAC+3', 'BAC+4' => 'BAC+4', 'BAC+5 et plus' => 'BAC+5 et plus'];
$mobiliteLabels = ['sur_place' => 'Sur place uniquement', 'regionale' => 'Régionale', 'nationale' => 'Nationale', 'internationale' => 'Internationale'];
$teletravailLabels = ['non' => 'Non souhaité', 'partiel' => 'Partiel', 'total' => 'Total'];

render_head('Pack Classic — Mon profil de recherche');
?>
<div class="flex w-full min-h-screen bg-surface">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs">
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
    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Contacter mon consultant</a>
      <a href="logout.php" class="mx-space-xs h-10 rounded-xl flex items-center justify-center gap-1 font-label-md text-label-md text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px]">logout</span>Déconnexion</a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></a>
      <a href="classic-mes-opportunites.php" class="font-label-md text-label-md text-secondary hover:text-primary transition-colors flex items-center gap-1 ml-auto"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Mes opportunités</a>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-3xl">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Mon profil de recherche</h1>
        <p class="font-body-sm text-body-sm text-secondary">Votre profil de recherche est construit automatiquement par l'IA à partir de votre CV — vous n'avez rien à saisir manuellement.</p>
      </div>

      <?php if (isset($_GET['revoque'])): ?>
      <div class="bg-green-50 rounded-xl p-space-sm font-body-sm text-body-sm text-on-surface">Consentement retiré.</div>
      <?php endif; ?>
      <?php if (!empty($erreurs)): ?>
      <div class="bg-error-container/20 rounded-xl p-space-sm flex flex-col gap-1">
        <?php foreach ($erreurs as $e): ?>
        <p class="font-body-sm text-body-sm text-error">⚠ <?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" class="flex flex-col gap-space-lg bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <?php echo csrf_field(); ?>

        <div class="flex items-start gap-space-sm">
          <span class="material-symbols-outlined text-[24px] <?php echo $cvActuel ? 'text-green-600' : 'text-orange-600'; ?>"><?php echo $cvActuel ? 'check_circle' : 'error'; ?></span>
          <div class="flex-1">
            <p class="font-label-lg text-label-lg text-on-surface font-bold">CV utilisé pour l'analyse</p>
            <?php if ($cvActuel): ?>
            <p class="font-body-sm text-body-sm text-secondary">Votre CV actuellement déposé sera analysé par l'IA : <strong><?php echo htmlspecialchars($cvActuel['nom_original']); ?></strong>. <a href="classic-depot-documents.php" class="text-primary underline">Mettre à jour mon CV</a></p>
            <?php else: ?>
            <p class="font-body-sm text-body-sm text-secondary"><a href="classic-depot-documents.php" class="text-primary underline font-semibold">Déposez votre CV</a> pour que l'IA puisse construire votre profil de recherche — indispensable pour activer « Mes opportunités ».</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="flex flex-col gap-space-sm bg-primary-container/5 rounded-xl p-space-md">
          <label class="flex items-start gap-space-xs">
            <input type="checkbox" name="consent_matching" <?php echo ($profil['consent_matching'] ?? false) ? 'checked' : ''; ?> class="mt-1 accent-primary">
            <span class="font-body-sm text-body-sm text-on-surface">J'accepte que NexRaiz analyse mon CV et utilise les informations qui en sont extraites pour me proposer des offres d'emploi, de stage, d'alternance ou de mission.</span>
          </label>
          <label class="flex items-start gap-space-xs">
            <input type="checkbox" name="consent_recruteurs" <?php echo ($profil['consent_recruteurs'] ?? false) ? 'checked' : ''; ?> class="mt-1 accent-primary">
            <span class="font-body-sm text-body-sm text-on-surface">J'accepte que mon profil soit visible par des recruteurs partenaires <em>(fonctionnalité à venir — aucun recruteur n'a accès à votre profil pour le moment)</em>.</span>
          </label>
          <p class="font-body-sm text-[11px] text-secondary">Vous pouvez retirer ces consentements à tout moment depuis cette page.</p>
        </div>

        <div class="bg-surface-container-low rounded-xl p-space-sm">
          <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(OPPORTUNITES_DISCLAIMER); ?></p>
        </div>

        <button type="submit" <?php echo $cvActuel ? '' : 'disabled'; ?> class="self-start h-12 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
          <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
          <?php echo $profil ? 'Réanalyser mon CV et mettre à jour mon profil' : 'Analyser mon CV et activer mes opportunités'; ?>
        </button>
      </form>

      <?php if ($profil): ?>
      <div class="flex flex-col gap-space-sm bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[20px] text-primary">auto_awesome</span>
          <h2 class="font-label-lg text-label-lg text-on-surface font-bold">Profil extrait de votre CV</h2>
        </div>
        <p class="font-body-sm text-[12px] text-secondary">Si une information vous semble incorrecte, signalez-le à votre consultant via la messagerie — ou mettez à jour votre CV puis cliquez sur « Réanalyser » ci-dessus.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-xs font-body-sm text-body-sm">
          <p><strong class="text-on-surface">Types recherchés :</strong> <?php echo htmlspecialchars(implode(', ', $profil['types']) ?: '—'); ?></p>
          <p><strong class="text-on-surface">Métiers visés :</strong> <?php echo htmlspecialchars(implode(', ', $profil['metiers']) ?: '—'); ?></p>
          <p><strong class="text-on-surface">Secteurs :</strong> <?php echo htmlspecialchars(implode(', ', $profil['secteurs']) ?: '—'); ?></p>
          <p><strong class="text-on-surface">Niveau d'études :</strong> <?php echo htmlspecialchars($niveauxLabels[$profil['niveau_etudes'] ?? ''] ?? 'Non déterminé'); ?></p>
          <p><strong class="text-on-surface">Expérience :</strong> <?php echo $profil['annees_experience'] !== null ? htmlspecialchars((string) $profil['annees_experience']) . ' an(s)' : 'Non déterminée'; ?></p>
          <p><strong class="text-on-surface">Compétences :</strong> <?php echo htmlspecialchars(implode(', ', $profil['competences']) ?: '—'); ?></p>
          <p><strong class="text-on-surface">Langues :</strong> <?php echo htmlspecialchars(implode(', ', $profil['langues']) ?: '—'); ?></p>
          <p><strong class="text-on-surface">Villes visées :</strong> <?php echo htmlspecialchars(implode(', ', $profil['villes']) ?: 'Aucune préférence détectée'); ?></p>
          <p><strong class="text-on-surface">Mobilité :</strong> <?php echo htmlspecialchars($mobiliteLabels[$profil['mobilite']] ?? $profil['mobilite']); ?></p>
          <p><strong class="text-on-surface">Télétravail :</strong> <?php echo htmlspecialchars($teletravailLabels[$profil['teletravail']] ?? $profil['teletravail']); ?></p>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($profil && ($profil['consent_matching'] || $profil['consent_recruteurs'])): ?>
      <div class="flex flex-wrap gap-space-sm">
        <?php if ($profil['consent_matching']): ?>
        <form method="post" onsubmit="return confirm('Retirer ce consentement ? Vous ne recevrez plus de recommandations tant qu\'il ne sera pas réactivé.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="revoquer"><input type="hidden" name="type" value="matching">
          <button type="submit" class="h-10 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-error flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">block</span>Retirer le consentement « matching »</button>
        </form>
        <?php endif; ?>
        <?php if ($profil['consent_recruteurs']): ?>
        <form method="post" onsubmit="return confirm('Retirer ce consentement ?');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="revoquer"><input type="hidden" name="type" value="recruteurs">
          <button type="submit" class="h-10 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-error flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">block</span>Retirer le consentement « recruteurs »</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
