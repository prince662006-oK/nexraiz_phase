<?php
/**
 * Gestion des comptes consultants/administrateurs, réservée aux
 * administrateurs. Les comptes clients ne sont pas gérés ici (ils
 * s'inscrivent eux-mêmes via le parcours des packs).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-sidebar-router.php';

$admin = require_role(['admin', 'super_admin'], 'mon-espace.php');
$estSuperAdmin = $admin['role'] === 'super_admin';
$rolesGerables = $estSuperAdmin ? ['consultant', 'admin', 'super_admin'] : ['consultant', 'admin'];

$succes = '';
$erreurs = [];
$nouveauMotDePasse = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'creer') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'consultant';
        $packId = (($_POST['pack_id'] ?? '') !== '') ? (int) $_POST['pack_id'] : null;

        if ($nom === '' || $prenom === '' || $email === '') {
            $erreurs[] = 'Le nom, le prénom et l\'email sont obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'Adresse email invalide.';
        } elseif (!in_array($role, $rolesGerables, true)) {
            $erreurs[] = 'Rôle invalide.';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $erreurs[] = 'Un compte existe déjà avec cet email.';
            } else {
                $motDePasseTemporaire = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
                $stmt = db()->prepare('INSERT INTO users (role, pack_id, nom, prenom, email, telephone, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$role, $packId, $nom, $prenom, $email, trim($_POST['telephone'] ?? ''), password_hash($motDePasseTemporaire, PASSWORD_DEFAULT)]);
                $succes = 'Compte créé pour ' . $prenom . ' ' . $nom . '.';
                $nouveauMotDePasse = ['email' => $email, 'mot_de_passe' => $motDePasseTemporaire];
            }
        }
    } elseif ($action === 'changer_role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $roleActuel = $stmt->fetchColumn();
        if ($userId === (int) $admin['id']) {
            $erreurs[] = 'Vous ne pouvez pas modifier votre propre rôle.';
        } elseif ($roleActuel === 'super_admin' && !$estSuperAdmin) {
            $erreurs[] = 'Seul un super administrateur peut modifier ce compte.';
        } elseif (in_array($role, $rolesGerables, true)) {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
            $succes = 'Rôle mis à jour.';
        }
    } elseif ($action === 'changer_pack') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $packId = (($_POST['pack_id'] ?? '') !== '') ? (int) $_POST['pack_id'] : null;
        $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === 'super_admin' && !$estSuperAdmin) {
            $erreurs[] = 'Seul un super administrateur peut modifier ce compte.';
        } else {
            db()->prepare('UPDATE users SET pack_id = ? WHERE id = ?')->execute([$packId, $userId]);
            $succes = 'Pack de rattachement mis à jour.';
        }
    } elseif ($action === 'toggle_actif') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $roleCible = $stmt->fetchColumn();
        if ($userId === (int) $admin['id']) {
            $erreurs[] = 'Vous ne pouvez pas désactiver votre propre compte.';
        } elseif ($roleCible === 'super_admin' && !$estSuperAdmin) {
            $erreurs[] = 'Seul un super administrateur peut modifier ce compte.';
        } else {
            db()->prepare('UPDATE users SET actif = 1 - actif WHERE id = ?')->execute([$userId]);
            $succes = 'Statut du compte mis à jour.';
        }
    } elseif ($action === 'changer_permissions') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $roleCible = $stmt->fetchColumn();
        if ($roleCible !== 'admin') {
            // Un super_admin a toujours accès à tout, et les permissions
            // par section ne s'appliquent qu'aux comptes administrateur —
            // pas aux consultants (modèle d'accès différent, par pack).
            $erreurs[] = 'Les permissions par section ne concernent que les comptes administrateur.';
        } elseif (!empty($_POST['tout'])) {
            db()->prepare('UPDATE users SET admin_permissions_json = NULL WHERE id = ?')->execute([$userId]);
            $succes = 'Permissions mises à jour : accès à tout.';
        } else {
            $sections = array_values(array_intersect($_POST['sections'] ?? [], ADMIN_SECTIONS_RESTREIGNABLES));
            db()->prepare('UPDATE users SET admin_permissions_json = ? WHERE id = ?')->execute([json_encode($sections, JSON_UNESCAPED_UNICODE), $userId]);
            $succes = 'Permissions mises à jour.';
        }
    } elseif ($action === 'reinitialiser_mot_de_passe') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare('SELECT email, role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $cible = $stmt->fetch();
        $email = $cible['email'] ?? null;
        if ($email && $cible['role'] === 'super_admin' && !$estSuperAdmin) {
            $erreurs[] = 'Seul un super administrateur peut modifier ce compte.';
            $email = null;
        }
        if ($email) {
            $motDePasseTemporaire = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($motDePasseTemporaire, PASSWORD_DEFAULT), $userId]);
            $succes = 'Mot de passe réinitialisé.';
            $nouveauMotDePasse = ['email' => $email, 'mot_de_passe' => $motDePasseTemporaire];
        }
    }
}

$stmt = db()->query('SELECT id, slug, nom FROM packs ORDER BY nom');
$packs = $stmt->fetchAll();
$packsParId = [];
foreach ($packs as $p) { $packsParId[$p['id']] = $p; }

$roleLabels = ['consultant' => 'Consultant', 'admin' => 'Administrateur', 'super_admin' => 'Super administrateur'];
$sectionLabels = [
    'dossiers' => 'Dossiers', 'paiements' => 'Paiements', 'rendezvous' => 'Rendez-vous',
    'ressources' => 'Ressources', 'formations' => 'Formations', 'modeles' => 'Modèles de documents', 'actualites' => 'Actualités',
];

$recherche = trim($_GET['q'] ?? '');
$sql = "SELECT id, role, pack_id, actif, nom, prenom, email, telephone, created_at, admin_permissions_json FROM users WHERE role IN ('consultant', 'admin', 'super_admin')";
$params = [];
if ($recherche !== '') {
    $sql .= ' AND (nom LIKE ? OR prenom LIKE ? OR email LIKE ?)';
    $like = '%' . $recherche . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY role DESC, prenom, nom';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$utilisateurs = $stmt->fetchAll();

render_head('Espace NexRaiz — Utilisateurs', 'Gestion des comptes consultants et administrateurs.');
?>
<div class="flex w-full min-h-screen">

  <?php render_consultant_pack_sidebar($admin, 'utilisateurs'); ?>

  <div class="flex-1 flex flex-col min-w-0">
  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
    </a>
    <div class="flex items-center gap-space-sm shrink-0 ml-auto">
      <a href="mon-espace.php" class="font-label-md text-label-md text-secondary hover:text-on-surface transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour à mon espace</a>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-max-width-content mx-auto w-full">
    <div>
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Utilisateurs</h1>
      <p class="font-body-sm text-body-sm text-secondary">Gestion des comptes consultants et administrateurs NexRaiz.</p>
    </div>

    <?php if ($succes): ?>
    <div class="flex items-start gap-space-xs bg-green-50 rounded-xl p-space-sm">
      <span class="material-symbols-outlined text-[18px] text-green-700 mt-0.5">check_circle</span>
      <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($succes); ?></p>
    </div>
    <?php endif; ?>
    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($nouveauMotDePasse): ?>
    <div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col gap-space-2xs font-body-sm text-body-sm border border-outline-variant">
      <p class="font-label-md text-label-md text-on-surface font-bold">Identifiants à communiquer :</p>
      <span>Email : <strong><?php echo htmlspecialchars($nouveauMotDePasse['email']); ?></strong></span>
      <span>Mot de passe temporaire : <strong><?php echo htmlspecialchars($nouveauMotDePasse['mot_de_passe']); ?></strong></span>
    </div>
    <?php endif; ?>

    <details class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
      <summary class="font-label-lg text-label-lg text-on-surface font-bold cursor-pointer flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">person_add</span>Créer un compte</summary>
      <form method="post" class="flex flex-col gap-space-sm pt-space-sm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="creer">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <input type="text" name="nom" required placeholder="Nom" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <input type="text" name="prenom" required placeholder="Prénom" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <input type="email" name="email" required placeholder="Email" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <input type="tel" name="telephone" placeholder="Téléphone" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <select name="role" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="consultant">Consultant</option>
            <option value="admin">Administrateur</option>
            <?php if ($estSuperAdmin): ?>
            <option value="super_admin">Super administrateur</option>
            <?php endif; ?>
          </select>
          <select name="pack_id" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Aucun pack de rattachement</option>
            <?php foreach ($packs as $p): ?>
            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">Créer le compte</button>
      </form>
    </details>

    <form method="get" class="flex items-center gap-space-sm max-w-md">
      <div class="relative flex-1">
        <span class="material-symbols-outlined text-[18px] text-secondary absolute left-space-sm top-1/2 -translate-y-1/2">search</span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($recherche); ?>" placeholder="Nom ou email..." class="w-full h-11 pl-space-2xl pr-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary" style="padding-left:2.5rem">
      </div>
      <button type="submit" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">Rechercher</button>
    </form>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[900px] border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/60">
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Utilisateur</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Rôle</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Pack</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Statut</th>
              <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Membre depuis</th>
              <th class="text-right font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($utilisateurs as $u):
              $estMoi = (int) $u['id'] === (int) $admin['id'];
              $cibleEstSuperAdmin = $u['role'] === 'super_admin';
              $peutGerer = !$estMoi && (!$cibleEstSuperAdmin || $estSuperAdmin);
            ?>
            <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
              <td class="py-space-sm pr-space-sm">
                <span class="flex items-center gap-space-xs">
                  <span class="w-8 h-8 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[11px] font-bold shrink-0"><?php echo strtoupper(mb_substr($u['prenom'], 0, 1) . mb_substr($u['nom'], 0, 1)); ?></span>
                  <span class="flex flex-col">
                    <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($u['prenom'] . ' ' . $u['nom']); ?><?php echo $estMoi ? ' (vous)' : ''; ?></span>
                    <span class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($u['email']); ?></span>
                  </span>
                </span>
              </td>
              <td class="py-space-sm pr-space-sm">
                <?php if ($peutGerer): ?>
                <form method="post">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="changer_role">
                  <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                  <select name="role" onchange="this.form.submit()" class="h-9 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                    <option value="consultant" <?php echo $u['role'] === 'consultant' ? 'selected' : ''; ?>>Consultant</option>
                    <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Administrateur</option>
                    <?php if ($estSuperAdmin): ?>
                    <option value="super_admin" <?php echo $u['role'] === 'super_admin' ? 'selected' : ''; ?>>Super administrateur</option>
                    <?php endif; ?>
                  </select>
                </form>
                <?php else: ?>
                <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $cibleEstSuperAdmin ? 'bg-primary-container/10 text-primary' : 'bg-surface-container-high text-secondary'; ?>"><?php echo htmlspecialchars($roleLabels[$u['role']] ?? $u['role']); ?></span>
                <?php endif; ?>
              </td>
              <td class="py-space-sm pr-space-sm">
                <?php if ($peutGerer): ?>
                <form method="post">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="changer_pack">
                  <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                  <select name="pack_id" onchange="this.form.submit()" class="h-9 px-space-2xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-[12px] focus:outline-none focus:border-primary">
                    <option value="">Aucun</option>
                    <?php foreach ($packs as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo (int) ($u['pack_id'] ?? 0) === (int) $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nom']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <?php else: ?>
                <span class="font-body-sm text-[12px] text-secondary"><?php echo $u['pack_id'] ? htmlspecialchars($packsParId[$u['pack_id']]['nom'] ?? '') : 'Aucun'; ?></span>
                <?php endif; ?>
              </td>
              <td class="py-space-sm pr-space-sm">
                <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $u['actif'] ? 'bg-green-100 text-green-700' : 'bg-error-container text-error'; ?>"><?php echo $u['actif'] ? 'Actif' : 'Désactivé'; ?></span>
              </td>
              <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars(date('d/m/Y', strtotime($u['created_at']))); ?></td>
              <td class="py-space-sm text-right">
                <?php if (!$peutGerer): ?>
                <span class="font-body-sm text-[11px] text-secondary"><?php echo $estMoi ? '—' : 'Réservé au super admin'; ?></span>
                <?php else: ?>
                <div class="flex items-center justify-end gap-1">
                  <?php if ($u['role'] === 'admin'): ?>
                  <details class="inline-block text-left">
                    <summary class="h-8 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface hover:bg-surface-container-lowest transition-colors cursor-pointer list-none">Permissions</summary>
                    <?php $permsActuelles = $u['admin_permissions_json'] !== null ? json_decode($u['admin_permissions_json'], true) : null; ?>
                    <form method="post" class="mt-space-2xs w-52 bg-surface-container-low rounded-xl p-space-sm flex flex-col gap-space-2xs">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="changer_permissions">
                      <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                      <label class="flex items-center gap-1 font-body-sm text-[12px] text-on-surface font-semibold">
                        <input type="checkbox" name="tout" value="1" <?php echo $permsActuelles === null ? 'checked' : ''; ?> onchange="var f=this.closest('form');f.querySelectorAll('.perm-section').forEach(function(c){c.disabled=f.tout.checked;});">
                        Accès à tout
                      </label>
                      <?php foreach (ADMIN_SECTIONS_RESTREIGNABLES as $section): ?>
                      <label class="flex items-center gap-1 font-body-sm text-[12px] text-secondary pl-space-sm">
                        <input type="checkbox" class="perm-section" name="sections[]" value="<?php echo $section; ?>" <?php echo ($permsActuelles !== null && in_array($section, $permsActuelles, true)) ? 'checked' : ''; ?> <?php echo $permsActuelles === null ? 'disabled' : ''; ?>>
                        <?php echo htmlspecialchars($sectionLabels[$section]); ?>
                      </label>
                      <?php endforeach; ?>
                      <button type="submit" class="h-8 mt-space-2xs rounded-lg bg-primary-container text-white font-label-md text-[11px]">Enregistrer</button>
                    </form>
                  </details>
                  <?php endif; ?>
                  <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reinitialiser_mot_de_passe">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    <button type="submit" class="h-8 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] text-on-surface hover:bg-surface-container-lowest transition-colors" title="Réinitialiser le mot de passe">Réinitialiser MDP</button>
                  </form>
                  <?php if (!$estMoi): ?>
                  <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle_actif">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    <button type="submit" class="h-8 px-space-xs rounded-lg border border-outline-variant font-label-md text-[11px] <?php echo $u['actif'] ? 'text-error' : 'text-green-700'; ?> hover:bg-surface-container-lowest transition-colors"><?php echo $u['actif'] ? 'Désactiver' : 'Réactiver'; ?></button>
                  </form>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$utilisateurs): ?>
            <tr><td colspan="6" class="py-space-md text-center font-body-sm text-body-sm text-secondary">Aucun utilisateur ne correspond à cette recherche.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="font-body-sm text-[12px] text-secondary"><?php echo count($utilisateurs); ?> utilisateur<?php echo count($utilisateurs) > 1 ? 's' : ''; ?> au total</p>
    </div>
  </main>
  </div>
</div>
</body>
</html>
