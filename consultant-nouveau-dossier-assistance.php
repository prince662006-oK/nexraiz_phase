<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');

$erreurs = [];
$succes = null;
$situationsConnues = ['Harcèlement présumé', 'Conflit avec un collègue', 'Discrimination alléguée', 'Licenciement contesté', 'Rupture de contrat', 'Problème de rémunération', 'Épuisement professionnel déclaré', 'Autre'];
$urgencesLabels = ['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique'];

// Enregistre la situation initiale (facultative) sur un dossier tout juste
// créé — jamais sur un dossier déjà existant, pour ne jamais écraser une
// qualification déjà renseignée par le client ou un collègue.
function enregistrer_situation_initiale(int $dossierId, string $situationType, string $urgence): void
{
    if ($situationType === '' && $urgence === '') {
        return;
    }
    db()->prepare('INSERT INTO qualifications (dossier_id, situation_type, urgence) VALUES (?, ?, ?)')
        ->execute([$dossierId, $situationType ?: null, $urgence ?: null]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $mode = $_POST['mode'] ?? 'nouveau';
    $situationType = in_array($_POST['situation_type'] ?? '', $situationsConnues, true) ? $_POST['situation_type'] : '';
    $urgence = array_key_exists($_POST['urgence'] ?? '', $urgencesLabels) ? $_POST['urgence'] : '';

    if ($mode === 'existant') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $stmt = db()->prepare("SELECT id, nom, prenom, email FROM users WHERE id = ? AND role = 'client'");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) {
            $erreurs[] = 'Merci de sélectionner un client existant dans la liste.';
        } else {
            $stmt = db()->prepare("SELECT id FROM dossiers WHERE user_id = ? AND pack_id = ? AND statut != 'cloture'");
            $stmt->execute([$clientId, $packId]);
            $dossierExistaitDeja = (bool) $stmt->fetchColumn();

            $dossier = get_or_create_dossier($clientId, 'assistance');
            if (!$dossierExistaitDeja) {
                db()->prepare('UPDATE dossiers SET source = ?, consultant_id = ? WHERE id = ?')
                    ->execute(['Consultant', $consultant['id'], $dossier['id']]);
                log_activite((int) $dossier['id'], 'Dossier créé manuellement par le consultant ' . $consultant['prenom'] . ' ' . $consultant['nom'] . ' pour un client existant');
                enregistrer_situation_initiale((int) $dossier['id'], $situationType, $urgence);
            }
            $succes = [
                'numero' => $dossier['numero'], 'email' => $client['email'], 'mot_de_passe' => null,
                'dossier_id' => $dossier['id'], 'deja_existant' => $dossierExistaitDeja,
            ];
        }
    } else {
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
                $dossier = get_or_create_dossier($user['id'], 'assistance');

                $stmt = db()->prepare('UPDATE dossiers SET source = ?, consultant_id = ? WHERE id = ?');
                $stmt->execute(['Consultant', $consultant['id'], $dossier['id']]);

                log_activite((int) $dossier['id'], 'Dossier créé manuellement par le consultant ' . $consultant['prenom'] . ' ' . $consultant['nom']);
                enregistrer_situation_initiale((int) $dossier['id'], $situationType, $urgence);

                $succes = [
                    'numero' => $dossier['numero'],
                    'email' => $email,
                    'mot_de_passe' => $motDePasseTemporaire,
                    'dossier_id' => $dossier['id'],
                    'deja_existant' => false,
                ];
            } catch (RuntimeException $e) {
                $erreurs[] = $e->getMessage();
            }
        }
    }
}

$rechercheClient = trim($_GET['recherche_client'] ?? '');
$clientsTrouves = [];
if ($rechercheClient !== '') {
    $like = '%' . $rechercheClient . '%';
    $stmt = db()->prepare("SELECT id, nom, prenom, email, telephone FROM users WHERE role = 'client' AND (nom LIKE ? OR prenom LIKE ? OR email LIKE ?) ORDER BY nom, prenom LIMIT 20");
    $stmt->execute([$like, $like, $like]);
    $clientsTrouves = $stmt->fetchAll();
}

