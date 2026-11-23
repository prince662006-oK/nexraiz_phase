<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');

$stmt = db()->prepare('SELECT donnees_json FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossier['id']]);
$qualificationExistante = json_decode((string) $stmt->fetchColumn(), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $objectifsBrut = json_decode($_POST['objectifs_json'] ?? '[]', true);
    $domainesBrut = json_decode($_POST['domaines_json'] ?? '[]', true);
    $donnees = array_merge($qualificationExistante, [
        // Plusieurs objectifs possibles (un candidat peut vouloir à la fois
        // améliorer son CV et préparer un entretien, par exemple) — même
        // principe que "domaines_cibles" ci-dessous, déjà multi-sélection.
        'objectif_principal' => is_array($objectifsBrut) ? array_values(array_filter($objectifsBrut, 'is_string')) : [],
        'situation_actuelle' => $_POST['situation'] ?? '',
        'domaines_cibles' => is_array($domainesBrut) ? $domainesBrut : [],
        'poste_vise' => trim($_POST['poste_vise'] ?? ''),
        'type_entreprise' => trim($_POST['type_entreprise'] ?? ''),
        'offre_en_tete' => trim($_POST['offre_en_tete'] ?? ''),
        'difficulte' => trim($_POST['difficulte'] ?? ''),
        'resultat_attendu' => trim($_POST['resultat_attendu'] ?? ''),
    ]);
    $stmt = db()->prepare(
        'INSERT INTO qualifications (dossier_id, donnees_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE donnees_json = VALUES(donnees_json)'
    );
    $stmt->execute([$dossier['id'], json_encode($donnees, JSON_UNESCAPED_UNICODE)]);
    update_dossier_step((int) $dossier['id'], 4);
    log_activite((int) $dossier['id'], 'Qualification du besoin complétée');

    header('Location: ' . step_url(4, 'classic'));
    exit;
}

$objectifs = [
    ['icone' => 'description', 'label' => 'Améliorer mon CV'],
    ['icone' => 'mail', 'label' => 'Améliorer ma lettre'],
    ['icone' => 'work_history', 'label' => 'Optimiser mon LinkedIn'],
    ['icone' => 'groups', 'label' => 'Préparer un entretien'],
    ['icone' => 'gps_fixed', 'label' => 'Préparer une candidature précise'],
    ['icone' => 'autorenew', 'label' => 'Revoir ma stratégie de candidature'],
];

$situations = [
    'Je cherche un emploi',
    'Je veux changer de poste',
    "Je prépare un entretien",
    'Je postule à une offre précise',
    'Candidature spontanée',
    'Améliorer mon image professionnelle',
];

$domaines = [
    'RH / administration',
    'Finance / audit',
    'Commercial',
    'Assistanat',
    'IT / digital',
    'Logistique',
    'Hôtellerie / événementiel',
    'Autre',
];

$champsComplementaires = [
    ['label' => 'Quel poste visez-vous ?', 'placeholder' => 'Ex. : Chargé de recrutement, Contrôleur de gestion, etc.'],
    ['label' => 'Quel type d\'entreprise ciblez-vous ?', 'placeholder' => 'Ex. : PME locale, multinationale, startup, ONG, etc.'],
    ['label' => 'Avez-vous déjà une offre en tête ?', 'placeholder' => "Ex. : Oui, j'ai une offre précise / Non"],
    ['label' => 'Quelle est votre principale difficulté ?', 'placeholder' => "Ex. : Mon CV manque d'impact, je ne suis pas sûr(e) de mon positionnement, je n'ai pas de réponses en entretien, etc."],
    ['label' => 'Quel résultat attendez-vous du Pack Classic ?', 'placeholder' => "Ex. : Avoir un CV percutant, réussir mes entretiens, être sélectionné(e) pour plus d'offres, etc."],
];

render_head('Pack Classic — Qualification du besoin');
render_top_header();
?>
<style>
main{overflow-x:hidden;}
.reveal-up,.reveal-scale,.reveal-left,.reveal-right{opacity:0;transition:opacity .7s cubic-bezier(.22,.61,.36,1),transform .7s cubic-bezier(.22,.61,.36,1);will-change:opacity,transform;}
.reveal-up{transform:translateY(24px);}
.reveal-scale{transform:scale(.94);}
.reveal-left{transform:translateX(-24px);}
.reveal-right{transform:translateX(24px);}
.reveal-up.is-visible,.reveal-scale.is-visible,.reveal-left.is-visible,.reveal-right.is-visible{opacity:1;transform:translate(0,0) scale(1);}
@media (prefers-reduced-motion:reduce){.reveal-up,.reveal-scale,.reveal-left,.reveal-right{transition:none;opacity:1;transform:none;}}
.option-card.is-selected{box-shadow:0 0 0 2px rgba(30,62,98,.15);}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
$totalEtapesClassic = count(pack_steps('classic'));
?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-[18px]">home</span>
      </a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="<?php echo htmlspecialchars(step_url(1, 'classic')); ?>" class="hover:text-primary transition-colors">Pack Classic</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Parcours client</span>
    </nav>

    <div class="reveal-up is-visible grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-space-lg items-center">
      <div class="flex flex-col gap-space-xs">
        <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-primary-container text-white font-label-md text-label-md font-bold">
          Étape 3 sur <?php echo $totalEtapesClassic; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Qualification du besoin</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Décrivez votre objectif professionnel et votre situation</p>
      </div>

      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
        <img src="img_classic/qualification.png" alt="Candidate exprimant sa situation à sa coach NexRaiz" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</section>
<?php
render_stepper('classic', 3);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-lg items-start">

    <form id="qualifForm" method="post" class="reveal-left bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-lg">
      <?php echo csrf_field(); ?>
      <h2 class="flex items-center gap-space-xs font-headline-md text-headline-md text-on-surface font-bold">
        <span class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">person</span></span>
        Profil candidat
      </h2>
      <?php
      // Données existantes : "objectif_principal" était un texte unique
      // avant ce changement — les dossiers déjà enregistrés sous cette
      // forme restent affichables (converti en tableau à un élément).
      $objectifExistantBrut = $qualificationExistante['objectif_principal'] ?? [];
      $objectifsExistants = is_array($objectifExistantBrut) ? $objectifExistantBrut : ($objectifExistantBrut !== '' ? [$objectifExistantBrut] : []);
      $situationExistante = $qualificationExistante['situation_actuelle'] ?? '';
      $domainesExistants = is_array($qualificationExistante['domaines_cibles'] ?? null) ? $qualificationExistante['domaines_cibles'] : [];
      ?>
      <input type="hidden" name="objectifs_json" id="objectifsInput" value="<?php echo htmlspecialchars(json_encode($objectifsExistants, JSON_UNESCAPED_UNICODE)); ?>">
      <input type="hidden" name="situation" id="situationInput" value="<?php echo htmlspecialchars($situationExistante); ?>">
      <input type="hidden" name="domaines_json" id="domainesInput" value="<?php echo htmlspecialchars(json_encode($domainesExistants, JSON_UNESCAPED_UNICODE)); ?>">

      <div class="reveal-up flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs">
          <span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[12px] font-bold shrink-0">1</span>
          <span class="font-label-lg text-label-lg text-on-surface font-bold">Objectif principal <span class="font-body-sm text-[12px] text-secondary font-normal">(un ou plusieurs)</span></span>
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-space-sm" id="objectifGroup">
          <?php foreach ($objectifs as $i => $o): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($o['label']); ?>" class="option-card relative flex flex-col items-center text-center gap-space-2xs p-space-md rounded-xl border-2 transition-all duration-200 hover:-translate-y-0.5 <?php echo in_array($o['label'], $objectifsExistants, true) ? 'is-selected border-primary-container bg-primary-container/5' : 'border-outline-variant hover:border-primary-container'; ?>">
            <span class="option-check absolute -top-2 -right-2 w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center shadow-sm <?php echo in_array($o['label'], $objectifsExistants, true) ? '' : 'hidden'; ?>"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <span class="material-symbols-outlined text-primary text-[24px]"><?php echo $o['icone']; ?></span>
            <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($o['label']); ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="reveal-up flex flex-col gap-space-sm" style="transition-delay:80ms">
        <h3 class="flex items-center gap-space-xs">
          <span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center font-label-md text-[12px] font-bold shrink-0">2</span>
          <span class="font-label-lg text-label-lg text-on-surface font-bold">Situation actuelle <span class="font-body-sm text-[12px] text-secondary font-normal">(une seule réponse)</span></span>
        </h3>
        <div class="flex flex-wrap gap-space-sm" id="situationGroup">
          <?php foreach ($situations as $i => $s): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($s); ?>" class="option-pill px-space-md py-2 rounded-full border-2 transition-all duration-200 hover:-translate-y-0.5 font-label-md text-label-md text-on-surface font-semibold <?php echo $s === $situationExistante ? 'border-primary-container bg-primary-container/5' : 'border-outline-variant hover:border-primary-container'; ?>"><?php echo htmlspecialchars($s); ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="reveal-up flex flex-col gap-space-sm" style="transition-delay:160ms">
        <h3 class="flex items-center gap-space-xs">
          <span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#1E3E62] to-[#0B192C] text-white flex items-center justify-center font-label-md text-[12px] font-bold shrink-0">3</span>
          <span class="font-label-lg text-label-lg text-on-surface font-bold">Domaine ciblé <span class="font-body-sm text-[12px] text-secondary font-normal">(un ou plusieurs)</span></span>
        </h3>
        <div class="flex flex-wrap gap-space-sm" id="domaineGroup">
          <?php foreach ($domaines as $i => $d): ?>
          <button type="button" data-label="<?php echo htmlspecialchars($d); ?>" class="option-pill-multi px-space-md py-2 rounded-full border-2 transition-all duration-200 hover:-translate-y-0.5 font-label-md text-label-md text-on-surface font-semibold <?php echo in_array($d, $domainesExistants, true) ? 'border-primary-container bg-primary-container/10' : 'border-outline-variant hover:border-primary-container'; ?>"><?php echo htmlspecialchars($d); ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="reveal-up flex flex-col gap-space-md" style="transition-delay:240ms">
        <h3 class="flex items-center gap-space-xs">
          <span class="w-7 h-7 rounded-full bg-gradient-to-br from-[#8FB2FF] to-[#2563EB] text-white flex items-center justify-center font-label-md text-[12px] font-bold shrink-0">4</span>
          <span class="font-label-lg text-label-lg text-on-surface font-bold">Informations complémentaires</span>
        </h3>
        <?php $champsNoms = ['poste_vise', 'type_entreprise', 'offre_en_tete', 'difficulte', 'resultat_attendu']; ?>
        <?php foreach ($champsComplementaires as $i => $champ): ?>
        <label class="flex flex-col gap-space-2xs">
          <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($champ['label']); ?></span>
          <textarea name="<?php echo $champsNoms[$i]; ?>" rows="2" placeholder="<?php echo htmlspecialchars($champ['placeholder']); ?>" class="w-full p-space-sm rounded-xl border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary resize-none"><?php echo htmlspecialchars($qualificationExistante[$champsNoms[$i]] ?? ''); ?></textarea>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="flex flex-col sm:flex-row gap-space-xs pt-space-xs">
        <button type="submit" class="h-12 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Enregistrer et continuer</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
      </div>
      <p class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">lock</span>Vos réponses sont enregistrées et sécurisées</p>
    </form>

    <div class="reveal-right flex flex-col gap-space-md lg:sticky lg:top-24">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
          <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">lightbulb</span></span>
          Conseils pour bien qualifier votre besoin
        </h3>
        <?php
        $conseils = [
            ['icone' => 'gps_fixed', 'degrade' => 'from-[#2563EB] to-[#1E3E62]', 'titre' => 'Soyez précis sur votre objectif', 'texte' => 'Plus vous êtes clair(e), plus nos experts pourront vous proposer un accompagnement adapté.'],
            ['icone' => 'search', 'degrade' => 'from-[#F0A83E] to-[#824500]', 'titre' => 'Détaillez votre situation actuelle', 'texte' => 'Cela nous aide à comprendre votre contexte et vos enjeux professionnels.'],
            ['icone' => 'apartment', 'degrade' => 'from-[#1E3E62] to-[#0B192C]', 'titre' => 'Ciblez votre domaine', 'texte' => "Indiquez le secteur ou le domaine visé pour bénéficier d'exemples pertinents et ciblés."],
            ['icone' => 'forum', 'degrade' => 'from-[#8FB2FF] to-[#2563EB]', 'titre' => 'Expliquez votre difficulté', 'texte' => 'Vos blocages nous permettent de vous apporter des solutions concrètes et personnalisées.'],
            ['icone' => 'insights', 'degrade' => 'from-[#824500] to-[#0B192C]', 'titre' => 'Définissez le résultat attendu', 'texte' => "Un objectif clair permet de mesurer votre progression et l'impact de notre accompagnement."],
        ];
        ?>
        <div class="flex flex-col gap-space-sm">
          <?php foreach ($conseils as $c): ?>
          <div class="flex items-start gap-space-xs p-space-2xs rounded-xl hover:bg-surface-container-low transition-colors duration-200">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br <?php echo $c['degrade']; ?> text-white flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?php echo $c['icone']; ?></span></div>
            <div><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($c['titre']); ?></p><p class="font-body-sm text-body-sm text-secondary"><?php echo htmlspecialchars($c['texte']); ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="flex items-start gap-space-xs bg-primary-container/5 rounded-xl p-space-sm">
          <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">shield</span>
          <p class="font-body-sm text-body-sm text-secondary">Vos informations restent strictement confidentielles et sont utilisées uniquement dans le cadre de votre accompagnement.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
(function() {
  function bindSingleSelect(groupId, cardClass, hiddenInputId) {
    var group = document.getElementById(groupId);
    var hiddenInput = document.getElementById(hiddenInputId);
    if (!group) return;
    group.querySelectorAll('.' + cardClass).forEach(function(el) {
      el.addEventListener('click', function() {
        group.querySelectorAll('.' + cardClass).forEach(function(o) { o.classList.remove('border-primary-container', 'bg-primary-container/5'); });
        el.classList.add('border-primary-container', 'bg-primary-container/5');
        if (hiddenInput) hiddenInput.value = el.getAttribute('data-label');
      });
    });
  }
  function bindMultiSelect(groupId, itemClass, hiddenInputId, toggleClasses) {
    var group = document.getElementById(groupId);
    var hiddenInput = document.getElementById(hiddenInputId);
    if (!group) return;
    group.querySelectorAll('.' + itemClass).forEach(function(el) {
      el.addEventListener('click', function() {
        toggleClasses.forEach(function(c) { el.classList.toggle(c); });
        el.classList.toggle('is-selected');
        var check = el.querySelector('.option-check');
        if (check) check.classList.toggle('hidden');
        var selectionnes = Array.from(group.querySelectorAll('.' + itemClass + '.' + toggleClasses[0])).map(function(o) { return o.getAttribute('data-label'); });
        if (hiddenInput) hiddenInput.value = JSON.stringify(selectionnes);
      });
    });
  }

  bindMultiSelect('objectifGroup', 'option-card', 'objectifsInput', ['border-primary-container', 'bg-primary-container/5']);
  bindSingleSelect('situationGroup', 'option-pill', 'situationInput');
  bindMultiSelect('domaineGroup', 'option-pill-multi', 'domainesInput', ['border-primary-container', 'bg-primary-container/10']);

  // --- Animations au scroll ---
  var cibles = document.querySelectorAll('.reveal-up, .reveal-scale, .reveal-left, .reveal-right');
  if ('IntersectionObserver' in window) {
    var observateur = new IntersectionObserver(function (entrees) {
      entrees.forEach(function (entree) {
        if (entree.isIntersecting) {
          entree.target.classList.add('is-visible');
          observateur.unobserve(entree.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    cibles.forEach(function (el) { observateur.observe(el); });
  } else {
    cibles.forEach(function (el) { el.classList.add('is-visible'); });
  }
})();
</script>

<?php render_footer(); ?>
