<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$rdv = $stmt->fetch();

$stmt = db()->prepare('SELECT statut, methode, updated_at FROM paiements WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$paiement = $stmt->fetch();

$consultantNom = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    if ($c = $stmt->fetch()) {
        $consultantNom = $c['prenom'] . ' ' . $c['nom'];
    }
}

$formatLabels = ['ligne' => 'Visioconférence', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir avec NexRaiz'];

$etapes = [
    ['titre' => 'Rendez-vous à venir', 'texte' => $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) . ' — Préparez vos questions et documents utiles.' : 'Votre rendez-vous sera confirmé sous peu.', 'statut' => 'current'],
    ['titre' => 'Rappel avant le rendez-vous', 'texte' => "Vous recevrez un email de rappel 24h avant l'échange avec le lien de connexion.", 'statut' => 'pending'],
    ['titre' => 'Après le rendez-vous', 'texte' => 'Vous recevrez votre plan d\'action personnalisé et les prochaines étapes à suivre.', 'statut' => 'pending'],
];

$paiementFaitPourRecu = ($paiement['statut'] ?? '') === 'paye';

render_head('Pack Booster — Confirmation de rendez-vous');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'booster',
    7,
    'Confirmation de rendez-vous',
    'Votre demande Pack Booster est validée',
    'task_alt',
    ['calendar_month', 'person', 'check_circle']
);
render_stepper('booster', 7);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">

    <div class="bg-primary-container/5 border border-primary-container/30 rounded-xl p-space-lg flex items-center gap-space-md">
      <div class="w-12 h-12 rounded-full bg-primary-container text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[26px]">check</span></div>
      <div>
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Votre rendez-vous est confirmé !</h2>
        <p class="font-body-sm text-body-sm text-secondary">Nous avons hâte d'échanger avec vous et de vous accompagner dans votre projet.</p>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-space-sm scroll-mt-20" id="recap-rdv">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">folder</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Numéro de dossier</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($dossier['numero']); ?></p>
        <p class="font-body-sm text-[11px] text-secondary">Conservez ce numéro pour un suivi simplifié.</p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">calendar_today</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Date et heure du rendez-vous</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H:i', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p>
        <p class="font-body-sm text-[11px] text-secondary">Europe/Paris</p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">videocam</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Format</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($formatLabels[$rdv['format'] ?? 'ligne'] ?? 'Non défini'); ?></p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">person</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Consultant / lien de connexion</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $consultantNom ? htmlspecialchars($consultantNom) : 'À définir'; ?></p>
        <p class="font-body-sm text-[11px] text-secondary"><?php echo $consultantNom ? 'Votre consultant vous contactera avant le rendez-vous.' : 'Un consultant vous sera assigné sous peu.'; ?></p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">credit_card</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Statut du paiement</p>
        <?php if (($paiement['statut'] ?? '') === 'paye'): ?>
        <span class="inline-flex w-fit items-center gap-1 px-space-xs py-0.5 rounded-full bg-green-100 text-green-700 font-label-md text-[11px] font-bold">Payé <span class="material-symbols-outlined text-[12px]">check</span></span>
        <p class="font-body-sm text-[11px] text-secondary">Confirmé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($paiement['updated_at']))); ?></p>
        <?php elseif (($paiement['methode'] ?? '') === 'virement'): ?>
        <span class="inline-flex w-fit items-center gap-1 px-space-xs py-0.5 rounded-full bg-orange-100 text-orange-700 font-label-md text-[11px] font-bold">En attente <span class="material-symbols-outlined text-[12px]">schedule</span></span>
        <p class="font-body-sm text-[11px] text-secondary">Virement en attente de réception.</p>
        <?php else: ?>
        <span class="inline-flex w-fit items-center gap-1 px-space-xs py-0.5 rounded-full bg-surface-container-high text-secondary font-label-md text-[11px] font-bold">Non payé</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Prochaines étapes</h3>
        <div class="flex flex-col gap-space-md relative pl-space-md">
          <div class="absolute left-[7px] top-2 bottom-2 w-0.5 bg-outline-variant"></div>
          <?php foreach ($etapes as $e): ?>
          <div class="flex items-start gap-space-sm relative">
            <span class="absolute -left-space-md top-1 w-3.5 h-3.5 rounded-full border-2 <?php echo $e['statut'] === 'current' ? 'bg-primary-container border-primary-container' : 'bg-surface-container-lowest border-outline-variant'; ?>"></span>
            <div>
              <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($e['titre']); ?></p>
              <p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($e['texte']); ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Documents téléchargeables</h3>
        <?php if ($paiementFaitPourRecu): ?>
        <a href="recu-paiement.php?dossier=<?php echo $dossier['id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
          <div class="flex items-center gap-space-xs">
            <div class="w-8 h-8 rounded bg-error/10 text-error flex items-center justify-center font-label-md text-[10px] font-bold">REC</div>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Reçu de paiement</p><p class="font-body-sm text-[11px] text-secondary">Généré le <?php echo htmlspecialchars(date('d/m/Y', strtotime($paiement['updated_at']))); ?></p></div>
          </div>
          <span class="material-symbols-outlined text-[18px] text-primary">open_in_new</span>
        </a>
        <?php endif; ?>
        <?php if ($rdv): ?>
        <a href="#recap-rdv" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
          <div class="flex items-center gap-space-xs">
            <div class="w-8 h-8 rounded bg-primary-container/10 text-primary flex items-center justify-center font-label-md text-[10px] font-bold">RDV</div>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Confirmation de rendez-vous</p><p class="font-body-sm text-[11px] text-secondary">Voir le récapitulatif ci-dessus</p></div>
          </div>
          <span class="material-symbols-outlined text-[18px] text-primary">arrow_upward</span>
        </a>
        <?php endif; ?>
        <?php if (!$paiementFaitPourRecu && !$rdv): ?>
        <p class="font-body-sm text-[12px] text-secondary">Vos documents apparaîtront ici une fois le paiement confirmé.</p>
        <?php endif; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Besoin d'aide ?</h3>
        <p class="font-body-sm text-[12px] text-secondary">Notre équipe est à votre disposition si vous avez des questions.</p>
        <div class="flex flex-col gap-space-2xs">
          <div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[16px] text-primary">mail</span><div><p class="font-body-sm text-[12px] text-on-surface font-semibold">Contactez-nous par email</p><p class="font-body-sm text-[11px] text-secondary">support@nexraiz.com</p></div></div>
          <div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[16px] text-primary">call</span><div><p class="font-body-sm text-[12px] text-on-surface font-semibold">Appelez-nous</p><p class="font-body-sm text-[11px] text-secondary">+33 1 84 80 00 30 · Lun-Ven, 9h-18h</p></div></div>
          <div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[16px] text-primary">chat</span><div><p class="font-body-sm text-[12px] text-on-surface font-semibold">Chat en ligne</p><p class="font-body-sm text-[11px] text-secondary">Disponible sur votre espace client</p></div></div>
        </div>
      </div>
    </div>

    <div class="flex flex-col items-center gap-space-xs">
      <a href="<?php echo step_url(8, 'booster'); ?>" class="w-full sm:w-auto h-12 px-space-xl rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
        <span>Accéder à mon tableau de bord</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
      <div class="flex items-center gap-space-2xs">
        <span class="material-symbols-outlined text-[16px] text-primary">lock</span>
        <span class="font-label-md text-[11px] text-secondary">Paiement 100% sécurisé</span>
      </div>
    </div>
  </div>
</section>

<?php render_footer(); ?>
