<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('wellbeing', 'wellbeing-espace-client.php');

$stmt = db()->prepare('SELECT date_heure, format FROM rendezvous WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$rdv = $stmt->fetch();

$stmt = db()->prepare('SELECT statut, methode, updated_at FROM paiements WHERE dossier_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$dossier['id']]);
$paiement = $stmt->fetch();

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossier['id']]);
$qualification = json_decode((string) $stmt->fetchColumn(), true) ?: [];
$stressLabels = ['1' => 'Faible', '2' => 'Faible à modéré', '3' => 'Modéré', '4' => 'Élevé', '5' => 'Très élevé'];

$formatLabels = ['ligne' => 'Visioconférence', 'presentiel' => 'En présentiel', 'hybride' => 'Hybride', 'a_definir' => 'À définir avec NexRaiz'];
$rdvLabel = $rdv ? date('d/m/Y à H:i', strtotime($rdv['date_heure'])) : 'Non planifié';

$nomConsultant = null;
if ($dossier['consultant_id']) {
    $stmt = db()->prepare('SELECT nom, prenom FROM users WHERE id = ?');
    $stmt->execute([$dossier['consultant_id']]);
    $c = $stmt->fetch();
    if ($c) {
        $nomConsultant = $c['prenom'] . ' ' . $c['nom'];
    }
}

$etapes = [
    ['titre' => 'Vous recevez un email de confirmation', 'texte' => 'Dès maintenant'],
    ['titre' => 'Préparation de votre séance', 'texte' => 'Avant le rendez-vous'],
    ['titre' => 'Votre séance avec votre consultant', 'texte' => $rdvLabel],
    ['titre' => 'Plan d\'action personnalisé', 'texte' => 'Sous 48h après la séance'],
];

