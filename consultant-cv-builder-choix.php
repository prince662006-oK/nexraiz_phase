<?php
/**
 * NexRaiz — CV Builder structuré (Pack Classic) : écran de choix du modèle
 * (mise en page avec/sans photo + couleur d'accent), passage obligatoire
 * avant de commencer les 6 étapes de construction du CV (voir
 * consultant-cv-builder.php). Troisième outil d'édition du CV, additif à
 * l'atelier/Studio et à l'éditeur Canvas — ne touche ni `corrections` ni
 * `canvas_editions`.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/cv-builder-classic.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$dossierId = (int) ($_GET['dossier'] ?? 0);
$stmt = db()->prepare('SELECT ds.*, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id WHERE ds.id = ? AND ds.pack_id = ?');
$stmt->execute([$dossierId, $packId]);
$dossier = $stmt->fetch();
if (!$dossier) {
    header('Location: consultant-analyse-classic.php');
    exit;
}
if ($dossier['consultant_id'] === null) {
    db()->prepare('UPDATE dossiers SET consultant_id = ? WHERE id = ?')->execute([$consultant['id'], $dossierId]);
}

$cv = classic_cv_builder_recuperer($dossierId);
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $erreurs = classic_cv_builder_definir_template($dossierId, $_POST['layout'] ?? '', $_POST['couleur'] ?? '');
    if (empty($erreurs)) {
        header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=1');
        exit;
    }
}

// Si un modèle est déjà choisi et qu'on n'est pas venu ici volontairement
// pour en changer, on file directement à la dernière étape atteinte.
if ($cv && $cv['layout'] && !isset($_GET['changer'])) {
    header('Location: consultant-cv-builder.php?dossier=' . $dossierId . '&etape=' . max(1, (int) $cv['etape_courante']));
    exit;
}

$layoutActuel = $cv['layout'] ?? null;
$couleurActuelle = $cv['couleur_accent'] ?? null;
$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

render_head('CV Builder structuré — Choix du modèle', 'Choisir le modèle de CV avant de commencer.');
?>
<div class="flex flex-col min-h-screen">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></a>
      <nav class="hidden md:flex items-center gap-space-sm">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
        <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>" class="px-space-md h-9 flex items-center rounded-xl bg-primary-container text-white font-label-lg text-label-lg font-bold">Corrections</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiersApercu, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-3xl">
      <a href="consultant-corrections-classic.php?dossier=<?php echo $dossierId; ?>" class="font-label-md text-label-md text-primary flex items-center gap-1 w-fit"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour au dossier</a>

      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">CV Builder structuré</h1>
        <p class="font-body-sm text-body-sm text-secondary">Dossier de <?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?> · <?php echo htmlspecialchars($dossier['numero']); ?></p>
        <p class="font-body-sm text-body-sm text-secondary mt-space-xs">Choisissez d'abord un modèle : la mise en page et la couleur ne pourront être changées qu'en revenant sur cet écran.</p>
      </div>

      <?php if (!empty($erreurs)): ?>
      <div class="bg-error-container/20 rounded-xl p-space-sm flex flex-col gap-1">
        <?php foreach ($erreurs as $e): ?>
        <p class="font-body-sm text-body-sm text-error">⚠ <?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" class="flex flex-col gap-space-lg bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <?php echo csrf_field(); ?>

        <div class="flex flex-col gap-space-sm">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Mise en page</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <?php foreach (cv_builder_layouts() as $cle => $label): ?>
            <label class="flex items-center gap-space-sm border-2 rounded-xl p-space-md cursor-pointer transition-colors <?php echo $layoutActuel === $cle ? 'border-primary bg-primary-container/5' : 'border-outline-variant hover:border-outline'; ?>">
              <input type="radio" name="layout" value="<?php echo $cle; ?>" <?php echo $layoutActuel === $cle ? 'checked' : ''; ?> required class="accent-primary">
              <span class="material-symbols-outlined text-[28px] text-primary"><?php echo $cle === 'avec_photo' ? 'account_circle' : 'description'; ?></span>
              <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($label); ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="flex flex-col gap-space-sm">
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Couleur d'accent</p>
          <div class="flex flex-wrap gap-space-sm">
            <?php foreach (cv_builder_palette_couleurs() as $cle => $couleur): ?>
            <label class="flex flex-col items-center gap-1 cursor-pointer">
              <span class="relative w-12 h-12 rounded-full border-4 transition-colors flex items-center justify-center <?php echo $couleurActuelle === $cle ? 'border-on-surface' : 'border-transparent'; ?>" style="background:<?php echo $couleur['hex']; ?>">
                <input type="radio" name="couleur" value="<?php echo $cle; ?>" <?php echo $couleurActuelle === $cle ? 'checked' : ''; ?> required class="absolute inset-0 opacity-0 cursor-pointer">
              </span>
              <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($couleur['label']); ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button type="submit" class="self-start h-12 px-space-xl rounded-full text-white font-label-lg text-label-lg font-bold flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity" style="background:linear-gradient(135deg,#2563EB,#13203F)">
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span><?php echo $cv ? 'Continuer' : 'Commencer la construction du CV'; ?>
        </button>
      </form>
    </main>
  </div>
</div>
</body>
</html>
