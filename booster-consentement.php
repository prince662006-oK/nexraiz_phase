<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $consentements = $_POST['consent'] ?? [];
    $signatureFournie = ($_POST['signature'] ?? '') === '1';

    if (count($consentements) < 3 || !$signatureFournie) {
        $erreurs[] = 'Merci de cocher les 3 cases et de signer pour continuer.';
    } else {
        $stmt = db()->prepare(
            'INSERT INTO consentements (dossier_id, accepte, accepte_le) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE accepte = 1, accepte_le = NOW()'
        );
        $stmt->execute([$dossier['id']]);
        update_dossier_step((int) $dossier['id'], 6);
        log_activite((int) $dossier['id'], 'Charte de consentement approuvée et signée');
        header('Location: ' . step_url(6, 'booster'));
        exit;
    }
}

$confidentialite = [
    'Tous les échanges avec nos consultants sont strictement confidentiels.',
    'Vos données personnelles sont traitées conformément à notre politique de confidentialité.',
    "Aucune information ne sera partagée sans votre accord explicite, sauf obligation légale.",
    "Vous disposez d'un droit d'accès, de rectification et de suppression de vos données.",
];

$engagementsNexraiz = [
    'Vous fournir un accompagnement personnalisé, bienveillant et structuré.',
    'Mobiliser des consultants qualifiés et sélectionnés avec soin.',
    'Respecter les délais annoncés pour chaque étape de votre parcours.',
    'Vous fournir des outils, ressources et recommandations pertinentes et actualisées.',
];

$engagementsClient = [
    'Fournir des informations exactes et à jour tout au long du parcours.',
    'Être acteur de votre évolution et appliquer les actions convenues.',
    "Respecter les rendez-vous et prévenir en cas d'empêchement.",
    "Faire preuve d'ouverture, d'écoute et de collaboration.",
];

$limitesMission = [
    'Une embauche ou une offre d\'emploi.',
    'Une promotion ou une évolution hiérarchique.',
    "L'obtention d'une certification ou d'un diplôme.",
    "La réussite d'une reconversion professionnelle.",
];

