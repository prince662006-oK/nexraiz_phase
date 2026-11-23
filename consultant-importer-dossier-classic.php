<?php
/**
 * Import en masse de dossiers candidats Pack Classic depuis un fichier CSV
 * (nom;prenom;email;telephone), pour les candidatures reçues par un autre
 * canal (email, salon, partenaire...). Chaque ligne valide crée un compte
 * client et son dossier, exactement comme la création manuelle unitaire.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');

$resultats = [];
$erreurGlobale = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        $erreurGlobale = 'Merci de choisir un fichier CSV à importer.';
    } else {
        $poignee = fopen($_FILES['fichier']['tmp_name'], 'r');
        if (!$poignee) {
            $erreurGlobale = 'Impossible de lire le fichier envoyé.';
        } else {
            $entete = fgetcsv($poignee, 0, ';');
            $ligneNum = 1;
            while (($ligne = fgetcsv($poignee, 0, ';')) !== false) {
                $ligneNum++;
                if (count($ligne) < 3 || trim(implode('', $ligne)) === '') {
                    continue;
                }
                [$nom, $prenom, $email, $telephone] = array_pad(array_map('trim', $ligne), 4, '');

                if ($nom === '' || $prenom === '' || $email === '') {
                    $resultats[] = ['ligne' => $ligneNum, 'ok' => false, 'message' => 'Nom, prénom et email obligatoires.'];
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $resultats[] = ['ligne' => $ligneNum, 'ok' => false, 'message' => "Email invalide : {$email}"];
                    continue;
                }

                $motDePasseTemporaire = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
                try {
                    $user = register_client($nom, $prenom, $email, $telephone, $motDePasseTemporaire, false);
                    $dossier = get_or_create_dossier($user['id'], 'classic');

                    $stmt = db()->prepare('UPDATE dossiers SET source = ?, consultant_id = ? WHERE id = ?');
                    $stmt->execute(['Import CSV', $consultant['id'], $dossier['id']]);

                    log_activite((int) $dossier['id'], 'Dossier importé par le consultant ' . $consultant['prenom'] . ' ' . $consultant['nom']);

                    $resultats[] = [
                        'ligne' => $ligneNum, 'ok' => true,
                        'numero' => $dossier['numero'], 'nom' => "$prenom $nom", 'email' => $email, 'mot_de_passe' => $motDePasseTemporaire,
                    ];
                } catch (RuntimeException $e) {
                    $resultats[] = ['ligne' => $ligneNum, 'ok' => false, 'message' => $e->getMessage() . " ({$email})"];
                }
            }
            fclose($poignee);

            if (!$resultats) {
                $erreurGlobale = 'Aucune ligne exploitable trouvée dans le fichier (colonnes attendues : nom;prenom;email;telephone).';
            }
        }
    }
}

$nbOk = count(array_filter($resultats, fn ($r) => $r['ok']));
$nbEchecs = count($resultats) - $nbOk;

$dossiersApercu = classic_dossiers_apercu($packId, $consultant);

render_head('Espace NexRaiz — Importer un dossier', 'Import en masse de dossiers candidats Pack Classic depuis un fichier CSV.');
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
      <span class="text-on-surface font-semibold">Importer un dossier</span>
    </nav>

    <div class="max-w-2xl w-full flex flex-col gap-space-md">
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Importer des dossiers depuis un CSV</h1>
      <p class="font-body-sm text-body-sm text-secondary">Importez plusieurs candidatures Pack Classic en une fois, reçues par un autre canal que le site (email, salon, partenaire...). Le fichier doit être au format CSV avec le séparateur point-virgule et les colonnes : <strong>nom;prenom;email;telephone</strong> (une ligne d'en-tête est acceptée). Un compte et un mot de passe temporaire seront générés pour chaque ligne valide.</p>

      <?php if ($erreurGlobale): ?>
      <div class="flex items-start gap-space-xs bg-error-container/40 rounded-xl p-space-sm">
        <span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($erreurGlobale); ?></p>
      </div>
      <?php endif; ?>

      <?php if ($resultats): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center gap-space-sm">
          <span class="flex items-center gap-1 font-label-lg text-label-lg text-green-700"><span class="material-symbols-outlined text-[18px]">check_circle</span><?php echo $nbOk; ?> dossier(s) créé(s)</span>
          <?php if ($nbEchecs > 0): ?>
          <span class="flex items-center gap-1 font-label-lg text-label-lg text-error"><span class="material-symbols-outlined text-[18px]">error</span><?php echo $nbEchecs; ?> ligne(s) ignorée(s)</span>
          <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[560px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Ligne</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Résultat</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Détail</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($resultats as $r): ?>
              <tr class="border-b border-outline-variant/40">
                <td class="py-space-xs pr-space-sm font-body-sm text-body-sm text-secondary">#<?php echo $r['ligne']; ?></td>
                <td class="py-space-xs pr-space-sm">
                  <?php if ($r['ok']): ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-green-100 text-green-700">Créé — <?php echo htmlspecialchars($r['numero']); ?></span>
                  <?php else: ?>
                  <span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold bg-red-100 text-red-700">Ignoré</span>
                  <?php endif; ?>
                </td>
                <td class="py-space-xs pr-space-sm font-body-sm text-[12px] text-on-surface">
                  <?php if ($r['ok']): ?>
                  <?php echo htmlspecialchars($r['nom'] . ' — ' . $r['email']); ?> · mot de passe : <strong><?php echo htmlspecialchars($r['mot_de_passe']); ?></strong>
                  <?php else: ?>
                  <?php echo htmlspecialchars($r['message']); ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <a href="consultant-dossiers-classic.php" class="self-start h-10 px-space-md rounded-xl bg-primary-container text-white font-label-md text-label-md flex items-center justify-center gap-1">Voir mes dossiers <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
      </div>
      <?php else: ?>

      <form method="post" enctype="multipart/form-data" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-lg text-label-lg text-on-surface font-semibold">Fichier CSV <span class="text-error">*</span></span>
          <input required type="file" name="fichier" accept=".csv,text/csv" class="font-body-sm text-[12px] text-secondary">
        </label>
        <p class="font-body-sm text-[11px] text-secondary">Exemple de ligne : <code>Diop;Awa;awa.diop@example.com;0612345678</code></p>
        <button type="submit" class="self-start h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span class="material-symbols-outlined text-[18px]">upload_file</span>
          <span>Importer</span>
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