render_head('Pack Wellbeing — Confirmation de rendez-vous');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'wellbeing',
    7,
    'Confirmation de rendez-vous',
    'Votre demande Pack Wellbeing est validée',
    'task_alt',
    []
);
render_stepper('wellbeing', 7);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">

    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#e9f3ec] to-[#dcece2] p-space-lg md:p-space-xl flex flex-col md:flex-row items-center gap-space-lg">
      <div class="flex flex-col items-center md:items-start text-center md:text-left gap-space-sm flex-1">
        <div class="w-16 h-16 rounded-full border-2 border-primary-container text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[32px]">check</span></div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface font-bold">Votre rendez-vous est confirmé !</h2>
        <p class="font-body-md text-body-md text-secondary max-w-sm">Merci pour votre confiance. Vous allez recevoir un email de confirmation avec tous les détails.</p>
      </div>
      <div class="grid grid-cols-1 gap-space-sm w-full md:w-64 shrink-0">
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex flex-col gap-space-2xs">
          <span class="material-symbols-outlined text-[18px] text-primary">bar_chart</span>
          <p class="font-body-sm text-[11px] text-secondary">Niveau de stress</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($stressLabels[(string) ($qualification['niveau_stress'] ?? '3')] ?? 'Non renseigné'); ?></p>
          <div class="h-1.5 rounded-full bg-surface-container-low overflow-hidden"><div class="h-full w-1/2 rounded-full bg-primary-container"></div></div>
        </div>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex flex-col gap-space-2xs">
          <span class="material-symbols-outlined text-[18px] text-primary">assignment</span>
          <p class="font-body-sm text-[11px] text-secondary">Plan d'action</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">En préparation</p>
          <div class="h-1.5 rounded-full bg-surface-container-low overflow-hidden"><div class="h-full w-1/4 rounded-full bg-primary-container"></div></div>
        </div>
        <div class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex flex-col gap-space-2xs">
          <span class="material-symbols-outlined text-[18px] text-primary">trending_up</span>
          <p class="font-body-sm text-[11px] text-secondary">Évolution Wellbeing</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold">En progression</p>
          <div class="h-1.5 rounded-full bg-surface-container-low overflow-hidden"><div class="h-full w-1/3 rounded-full bg-primary-container"></div></div>
        </div>
      </div>
    </div>

    <div id="recap-rdv" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-space-sm">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">folder</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Numéro de dossier</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($dossier['numero']); ?></p>
        <p class="font-body-sm text-[11px] text-secondary">Conservez ce numéro pour vos échanges avec NexRaiz.</p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">calendar_today</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Date et heure du rendez-vous</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($rdvLabel); ?></p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">videocam</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Format</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($formatLabels[$rdv['format'] ?? 'ligne'] ?? 'Non défini'); ?></p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">person</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Consultant / Lien de connexion</p>
        <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($nomConsultant ?? 'À définir'); ?></p>
        <p class="font-body-sm text-[11px] text-secondary mb-space-2xs"><?php echo $nomConsultant ? 'Votre consultant vous contactera avant la séance.' : 'Un consultant vous sera assigné sous peu.'; ?></p>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="w-9 h-9 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">credit_card</span></div>
        <p class="font-body-sm text-[12px] text-secondary">Statut du paiement</p>
        <?php if (($paiement['statut'] ?? '') === 'paye'): ?>
        <p class="font-label-lg text-label-lg text-on-surface font-bold">Payé</p>
        <span class="inline-flex w-fit items-center gap-1 font-label-md text-[11px] font-bold text-green-700"><span class="material-symbols-outlined text-[14px]">check_circle</span>Confirmé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($paiement['updated_at']))); ?></span>
        <?php elseif (($paiement['methode'] ?? '') === 'virement'): ?>
        <p class="font-label-lg text-label-lg text-on-surface font-bold">En attente</p>
        <span class="inline-flex w-fit items-center gap-1 font-label-md text-[11px] font-bold text-orange-600"><span class="material-symbols-outlined text-[14px]">schedule</span>Virement en attente de réception</span>
        <?php else: ?>
        <p class="font-label-lg text-label-lg text-on-surface font-bold">Non payé</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Prochaines étapes</h3>
        <?php foreach ($etapes as $i => $e): ?>
        <div class="flex items-start gap-space-sm">
          <div class="w-6 h-6 rounded-full bg-primary-container text-white flex items-center justify-center shrink-0 font-label-md text-[11px] font-bold"><?php echo $i + 1; ?></div>
          <div><p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($e['titre']); ?></p><p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($e['texte']); ?></p></div>
        </div>
        <?php endforeach; ?>
        <div class="flex items-start gap-space-xs bg-primary-container/5 rounded-xl p-space-sm mt-space-xs">
          <span class="material-symbols-outlined text-[16px] text-primary mt-0.5">eco</span>
          <div><p class="font-label-md text-label-md text-on-surface font-semibold">Besoin de modifier ou d'annuler ?</p><p class="font-body-sm text-[11px] text-secondary">Vous pouvez le faire depuis votre espace client jusqu'à 24h avant le rendez-vous.</p></div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Documents</h3>
        <?php if (($paiement['statut'] ?? '') === 'paye'): ?>
        <a href="recu-paiement.php?dossier=<?php echo $dossier['id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container-high transition-colors">
          <div class="flex items-center gap-space-xs">
            <div class="w-8 h-8 rounded bg-error/10 text-error flex items-center justify-center font-label-md text-[10px] font-bold">PDF</div>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Reçu de paiement</p><p class="font-body-sm text-[11px] text-secondary">Dossier <?php echo htmlspecialchars($dossier['numero']); ?></p></div>
          </div>
          <span class="material-symbols-outlined text-[18px] text-primary">download</span>
        </a>
        <?php else: ?>
        <p class="font-body-sm text-[12px] text-secondary">Votre reçu de paiement sera disponible ici une fois le paiement confirmé.</p>
        <?php endif; ?>
        <a href="#recap-rdv" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container-high transition-colors">
          <div class="flex items-center gap-space-xs">
            <div class="w-8 h-8 rounded bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">event</span></div>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Récapitulatif de votre rendez-vous</p><p class="font-body-sm text-[11px] text-secondary">Voir les détails ci-dessus</p></div>
          </div>
          <span class="material-symbols-outlined text-[18px] text-primary">arrow_upward</span>
        </a>
        <div class="flex items-start gap-space-xs bg-primary-container/5 rounded-xl p-space-sm mt-space-xs">
          <span class="material-symbols-outlined text-[16px] text-primary mt-0.5">shield</span>
          <div><p class="font-label-md text-label-md text-on-surface font-semibold">Vos données sont sécurisées</p><p class="font-body-sm text-[11px] text-secondary">NexRaiz s'engage à protéger vos informations conformément au RGPD.</p></div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Besoin d'aide ?</h3>
        <p class="font-body-sm text-[12px] text-secondary">Notre équipe est à votre disposition.</p>
        <div class="flex items-center justify-between"><span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">mail</span>Par email</span><span class="material-symbols-outlined text-[16px] text-outline">chevron_right</span></div>
        <p class="font-body-sm text-[11px] text-secondary -mt-space-xs pl-space-md">support@nexraiz.com</p>
        <div class="flex items-center justify-between"><span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">call</span>Par téléphone</span><span class="material-symbols-outlined text-[16px] text-outline">chevron_right</span></div>
        <p class="font-body-sm text-[11px] text-secondary -mt-space-xs pl-space-md">01 84 80 00 30 · Lun.-Ven. de 9h à 18h</p>
        <div class="flex items-center justify-between"><span class="flex items-center gap-1 font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">chat</span>Par chat</span><span class="material-symbols-outlined text-[16px] text-outline">chevron_right</span></div>
        <p class="font-body-sm text-[11px] text-secondary -mt-space-xs pl-space-md">Discutez avec un conseiller · Réponse sous 5 min en moyenne</p>
      </div>
    </div>

    <a href="<?php echo step_url(8, 'wellbeing'); ?>" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
      <span>Accéder à mon tableau de bord</span>
      <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
    </a>
  </div>
</section>

<?php render_footer(); ?>
