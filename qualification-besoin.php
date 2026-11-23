<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('assistance', 'espace-client.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $situationType = trim($_POST['situation_type'] ?? '');
    $urgence = trim($_POST['urgence'] ?? '');
    $priorites = trim($_POST['priorites'] ?? '[]');
    $resume = trim($_POST['resume'] ?? '');
    $documentsChoisis = $_POST['documents'] ?? [];

    $stmt = db()->prepare(
        'INSERT INTO qualifications (dossier_id, situation_type, urgence, priorites, resume, documents_disponibles)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE situation_type = VALUES(situation_type), urgence = VALUES(urgence),
             priorites = VALUES(priorites), resume = VALUES(resume), documents_disponibles = VALUES(documents_disponibles)'
    );
    $stmt->execute([$dossier['id'], $situationType, $urgence, $priorites, $resume, json_encode($documentsChoisis)]);

    update_dossier_step((int) $dossier['id'], 4);
    log_activite((int) $dossier['id'], 'Qualification du besoin complétée');

    header('Location: ' . step_url(4, 'assistance'));
    exit;
}

$situations = [
    ['icone' => 'account_balance_wallet', 'label' => 'Négociation salariale'],
    ['icone' => 'handshake', 'label' => 'Rupture conventionnelle'],
    ['icone' => 'groups', 'label' => "Conflit avec l'employeur"],
    ['icone' => 'front_hand', 'label' => 'Harcèlement présumé'],
    ['icone' => 'description', 'label' => 'Licenciement contesté'],
    ['icone' => 'more_horiz', 'label' => 'Autre situation'],
];

$urgences = [
    ['niveau' => 'faible', 'label' => 'Faible', 'texte' => 'Je peux attendre', 'couleur' => 'bg-outline-variant'],
    ['niveau' => 'moyen', 'label' => 'Moyen', 'texte' => 'À traiter rapidement', 'couleur' => 'bg-yellow-500'],
    ['niveau' => 'eleve', 'label' => 'Élevé', 'texte' => 'Situation préoccupante', 'couleur' => 'bg-orange-500'],
    ['niveau' => 'critique', 'label' => 'Critique', 'texte' => 'Urgence immédiate', 'couleur' => 'bg-error'],
];

$priorites = [
    ['icone' => 'forum', 'label' => 'Préparer un échange'],
    ['icone' => 'folder', 'label' => 'Organiser mes documents'],
    ['icone' => 'lightbulb', 'label' => 'Comprendre ma situation'],
    ['icone' => 'balance', 'label' => 'Préparer une négociation'],
    ['icone' => 'person', 'label' => 'Être orienté vers un professionnel'],
];

$documents = [
    'Contrat de travail',
    'Captures (messages, outils internes)',
    'Emails',
    'Témoignages',
    'Échanges RH',
    'Autres documents',
];

