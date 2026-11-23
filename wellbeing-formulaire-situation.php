<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('wellbeing', 'wellbeing-espace-client.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $donnees = [
        'motif_principal' => $_POST['motif'] ?? '',
        'niveau_stress' => $_POST['stress'] ?? '',
        'situation_impactee' => $_POST['situation'] ?? [],
        'details' => trim($_POST['details'] ?? ''),
    ];

    $stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
    $stmt->execute([$dossier['id']]);
    $existantes = json_decode((string) $stmt->fetchColumn(), true) ?: [];
    $donnees = array_merge($existantes, $donnees);

    $stmt = db()->prepare(
        'INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)'
    );
    $stmt->execute([$dossier['id'], json_encode($donnees, JSON_UNESCAPED_UNICODE)]);
    update_dossier_step((int) $dossier['id'], 4);
    log_activite((int) $dossier['id'], 'Formulaire de situation complété');

    header('Location: ' . step_url(4, 'wellbeing'));
    exit;
}

$motifs = [
    ['icone' => 'psychology', 'label' => 'Stress professionnel'],
    ['icone' => 'work', 'label' => 'Surcharge de travail'],
    ['icone' => 'gps_fixed', 'label' => 'Difficulté à prioriser'],
    ['icone' => 'battery_alert', 'label' => 'Fatigue / manque de récupération'],
    ['icone' => 'balance', 'label' => 'Équilibre vie pro / vie perso'],
    ['icone' => 'front_hand', 'label' => 'Besoin de poser des limites'],
    ['icone' => 'edit', 'label' => 'Autre'],
];

$echelleStress = [
    ['valeur' => 1, 'label' => 'Faible'],
    ['valeur' => 2, 'label' => ''],
    ['valeur' => 3, 'label' => 'Modéré'],
    ['valeur' => 4, 'label' => ''],
    ['valeur' => 5, 'label' => 'Très élevé'],
];

$situationActuelle = [
    'Charge de travail',
    'Pression des délais',
    'Relations avec la hiérarchie',
    'Sommeil',
    'Temps de repos',
    'Ressources personnelles',
];