render_head('Pack Booster — Consentement et cadre');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'booster',
    5,
    "Validez le cadre d'accompagnement",
    'Prenez connaissance des règles de confidentialité et des limites de mission',
    'gpp_good',
    []
);
render_stepper('booster', 5);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="flex items-center gap-space-xs">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]">shield</span></div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Confidentialité des échanges</h3>
        </div>
        <ul class="flex flex-col gap-space-2xs">
          <?php foreach ($confidentialite as $c): ?>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span><span><?php echo htmlspecialchars($c); ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="flex items-center gap-space-xs">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]">handshake</span></div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Engagements NexRaiz</h3>
        </div>
        <ul class="flex flex-col gap-space-2xs">
          <?php foreach ($engagementsNexraiz as $e): ?>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span><span><?php echo htmlspecialchars($e); ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="flex items-center gap-space-xs">
          <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]">person</span></div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Engagements du client</h3>
        </div>
        <ul class="flex flex-col gap-space-2xs">
          <?php foreach ($engagementsClient as $e): ?>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span><span><?php echo htmlspecialchars($e); ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <div class="flex items-center gap-space-xs">
          <div class="w-10 h-10 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center"><span class="material-symbols-outlined text-[20px]">warning</span></div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Limites de mission</h3>
        </div>
        <p class="font-body-sm text-body-sm text-secondary">Le Pack Booster ne garantit en aucun cas :</p>
        <ul class="flex flex-col gap-space-2xs">
          <?php foreach ($limitesMission as $l): ?>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-error mt-0.5">close</span><span><?php echo htmlspecialchars($l); ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p class="font-body-sm text-[12px] text-secondary pt-space-2xs">Le Pack Booster ne remplace pas un conseil juridique, médical ou psychologique. En cas de besoin spécifique, nous vous recommandons de consulter un professionnel compétent.</p>
      </div>
    </div>

    <?php if ($erreurs): ?>
    <div class="flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
      <?php foreach ($erreurs as $e): ?>
      <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" id="consentForm" class="flex flex-col gap-space-md">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="signature" id="signatureInput" value="0">
    <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm grid grid-cols-1 md:grid-cols-2 gap-space-lg">
      <div class="flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Pour continuer, merci de confirmer :</h3>
        <label class="flex items-start gap-space-xs">
          <input type="checkbox" name="consent[]" value="charte" class="consent-check mt-1 w-4 h-4 accent-primary-container rounded">
          <span class="font-body-sm text-body-sm text-on-surface">J'ai lu et j'approuve la charte de consentement</span>
        </label>
        <label class="flex items-start gap-space-xs">
          <input type="checkbox" name="consent[]" value="cgu" class="consent-check mt-1 w-4 h-4 accent-primary-container rounded">
          <span class="font-body-sm text-body-sm text-on-surface">J'accepte les conditions d'utilisation</span>
        </label>
        <label class="flex items-start gap-space-xs">
          <input type="checkbox" name="consent[]" value="limites" class="consent-check mt-1 w-4 h-4 accent-primary-container rounded">
          <span class="font-body-sm text-body-sm text-on-surface">Je comprends les limites de mission du Pack Booster</span>
        </label>
      </div>
      <div class="flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Confirmation</h3>
        <p class="font-body-sm text-body-sm text-secondary">En validant, vous confirmez avoir compris et accepté le cadre d'accompagnement du Pack Booster.</p>
        <div class="relative border-2 border-dashed border-outline-variant rounded-xl bg-surface-container-low h-28 flex items-end justify-center pb-space-xs">
          <canvas id="signaturePad" class="absolute inset-0 w-full h-full cursor-crosshair" width="400" height="112"></canvas>
          <span id="signatureHint" class="font-body-sm text-[12px] text-secondary pointer-events-none">Signez ici</span>
        </div>
        <button type="button" id="clearSignatureBtn" class="self-start font-label-md text-label-md text-primary flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">refresh</span>Effacer la signature</button>
      </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
      <a href="<?php echo step_url(4, 'booster'); ?>" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Précédent</span>
      </a>
      <button type="submit" id="continueBtn" class="pointer-events-none opacity-50 h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm transition-colors">
        <span>Continuer vers le paiement</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </button>
    </div>
    <div class="flex items-center justify-center gap-space-2xs">
      <span class="material-symbols-outlined text-[16px] text-primary">lock</span>
      <span class="font-label-md text-[11px] text-secondary">Paiement 100% sécurisé</span>
    </div>
    </form>
  </div>
</section>

<script>
(function() {
  var checkboxes = document.querySelectorAll('.consent-check');
  var continueBtn = document.getElementById('continueBtn');
  var canvas = document.getElementById('signaturePad');
  var hint = document.getElementById('signatureHint');
  var clearBtn = document.getElementById('clearSignatureBtn');
  var signatureInput = document.getElementById('signatureInput');
  var hasSignature = false;

  function updateContinueState() {
    var allChecked = Array.prototype.every.call(checkboxes, function(c) { return c.checked; });
    var enabled = allChecked && hasSignature;
    if (continueBtn) {
      continueBtn.classList.toggle('pointer-events-none', !enabled);
      continueBtn.classList.toggle('opacity-50', !enabled);
    }
  }
  checkboxes.forEach(function(c) { c.addEventListener('change', updateContinueState); });

  if (canvas) {
    var ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#1E3E62';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    var drawing = false;

    function pos(e) {
      var rect = canvas.getBoundingClientRect();
      var scaleX = canvas.width / rect.width;
      var scaleY = canvas.height / rect.height;
      var point = e.touches ? e.touches[0] : e;
      return { x: (point.clientX - rect.left) * scaleX, y: (point.clientY - rect.top) * scaleY };
    }
    function start(e) {
      drawing = true;
      hasSignature = true;
      if (signatureInput) signatureInput.value = '1';
      if (hint) hint.style.display = 'none';
      var p = pos(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      updateContinueState();
    }
    function move(e) {
      if (!drawing) return;
      var p = pos(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      e.preventDefault();
    }
    function end() { drawing = false; }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start);
    canvas.addEventListener('touchmove', move);
    window.addEventListener('touchend', end);

    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasSignature = false;
        if (signatureInput) signatureInput.value = '0';
        if (hint) hint.style.display = '';
        updateContinueState();
      });
    }
  }
})();
</script>

<?php render_footer(); ?>