render_head('Pack Assistance — Qualification du besoin');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'assistance',
    3,
    'Qualification du besoin',
    'Décrivez votre situation pour orienter votre accompagnement',
    'quiz',
    [],
    [['label' => 'Qualification du besoin']]
);
render_stepper('assistance', 3);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-lg items-start">

    <form id="qualifForm" method="post" class="flex flex-col gap-space-lg bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="situation_type" id="situationTypeInput" value="">
      <input type="hidden" name="urgence" id="urgenceInput" value="">
      <input type="hidden" name="priorites" id="prioritesInput" value="[]">

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">1. Type de situation</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-space-sm" id="situationGroup">
          <?php foreach ($situations as $i => $s): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($s['label']); ?>" class="option-card situation-card flex flex-col items-center text-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="material-symbols-outlined text-primary text-[26px]"><?php echo $s['icone']; ?></span>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($s['label']); ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <p class="font-body-sm text-[12px] text-secondary">Sélectionnez la situation qui correspond le mieux à votre demande.</p>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">2. Niveau d'urgence ressenti</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-space-sm" id="urgenceGroup">
          <?php foreach ($urgences as $u): ?>
          <button type="button" data-value="<?php echo $u['niveau']; ?>" class="option-card urgence-card flex flex-col gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors text-left">
            <span class="flex items-center gap-space-2xs font-label-lg text-label-lg text-on-surface font-bold"><span class="w-2.5 h-2.5 rounded-full <?php echo $u['couleur']; ?>"></span><?php echo $u['label']; ?></span>
            <span class="font-body-sm text-[12px] text-secondary"><?php echo $u['texte']; ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <p class="font-body-sm text-[12px] text-secondary">Cette information nous aide à prioriser votre accompagnement.</p>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">3. Priorité immédiate <span class="font-body-sm text-[12px] text-secondary font-normal">(sélectionnez jusqu'à 2 priorités)</span></h2>
        <div class="flex flex-wrap gap-space-sm" id="prioriteGroup">
          <?php foreach ($priorites as $i => $p): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($p['label']); ?>" class="option-pill priorite-pill inline-flex items-center gap-space-2xs px-space-md py-2 rounded-full border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="material-symbols-outlined text-primary text-[18px]"><?php echo $p['icone']; ?></span>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($p['label']); ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <p class="font-body-sm text-[12px] text-secondary">Vos priorités nous permettent d'adapter dès maintenant notre préparation.</p>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">4. Résumé de votre situation</h2>
        <p class="font-body-sm text-body-sm text-secondary">Décrivez brièvement les faits, le contexte et votre demande.</p>
        <textarea name="resume" id="resumeTextarea" maxlength="1500" rows="5" placeholder="Décrivez votre situation ici..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
        <span class="font-body-sm text-[12px] text-secondary self-end" id="resumeCounter">0 / 1500 caractères</span>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">5. Documents disponibles <span class="font-body-sm text-[12px] text-secondary font-normal">(sélectionnez tous les documents dont vous disposez)</span></h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-xs">
          <?php foreach ($documents as $doc): ?>
          <label class="flex items-center gap-space-xs">
            <input type="checkbox" name="documents[]" value="<?php echo htmlspecialchars($doc); ?>" class="w-4 h-4 accent-primary-container rounded">
            <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($doc); ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="font-body-sm text-[12px] text-secondary">Vous pourrez envoyer vos documents en toute sécurité avant votre premier rendez-vous.</p>
      </div>

      <div class="flex items-center justify-between gap-space-sm pt-space-xs">
        <a href="<?php echo step_url(2, 'assistance'); ?>" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">arrow_back</span>
          <span>Retour</span>
        </a>
        <button type="submit" class="h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Enregistrer et continuer</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </div>
    </form>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]">person</span></div>
          <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Pourquoi ces informations ?</h3>
        </div>
        <div class="flex flex-col gap-space-sm">
          <div class="flex items-start gap-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">track_changes</span>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Clarifier votre besoin</p><p class="font-body-sm text-body-sm text-secondary">Ces informations nous aident à bien comprendre votre situation et vos objectifs.</p></div>
          </div>
          <div class="flex items-start gap-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">groups</span>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Préparer votre accompagnement</p><p class="font-body-sm text-body-sm text-secondary">Elles permettent à votre expert de préparer votre premier échange de manière <a href="#" class="text-primary underline">ciblée</a>.</p></div>
          </div>
          <div class="flex items-start gap-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">schedule</span>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Gagner du temps</p><p class="font-body-sm text-body-sm text-secondary">Vous évitez de tout réexpliquer et démarrez plus efficacement votre accompagnement.</p></div>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <div class="flex items-center gap-space-xs">
          <span class="material-symbols-outlined text-[20px] text-primary">shield</span>
          <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Confidentialité garantie</h3>
        </div>
        <p class="font-body-sm text-body-sm text-secondary">Vos informations sont protégées et ne sont jamais partagées.</p>
        <div class="flex items-start gap-space-xs pt-space-xs border-t border-outline-variant/60">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">lock</span>
          <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Un cadre sécurisé</p><p class="font-body-sm text-body-sm text-secondary">Un espace dédié et bienveillant pour avancer à votre rythme.</p></div>
        </div>
        <div class="flex items-start gap-space-xs">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">verified_user</span>
          <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">NexRaiz n'est pas un avocat</p><p class="font-body-sm text-body-sm text-secondary">Nos services ne remplacent pas un conseil juridique. En cas de besoin, nous vous orientons vers des professionnels.</p></div>
        </div>
      </div>

      <div class="bg-error-container/40 rounded-xl p-space-md shadow-sm flex items-center justify-between gap-space-sm">
        <div>
          <p class="font-label-lg text-label-lg text-on-surface font-semibold">Besoin d'aide ?</p>
          <p class="font-body-sm text-body-sm text-secondary">Notre équipe est là pour vous aider.</p>
        </div>
      </div>
      <a href="contact.php" class="h-11 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors -mt-space-xs">
        <span>Nous contacter</span>
        <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
      </a>
    </div>
  </div>
</section>

<script>
(function() {
  var situationInput = document.getElementById('situationTypeInput');
  var urgenceInput = document.getElementById('urgenceInput');
  var prioritesInput = document.getElementById('prioritesInput');

  var situationGroup = document.getElementById('situationGroup');
  if (situationGroup) {
    situationGroup.querySelectorAll('.situation-card').forEach(function(card) {
      card.addEventListener('click', function() {
        situationGroup.querySelectorAll('.situation-card').forEach(function(c) { c.classList.remove('border-primary-container', 'bg-primary-container/5'); });
        card.classList.add('border-primary-container', 'bg-primary-container/5');
        situationInput.value = card.getAttribute('data-label');
      });
    });
  }

  var urgenceGroup = document.getElementById('urgenceGroup');
  if (urgenceGroup) {
    urgenceGroup.querySelectorAll('.urgence-card').forEach(function(card) {
      card.addEventListener('click', function() {
        urgenceGroup.querySelectorAll('.urgence-card').forEach(function(c) { c.classList.remove('border-primary-container', 'bg-primary-container/5'); });
        card.classList.add('border-primary-container', 'bg-primary-container/5');
        urgenceInput.value = card.getAttribute('data-value');
      });
    });
  }

  var prioriteGroup = document.getElementById('prioriteGroup');
  if (prioriteGroup) {
    prioriteGroup.querySelectorAll('.priorite-pill').forEach(function(pill) {
      pill.addEventListener('click', function() {
        var selected = prioriteGroup.querySelectorAll('.priorite-pill.border-primary-container');
        var isSelected = pill.classList.contains('border-primary-container');
        if (!isSelected && selected.length >= 2) return;
        pill.classList.toggle('border-primary-container');
        pill.classList.toggle('bg-primary-container/10');
        var choisies = [];
        prioriteGroup.querySelectorAll('.priorite-pill.border-primary-container').forEach(function(p) { choisies.push(p.getAttribute('data-label')); });
        prioritesInput.value = JSON.stringify(choisies);
      });
    });
  }

  var textarea = document.getElementById('resumeTextarea');
  var counter = document.getElementById('resumeCounter');
  if (textarea && counter) {
    textarea.addEventListener('input', function() {
      counter.textContent = textarea.value.length + ' / 1500 caractères';
    });
  }
})();
</script>

<?php render_footer(); ?>
