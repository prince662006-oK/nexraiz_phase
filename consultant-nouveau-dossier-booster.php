<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-booster.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('booster');

$erreurs = [];
$succes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');

    if ($nom === '' || $prenom === '' || $email === '') {
        $erreurs[] = 'Le nom, le prénom et l\'email sont obligatoires.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Adresse email invalide.';
    } else {
        $motDePasseTemporaire = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
        try {
            $user = register_client($nom, $prenom, $email, $telephone, $motDePasseTemporaire, false);
            $dossier = get_or_create_dossier($user['id'], 'booster');

            $stmt = db()->prepare('UPDATE dossiers SET source = ?, consultant_id = ? WHERE id = ?');
            $stmt->execute(['Consultant', $consultant['id'], $dossier['id']]);

            log_activite((int) $dossier['id'], 'Dossier créé manuellement par le consultant ' . $consultant['prenom'] . ' ' . $consultant['nom']);

            $succes = [
                'numero' => $dossier['numero'],
                'email' => $email,
                'mot_de_passe' => $motDePasseTemporaire,
                'dossier_id' => $dossier['id'],
            ];
        } catch (RuntimeException $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

render_head('Espace NexRaiz — Nouveau dossier', 'Créer manuellement un dossier client pour le Pack Booster.');
?>
<div class="flex w-full min-h-screen">

  <?php render_booster_sidebar($packId, 'clients'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <?php render_booster_header($consultant); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="consultant-dossiers-booster.php" class="hover:text-primary transition-colors">Mes clients Pack Booster</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Nouveau dossier</span>
      </nav>

      <div class="max-w-xl w-full flex flex-col gap-space-md">
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Créer un dossier client</h1>
        <p class="font-body-sm text-body-sm text-secondary">Utilisez ce formulaire pour créer manuellement un dossier Pack Booster. Un mot de passe temporaire sera généré : communiquez-le au client pour qu'il puisse se connecter et compléter son dossier.</p>

        <?php if ($succes): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-space-lg flex flex-col gap-space-sm">
          <div class="flex items-center gap-space-xs">
            <span class="material-symbols-outlined text-[22px] text-green-700">check_circle</span>
            <p class="font-label-lg text-label-lg text-on-surface font-bold">Dossier <?php echo htmlspecialchars($succes['numero']); ?> créé avec succès</p>
          </div>
          <p class="font-body-sm text-body-sm text-secondary">Communiquez ces identifiants au client pour qu'il se connecte sur <strong>mon-espace.php</strong> :</p>
          <div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col gap-space-2xs font-body-sm text-body-sm">
            <span>Email : <strong><?php echo htmlspecialchars($succes['email']); ?></strong></span>
            <span>Mot de passe temporaire : <strong><?php echo htmlspecialchars($succes['mot_de_passe']); ?></strong></span>
          </div>
          <div class="flex gap-space-sm">
            <a href="consultant-dossier-booster.php?dossier=<?php echo $succes['dossier_id']; ?>" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
            <a href="consultant-nouveau-dossier-booster.php" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md flex items-center justify-center">Créer un autre dossier</a>
          </div>
        </div>
        <?php else: ?>

        <form method="post" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
          <?php echo csrf_field(); ?>
          <?php if ($erreurs): ?>
          <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
            <?php foreach ($erreurs as $e): ?>
            <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nom <span class="text-error">*</span></span>
              <input required type="text" name="nom" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            </label>
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-lg text-label-lg text-on-surface font-semibold">Prénom <span class="text-error">*</span></span>
              <input required type="text" name="prenom" value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            </label>
          </div>
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email <span class="text-error">*</span></span>
            <input required type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone</span>
            <input type="tel" name="telephone" value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>

          <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
            <span class="material-symbols-outlined text-[18px]">add_circle</span>
            <span>Créer le dossier</span>
          </button>
        </form>
        <?php endif; ?>

        <a href="consultant-dossiers-booster.php" class="self-start font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">arrow_back</span>Retour à la liste des dossiers</a>
      </div>
    </main>
  </div>
</div>
</body>
</html>
