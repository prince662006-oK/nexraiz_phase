<?php
/**
 * NexRaiz — Back-office « Opportunités » (Pack Classic), réservé aux
 * consultants et admins. Créer/modifier/publier/dépublier/dupliquer une
 * offre, expirer manuellement. Même esprit que ressources.php, mais la
 * mutation est ouverte aux consultants (pas admin-only) : ce sont eux qui
 * connaissent le mieux les besoins de leurs candidats Pack Classic.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/opportunites.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');
$dossiers = classic_dossiers_apercu($packId, $consultant);

$secteursDisponibles = ['RH', 'Finance', 'Commercial', 'Assistanat', 'IT / Digital', 'Logistique', 'Hôtellerie', 'Autre'];
$niveauxEtudes = ['BAC', 'BAC+2', 'BAC+3', 'BAC+4', 'BAC+5 et plus'];

$erreurs = [];
$succes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $champsFormulaire = [
        'titre' => trim($_POST['titre'] ?? ''),
        'type' => $_POST['type'] ?? '',
        'entreprise' => trim($_POST['entreprise'] ?? ''),
        'secteur' => trim($_POST['secteur'] ?? ''),
        'ville' => trim($_POST['ville'] ?? ''),
        'pays' => trim($_POST['pays'] ?? "Côte d'Ivoire"),
        'teletravail' => $_POST['teletravail'] ?? 'non',
        'niveau_etudes_min' => trim($_POST['niveau_etudes_min'] ?? ''),
        'experience_min_annees' => $_POST['experience_min_annees'] ?? '',
        'competences_requises' => array_filter(array_map('trim', explode(',', $_POST['competences_requises'] ?? ''))),
        'description' => trim($_POST['description'] ?? ''),
        'lien_candidature' => trim($_POST['lien_candidature'] ?? ''),
        'email_candidature' => trim($_POST['email_candidature'] ?? ''),
        'salaire_indicatif' => trim($_POST['salaire_indicatif'] ?? ''),
        'date_limite' => $_POST['date_limite'] ?? '',
    ];

    switch ($action) {
        case 'creer':
            $resultat = opportunites_admin_creer($champsFormulaire, (int) $consultant['id']);
            $erreurs = $resultat['erreurs'];
            if (empty($erreurs)) {
                $succes = 'Offre créée en brouillon.';
            }
            break;
        case 'modifier':
            $erreurs = opportunites_admin_modifier($id, $champsFormulaire);
            if (empty($erreurs)) {
                $succes = 'Offre modifiée.';
            }
            break;
        case 'publier':
            opportunites_admin_publier($id);
            $succes = 'Offre publiée.';
            break;
        case 'depublier':
            opportunites_admin_depublier($id);
            $succes = 'Offre dépubliée.';
            break;
        case 'dupliquer':
            opportunites_admin_dupliquer($id);
            $succes = 'Offre dupliquée en brouillon.';
            break;
        case 'expirer_maintenant':
            $n = opportunites_expirer_echues();
            $succes = "$n offre(s) expirée(s).";
            break;
    }
}

$filtres = [
    'statut' => $_GET['statut'] ?? '',
    'type' => $_GET['type'] ?? '',
    'ville' => $_GET['ville'] ?? '',
];
$offres = opportunites_admin_liste(array_filter($filtres));
$offreAModifier = isset($_GET['modifier']) ? (int) $_GET['modifier'] : null;
$donneesAModifier = null;
if ($offreAModifier) {
    foreach ($offres as $o) {
        if ($o['id'] === $offreAModifier) { $donneesAModifier = $o; break; }
    }
}

render_head('Espace NexRaiz — Opportunités', 'Gestion des offres du Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></a>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <a href="mon-espace.php" class="font-label-md text-label-md text-secondary hover:text-on-surface transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Mon espace</a>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiers, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <div class="flex items-start justify-between gap-space-sm flex-wrap">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Opportunités — Pack Classic</h1>
          <p class="font-body-sm text-body-sm text-secondary">Offres proposées aux candidats selon leur score de correspondance.</p>
        </div>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="expirer_maintenant">
          <button type="submit" class="h-10 px-space-md rounded-xl border border-outline-variant bg-surface-container-lowest font-label-md text-label-md text-on-surface flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[16px]">schedule</span>Expirer les offres échues</button>
        </form>
      </div>

      <div class="bg-surface-container-low rounded-xl p-space-sm">
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(OPPORTUNITES_DISCLAIMER); ?></p>
      </div>

      <?php if ($succes): ?>
      <div class="bg-green-50 rounded-xl p-space-sm font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></div>
      <?php endif; ?>
      <?php if (!empty($erreurs)): ?>
      <div class="bg-error-container/20 rounded-xl p-space-sm flex flex-col gap-1">
        <?php foreach ($erreurs as $e): ?><p class="font-body-sm text-body-sm text-error">⚠ <?php echo htmlspecialchars($e); ?></p><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php
      // Formulaire de création/modification (même bloc, réutilisé selon $donneesAModifier).
      $d = $donneesAModifier ?? ['titre' => '', 'type' => 'emploi', 'entreprise' => '', 'secteur' => '', 'ville' => '', 'pays' => "Côte d'Ivoire", 'teletravail' => 'non', 'niveau_etudes_min' => '', 'experience_min_annees' => '', 'competences_requises' => [], 'description' => '', 'lien_candidature' => '', 'email_candidature' => '', 'salaire_indicatif' => '', 'date_limite' => ''];
      ?>
      <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg" <?php echo $donneesAModifier ? 'open' : ''; ?>>
        <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">add_circle</span><?php echo $donneesAModifier ? 'Modifier l\'offre #' . $offreAModifier : 'Créer une offre'; ?></summary>
        <form method="post" class="flex flex-col gap-space-sm pt-space-md">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="<?php echo $donneesAModifier ? 'modifier' : 'creer'; ?>">
          <?php if ($donneesAModifier): ?><input type="hidden" name="id" value="<?php echo $offreAModifier; ?>"><?php endif; ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <input type="text" name="titre" required placeholder="Titre de l'offre" value="<?php echo htmlspecialchars($d['titre']); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <select name="type" required class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
              <?php foreach (['emploi' => 'Emploi', 'stage' => 'Stage', 'alternance' => 'Alternance', 'mission' => 'Mission / freelance'] as $val => $label): ?>
              <option value="<?php echo $val; ?>" <?php echo $d['type'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="entreprise" placeholder="Entreprise (facultatif)" value="<?php echo htmlspecialchars($d['entreprise'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <select name="secteur" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
              <option value="">Secteur...</option>
              <?php foreach ($secteursDisponibles as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($d['secteur'] ?? '') === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="ville" placeholder="Ville" value="<?php echo htmlspecialchars($d['ville'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <input type="text" name="pays" placeholder="Pays" value="<?php echo htmlspecialchars($d['pays'] ?? "Côte d'Ivoire"); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <select name="teletravail" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
              <?php foreach (['non' => 'Télétravail : non', 'partiel' => 'Télétravail : partiel', 'total' => 'Télétravail : total'] as $val => $label): ?>
              <option value="<?php echo $val; ?>" <?php echo ($d['teletravail'] ?? 'non') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
            <select name="niveau_etudes_min" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
              <option value="">Niveau d'études min. : aucun</option>
              <?php foreach ($niveauxEtudes as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($d['niveau_etudes_min'] ?? '') === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="experience_min_annees" min="0" max="50" placeholder="Expérience min. (années)" value="<?php echo htmlspecialchars((string) ($d['experience_min_annees'] ?? '')); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <input type="text" name="competences_requises" placeholder="Compétences requises, séparées par des virgules" value="<?php echo htmlspecialchars(implode(', ', $d['competences_requises'] ?? [])); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm sm:col-span-2">
            <input type="url" name="lien_candidature" placeholder="Lien externe de candidature (https://...)" value="<?php echo htmlspecialchars($d['lien_candidature'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <input type="email" name="email_candidature" placeholder="E-mail de candidature (facultatif)" value="<?php echo htmlspecialchars($d['email_candidature'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <input type="text" name="salaire_indicatif" placeholder="Salaire indicatif (facultatif)" value="<?php echo htmlspecialchars($d['salaire_indicatif'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
            <input type="date" name="date_limite" value="<?php echo htmlspecialchars($d['date_limite'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm">
          </div>
          <textarea name="description" required rows="4" placeholder="Description de l'offre..." class="w-full p-space-sm rounded-xl border border-outline-variant font-body-sm text-body-sm resize-none"><?php echo htmlspecialchars($d['description'] ?? ''); ?></textarea>
          <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors"><?php echo $donneesAModifier ? 'Enregistrer les modifications' : 'Créer en brouillon'; ?></button>
        </form>
      </details>

      <form method="get" class="flex items-center gap-space-sm flex-wrap">
        <select name="statut" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Tous les statuts</option>
          <?php foreach (['brouillon' => 'Brouillon', 'publie' => 'Publiée', 'expire' => 'Expirée', 'depublie' => 'Dépubliée'] as $val => $label): ?>
          <option value="<?php echo $val; ?>" <?php echo $filtres['statut'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
        <select name="type" onchange="this.form.submit()" class="h-10 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm">
          <option value="">Tous les types</option>
          <?php foreach (['emploi' => 'Emploi', 'stage' => 'Stage', 'alternance' => 'Alternance', 'mission' => 'Mission'] as $val => $label): ?>
          <option value="<?php echo $val; ?>" <?php echo $filtres['type'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($filtres['statut'] || $filtres['type']): ?>
        <a href="opportunites-admin.php" class="font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">close</span>Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="flex flex-col gap-space-sm">
        <?php if (!$offres): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucune offre.</p>
        <?php endif; ?>
        <?php foreach ($offres as $o): ?>
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex items-start justify-between gap-space-sm flex-wrap">
          <div>
            <div class="flex items-center gap-space-xs flex-wrap">
              <span class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($o['titre']); ?></span>
              <span class="font-label-md text-[10px] uppercase px-space-xs py-0.5 rounded-full <?php echo ['brouillon' => 'bg-surface-container-high text-secondary', 'publie' => 'bg-green-100 text-green-700', 'expire' => 'bg-error-container/30 text-error', 'depublie' => 'bg-surface-container-high text-secondary'][$o['statut']]; ?>"><?php echo htmlspecialchars($o['statut']); ?></span>
              <span class="font-label-md text-[10px] uppercase px-space-xs py-0.5 rounded-full bg-surface-container-low text-secondary"><?php echo htmlspecialchars($o['source']); ?></span>
            </div>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($o['type']); ?><?php echo $o['ville'] ? ' · ' . htmlspecialchars($o['ville']) : ''; ?><?php echo $o['date_limite'] ? ' · limite ' . htmlspecialchars(date('d/m/Y', strtotime($o['date_limite']))) : ''; ?></p>
          </div>
          <div class="flex items-center gap-space-xs flex-wrap">
            <a href="?modifier=<?php echo $o['id']; ?>" class="h-9 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">edit</span>Modifier</a>
            <form method="post" class="inline"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $o['id']; ?>">
              <?php if ($o['statut'] === 'publie'): ?>
              <input type="hidden" name="action" value="depublier"><button type="submit" class="h-9 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md">Dépublier</button>
              <?php else: ?>
              <input type="hidden" name="action" value="publier"><button type="submit" class="h-9 px-space-sm rounded-full bg-primary-container text-white font-label-md text-label-md">Publier</button>
              <?php endif; ?>
            </form>
            <form method="post" class="inline"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $o['id']; ?>"><input type="hidden" name="action" value="dupliquer"><button type="submit" class="h-9 px-space-sm rounded-full border border-outline-variant font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">content_copy</span>Dupliquer</button></form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
