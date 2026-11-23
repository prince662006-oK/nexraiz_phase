<?php
/**
 * Compte rendu consultable par le client (et son consultant) pour un
 * dossier donné. Générique, pack-agnostique : le contenu est reconstruit
 * à partir des données réelles du dossier (qualification, plan d'action,
 * orientations recommandées), rien n'est stocké en dur dans un fichier.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$user = require_login('mon-espace.php');
$dossierId = (int) ($_GET['dossier'] ?? 0);

$stmt = db()->prepare(
    'SELECT ds.*, u.nom, u.prenom, pk.nom AS pack_nom
     FROM dossiers ds JOIN users u ON u.id = ds.user_id JOIN packs pk ON pk.id = ds.pack_id
     WHERE ds.id = ?'
);
$stmt->execute([$dossierId]);
$dossier = $stmt->fetch();

$autorise = $dossier && ((int) $dossier['user_id'] === (int) $user['id'] || in_array($user['role'], ['consultant', 'admin'], true));
if (!$dossier || !$autorise) {
    header('Location: mon-espace.php');
    exit;
}

$stmt = db()->prepare('SELECT situation_type, urgence, resume, donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossierId]);
$qualification = $stmt->fetch() ?: [];
$donneesJson = json_decode((string) ($qualification['donnees_json'] ?? ''), true) ?: [];

// Certains packs (Booster, Classic, Wellbeing...) stockent leurs réponses dans
// donnees_json plutôt que dans les colonnes dédiées situation_type/resume
// (propres au Pack Assistance) : on complète avec ce qui est disponible, sans
// rien inventer, en tenant compte du champ propre à chaque pack.
// Pack Classic : "objectif_principal" est désormais une sélection multiple
// (tableau) — affiché sous forme de liste séparée par des virgules ; les
// dossiers enregistrés avant ce changement le stockent encore en texte
// simple, les deux formes restent donc gérées ici.
$objectifPrincipalBrut = $donneesJson['objectif_principal'] ?? null;
$objectifPrincipalAffiche = is_array($objectifPrincipalBrut) ? implode(', ', $objectifPrincipalBrut) : $objectifPrincipalBrut;
$situationAffichee = $qualification['situation_type']
    ?: ($objectifPrincipalAffiche ?: ($donneesJson['motif_principal'] ?? null));
$resumeAffiche = $qualification['resume']
    ?: ($donneesJson['resume'] ?? ($donneesJson['details'] ?? null));

$stmt = db()->prepare("SELECT titre, proprietaire, echeance, statut FROM actions_plan WHERE dossier_id = ? ORDER BY echeance ASC");
$stmt->execute([$dossierId]);
$planAction = $stmt->fetchAll();
$actionStatutLabels = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'termine' => 'Terminé', 'a_venir' => 'À venir'];

$stmt = db()->prepare('SELECT type, recommande FROM dossier_orientations WHERE dossier_id = ? AND recommande = 1');
$stmt->execute([$dossierId]);
$orientationLabels = ['avocat' => 'Avocat (droit du travail)', 'medecin_travail' => 'Médecin du travail', 'psychologue' => 'Psychologue', 'expert_rh' => 'Expert RH'];
$orientationsRecommandees = array_map(fn ($o) => $orientationLabels[$o['type']] ?? $o['type'], $stmt->fetchAll());

$urgenceLabels = ['faible' => 'Faible', 'moyen' => 'Moyenne', 'eleve' => 'Élevée', 'critique' => 'Critique'];
$urgenceLabel = $urgenceLabels[$qualification['urgence'] ?? ''] ?? 'Non évaluée';

render_head('Compte rendu — Dossier ' . htmlspecialchars($dossier['numero']));
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-4rem)]">
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-reading mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors"><span class="material-symbols-outlined text-[18px]">home</span></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Compte rendu</span>
    </nav>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Compte rendu — <?php echo htmlspecialchars($dossier['pack_nom']); ?></h1>
        <p class="font-body-sm text-body-sm text-secondary">Dossier <?php echo htmlspecialchars($dossier['numero']); ?> · <?php echo htmlspecialchars($dossier['prenom'] . ' ' . $dossier['nom']); ?> · Généré le <?php echo htmlspecialchars(date('d/m/Y')); ?></p>
      </div>

      <div class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Situation</h2>
        <p class="font-body-sm text-body-sm text-on-surface"><strong>Situation concernée :</strong> <?php echo htmlspecialchars($situationAffichee ?: 'Non renseignée'); ?></p>
        <?php if (!empty($qualification['urgence'])): ?>
        <p class="font-body-sm text-body-sm text-on-surface"><strong>Niveau d'urgence :</strong> <?php echo htmlspecialchars($urgenceLabel); ?></p>
        <?php endif; ?>
        <?php if (!empty($donneesJson['secteur_activite'])): ?>
        <p class="font-body-sm text-body-sm text-on-surface"><strong>Secteur d'activité :</strong> <?php echo htmlspecialchars($donneesJson['secteur_activite']); ?></p>
        <?php endif; ?>
        <?php if (!empty($resumeAffiche)): ?>
        <p class="font-body-sm text-body-sm text-on-surface"><?php echo nl2br(htmlspecialchars($resumeAffiche)); ?></p>
        <?php endif; ?>
      </div>

      <div class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Plan d'action</h2>
        <?php if (!$planAction): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune action définie pour le moment.</p>
        <?php endif; ?>
        <?php foreach ($planAction as $a): ?>
        <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
          <div><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($a['titre']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo $a['echeance'] ? 'Échéance : ' . htmlspecialchars(date('d/m/Y', strtotime($a['echeance']))) : 'Sans échéance'; ?></p></div>
          <span class="px-space-xs py-0.5 rounded-full bg-surface-container-lowest border border-outline-variant font-label-md text-[11px] font-bold text-secondary"><?php echo htmlspecialchars($actionStatutLabels[$a['statut']] ?? $a['statut']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($orientationsRecommandees): ?>
      <div class="flex flex-col gap-space-xs pt-space-xs border-t border-outline-variant/60">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Orientations recommandées</h2>
        <ul class="flex flex-col gap-space-2xs">
          <?php foreach ($orientationsRecommandees as $o): ?>
          <li class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">check_circle</span><?php echo htmlspecialchars($o); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <a href="mon-espace.php" class="self-start h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors mt-space-xs">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Retour à mon espace</span>
      </a>
    </div>
  </div>
</section>
<?php render_footer(); ?>
