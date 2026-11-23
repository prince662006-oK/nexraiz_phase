<?php
/**
 * NexRaiz — Vue coach « Opportunités » (Pack Classic) : liste des clients
 * ayant consenti au matching, avec compteurs, et vue détaillée lecture seule
 * d'un dossier précis (recommandations + suivi de candidature).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/consultant-classic.php';
require_once __DIR__ . '/includes/opportunites.php';

$consultant = require_role(['consultant', 'admin'], 'mon-espace.php');
$packId = get_pack_id('classic');
$dossiers = classic_dossiers_apercu($packId, $consultant);
$apercu = classic_opportunites_apercu($packId);

$dossierSelectionne = isset($_GET['dossier']) ? (int) $_GET['dossier'] : null;
$detail = null;
if ($dossierSelectionne) {
    foreach ($apercu as $ligne) {
        if ((int) $ligne['dossier_id'] === $dossierSelectionne) { $detail = $ligne; break; }
    }
    if ($detail) {
        $profil = opportunites_profil_dossier($dossierSelectionne);
        $recommandees = opportunites_recommandees_pour_dossier($dossierSelectionne);
    }
}

render_head('Espace NexRaiz — Suivi candidats', 'Suivi des opportunités des clients Pack Classic.');
?>
<div class="flex flex-col min-h-screen">

  <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
    <div class="flex items-center gap-space-lg min-w-0">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></a>
    </div>
    <div class="flex items-center gap-space-xs shrink-0">
      <a href="mon-espace.php" class="font-label-md text-label-md text-secondary hover:text-on-surface transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Mon espace</a>
      <a href="logout.php" class="font-label-md text-label-md text-secondary hover:text-error transition-colors" title="Se déconnecter"><span class="material-symbols-outlined text-[18px]">logout</span></a>
    </div>
  </header>

  <div class="flex flex-1 min-w-0">
    <?php render_classic_sidebar($dossiers, $packId); ?>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <?php if (!$detail): ?>
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Suivi candidats — Opportunités</h1>
        <p class="font-body-sm text-body-sm text-secondary">Clients ayant consenti à l'analyse de profil (Pack Classic).</p>
      </div>

      <div class="bg-surface-container-low rounded-xl p-space-sm">
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(OPPORTUNITES_DISCLAIMER); ?></p>
      </div>

      <div class="flex flex-col gap-space-sm">
        <?php if (!$apercu): ?>
        <p class="font-body-sm text-body-sm text-secondary text-center py-space-lg">Aucun client n'a encore consenti à l'analyse de profil.</p>
        <?php endif; ?>
        <?php foreach ($apercu as $ligne): ?>
        <a href="?dossier=<?php echo $ligne['dossier_id']; ?>" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex items-center justify-between gap-space-sm flex-wrap hover:shadow-md transition-shadow">
          <div>
            <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($ligne['prenom'] . ' ' . $ligne['nom']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary">Dossier <?php echo htmlspecialchars($ligne['numero']); ?> · <?php echo htmlspecialchars($ligne['email']); ?></p>
          </div>
          <div class="flex items-center gap-space-md">
            <div class="text-center"><p class="font-headline-md text-headline-md text-primary font-bold"><?php echo $ligne['nb_recommandees']; ?></p><p class="font-body-sm text-[10px] text-secondary">recommandées</p></div>
            <div class="text-center"><p class="font-headline-md text-headline-md text-on-surface font-bold"><?php echo $ligne['nb_enregistrees']; ?></p><p class="font-body-sm text-[10px] text-secondary">enregistrées</p></div>
            <div class="text-center"><p class="font-headline-md text-headline-md text-on-surface font-bold"><?php echo $ligne['nb_postulees']; ?></p><p class="font-body-sm text-[10px] text-secondary">candidatures</p></div>
            <span class="material-symbols-outlined text-secondary">chevron_right</span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <?php else: ?>
      <div class="flex items-center gap-space-xs">
        <a href="consultant-opportunites-classic.php" class="font-label-md text-label-md text-secondary hover:text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour</a>
      </div>
      <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo htmlspecialchars($detail['prenom'] . ' ' . $detail['nom']); ?></h1>
        <p class="font-body-sm text-body-sm text-secondary">Dossier <?php echo htmlspecialchars($detail['numero']); ?> — vue lecture seule</p>
      </div>

      <?php if ($profil): ?>
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <p class="font-label-md text-label-md text-on-surface font-bold mb-space-xs">Profil de recherche</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-xs font-body-sm text-body-sm text-secondary">
          <p><strong class="text-on-surface">Types :</strong> <?php echo htmlspecialchars(implode(', ', $profil['types'])); ?></p>
          <p><strong class="text-on-surface">Métiers :</strong> <?php echo htmlspecialchars(implode(', ', $profil['metiers'])); ?></p>
          <p><strong class="text-on-surface">Secteurs :</strong> <?php echo htmlspecialchars(implode(', ', $profil['secteurs'])); ?></p>
          <p><strong class="text-on-surface">Niveau d'études :</strong> <?php echo htmlspecialchars($profil['niveau_etudes'] ?? '—'); ?></p>
          <p><strong class="text-on-surface">Expérience :</strong> <?php echo htmlspecialchars((string) ($profil['annees_experience'] ?? '—')); ?> an(s)</p>
          <p><strong class="text-on-surface">Compétences :</strong> <?php echo htmlspecialchars(implode(', ', $profil['competences'])); ?></p>
          <p><strong class="text-on-surface">Villes :</strong> <?php echo htmlspecialchars(implode(', ', $profil['villes'])); ?></p>
          <p><strong class="text-on-surface">Mobilité :</strong> <?php echo htmlspecialchars($profil['mobilite']); ?> · Télétravail : <?php echo htmlspecialchars($profil['teletravail']); ?></p>
        </div>
      </div>
      <?php endif; ?>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <p class="font-label-md text-label-md text-on-surface font-bold mb-space-sm">Offres recommandées (<?php echo count($recommandees); ?>)</p>
        <?php if (!$recommandees): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune offre ne correspond au profil pour le moment.</p>
        <?php endif; ?>
        <div class="flex flex-col gap-space-sm">
          <?php foreach ($recommandees as $r): ?>
          <div class="flex items-center justify-between gap-space-sm border border-outline-variant/50 rounded-xl p-space-sm flex-wrap">
            <div>
              <p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($r['offre']['titre']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($r['offre']['type']); ?><?php echo $r['offre']['ville'] ? ' · ' . htmlspecialchars($r['offre']['ville']) : ''; ?></p>
            </div>
            <span class="font-label-lg text-label-lg text-primary font-bold"><?php echo $r['score']; ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg">
        <p class="font-label-md text-label-md text-on-surface font-bold mb-space-sm">Suivi de candidature (<?php echo count($detail['candidatures']); ?>)</p>
        <?php if (!$detail['candidatures']): ?>
        <p class="font-body-sm text-body-sm text-secondary">Aucune candidature suivie pour le moment.</p>
        <?php endif; ?>
        <div class="flex flex-col gap-space-sm">
          <?php foreach ($detail['candidatures'] as $c): ?>
          <div class="flex items-center justify-between gap-space-sm border border-outline-variant/50 rounded-xl p-space-sm flex-wrap">
            <div>
              <p class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars($c['titre']); ?></p>
              <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($c['entreprise'] ?? ''); ?><?php echo $c['ville'] ? ' · ' . htmlspecialchars($c['ville']) : ''; ?></p>
            </div>
            <span class="font-label-md text-[10px] uppercase px-space-xs py-0.5 rounded-full bg-surface-container-low text-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', $c['statut'])); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