render_head('Pack Wellbeing — Formulaire de situation');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'wellbeing',
    3,
    'Parlez-nous de votre situation',
    'Ce formulaire nous aide à mieux comprendre vos besoins et vos priorités de bien-être pour vous proposer un accompagnement adapté.',
    'edit_note',
    [],
    [['label' => 'Formulaire de situation']]
);
render_stepper('wellbeing', 3);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-lg items-start">

    <form id="situationForm" method="post" class="flex flex-col gap-space-lg bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="motif" id="motifInput" value="<?php echo htmlspecialchars($motifs[0]['label']); ?>">
      <input type="hidden" name="stress" id="stressInput" value="3">

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">1. Motif principal de la demande</h2>
        <p class="font-body-sm text-body-sm text-secondary">Qu'est-ce qui vous préoccupe le plus actuellement ?</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-sm" id="motifGroup">
          <?php foreach ($motifs as $i => $m): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($m['label']); ?>" class="option-radio flex items-center justify-between gap-space-xs px-space-sm h-12 rounded-xl border-2 <?php echo $i === 0 ? 'border-primary-container bg-primary-container/5' : 'border-outline-variant hover:border-primary-container'; ?> transition-colors">
            <span class="flex items-center gap-space-xs font-label-md text-label-md text-on-surface font-semibold"><span class="material-symbols-outlined text-[18px] text-primary"><?php echo $m['icone']; ?></span><?php echo htmlspecialchars($m['label']); ?></span>
            <span class="w-4 h-4 rounded-full border-2 radio-dot shrink-0 <?php echo $i === 0 ? 'bg-primary-container border-primary-container' : 'border-outline-variant'; ?>"></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">2. Niveau de stress ressenti</h2>
        <p class="font-body-sm text-body-sm text-secondary">Comment évalueriez-vous votre niveau de stress global ces dernières semaines ?</p>
        <div class="flex items-center justify-between gap-0 max-w-md" id="stressScale">
          <?php foreach ($echelleStress as $i => $s): ?>
          <div class="flex flex-col items-center flex-1 gap-space-2xs relative">
            <?php if ($i > 0): ?><div class="absolute top-4 right-1/2 w-full h-0 border-t-2 border-outline-variant" style="left:-50%"></div><?php endif; ?>
            <button type="button" data-value="<?php echo $s['valeur']; ?>" class="stress-dot relative z-10 w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold transition-colors <?php echo $s['valeur'] === 3 ? 'bg-primary-container text-white' : 'bg-surface-container text-secondary hover:bg-surface-container-high'; ?>"><?php echo $s['valeur']; ?></button>
            <span class="font-label-md text-[11px] text-secondary"><?php echo htmlspecialchars($s['label']); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">3. Situation actuelle <span class="font-body-sm text-[12px] text-secondary font-normal">(plusieurs choix possibles)</span></h2>
        <p class="font-body-sm text-body-sm text-secondary">Quels aspects impactent le plus votre bien-être en ce moment ?</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-xs">
          <?php foreach ($situationActuelle as $s): ?>
          <label class="flex items-center gap-space-xs">
            <input type="checkbox" name="situation[]" value="<?php echo htmlspecialchars($s); ?>" class="w-4 h-4 accent-primary-container rounded">
            <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($s); ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">4. Expliquez brièvement votre situation</h2>
        <p class="font-body-sm text-body-sm text-secondary">Partagez en quelques lignes ce que vous traversez actuellement et ce que vous aimeriez améliorer.</p>
        <textarea name="details" id="situationTextarea" maxlength="1000" rows="4" placeholder="Votre réponse..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
        <span class="font-body-sm text-[12px] text-secondary self-end" id="situationCounter">0 / 1000 caractères</span>
      </div>

      <div class="flex flex-col sm:flex-row gap-space-sm pt-space-xs">
        <button type="submit" class="h-12 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span class="material-symbols-outlined text-[18px]">eco</span>
          <span>Continuer vers le rendez-vous</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </div>
    </form>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">eco</span>Pourquoi ces informations ?</h3>
        <p class="font-body-sm text-body-sm text-secondary">Vos réponses nous permettent de mieux comprendre votre situation et de personnaliser votre accompagnement pour des résultats concrets et durables.</p>
        <div class="flex items-center gap-space-xs pt-space-xs border-t border-outline-variant/60">
          <span class="material-symbols-outlined text-[18px] text-primary">schedule</span>
          <span class="font-label-md text-label-md text-on-surface">Durée estimée : <strong>5 min</strong></span>
        </div>
      </div>

      <div class="bg-primary-container/5 rounded-xl p-space-md flex flex-col gap-space-xs">
        <h3 class="flex items-center gap-space-xs font-label-lg text-label-lg text-on-surface font-bold"><span class="material-symbols-outlined text-[18px] text-primary">shield</span>Important</h3>
        <p class="font-body-sm text-body-sm text-secondary">Le Pack Wellbeing est un accompagnement de coaching bien-être et ne constitue pas un diagnostic médical.</p>
        <p class="font-body-sm text-body-sm text-secondary">En cas de détresse importante ou de souffrance psychologique, veuillez contacter un professionnel de santé qualifié ou un service d'écoute adapté à votre situation.</p>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex items-start gap-space-xs">
        <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">lock</span>
        <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Confidentialité</p><p class="font-body-sm text-body-sm text-secondary">Toutes vos réponses sont confidentielles et utilisées uniquement dans le cadre de votre accompagnement NexRaiz.</p></div>
      </div>
    </div>
  </div>
</section>

<script>
(function() {
  var motifGroup = document.getElementById('motifGroup');
  var motifInput = document.getElementById('motifInput');
  if (motifGroup) {
    motifGroup.querySelectorAll('.option-radio').forEach(function(opt) {
      opt.addEventListener('click', function() {
        motifGroup.querySelectorAll('.option-radio').forEach(function(o) {
          o.classList.remove('border-primary-container', 'bg-primary-container/5');
          o.querySelector('.radio-dot').classList.remove('bg-primary-container', 'border-primary-container');
        });
        opt.classList.add('border-primary-container', 'bg-primary-container/5');
        opt.querySelector('.radio-dot').classList.add('bg-primary-container', 'border-primary-container');
        if (motifInput) motifInput.value = opt.getAttribute('data-label');
      });
    });
  }

  var stressScale = document.getElementById('stressScale');
  var stressInput = document.getElementById('stressInput');
  if (stressScale) {
    stressScale.querySelectorAll('.stress-dot').forEach(function(dot) {
      dot.addEventListener('click', function() {
        stressScale.querySelectorAll('.stress-dot').forEach(function(d) {
          d.classList.remove('bg-primary-container', 'text-white');
          d.classList.add('bg-surface-container', 'text-secondary');
        });
        dot.classList.remove('bg-surface-container', 'text-secondary');
        dot.classList.add('bg-primary-container', 'text-white');
        if (stressInput) stressInput.value = dot.getAttribute('data-value');
      });
    });
  }

  var textarea = document.getElementById('situationTextarea');
  var counter = document.getElementById('situationCounter');
  if (textarea && counter) {
    textarea.addEventListener('input', function() {
      counter.textContent = textarea.value.length + ' / 1000 caractères';
    });
  }
})();
</script>

<?php render_footer(); ?>
