<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-oauth.php';

$user = require_login('mon-espace.php');
$compteGoogle = google_compte_pour_utilisateur((int) $user['id']);
$googleStatut = $_GET['google'] ?? '';

$stmt = db()->prepare('SELECT id, role, nom, prenom, email, telephone FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$compte = $stmt->fetch();

$erreursProfil = [];
$succesProfil = '';
$erreursMotDePasse = [];
$succesMotDePasse = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mettre_a_jour_profil') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');

        if ($nom === '' || $prenom === '' || $email === '') {
            $erreursProfil[] = 'Le nom, le prénom et l\'email sont obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreursProfil[] = 'Adresse email invalide.';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                $erreursProfil[] = 'Cet email est déjà utilisé par un autre compte.';
            } else {
                $stmt = db()->prepare('UPDATE users SET nom = ?, prenom = ?, email = ?, telephone = ? WHERE id = ?');
                $stmt->execute([$nom, $prenom, $email, $telephone !== '' ? $telephone : null, $user['id']]);

                $compte = ['id' => $user['id'], 'role' => $user['role'], 'nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'telephone' => $telephone];
                login_user(['id' => $user['id'], 'role' => $user['role'], 'nom' => $nom, 'prenom' => $prenom, 'email' => $email]);
                $succesProfil = 'Vos informations ont été mises à jour.';
            }
        }
    } elseif ($action === 'changer_mot_de_passe') {
        $motDePasseActuel = $_POST['mot_de_passe_actuel'] ?? '';
        $nouveauMotDePasse = $_POST['nouveau_mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation_mot_de_passe'] ?? '';

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hashActuel = $stmt->fetchColumn();

        if (!password_verify($motDePasseActuel, $hashActuel)) {
            $erreursMotDePasse[] = 'Mot de passe actuel incorrect.';
        } elseif (strlen($nouveauMotDePasse) < 8) {
            $erreursMotDePasse[] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        } elseif ($nouveauMotDePasse !== $confirmation) {
            $erreursMotDePasse[] = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($nouveauMotDePasse, PASSWORD_DEFAULT), $user['id']]);
            $succesMotDePasse = 'Votre mot de passe a été modifié.';
        }
    } elseif ($action === 'google_deconnecter') {
        google_compte_deconnecter((int) $user['id']);
        header('Location: parametres.php?google=deconnecte');
        exit;
    }
}

$roleLabels = ['client' => 'Client', 'consultant' => 'Consultant', 'admin' => 'Administrateur'];

render_head('Paramètres du compte — NexRaiz');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Paramètres du compte</span>
    </nav>

    <div class="flex items-center gap-space-sm">
      <div class="w-14 h-14 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-headline-sm text-headline-sm font-bold"><?php echo htmlspecialchars(mb_substr($compte['prenom'], 0, 1) . mb_substr($compte['nom'], 0, 1)); ?></div>
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo htmlspecialchars($compte['prenom'] . ' ' . $compte['nom']); ?></h1>
        <p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($roleLabels[$compte['role']] ?? $compte['role']); ?></p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-lg items-start">

      <form method="post" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="mettre_a_jour_profil">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Informations personnelles</h2>

        <?php if ($erreursProfil): ?>
        <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
          <?php foreach ($erreursProfil as $e): ?>
          <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($succesProfil): ?>
        <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
          <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
          <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succesProfil); ?></p>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nom</span>
            <input required type="text" name="nom" value="<?php echo htmlspecialchars($compte['nom']); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-lg text-label-lg text-on-surface font-semibold">Prénom</span>
            <input required type="text" name="prenom" value="<?php echo htmlspecialchars($compte['prenom']); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>
        </div>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email</span>
          <input required type="email" name="email" value="<?php echo htmlspecialchars($compte['email']); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone</span>
          <input type="tel" name="telephone" value="<?php echo htmlspecialchars($compte['telephone'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>

        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Enregistrer les modifications</span>
        </button>
      </form>

      <form method="post" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="changer_mot_de_passe">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Changer de mot de passe</h2>

        <?php if ($erreursMotDePasse): ?>
        <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
          <?php foreach ($erreursMotDePasse as $e): ?>
          <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($succesMotDePasse): ?>
        <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
          <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
          <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succesMotDePasse); ?></p>
        </div>
        <?php endif; ?>

        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Mot de passe actuel</span>
          <input required type="password" name="mot_de_passe_actuel" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nouveau mot de passe</span>
          <input required type="password" name="nouveau_mot_de_passe" placeholder="8 caractères minimum" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Confirmer le nouveau mot de passe</span>
          <input required type="password" name="confirmation_mot_de_passe" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>

        <button type="submit" class="self-start h-11 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">lock</span>
          <span>Changer le mot de passe</span>
        </button>
      </form>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <div class="flex items-center gap-space-xs">
        <span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]">mail</span></span>
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Compte Google</h2>
      </div>

      <?php if ($googleStatut === 'connecte'): ?>
      <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
        <p class="font-body-sm text-body-sm text-on-surface">Compte Google connecté avec succès.</p>
      </div>
      <?php elseif ($googleStatut === 'deconnecte'): ?>
      <div class="flex items-start gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-secondary mt-0.5">info</span>
        <p class="font-body-sm text-body-sm text-on-surface">Compte Google déconnecté.</p>
      </div>
      <?php elseif ($googleStatut === 'refuse'): ?>
      <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span>
        <p class="font-body-sm text-body-sm text-on-surface">Connexion annulée ou refusée par Google.</p>
      </div>
      <?php elseif ($googleStatut === 'erreur' || $googleStatut === 'indisponible'): ?>
      <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span>
        <p class="font-body-sm text-body-sm text-on-surface">La connexion à Google a échoué. Réessayez dans un instant ou contactez votre consultant.</p>
      </div>
      <?php endif; ?>

      <?php if ($compteGoogle): ?>
      <p class="font-body-sm text-body-sm text-secondary">Vos candidatures envoyées via NexRaiz partent désormais directement depuis <strong class="text-on-surface"><?php echo htmlspecialchars($compteGoogle['google_email']); ?></strong> — le recruteur voit votre vraie adresse.</p>
      <form method="post" class="self-start">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="google_deconnecter">
        <button type="submit" class="h-11 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">link_off</span>
          <span>Déconnecter mon compte Google</span>
        </button>
      </form>
      <?php else: ?>
      <p class="font-body-sm text-body-sm text-secondary">Connectez votre compte Google pour que vos candidatures « Postuler via NexRaiz » partent depuis votre propre Gmail plutôt que depuis l'adresse de NexRaiz. Facultatif, et réversible à tout moment.</p>
      <a href="google-oauth-start.php" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
        <span class="material-symbols-outlined text-[18px]">add_link</span>
        <span>Connecter mon compte Google</span>
      </a>
      <?php endif; ?>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex items-center justify-between gap-space-md flex-wrap">
      <div>
        <p class="font-label-lg text-label-lg text-on-surface font-bold">Se déconnecter</p>
        <p class="font-body-sm text-body-sm text-secondary">Terminer votre session sur cet appareil.</p>
      </div>
      <a href="logout.php" class="h-11 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
        <span class="material-symbols-outlined text-[18px]">logout</span>
        <span>Déconnexion</span>
      </a>
    </div>
  </div>
</section>

<?php render_footer(); ?>