render_head('Espace NexRaiz — Nouveau dossier', 'Créer manuellement un dossier candidat pour le Pack Assistance.');
?>
<div class="flex w-full min-h-screen">

  <?php render_assistance_dashboard_sidebar($packId, 'dossiers'); ?>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-lg min-w-0">
        <a href="index.php" class="flex items-center gap-1.5 shrink-0">
          <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
        </a>
      </div>
      <div class="flex items-center gap-space-xs shrink-0">
        <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold"><?php echo htmlspecialchars(mb_substr($consultant['prenom'], 0, 1) . mb_substr($consultant['nom'], 0, 1)); ?></div>
        <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
      <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
        <a href="consultant-dossiers-assistance.php" class="hover:text-primary transition-colors">Dossiers Pack Assistance</a>
        <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
        <span class="text-on-surface font-semibold">Nouveau dossier</span>
      </nav>

      <div class="max-w-xl w-full flex flex-col gap-space-md">
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Créer un dossier client</h1>
        <p class="font-body-sm text-body-sm text-secondary">Utilisez ce formulaire pour créer manuellement un dossier Pack Assistance (demande reçue par un autre canal que le site). Un mot de passe temporaire sera généré : communiquez-le au client pour qu'il puisse se connecter et compléter son dossier.</p>

        <?php if ($succes): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-space-lg flex flex-col gap-space-sm">
          <div class="flex items-center gap-space-xs">
            <span class="material-symbols-outlined text-[22px] text-green-700">check_circle</span>
            <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $succes['deja_existant'] ? 'Dossier existant retrouvé' : 'Dossier ' . htmlspecialchars($succes['numero']) . ' créé avec succès'; ?></p>
          </div>
          <?php if ($succes['deja_existant']): ?>
          <p class="font-body-sm text-body-sm text-secondary">Ce client avait déjà un dossier Pack Assistance actif (<?php echo htmlspecialchars($succes['numero']); ?>) : aucun doublon n'a été créé, vous êtes redirigé vers son dossier existant.</p>
          <?php elseif ($succes['mot_de_passe']): ?>
          <p class="font-body-sm text-body-sm text-secondary">Communiquez ces identifiants au client pour qu'il se connecte sur <strong>mon-espace.php</strong> :</p>
          <div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col gap-space-2xs font-body-sm text-body-sm">
            <span>Email : <strong><?php echo htmlspecialchars($succes['email']); ?></strong></span>
            <span>Mot de passe temporaire : <strong><?php echo htmlspecialchars($succes['mot_de_passe']); ?></strong></span>
          </div>
          <?php else: ?>
          <p class="font-body-sm text-body-sm text-secondary">Nouveau dossier Pack Assistance créé pour <strong><?php echo htmlspecialchars($succes['email']); ?></strong> (client déjà inscrit — il se connecte avec son mot de passe habituel).</p>
          <?php endif; ?>
          <div class="flex gap-space-sm">
            <a href="consultant-dossier.php?dossier=<?php echo $succes['dossier_id']; ?>" class="h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
            <a href="consultant-nouveau-dossier-assistance.php" class="h-10 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-md text-label-md flex items-center justify-center">Créer un autre dossier</a>
          </div>
        </div>
        <?php else: ?>

        <div class="flex items-center gap-1 bg-surface-container-low rounded-xl p-1 self-start">
          <button type="button" id="ongletNouveau" onclick="basculerModeClient('nouveau')" class="h-9 px-space-md rounded-lg font-label-md text-label-md transition-colors bg-surface-container-lowest text-on-surface shadow-sm">Nouveau client</button>
          <button type="button" id="ongletExistant" onclick="basculerModeClient('existant')" class="h-9 px-space-md rounded-lg font-label-md text-label-md transition-colors text-secondary">Client existant</button>
        </div>

        <form method="post" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="mode" id="champMode" value="nouveau">
          <?php if ($erreurs): ?>
          <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
            <?php foreach ($erreurs as $e): ?>
            <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div id="panneauNouveau" class="flex flex-col gap-space-md">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
              <label class="flex flex-col gap-space-2xs">
                <span class="font-label-lg text-label-lg text-on-surface font-semibold">Nom <span class="text-error">*</span></span>
                <input type="text" name="nom" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
              </label>
              <label class="flex flex-col gap-space-2xs">
                <span class="font-label-lg text-label-lg text-on-surface font-semibold">Prénom <span class="text-error">*</span></span>
                <input type="text" name="prenom" value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
              </label>
            </div>
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-lg text-label-lg text-on-surface font-semibold">Email <span class="text-error">*</span></span>
              <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            </label>
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-lg text-label-lg text-on-surface font-semibold">Téléphone</span>
              <input type="tel" name="telephone" value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            </label>
          </div>

          <div id="panneauExistant" class="hidden flex-col gap-space-sm">
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-lg text-label-lg text-on-surface font-semibold">Rechercher le client</span>
              <div class="flex gap-space-xs">
                <input type="text" id="champRechercheClient" value="<?php echo htmlspecialchars($rechercheClient); ?>" placeholder="Nom, prénom ou email…" class="flex-1 h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
                <button type="button" onclick="lancerRechercheClient()" class="h-11 px-space-md rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface">Rechercher</button>
              </div>
            </label>
            <?php if ($rechercheClient !== ''): ?>
            <div class="flex flex-col gap-space-2xs max-h-64 overflow-y-auto">
              <?php if (!$clientsTrouves): ?>
              <p class="font-body-sm text-[12px] text-secondary py-space-sm text-center">Aucun client ne correspond à cette recherche.</p>
              <?php endif; ?>
              <?php foreach ($clientsTrouves as $c): ?>
              <label class="flex items-center gap-space-xs p-space-sm rounded-lg border border-outline-variant/60 hover:bg-surface-container-low cursor-pointer">
                <input type="radio" name="client_id" value="<?php echo $c['id']; ?>" <?php echo (int) ($_POST['client_id'] ?? 0) === (int) $c['id'] ? 'checked' : ''; ?> required>
                <span class="font-body-sm text-[13px] text-on-surface"><strong><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></strong> — <?php echo htmlspecialchars($c['email']); ?><?php echo $c['telephone'] ? ' · ' . htmlspecialchars($c['telephone']) : ''; ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm pt-space-sm border-t border-outline-variant/40">
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-md text-[12px] text-on-surface font-semibold">Situation initiale <span class="font-normal text-secondary">(facultatif)</span></span>
              <select name="situation_type" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
                <option value="">— Non renseignée —</option>
                <?php foreach ($situationsConnues as $s): ?>
                <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($_POST['situation_type'] ?? '') === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col gap-space-2xs">
              <span class="font-label-md text-[12px] text-on-surface font-semibold">Urgence déclarée <span class="font-normal text-secondary">(facultatif)</span></span>
              <select name="urgence" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
                <option value="">— Non renseignée —</option>
                <?php foreach ($urgencesLabels as $val => $label): ?>
                <option value="<?php echo $val; ?>" <?php echo ($_POST['urgence'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <button type="submit" onclick="return validerAvantEnvoi()" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
            <span class="material-symbols-outlined text-[18px]">add_circle</span>
            <span>Créer le dossier</span>
          </button>
        </form>
        <?php endif; ?>

        <a href="consultant-dossiers-assistance.php" class="self-start font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">arrow_back</span>Retour à la liste des dossiers</a>
      </div>
    </main>
  </div>
</div>

<script>
function basculerModeClient(mode) {
  document.getElementById('champMode').value = mode;
  var estNouveau = mode === 'nouveau';
  document.getElementById('panneauNouveau').classList.toggle('hidden', !estNouveau);
  document.getElementById('panneauExistant').classList.toggle('hidden', estNouveau);
  document.getElementById('panneauExistant').classList.toggle('flex', !estNouveau);
  document.getElementById('ongletNouveau').classList.toggle('bg-surface-container-lowest', estNouveau);
  document.getElementById('ongletNouveau').classList.toggle('shadow-sm', estNouveau);
  document.getElementById('ongletNouveau').classList.toggle('text-on-surface', estNouveau);
  document.getElementById('ongletNouveau').classList.toggle('text-secondary', !estNouveau);
  document.getElementById('ongletExistant').classList.toggle('bg-surface-container-lowest', !estNouveau);
  document.getElementById('ongletExistant').classList.toggle('shadow-sm', !estNouveau);
  document.getElementById('ongletExistant').classList.toggle('text-on-surface', !estNouveau);
  document.getElementById('ongletExistant').classList.toggle('text-secondary', estNouveau);
}

function lancerRechercheClient() {
  var valeur = document.getElementById('champRechercheClient').value.trim();
  var url = new URL(window.location.href);
  if (valeur) { url.searchParams.set('recherche_client', valeur); } else { url.searchParams.delete('recherche_client'); }
  window.location.href = url.toString();
}
document.getElementById('champRechercheClient') && document.getElementById('champRechercheClient').addEventListener('keydown', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); lancerRechercheClient(); }
});

function validerAvantEnvoi() {
  var mode = document.getElementById('champMode').value;
  if (mode === 'nouveau') {
    var nom = document.querySelector('[name="nom"]').value.trim();
    var prenom = document.querySelector('[name="prenom"]').value.trim();
    var email = document.querySelector('[name="email"]').value.trim();
    if (!nom || !prenom || !email) { alert('Le nom, le prénom et l\'email sont obligatoires.'); return false; }
  } else {
    var selectionne = document.querySelector('[name="client_id"]:checked');
    if (!selectionne) { alert('Merci de rechercher puis de sélectionner un client existant.'); return false; }
  }
  return true;
}

<?php if ($rechercheClient !== ''): ?>
basculerModeClient('existant');
<?php endif; ?>
</script>
</body>
</html>
