<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('booster', 'booster-espace-client.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
    $stmt->execute([$dossier['id']]);
    $donneesExistantes = json_decode((string) $stmt->fetchColumn(), true) ?: [];

    $donnees = array_merge($donneesExistantes, [
        'objectif_principal' => trim($_POST['objectif'] ?? ''),
        'situation_actuelle' => trim($_POST['situation_actuelle'] ?? ''),
        'secteur_activite' => trim($_POST['secteur'] ?? ''),
        'poste_vise' => trim($_POST['poste_vise'] ?? ''),
        'horizon' => trim($_POST['horizon'] ?? ''),
        'contraintes' => $_POST['contraintes'] ?? [],
        'resultats_attendus' => $_POST['resultats'] ?? [],
        'resume' => trim($_POST['resume'] ?? ''),
        'poste_actuel' => trim($_POST['poste_actuel'] ?? ''),
        'entreprise' => trim($_POST['entreprise'] ?? ''),
        'ville' => trim($_POST['ville'] ?? ''),
    ]);

    $stmt = db()->prepare(
        'INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)'
    );
    $stmt->execute([$dossier['id'], json_encode($donnees, JSON_UNESCAPED_UNICODE)]);

    update_dossier_step((int) $dossier['id'], 4);
    log_activite((int) $dossier['id'], 'Qualification du projet complétée');

    header('Location: ' . step_url(4, 'booster'));
    exit;
}

$objectifs = [
    ['icone' => 'explore', 'label' => 'Clarifier mon projet'],
    ['icone' => 'work', 'label' => 'Faire un bilan de compétences'],
    ['icone' => 'autorenew', 'label' => 'Préparer une reconversion'],
    ['icone' => 'school', 'label' => 'Identifier des formations'],
    ['icone' => 'forum', 'label' => 'Renforcer ma communication'],
    ['icone' => 'badge', 'label' => 'Préparer une prise de poste'],
    ['icone' => 'psychology', 'label' => 'Développer mon leadership'],
    ['icone' => 'more_horiz', 'label' => 'Autre'],
];

$contraintes = [
    'Manque de temps',
    'Manque de clarté',
    'Manque de compétences',
    'Manque de confiance',
    'Situation professionnelle instable',
    'Autre contrainte',
];

$resultatsAttendus = [
    'Y voir plus clair sur mon avenir',
    'Gagner en compétences ciblées',
    'Changer de poste ou de métier',
    'Obtenir une formation adaptée',
    'Être plus confiant et impactant',
    'Autre résultat',
];

