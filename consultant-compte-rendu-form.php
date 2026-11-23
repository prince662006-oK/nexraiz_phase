<?php
/**
 * NexRaiz — Création / édition d'un compte rendu structuré (Pack
 * Assistance). Un brouillon se sauvegarde sans champs obligatoires au-delà
 * du type et de la date d'intervention (contraintes NOT NULL de la table) ;
 * la soumission pour validation exige un minimum de contenu réel (objet).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';
require_once __DIR__ . '/includes/comptes-rendus.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);
$condVisibilite = $voitTout ? '' : ' AND (ds.consultant_id = ? OR ds.consultant_id IS NULL)';
$paramVisibilite = $voitTout ? [] : [$consultantId];

$crId = (int) ($_GET['id'] ?? 0);
$dossierIdPreselectionne = (int) ($_GET['dossier'] ?? 0);
$compteRendu = null;

if ($crId) {
    $stmt = db()->prepare(
        "SELECT cr.*, ds.numero, u.nom AS client_nom, u.prenom AS client_prenom
         FROM comptes_rendus cr
         JOIN dossiers ds ON ds.id = cr.dossier_id
         JOIN users u ON u.id = ds.user_id
         WHERE cr.id = ? AND ds.pack_id = ?{$condVisibilite}"
    );
    $stmt->execute(array_merge([$crId, $packId], $paramVisibilite));
    $compteRendu = $stmt->fetch();
    if (!$compteRendu) {
        header('Location: consultant-comptes-rendus-assistance.php');
        exit;
    }
    if (!cr_peut_modifier($compteRendu, $consultant)) {
        header('Location: consultant-compte-rendu.php?id=' . $crId . '&erreur=' . urlencode('Ce compte rendu ne peut plus être modifié dans son statut actuel.'));
        exit;
    }
    $dossierId = (int) $compteRendu['dossier_id'];
} else {
    $dossierId = $dossierIdPreselectionne;
}

// Dossiers accessibles pour le sélecteur (création uniquement).
$stmt = db()->prepare(
    "SELECT ds.id, ds.numero, u.nom, u.prenom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id
     WHERE ds.pack_id = ?{$condVisibilite} AND ds.statut != 'cloture'
     ORDER BY ds.updated_at DESC"
);
$stmt->execute(array_merge([$packId], $paramVisibilite));
$dossiersDisponibles = $stmt->fetchAll();

$dossierActuel = null;
foreach ($dossiersDisponibles as $d) {
    if ((int) $d['id'] === $dossierId) {
        $dossierActuel = $d;
        break;
    }
}
if (!$dossierActuel && $dossierId) {
    $stmt = db()->prepare(
        "SELECT ds.id, ds.numero, u.nom, u.prenom FROM dossiers ds JOIN users u ON u.id = ds.user_id
         WHERE ds.id = ? AND ds.pack_id = ?{$condVisibilite}"
    );
    $stmt->execute(array_merge([$dossierId, $packId], $paramVisibilite));
    $dossierActuel = $stmt->fetch() ?: null;
}

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $dossierId = (int) $_POST['dossier_id'];
    $dossierValide = $dossierActuel && (int) $dossierActuel['id'] === $dossierId;
    if (!$dossierValide && !$compteRendu) {
        // Revalide le dossier choisi dans le formulaire contre la liste autorisée.
        foreach ($dossiersDisponibles as $d) {
            if ((int) $d['id'] === $dossierId) { $dossierValide = true; $dossierActuel = $d; break; }
        }
    }

    if (!$dossierValide) {
        $erreur = 'Dossier invalide ou non accessible.';
    } else {
        $actionsPropres = [];
        foreach ($_POST['action_description'] ?? [] as $i => $description) {
            $description = trim((string) $description);
            if ($description === '') { continue; }
            $actionsPropres[] = [
                'description' => $description,
                'responsable' => trim((string) ($_POST['action_responsable'][$i] ?? '')),
                'echeance'    => trim((string) ($_POST['action_echeance'][$i] ?? '')) ?: null,
            ];
        }

        $champs = [
            'type_intervention'    => in_array($_POST['type_intervention'] ?? '', array_keys(cr_types_labels()), true) ? $_POST['type_intervention'] : 'autre',
            'date_intervention'    => $_POST['date_intervention'] ?: date('Y-m-d'),
            'participants'         => trim($_POST['participants'] ?? ''),
            'objet'                => trim($_POST['objet'] ?? ''),
            'contexte'             => trim($_POST['contexte'] ?? ''),
            'objectifs'            => trim($_POST['objectifs'] ?? ''),
            'resume_echanges'      => trim($_POST['resume_echanges'] ?? ''),
            'faits_rapportes'      => trim($_POST['faits_rapportes'] ?? ''),
            'observations_internes' => trim($_POST['observations_internes'] ?? ''),
            'decisions'            => trim($_POST['decisions'] ?? ''),
            'actions_proposees'    => $actionsPropres,
            'prochaine_etape'      => trim($_POST['prochaine_etape'] ?? ''),
        ];

        $bouton = $_POST['bouton'] ?? 'brouillon';
        if ($bouton === 'soumettre' && $champs['objet'] === '') {
            $erreur = "L'objet du compte rendu est requis avant de le soumettre pour validation.";
        } else {
            if ($compteRendu) {
                cr_mettre_a_jour($crId, $dossierId, $champs);
                $id = $crId;
            } else {
                $id = cr_creer($dossierId, $consultantId, $champs);
            }

            if ($bouton === 'soumettre') {
                db()->prepare("UPDATE comptes_rendus SET statut = 'a_valider', motif_correction = NULL WHERE id = ?")->execute([$id]);
                log_activite($dossierId, 'Compte rendu soumis pour validation');
                $succesMsg = 'Compte rendu soumis pour validation.';
            } else {
                $succesMsg = 'Brouillon enregistré.';
            }
            header('Location: consultant-compte-rendu.php?id=' . $id . '&succes=' . urlencode($succesMsg));
            exit;
        }
    }
}

$donnees = $compteRendu ?: [
    'type_intervention' => 'entretien', 'date_intervention' => date('Y-m-d'), 'participants' => '', 'objet' => '',
    'contexte' => '', 'objectifs' => '', 'resume_echanges' => '', 'faits_rapportes' => '', 'observations_internes' => '',
    'decisions' => '', 'prochaine_etape' => '', 'statut' => 'brouillon',
];
$actionsExistantes = $compteRendu ? (json_decode((string) $compteRendu['actions_proposees_json'], true) ?: []) : [];
$typesLabels = cr_types_labels();

render_head('Espace NexRaiz — ' . ($compteRendu ? 'Modifier le compte rendu' : 'Nouveau compte rendu'), 'Compte rendu structuré d\'intervention, Pack Assistance.');
?>
<div class="flex w-full min-h-screen">
  <?php render_assistance_dashboard_sidebar($packId, 'comptes'); ?>
  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center px-gutter-mobile lg:px-gutter-desktop">
      <a href="consultant-comptes-rendus-assistance.php" class="flex items-center gap-1 font-label-md text-label-md text-secondary hover:text-on-surface"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Comptes rendus</a>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-3xl">
      <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo $compteRendu ? 'Modifier le compte rendu ' . htmlspecialchars($compteRendu['reference']) : 'Nouveau compte rendu'; ?></h1>

      <?php if ($erreur): ?>
      <div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreur); ?></div>
      <?php endif; ?>

      <?php if ($compteRendu && $compteRendu['statut'] === 'correction_demandee' && $compteRendu['motif_correction']): ?>
      <div class="bg-orange-50 border border-orange-200 text-orange-800 rounded-xl p-space-sm font-body-sm text-body-sm"><strong>Correction demandée :</strong> <?php echo htmlspecialchars($compteRendu['motif_correction']); ?></div>
      <?php endif; ?>

      <form method="post" id="formCR" class="flex flex-col gap-space-md">
        <?php echo csrf_field(); ?>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Informations générales</h2>

          <?php if (!$compteRendu): ?>
          <div class="flex flex-col gap-0.5">
            <label class="font-label-md text-[12px] text-secondary">Dossier concerné</label>
            <select name="dossier_id" required class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
              <option value="">— Choisir —</option>
              <?php foreach ($dossiersDisponibles as $d): ?>
              <option value="<?php echo $d['id']; ?>" <?php echo $dossierId === (int) $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['prenom'] . ' ' . $d['nom'] . ' · ' . $d['numero']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <input type="hidden" name="dossier_id" value="<?php echo $dossierId; ?>">
          <p class="font-body-sm text-[13px] text-on-surface">Dossier : <strong><?php echo htmlspecialchars($compteRendu['client_prenom'] . ' ' . $compteRendu['client_nom'] . ' · ' . $compteRendu['numero']); ?></strong></p>
          <?php endif; ?>

          <div class="grid grid-cols-2 gap-space-sm">
            <div class="flex flex-col gap-0.5">
              <label class="font-label-md text-[12px] text-secondary">Type d'intervention</label>
              <select name="type_intervention" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
                <?php foreach ($typesLabels as $cle => $label): ?>
                <option value="<?php echo $cle; ?>" <?php echo $donnees['type_intervention'] === $cle ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex flex-col gap-0.5">
              <label class="font-label-md text-[12px] text-secondary">Date de l'intervention</label>
              <input type="date" name="date_intervention" value="<?php echo htmlspecialchars($donnees['date_intervention']); ?>" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            </div>
          </div>
          <div class="flex flex-col gap-0.5">
            <label class="font-label-md text-[12px] text-secondary">Participants</label>
            <input type="text" name="participants" value="<?php echo htmlspecialchars($donnees['participants'] ?? ''); ?>" placeholder="Ex. Client, consultant NexRaiz" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
          </div>
          <div class="flex flex-col gap-0.5">
            <label class="font-label-md text-[12px] text-secondary">Objet <span class="text-error">*</span> <span class="text-secondary font-normal">(requis pour soumettre)</span></label>
            <input type="text" name="objet" value="<?php echo htmlspecialchars($donnees['objet'] ?? ''); ?>" placeholder="Résumé en une ligne" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
          </div>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Contenu partageable</h2>
          <p class="font-body-sm text-[11px] text-secondary -mt-1">Ces sections pourront être transmises au client une fois le compte rendu validé.</p>
          <?php foreach ([
              'contexte' => 'Contexte', 'objectifs' => 'Objectifs', 'resume_echanges' => 'Résumé des échanges',
              'faits_rapportes' => 'Faits rapportés', 'decisions' => 'Décisions', 'prochaine_etape' => 'Prochaine étape',
          ] as $champ => $label): ?>
          <div class="flex flex-col gap-0.5">
            <div class="flex items-center justify-between">
              <label class="font-label-md text-[12px] text-secondary"><?php echo $label; ?></label>
              <button type="button" class="btnReformuler font-label-md text-[11px] text-primary flex items-center gap-0.5" data-champ="<?php echo $champ; ?>"><span class="material-symbols-outlined text-[13px]">auto_awesome</span>Reformuler (IA)</button>
            </div>
            <textarea name="<?php echo $champ; ?>" id="champ_<?php echo $champ; ?>" rows="3" class="px-space-xs py-1.5 rounded-lg border border-outline-variant font-body-sm text-[13px] resize-y"><?php echo htmlspecialchars($donnees[$champ] ?? ''); ?></textarea>
            <div class="suggestionIA hidden bg-primary-container/10 border border-primary/30 rounded-lg p-space-xs flex flex-col gap-1" data-champ="<?php echo $champ; ?>">
              <p class="font-body-sm text-[12px] text-on-surface suggestionTexte"></p>
              <div class="flex gap-1">
                <button type="button" class="btnInsererSuggestion h-7 px-space-xs rounded bg-primary text-white font-label-md text-[11px]">Insérer</button>
                <button type="button" class="btnFermerSuggestion h-7 px-space-xs rounded border border-outline-variant font-label-md text-[11px] text-secondary">Ignorer</button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Actions à réaliser</h2>
          <p class="font-body-sm text-[11px] text-secondary -mt-1">Une fois le compte rendu validé, ces actions pourront être confirmées dans le plan d'action (module Suivi).</p>
          <div id="listeActions" class="flex flex-col gap-space-xs">
            <?php
            $lignesInitiales = $actionsExistantes ?: [['description' => '', 'responsable' => '', 'echeance' => '']];
            foreach ($lignesInitiales as $a):
            ?>
            <div class="flex items-center gap-1 ligneAction">
              <input type="text" name="action_description[]" value="<?php echo htmlspecialchars($a['description'] ?? ''); ?>" placeholder="Description de l'action" class="flex-1 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">
              <input type="text" name="action_responsable[]" value="<?php echo htmlspecialchars($a['responsable'] ?? ''); ?>" placeholder="Responsable" class="w-32 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">
              <input type="date" name="action_echeance[]" value="<?php echo htmlspecialchars($a['echeance'] ?? ''); ?>" class="w-36 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">
              <button type="button" class="supprimerLigne shrink-0 w-9 h-9 rounded-lg border border-outline-variant text-secondary hover:text-error flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">close</span></button>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="flex items-center gap-space-xs">
            <button type="button" id="ajouterAction" class="self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">add</span>Ajouter une action</button>
            <button type="button" id="btnSuggererActions" class="self-start h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">auto_awesome</span>Suggérer des actions (IA)</button>
          </div>
          <p id="messageIAActions" class="font-body-sm text-[12px] text-secondary hidden"></p>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Observations professionnelles (usage interne)</h2>
          <p class="font-body-sm text-[11px] text-secondary -mt-1">Jamais incluses dans le PDF partageable ni transmises au client.</p>
          <textarea name="observations_internes" rows="4" class="px-space-xs py-1.5 rounded-lg border border-outline-variant font-body-sm text-[13px] resize-y"><?php echo htmlspecialchars($donnees['observations_internes'] ?? ''); ?></textarea>
        </div>

        <div class="flex items-center gap-space-sm">
          <button type="submit" name="bouton" value="brouillon" class="h-11 px-space-md rounded-xl border border-outline-variant font-label-lg text-label-lg text-on-surface hover:bg-surface-container-low transition-colors">Enregistrer le brouillon</button>
          <button type="submit" name="bouton" value="soumettre" class="h-11 px-space-md rounded-xl bg-primary text-white font-label-lg text-label-lg shadow-sm hover:opacity-90 transition-opacity">Soumettre pour validation</button>
        </div>
      </form>
    </main>
  </div>
</div>

<script>
function ajouterLigneAction(description, responsable, echeance) {
  var ligne = document.createElement('div');
  ligne.className = 'flex items-center gap-1 ligneAction';
  ligne.innerHTML = '<input type="text" name="action_description[]" placeholder="Description de l\'action" class="flex-1 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">'
    + '<input type="text" name="action_responsable[]" placeholder="Responsable" class="w-32 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">'
    + '<input type="date" name="action_echeance[]" class="w-36 h-9 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[12px]">'
    + '<button type="button" class="supprimerLigne shrink-0 w-9 h-9 rounded-lg border border-outline-variant text-secondary hover:text-error flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">close</span></button>';
  document.getElementById('listeActions').appendChild(ligne);
  if (description) {
    var champs = ligne.querySelectorAll('input');
    champs[0].value = description;
    champs[1].value = responsable || '';
    champs[2].value = echeance || '';
  }
}

document.getElementById('ajouterAction').addEventListener('click', function () { ajouterLigneAction(); });
document.getElementById('listeActions').addEventListener('click', function (e) {
  var bouton = e.target.closest('.supprimerLigne');
  if (bouton) { bouton.closest('.ligneAction').remove(); }
});

function appelIA(mode, contenu, surBouton) {
  var dossierId = document.querySelector('[name="dossier_id"]').value;
  var csrf = document.querySelector('[name="_csrf"]').value;
  if (!dossierId) { alert('Choisissez un dossier avant de demander une assistance IA.'); return Promise.reject(); }
  surBouton.disabled = true;
  var texteOriginal = surBouton.innerHTML;
  surBouton.innerHTML = '…';
  var corps = new URLSearchParams({ dossier_id: dossierId, mode: mode, contenu: contenu, _csrf: csrf });
  return fetch('compte-rendu-ia.php', { method: 'POST', body: corps })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      surBouton.disabled = false;
      surBouton.innerHTML = texteOriginal;
      if (data.error) { alert(data.error); return Promise.reject(data.error); }
      return data;
    })
    .catch(function (err) {
      surBouton.disabled = false;
      surBouton.innerHTML = texteOriginal;
      throw err;
    });
}

document.querySelectorAll('.btnReformuler').forEach(function (bouton) {
  bouton.addEventListener('click', function () {
    var champ = bouton.dataset.champ;
    var textarea = document.getElementById('champ_' + champ);
    var contenu = textarea.value.trim();
    if (!contenu) { alert('Ce champ est vide.'); return; }
    appelIA('reformulation', contenu, bouton).then(function (data) {
      var boite = document.querySelector('.suggestionIA[data-champ="' + champ + '"]');
      boite.querySelector('.suggestionTexte').textContent = data.texte;
      boite.classList.remove('hidden');
    }).catch(function () {});
  });
});

document.querySelectorAll('.suggestionIA').forEach(function (boite) {
  boite.querySelector('.btnInsererSuggestion').addEventListener('click', function () {
    var champ = boite.dataset.champ;
    document.getElementById('champ_' + champ).value = boite.querySelector('.suggestionTexte').textContent;
    boite.classList.add('hidden');
  });
  boite.querySelector('.btnFermerSuggestion').addEventListener('click', function () {
    boite.classList.add('hidden');
  });
});

document.getElementById('btnSuggererActions').addEventListener('click', function () {
  var bouton = this;
  var contenu = ['faits_rapportes', 'decisions', 'prochaine_etape'].map(function (champ) {
    var el = document.getElementById('champ_' + champ);
    return el ? el.value.trim() : '';
  }).filter(Boolean).join('\n\n');
  var message = document.getElementById('messageIAActions');
  if (!contenu) { alert("Renseignez d'abord les faits rapportés, décisions ou la prochaine étape."); return; }
  appelIA('actions-suggerees', contenu, bouton).then(function (data) {
    if (!data.actions.length) {
      message.textContent = "L'IA n'a identifié aucune action concrète à partir de ce contenu.";
      message.classList.remove('hidden');
      return;
    }
    message.classList.add('hidden');
    data.actions.forEach(function (a) { ajouterLigneAction(a.description, a.responsable, a.echeance); });
  }).catch(function () {});
});
</script>
</body>
</html>
