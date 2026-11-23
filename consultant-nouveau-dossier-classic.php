<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$erreurs = [];
$succes = null;
$doublonsPotentiels = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $confirmerDoublon = ($_POST['confirmer_doublon'] ?? '') === '1';

    if ($nom === '' || $prenom === '' || $email === '') {
        $erreurs[] = 'Le nom, le prénom et l\'email sont obligatoires.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Adresse email invalide.';
    } else {
        // Détection de doublon avant création : même nom+prénom, ou même
        // téléphone — jamais une fusion automatique, seulement une alerte
        // que le consultant choisit d'ignorer ou de suivre.
        if (!$confirmerDoublon) {
            $conditions = ['(LOWER(u.nom) = LOWER(?) AND LOWER(u.prenom) = LOWER(?))'];
            $parametres = [$nom, $prenom];
            if ($telephone !== '') {
                $conditions[] = 'u.telephone = ?';
                $parametres[] = $telephone;
            }
            $stmt = db()->prepare(
                "SELECT DISTINCT u.id, u.nom, u.prenom, u.email, u.telephone FROM users u
                 WHERE u.role = 'client' AND (" . implode(' OR ', $conditions) . ')'
            );
            $stmt->execute($parametres);
            $utilisateursSimilaires = $stmt->fetchAll();

            foreach ($utilisateursSimilaires as $u) {
                $stmt = db()->prepare(
                    'SELECT ds.id, ds.numero, ds.statut, ds.created_at, p.slug, p.nom AS pack_nom
                     FROM dossiers ds JOIN packs p ON p.id = ds.pack_id
                     WHERE ds.user_id = ? ORDER BY ds.created_at DESC'
                );
                $stmt->execute([$u['id']]);
                $doublonsPotentiels[] = ['utilisateur' => $u, 'dossiers' => $stmt->fetchAll()];
            }
        }

        if ($doublonsPotentiels) {
            // Ne crée rien : affiche l'alerte, le consultant décide (voir le
            // formulaire plus bas, bouton "Créer quand même").
        } else {
            $motDePasseTemporaire = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
            try {
                $user = register_client($nom, $prenom, $email, $telephone, $motDePasseTemporaire, false);
                $dossier = get_or_create_dossier($user['id'], 'classic');

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
}

$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

render_head('Espace NexRaiz — Nouveau dossier', 'Créer manuellement un dossier candidat pour le Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <nav class="hidden md:flex items-center gap-space-sm">
        <a href="consultant-tableau-bord-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Tableau de bord</a>
        <a href="consultant-dossiers-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Dossiers</a>
        <a href="consultant-analyse-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Analyses</a>
        <a href="consultant-corrections-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Corrections</a>
        <a href="consultant-entretiens-classic.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Entretiens</a>
        <a href="parametres.php" class="px-space-sm h-9 flex items-center font-label-lg text-label-lg text-secondary hover:text-on-surface transition-colors">Paramètres</a>
      </nav>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <div class="w-9 h-9 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
      <span class="hidden sm:inline font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($consultant['prenom'] . ' ' . $consultant['nom']); ?></span>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiersApercu, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="consultant-analyse-classic.php" class="hover:text-primary transition-colors">Dossiers Pack Classic</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Nouveau dossier</span>
    </nav>

    <div class="max-w-xl w-full flex flex-col gap-space-md">
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Créer un dossier candidat</h1>
      <p class="font-body-sm text-body-sm text-secondary">Utilisez ce formulaire pour créer manuellement un dossier Pack Classic (candidature reçue par un autre canal que le site). Un mot de passe temporaire sera généré : communiquez-le au candidat pour qu'il puisse se connecter et compléter son dossier.</p>

      <?php if ($succes): ?>
      <div class="bg-green-50 border border-green-200 rounded-xl p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[22px] text-green-700">check_circle</span>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Dossier <?php echo htmlspecialchars($succes['numero']); ?> créé avec succès</p>
        </div>
        <p class="font-body-sm text-body-sm text-secondary">Communiquez ces identifiants au candidat pour qu'il se connecte sur <strong>mon-espace.php</strong> :</p>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col gap-space-2xs font-body-sm text-body-sm">
          <span>Email : <strong><?php echo htmlspecialchars($succes['email']); ?></strong></span>
          <span>Mot de passe temporaire : <strong><?php echo htmlspecialchars($succes['mot_de_passe']); ?></strong></span>
        </div>
        <div class="flex gap-space-sm">
          <a href="consultant-corrections-classic.php?dossier=<?php echo $succes['dossier_id']; ?>" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
          <a href="consultant-nouveau-dossier-classic.php" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md flex items-center justify-center">Créer un autre dossier</a>
        </div>
      </div>
      <?php elseif ($doublonsPotentiels): ?>

      <div class="bg-orange-50 border border-orange-200 rounded-xl p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[22px] text-orange-600">warning</span>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">Candidat(s) similaire(s) déjà enregistré(s)</p>
        </div>
        <p class="font-body-sm text-body-sm text-secondary">Même nom/prénom<?php echo trim($_POST['telephone'] ?? '') !== '' ? ' ou même téléphone' : ''; ?> trouvé dans NexRaiz. Vérifiez qu'il ne s'agit pas de la même personne avant de créer un nouveau dossier — rien n'est fusionné automatiquement, à vous de décider.</p>

        <?php foreach ($doublonsPotentiels as $d): $u = $d['utilisateur']; ?>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col gap-space-xs">
          <p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($u['prenom'] . ' ' . $u['nom']); ?> <span class="font-body-sm text-[11px] text-secondary font-normal">— <?php echo htmlspecialchars($u['email']); ?><?php echo $u['telephone'] ? ' · ' . htmlspecialchars($u['telephone']) : ''; ?></span></p>
          <?php if (!$d['dossiers']): ?>
          <p class="font-body-sm text-[12px] text-secondary">Aucun dossier associé pour le moment.</p>
          <?php endif; ?>
          <?php foreach ($d['dossiers'] as $ds): ?>
          <div class="flex items-center justify-between gap-space-xs">
            <p class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($ds['pack_nom']); ?> · <?php echo htmlspecialchars($ds['numero']); ?> · créé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($ds['created_at']))); ?></p>
            <?php if ($ds['slug'] === 'classic'): ?>
            <a href="consultant-corrections-classic.php?dossier=<?php echo $ds['id']; ?>" class="h-8 px-space-sm rounded-lg bg-primary-container text-white font-label-md text-[11px] flex items-center gap-1">Ouvrir ce dossier <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <form method="post" class="flex flex-wrap items-center gap-space-xs pt-space-xs border-t border-outline-variant/40">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="confirmer_doublon" value="1">
          <input type="hidden" name="nom" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
          <input type="hidden" name="prenom" value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>">
          <input type="hidden" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          <input type="hidden" name="telephone" value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>">
          <button type="submit" class="h-10 px-space-md rounded-xl bg-orange-600 text-white font-label-md text-label-md flex items-center gap-1">Créer quand même un nouveau dossier</button>
          <a href="consultant-nouveau-dossier-classic.php" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md flex items-center justify-center">Annuler</a>
        </form>
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

      <a href="consultant-analyse-classic.php" class="self-start font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">arrow_back</span>Retour à la liste des dossiers</a>
    </div>

  </main>
  </div>

  <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-lg mt-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm max-w-max-width-content mx-auto w-full">
      <div class="flex items-center gap-1.5">
        <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-8 w-auto"></span>
      </div>
      <nav class="flex flex-wrap items-center gap-space-md">
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">À propos</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">CGU</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Confidentialité</a>
        <a href="#" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Mentions légales</a>
        <a href="contact.php" class="font-label-md text-label-md text-inverse-on-surface/80 hover:text-white transition-colors">Contact</a>
      </nav>
    </div>
  </footer>
</div>
</body>
</html>
