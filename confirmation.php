<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('assistance', 'espace-client.php');

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
    $consultantRow = $stmt->fetch();
    if ($consultantRow) {
        $consultantNom = $consultantRow['prenom'] . ' ' . $consultantRow['nom'];
    }
}

$formatLabels = ['ligne' => 'Visioconférence', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir avec NexRaiz'];
$rdvConfirme = (bool) $rdv;

$stmt = db()->prepare('SELECT documents_disponibles, diagnostic_valide_le FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossier['id']]);
$qualification = $stmt->fetch() ?: [];
$documentsTransmis = !empty(json_decode((string) ($qualification['documents_disponibles'] ?? ''), true));
$diagnosticCommence = !empty($qualification['diagnostic_valide_le']);

$etapes = [
    ['titre' => 'Dossier ouvert', 'texte' => 'Votre dossier a été créé avec succès.', 'statut' => 'done'],
    ['titre' => 'Documents à transmettre', 'texte' => $documentsTransmis ? 'Vous avez indiqué les documents disponibles pour votre situation.' : 'Nous vous indiquerons les documents nécessaires à l\'analyse de votre situation.', 'statut' => $documentsTransmis ? 'done' : 'pending'],
    ['titre' => 'Rendez-vous confirmé', 'texte' => 'Votre rendez-vous est planifié. Vous allez recevoir un rappel 24h avant l\'échéance.', 'statut' => $rdvConfirme ? 'done' : 'pending'],
    ['titre' => 'Début du diagnostic', 'texte' => $diagnosticCommence ? 'Votre consultant a démarré votre diagnostic personnalisé.' : 'Le diagnostic personnalisé débutera à l\'issue de votre rendez-vous.', 'statut' => $diagnosticCommence ? 'done' : 'pending'],
];

$paiementFaitPourRecu = ($paiement['statut'] ?? '') === 'paye';

render_head('Pack Assistance — Confirmation de rendez-vous');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'assistance',
    7,
    'Confirmation de rendez-vous',
    'Votre demande Pack Assistance est validée',
    'task_alt',
    ['calendar_month', 'person', 'check_circle']
);
render_stepper('assistance', 7);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">

    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#E5EEFF] via-[#F8F9FF] to-[#DCE9FF] p-space-lg flex items-center gap-space-md">
      <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full bg-[#2563EB]/10 blur-3xl pointer-events-none"></div>
      <div class="relative z-10 w-14 h-14 rounded-full bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white flex items-center justify-center shrink-0 shadow-md"><span class="material-symbols-outlined text-[28px]">check</span></div>
      <div class="relative z-10">
        <h2 class="font-headline-sm text-headline-sm text-[#13203F] font-bold">Votre rendez-vous est confirmé !</h2>
        <p class="font-body-sm text-body-sm text-[#444653]">Votre demande a bien été enregistrée. Vous pouvez retrouver tous les détails ci-dessous.</p>
        <div class="w-12 h-1 rounded-full bg-[#FFC700] mt-space-2xs"></div>
      </div>
    </div>

    <div class="flex flex-col gap-space-sm scroll-mt-20" id="recap-rdv">
      <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Récapitulatif de votre rendez-vous</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-space-sm">
        <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">folder</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Numéro de dossier</p>
          <button type="button" id="copyNumeroBtn" data-numero="<?php echo htmlspecialchars($dossier['numero']); ?>" class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-2xs hover:text-primary transition-colors" title="Copier le numéro de dossier"><?php echo htmlspecialchars($dossier['numero']); ?> <span class="material-symbols-outlined text-[14px] text-outline">content_copy</span></button>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">calendar_today</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Date et heure du rendez-vous</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $rdv ? htmlspecialchars(date('d/m/Y à H\hi', strtotime($rdv['date_heure']))) : 'Non planifié'; ?></p>
          <p class="font-body-sm text-[11px] text-secondary">Europe/Paris</p>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#7C3AED] to-[#4C1D95] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">videocam</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Format</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars($formatLabels[$rdv['format'] ?? 'ligne'] ?? 'Non défini'); ?></p>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#10B981] to-[#047857] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">person</span></div>
          <p class="font-body-sm text-[12px] text-secondary">Consultant / lien de connexion</p>
          <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $consultantNom ? htmlspecialchars($consultantNom) : 'À définir'; ?></p>
          <p class="font-body-sm text-[11px] text-secondary mb-space-2xs"><?php echo $consultantNom ? 'Votre consultant vous contactera avant le rendez-vous.' : 'Un consultant vous sera assigné sous peu.'; ?></p>
        </div>
        <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">credit_card</span></div>
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
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-space-sm items-start">
      <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">flag</span></span>Prochaines étapes</h3>
        <div class="flex flex-col gap-space-sm">
          <?php foreach ($etapes as $i => $e): ?>
          <div class="flex items-start gap-space-sm">
            <div class="w-6 h-6 rounded-full <?php echo $e['statut'] === 'done' ? 'bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white' : 'bg-surface-container text-secondary'; ?> flex items-center justify-center shrink-0 font-label-md text-[11px] font-bold"><?php echo $i + 1; ?></div>
            <div class="flex-1">
              <p class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($e['titre']); ?></p>
              <p class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($e['texte']); ?></p>
            </div>
            <span class="material-symbols-outlined text-[18px] <?php echo $e['statut'] === 'done' ? 'text-[#2563EB]' : 'text-outline'; ?>"><?php echo $e['statut'] === 'done' ? 'check_circle' : 'schedule'; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold flex items-center gap-space-xs"><span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#FFC700] to-[#8A6D00] text-[#13203F] flex items-center justify-center"><span class="material-symbols-outlined text-[16px]">folder_open</span></span>Documents téléchargeables</h3>
        <p class="font-body-sm text-[12px] text-secondary">Consultez vos documents de confirmation et de paiement.</p>
        <?php if ($paiementFaitPourRecu): ?>
        <a href="recu-paiement.php?dossier=<?php echo $dossier['id']; ?>" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
          <div class="flex items-center gap-space-xs">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-red-400 to-error text-white flex items-center justify-center font-label-md text-[10px] font-bold">REC</div>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Reçu de paiement</p><p class="font-body-sm text-[11px] text-secondary">Généré le <?php echo htmlspecialchars(date('d/m/Y', strtotime($paiement['updated_at']))); ?></p></div>
          </div>
          <span class="material-symbols-outlined text-[18px] text-[#2563EB]">open_in_new</span>
        </a>
        <?php endif; ?>
        <?php if ($rdvConfirme): ?>
        <a href="#recap-rdv" class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm hover:bg-surface-container transition-colors">
          <div class="flex items-center gap-space-xs">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[10px] font-bold">RDV</div>
            <div><p class="font-label-md text-label-md text-on-surface font-semibold">Confirmation de rendez-vous</p><p class="font-body-sm text-[11px] text-secondary">Voir le récapitulatif ci-dessus</p></div>
          </div>
          <span class="material-symbols-outlined text-[18px] text-[#2563EB]">arrow_upward</span>
        </a>
        <?php endif; ?>
        <?php if (!$paiementFaitPourRecu && !$rdvConfirme): ?>
        <p class="font-body-sm text-[12px] text-secondary">Vos documents apparaîtront ici une fois le paiement confirmé.</p>
        <?php endif; ?>
        <p class="font-body-sm text-[11px] text-secondary">Les documents vous ont également été envoyés par email.</p>
      </div>

      <div class="bg-gradient-to-br from-[#0B192C] to-[#1E3E62] rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm items-center text-center text-white">
        <div class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center"><span class="material-symbols-outlined text-[24px] text-[#FFC700]">support_agent</span></div>
        <h3 class="font-label-lg text-label-lg text-white font-bold">Besoin d'aide ?</h3>
        <p class="font-body-sm text-[12px] text-white/70">Notre équipe est à votre écoute.</p>
        <div class="w-full flex flex-col gap-space-2xs text-left">
          <div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[16px] text-[#FFC700]">mail</span><span class="font-body-sm text-[12px] text-white/90">support@nexraiz.fr</span></div>
          <div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[16px] text-[#FFC700]">call</span><span class="font-body-sm text-[12px] text-white/90">01 84 80 00 40 <span class="text-white/60">(Lun.-Ven. 9h-18h)</span></span></div>
          <div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-[16px] text-[#FFC700]">chat</span><a href="messages.php?dossier=<?php echo $dossier['id']; ?>" class="font-body-sm text-[12px] text-white underline">Accéder au chat</a></div>
        </div>
        <div class="w-full flex items-center gap-space-xs bg-white/10 rounded-xl p-space-xs mt-space-xs">
          <span class="material-symbols-outlined text-[16px] text-[#FFC700]">shield</span>
          <span class="font-body-sm text-[11px] text-white/70 text-left">Vos données sont sécurisées et traitées en toute confidentialité.</span>
        </div>
      </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm bg-surface-container-lowest rounded-2xl p-space-md shadow-sm">
      <a href="<?php echo step_url(8, 'assistance'); ?>" class="h-11 px-space-lg rounded-xl bg-gradient-to-br from-[#2563EB] to-[#13203F] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
        <span>Accéder à mon tableau de bord</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
      <p class="font-body-sm text-body-sm text-secondary">Suivez l'avancement de votre dossier, échangez avec votre consultant et accédez à vos documents à tout moment.</p>
    </div>
  </div>
</section>

<script>
(function() {
  var btn = document.getElementById('copyNumeroBtn');
  if (!btn) return;
  btn.addEventListener('click', function() {
    var numero = btn.getAttribute('data-numero');
    var icon = btn.querySelector('.material-symbols-outlined');
    navigator.clipboard.writeText(numero).then(function() {
      if (icon) {
        var original = icon.textContent;
        icon.textContent = 'check';
        setTimeout(function() { icon.textContent = original; }, 1500);
      }
    });
  });
})();
</script>
<?php render_footer(); ?>