render_head('Pack Booster — Qualification du projet');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'booster',
    3,
    'Parlez-nous de votre projet professionnel',
    'Aidez-nous à personnaliser votre Pack Booster',
    'quiz',
    [],
    [['label' => 'Qualification du projet']]
);
render_stepper('booster', 3);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-lg items-start">

    <form id="qualifForm" method="post" class="flex flex-col gap-space-lg bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="objectif" id="objectifInput" value="">

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">1. Quel est votre objectif principal ?</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm" id="objectifGroup">
          <?php foreach ($objectifs as $i => $o): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($o['label']); ?>" class="option-radio flex items-center justify-between gap-space-xs px-space-sm h-12 rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="flex items-center gap-space-xs font-label-md text-label-md text-on-surface font-semibold"><span class="material-symbols-outlined text-[18px] text-primary"><?php echo $o['icone']; ?></span><?php echo htmlspecialchars($o['label']); ?></span>
            <span class="w-4 h-4 rounded-full border-2 border-outline-variant radio-dot shrink-0"></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-headline-sm text-headline-sm text-on-surface font-bold">2. Quelle est votre situation actuelle ?</span>
          <select name="situation_actuelle" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Sélectionnez votre situation</option>
            <option>En poste</option>
            <option>En transition</option>
            <option>En reconversion</option>
            <option>En recherche</option>
          </select>
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-headline-sm text-headline-sm text-on-surface font-bold">3. Secteur d'activité</span>
          <select name="secteur" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Sélectionnez votre secteur</option>
            <option>Tech &amp; Digital</option>
            <option>Finance &amp; Banque</option>
            <option>Santé</option>
            <option>Industrie</option>
            <option>Commerce &amp; Distribution</option>
            <option>Autre</option>
          </select>
        </label>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-space-2xs">
          <span class="font-headline-sm text-headline-sm text-on-surface font-bold">4. Métier ou poste visé <span class="font-body-sm text-[12px] text-secondary font-normal">(si déjà identifié)</span></span>
          <input type="text" name="poste_vise" placeholder="Ex. : Chef de projet digital, Responsable RH..." class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
        </label>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-headline-sm text-headline-sm text-on-surface font-bold">5. Horizon de projet</span>
          <select name="horizon" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
            <option value="">Sélectionnez votre horizon</option>
            <option>Moins de 3 mois</option>
            <option>3 à 6 mois</option>
            <option>6 à 12 mois</option>
            <option>Plus d'un an</option>
          </select>
        </label>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">6. Quelles sont vos principales contraintes actuelles ? <span class="font-body-sm text-[12px] text-secondary font-normal">(plusieurs choix possibles)</span></h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-xs">
          <?php foreach ($contraintes as $c): ?>
          <label class="flex items-center gap-space-xs">
            <input type="checkbox" name="contraintes[]" value="<?php echo htmlspecialchars($c); ?>" class="w-4 h-4 accent-primary-container rounded">
            <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($c); ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">7. Quel résultat attendez-vous en priorité ? <span class="font-body-sm text-[12px] text-secondary font-normal">(plusieurs choix possibles)</span></h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-xs">
          <?php foreach ($resultatsAttendus as $r): ?>
          <label class="flex items-center gap-space-xs">
            <input type="checkbox" name="resultats[]" value="<?php echo htmlspecialchars($r); ?>" class="w-4 h-4 accent-primary-container rounded">
            <span class="font-body-sm text-body-sm text-on-surface"><?php echo htmlspecialchars($r); ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Informations complémentaires <span class="font-body-sm text-[12px] text-secondary font-normal">(optionnel, aide votre coach à préparer la session)</span></h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-md text-label-md text-on-surface font-semibold">Poste actuel</span>
            <input type="text" name="poste_actuel" placeholder="Ex. : Responsable marketing" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-md text-label-md text-on-surface font-semibold">Entreprise actuelle</span>
            <input type="text" name="entreprise" placeholder="Ex. : Lumière Digital" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>
          <label class="flex flex-col gap-space-2xs">
            <span class="font-label-md text-label-md text-on-surface font-semibold">Ville</span>
            <input type="text" name="ville" placeholder="Ex. : Lyon" class="h-11 px-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          </label>
        </div>
      </div>

      <div class="flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">8. Décrivez votre situation en quelques lignes</h2>
        <p class="font-body-sm text-body-sm text-secondary">Partagez vos motivations, vos questionnements ou toute information utile pour mieux comprendre votre projet.</p>
        <textarea name="resume" id="projetTextarea" maxlength="1000" rows="4" placeholder="Écrivez ici..." class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"></textarea>
        <span class="font-body-sm text-[12px] text-secondary self-end" id="projetCounter">0 / 1000 caractères</span>
      </div>

      <div class="flex items-center justify-between gap-space-sm pt-space-xs">
        <a href="<?php echo step_url(2, 'booster'); ?>" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">arrow_back</span>
          <span>Précédent</span>
        </a>
        <button type="submit" class="h-11 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Enregistrer et continuer</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </div>
    </form>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Vos réponses serviront à...</h3>
        <div class="flex flex-col gap-space-sm">
          <div class="flex items-start gap-space-xs">
            <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">assignment</span></div>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Adapter le diagnostic</p><p class="font-body-sm text-body-sm text-secondary">Nous comprendrons mieux votre situation pour un diagnostic personnalisé et pertinent.</p></div>
          </div>
          <div class="flex items-start gap-space-xs">
            <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">tune</span></div>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Orienter les sessions</p><p class="font-body-sm text-body-sm text-secondary">Nous sélectionnerons les experts et les contenus les plus adaptés à vos besoins.</p></div>
          </div>
          <div class="flex items-start gap-space-xs">
            <div class="w-10 h-10 rounded-full bg-primary-container/10 text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">description</span></div>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Préparer le plan d'action</p><p class="font-body-sm text-body-sm text-secondary">Nous construirons un parcours sur-mesure pour atteindre vos objectifs efficacement.</p></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function() {
  var objectifGroup = document.getElementById('objectifGroup');
  var objectifInput = document.getElementById('objectifInput');
  if (objectifGroup) {
    objectifGroup.querySelectorAll('.option-radio').forEach(function(opt) {
      opt.addEventListener('click', function() {
        objectifGroup.querySelectorAll('.option-radio').forEach(function(o) {
          o.classList.remove('border-primary-container', 'bg-primary-container/5');
          o.querySelector('.radio-dot').classList.remove('bg-primary-container', 'border-primary-container');
        });
        opt.classList.add('border-primary-container', 'bg-primary-container/5');
        opt.querySelector('.radio-dot').classList.add('bg-primary-container', 'border-primary-container');
        if (objectifInput) objectifInput.value = opt.getAttribute('data-label');
      });
    });
  }

  var textarea = document.getElementById('projetTextarea');
  var counter = document.getElementById('projetCounter');
  if (textarea && counter) {
    textarea.addEventListener('input', function() {
      counter.textContent = textarea.value.length + ' / 1000 caractères';
    });
  }
})();
</script>

<?php render_footer(); ?>
