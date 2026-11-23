<?php
/**
 * NexRaiz — Détail d'un compte rendu structuré, Pack Assistance : cycle de
 * validation (brouillon → à valider → validé / correction demandée →
 * archivé), confirmation des actions proposées dans le plan d'action
 * (module Suivi, via actions_plan.compte_rendu_id), export PDF et partage
 * au client (réutilise le mécanisme livrables existant — un seul point de
 * publication, comme pour les autres livrables du Pack Assistance).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-assistance.php';
require_once __DIR__ . '/includes/pdf-compte-rendu.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('assistance');
$consultantId = (int) $consultant['id'];
$voitTout = in_array($consultant['role'], ['admin', 'super_admin'], true);
$condVisibilite = $voitTout ? '' : ' AND (ds.consultant_id = ? OR ds.consultant_id IS NULL)';
$paramVisibilite = $voitTout ? [] : [$consultantId];

$crId = (int) ($_GET['id'] ?? 0);

function charger_compte_rendu(int $crId, int $packId, string $condVisibilite, array $paramVisibilite): array|false
{
    $stmt = db()->prepare(
        "SELECT cr.*, ds.id AS dossier_id, ds.numero, ds.consultant_id, u.nom AS client_nom, u.prenom AS client_prenom,
                ua.nom AS auteur_nom, ua.prenom AS auteur_prenom, uv.nom AS valideur_nom, uv.prenom AS valideur_prenom
         FROM comptes_rendus cr
         JOIN dossiers ds ON ds.id = cr.dossier_id
         JOIN users u ON u.id = ds.user_id
         LEFT JOIN users ua ON ua.id = cr.auteur_id
         LEFT JOIN users uv ON uv.id = cr.valide_par
         WHERE cr.id = ? AND ds.pack_id = ?{$condVisibilite}"
    );
    $stmt->execute(array_merge([$crId, $packId], $paramVisibilite));
    return $stmt->fetch();
}

$compteRendu = charger_compte_rendu($crId, $packId, $condVisibilite, $paramVisibilite);
if (!$compteRendu) {
    header('Location: consultant-comptes-rendus-assistance.php');
    exit;
}
$dossierId = (int) $compteRendu['dossier_id'];
$estProprietaireDossier = $compteRendu['consultant_id'] === null || (int) $compteRendu['consultant_id'] === $consultantId;
$peutGererDossier = $voitTout || $estProprietaireDossier;
$peutModifierCR = cr_peut_modifier($compteRendu, $consultant);
$peutValider = cr_peut_valider($consultant) && $compteRendu['statut'] === 'a_valider';

$succes = $_GET['succes'] ?? '';
$erreur = $_GET['erreur'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'valider') {
        if (!cr_peut_valider($consultant) || $compteRendu['statut'] !== 'a_valider') {
            $erreur = "Action non autorisée dans l'état actuel.";
        } else {
            db()->prepare("UPDATE comptes_rendus SET statut = 'valide', valide_par = ?, valide_le = NOW(), motif_correction = NULL WHERE id = ?")
                ->execute([$consultantId, $crId]);
            log_activite($dossierId, 'Compte rendu validé : ' . $compteRendu['reference']);
            if ($compteRendu['auteur_id'] && (int) $compteRendu['auteur_id'] !== $consultantId) {
                notify((int) $compteRendu['auteur_id'], 'compte_rendu', 'Compte rendu validé', $compteRendu['reference'], 'consultant-compte-rendu.php?id=' . $crId);
            }
            header('Location: consultant-compte-rendu.php?id=' . $crId . '&succes=' . urlencode('Compte rendu validé.'));
            exit;
        }
    } elseif ($action === 'demander_correction') {
        $motif = trim($_POST['motif_correction'] ?? '');
        if (!cr_peut_valider($consultant) || $compteRendu['statut'] !== 'a_valider') {
            $erreur = "Action non autorisée dans l'état actuel.";
        } elseif ($motif === '') {
            $erreur = 'Le motif de la demande de correction est requis.';
        } else {
            db()->prepare("UPDATE comptes_rendus SET statut = 'correction_demandee', motif_correction = ? WHERE id = ?")
                ->execute([$motif, $crId]);
            log_activite($dossierId, 'Correction demandée sur le compte rendu ' . $compteRendu['reference']);
            if ($compteRendu['auteur_id'] && (int) $compteRendu['auteur_id'] !== $consultantId) {
                notify((int) $compteRendu['auteur_id'], 'compte_rendu', 'Correction demandée', $compteRendu['reference'] . ' : ' . $motif, 'consultant-compte-rendu-form.php?id=' . $crId);
            }
            header('Location: consultant-compte-rendu.php?id=' . $crId . '&succes=' . urlencode('Correction demandée à l\'auteur.'));
            exit;
        }
    } elseif ($action === 'confirmer_actions') {
        if (!$peutGererDossier || $compteRendu['statut'] !== 'valide') {
            $erreur = "Les actions ne peuvent être confirmées que sur un compte rendu validé.";
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM actions_plan WHERE compte_rendu_id = ?');
            $stmt->execute([$crId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $erreur = 'Les actions de ce compte rendu ont déjà été confirmées.';
            } else {
                $actions = json_decode((string) $compteRendu['actions_proposees_json'], true) ?: [];
                $inserer = db()->prepare(
                    'INSERT INTO actions_plan (dossier_id, titre, description, proprietaire, echeance, statut, priorite, compte_rendu_id)
                     VALUES (?, ?, ?, ?, ?, "a_faire", "normale", ?)'
                );
                $nb = 0;
                foreach ($actions as $a) {
                    $description = trim((string) ($a['description'] ?? ''));
                    if ($description === '') { continue; }
                    $inserer->execute([$dossierId, mb_substr($description, 0, 255), $description, $a['responsable'] ?? null, $a['echeance'] ?: null, $crId]);
                    $nb++;
                }
                if ($nb > 0) {
                    log_activite($dossierId, $nb . ' action(s) du compte rendu ' . $compteRendu['reference'] . ' confirmée(s) dans le plan d\'action');
                    $succes = $nb . ' action(s) ajoutée(s) au plan d\'action.';
                } else {
                    $erreur = 'Aucune action à confirmer sur ce compte rendu.';
                }
            }
        }
    } elseif ($action === 'generer_pdf') {
        if (!$peutGererDossier) {
            $erreur = 'Action non autorisée.';
        } else {
            $resultat = generer_pdf_compte_rendu($compteRendu, $compteRendu['client_prenom'], $compteRendu['client_nom']);
            db()->prepare('UPDATE comptes_rendus SET pdf_chemin = ? WHERE id = ?')->execute([$resultat['chemin'], $crId]);
            $succes = 'PDF généré.';
        }
    } elseif ($action === 'partager') {
        if (!$peutGererDossier || $compteRendu['statut'] !== 'valide') {
            $erreur = 'Seul un compte rendu validé peut être partagé au client.';
        } else {
            $resultat = generer_pdf_compte_rendu($compteRendu, $compteRendu['client_prenom'], $compteRendu['client_nom']);
            $stmt = db()->prepare(
                'INSERT INTO livrables (dossier_id, titre, nom_original, chemin, taille_ko, statut)
                 VALUES (?, ?, ?, ?, ?, "en_preparation")'
            );
            $titreLivrable = 'Compte rendu ' . $compteRendu['reference'];
            $stmt->execute([$dossierId, $titreLivrable, $resultat['nom'], $resultat['chemin'], $resultat['taille_ko']]);
            $livrableId = (int) db()->lastInsertId();
            marquer_livrable_disponible($livrableId, $dossierId);
            db()->prepare('UPDATE comptes_rendus SET pdf_chemin = ?, partage_le = NOW() WHERE id = ?')->execute([$resultat['chemin'], $crId]);
            $succes = 'Compte rendu partagé au client.';
        }
    } elseif ($action === 'archiver') {
        if (!$voitTout || $compteRendu['statut'] === 'archive') {
            $erreur = 'Action non autorisée.';
        } else {
            db()->prepare("UPDATE comptes_rendus SET statut = 'archive' WHERE id = ?")->execute([$crId]);
            log_activite($dossierId, 'Compte rendu archivé : ' . $compteRendu['reference']);
            $succes = 'Compte rendu archivé.';
        }
    }

    $compteRendu = charger_compte_rendu($crId, $packId, $condVisibilite, $paramVisibilite);
    $peutModifierCR = cr_peut_modifier($compteRendu, $consultant);
}

$typesLabels = cr_types_labels();
$statutsLabels = cr_statuts_labels();
[$labelStatut, $classeStatut] = $statutsLabels[$compteRendu['statut']];

$actions = json_decode((string) $compteRendu['actions_proposees_json'], true) ?: [];
$stmt = db()->prepare('SELECT id, statut, echeance FROM actions_plan WHERE compte_rendu_id = ?');
$stmt->execute([$crId]);
$actionsConfirmees = $stmt->fetchAll();

$stmt = db()->prepare("SELECT texte, created_at FROM activites WHERE dossier_id = ? AND texte LIKE ? ORDER BY created_at ASC");
$stmt->execute([$dossierId, '%' . $compteRendu['reference'] . '%']);
$historique = $stmt->fetchAll();

render_head('Espace NexRaiz — ' . $compteRendu['reference'], 'Compte rendu structuré d\'intervention, Pack Assistance.');
?>
<div class="flex w-full min-h-screen">
  <?php render_assistance_dashboard_sidebar($packId, 'comptes'); ?>
  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <a href="consultant-comptes-rendus-assistance.php" class="flex items-center gap-1 font-label-md text-label-md text-secondary hover:text-on-surface"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Comptes rendus</a>
      <a href="consultant-dossier.php?dossier=<?php echo $dossierId; ?>" class="font-label-md text-label-md text-primary hover:underline">Ouvrir le dossier</a>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-3xl">
      <?php if ($succes): ?><div class="bg-green-50 text-green-700 rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($succes); ?></div><?php endif; ?>
      <?php if ($erreur): ?><div class="bg-error-container text-error rounded-xl p-space-sm font-body-sm text-body-sm"><?php echo htmlspecialchars($erreur); ?></div><?php endif; ?>

      <div class="flex items-start justify-between gap-space-sm flex-wrap">
        <div>
          <p class="font-label-md text-[11px] text-secondary">RÉFÉRENCE <?php echo htmlspecialchars($compteRendu['reference']); ?></p>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo htmlspecialchars($typesLabels[$compteRendu['type_intervention']] ?? $compteRendu['type_intervention']); ?> — <?php echo htmlspecialchars(date('d/m/Y', strtotime($compteRendu['date_intervention']))); ?></h1>
          <p class="font-body-sm text-body-sm text-secondary">Client : <?php echo htmlspecialchars($compteRendu['client_prenom'] . ' ' . $compteRendu['client_nom'] . ' · ' . $compteRendu['numero']); ?></p>
        </div>
        <span class="px-space-sm py-1.5 rounded-full font-label-md text-[12px] font-semibold <?php echo $classeStatut; ?>"><?php echo htmlspecialchars($labelStatut); ?></span>
      </div>

      <?php if ($compteRendu['statut'] === 'correction_demandee' && $compteRendu['motif_correction']): ?>
      <div class="bg-orange-50 border border-orange-200 text-orange-800 rounded-xl p-space-sm font-body-sm text-body-sm"><strong>Correction demandée :</strong> <?php echo htmlspecialchars($compteRendu['motif_correction']); ?></div>
      <?php endif; ?>

      <div class="flex items-center gap-space-xs flex-wrap">
        <?php if ($peutModifierCR): ?>
        <a href="consultant-compte-rendu-form.php?id=<?php echo $crId; ?>" class="h-10 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">edit</span>Modifier</a>
        <?php endif; ?>

        <?php if ($peutValider): ?>
        <form method="post" onsubmit="return confirm('Valider ce compte rendu ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="valider">
          <button type="submit" class="h-10 px-space-sm rounded-lg bg-green-600 text-white font-label-md text-label-md flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">task_alt</span>Valider</button>
        </form>
        <button type="button" onclick="document.getElementById('formCorrection').classList.toggle('hidden')" class="h-10 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">undo</span>Demander une correction</button>
        <?php endif; ?>

        <?php if ($compteRendu['statut'] === 'valide' && $peutGererDossier): ?>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generer_pdf">
          <button type="submit" class="h-10 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">picture_as_pdf</span>Générer le PDF</button>
        </form>
        <?php if ($compteRendu['pdf_chemin']): ?>
        <a href="<?php echo htmlspecialchars($compteRendu['pdf_chemin']); ?>" target="_blank" rel="noopener" class="h-10 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Voir le PDF</a>
        <?php endif; ?>
        <?php if (!$compteRendu['partage_le']): ?>
        <form method="post" onsubmit="return confirm('Transmettre ce compte rendu au client ? Seul le contenu partageable sera visible, les observations internes ne seront jamais transmises.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="partager">
          <button type="submit" class="h-10 px-space-sm rounded-lg bg-primary text-white font-label-md text-label-md flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[16px]">send</span>Partager au client</button>
        </form>
        <?php else: ?>
        <span class="h-10 px-space-sm rounded-lg bg-surface-container-high font-label-md text-[12px] text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check_circle</span>Partagé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($compteRendu['partage_le']))); ?></span>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($voitTout && $compteRendu['statut'] !== 'archive'): ?>
        <form method="post" onsubmit="return confirm('Archiver ce compte rendu ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="archiver">
          <button type="submit" class="h-10 px-space-sm rounded-lg border border-outline-variant font-label-md text-label-md text-secondary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">archive</span>Archiver</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($peutValider): ?>
      <form method="post" id="formCorrection" class="hidden bg-surface-container-lowest rounded-xl shadow-sm p-space-sm flex flex-col gap-space-xs">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="demander_correction">
        <label class="font-label-md text-[12px] text-secondary">Motif de la demande de correction</label>
        <textarea name="motif_correction" rows="2" required class="px-space-xs py-1 rounded-lg border border-outline-variant font-body-sm text-[13px]"></textarea>
        <button type="submit" class="self-start h-9 px-space-sm rounded-lg bg-orange-600 text-white font-label-md text-[12px]">Envoyer la demande</button>
      </form>
      <?php endif; ?>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <?php
        $sections = [
            'objet' => 'Objet', 'contexte' => 'Contexte', 'objectifs' => 'Objectifs',
            'resume_echanges' => 'Résumé des échanges', 'faits_rapportes' => 'Faits rapportés', 'decisions' => 'Décisions',
            'prochaine_etape' => 'Prochaine étape',
        ];
        foreach ($sections as $champ => $label):
            if (trim((string) $compteRendu[$champ]) === '') { continue; }
        ?>
        <div>
          <h2 class="font-headline-sm text-[13px] text-on-surface font-bold uppercase tracking-wide"><?php echo $label; ?></h2>
          <p class="font-body-sm text-body-sm text-on-surface whitespace-pre-line"><?php echo htmlspecialchars($compteRendu[$champ]); ?></p>
        </div>
        <?php endforeach; ?>

        <?php if ($actions): ?>
        <div>
          <h2 class="font-headline-sm text-[13px] text-on-surface font-bold uppercase tracking-wide">Actions proposées</h2>
          <table class="w-full font-body-sm text-[13px]">
            <?php foreach ($actions as $a): ?>
            <tr class="border-b border-outline-variant/30">
              <td class="py-1 pr-space-xs"><?php echo htmlspecialchars($a['description'] ?? ''); ?></td>
              <td class="py-1 pr-space-xs text-secondary"><?php echo htmlspecialchars($a['responsable'] ?? ''); ?></td>
              <td class="py-1 text-secondary"><?php echo !empty($a['echeance']) ? htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
          <?php if ($actionsConfirmees): ?>
          <p class="font-body-sm text-[12px] text-green-700 mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">check_circle</span>Actions confirmées dans le plan d'action — <a href="consultant-suivi-assistance.php" class="underline">voir le Suivi</a></p>
          <?php elseif ($compteRendu['statut'] === 'valide' && $peutGererDossier): ?>
          <form method="post" class="mt-1" onsubmit="return confirm('Confirmer ces actions dans le plan d\'action du dossier ?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="confirmer_actions">
            <button type="submit" class="h-9 px-space-sm rounded-lg bg-primary text-white font-label-md text-[12px] flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">playlist_add_check</span>Confirmer les actions dans le plan d'action</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (trim((string) $compteRendu['observations_internes']) !== ''): ?>
        <div class="bg-surface-container-high rounded-lg p-space-sm">
          <h2 class="font-headline-sm text-[13px] text-on-surface font-bold uppercase tracking-wide flex items-center gap-1"><span class="material-symbols-outlined text-[14px] text-secondary">lock</span>Observations professionnelles (usage interne — jamais transmises)</h2>
          <p class="font-body-sm text-body-sm text-on-surface whitespace-pre-line"><?php echo htmlspecialchars($compteRendu['observations_internes']); ?></p>
        </div>
        <?php endif; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Historique</h2>
        <p class="font-body-sm text-[12px] text-secondary">Rédigé par <?php echo htmlspecialchars(($compteRendu['auteur_prenom'] ?? '') . ' ' . ($compteRendu['auteur_nom'] ?? '')); ?> le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($compteRendu['created_at']))); ?><?php if ($compteRendu['valide_le']): ?> · Validé par <?php echo htmlspecialchars(($compteRendu['valideur_prenom'] ?? '') . ' ' . ($compteRendu['valideur_nom'] ?? '')); ?> le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($compteRendu['valide_le']))); ?><?php endif; ?></p>
        <?php foreach ($historique as $h): ?>
        <p class="font-body-sm text-[12px] text-secondary border-t border-outline-variant/30 pt-1"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($h['created_at']))); ?> — <?php echo htmlspecialchars($h['texte']); ?></p>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
