<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('wellbeing', 'wellbeing-espace-client.php');
$packId = (int) $dossier['pack_id'];

$erreurs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $jourChoisi = $_POST['jour'] ?? '';
    $heureChoisie = $_POST['heure'] ?? '';
    $formatChoisi = $_POST['format'] ?? 'ligne';

    if ($jourChoisi === '' || $heureChoisie === '') {
        $erreurs[] = 'Merci de choisir une date et un horaire disponibles.';
    } elseif (creneau_est_pris($packId, $jourChoisi, $heureChoisie)) {
        $erreurs[] = 'Ce créneau vient d\'être réservé par un autre candidat. Merci d\'en choisir un autre.';
    } else {
        $dateHeure = $jourChoisi . ' ' . $heureChoisie . ':00';
        $stmt = db()->prepare('INSERT INTO rendezvous (dossier_id, date_heure, format) VALUES (?, ?, ?)');
        $stmt->execute([$dossier['id'], $dateHeure, $formatChoisi]);

        update_dossier_step((int) $dossier['id'], 5);
        log_activite((int) $dossier['id'], 'Rendez-vous planifié pour le ' . $jourChoisi . ' à ' . $heureChoisie);

        header('Location: ' . step_url(5, 'wellbeing'));
        exit;
    }
}

$moisNoms = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
$joursSemaine = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
$jourNomsComplets = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

$moisParam = $_GET['mois'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $moisParam)) {
    $moisParam = date('Y-m');
}
[$anneeAff, $moisAff] = array_map('intval', explode('-', $moisParam));
$premierJour = new DateTimeImmutable(sprintf('%04d-%02d-01', $anneeAff, $moisAff));
$mois = $moisNoms[(int) $premierJour->format('n')] . ' ' . $premierJour->format('Y');
$moisPrecedent = $premierJour->modify('-1 month')->format('Y-m');
$moisSuivant = $premierJour->modify('+1 month')->format('Y-m');

$nbJoursMois = (int) $premierJour->format('t');
$decalageDebut = (int) $premierJour->format('N') - 1;

$aujourdhui = new DateTimeImmutable('today');
$cellules = [];
for ($i = 0; $i < $decalageDebut; $i++) {
    $cellules[] = null;
}
for ($j = 1; $j <= $nbJoursMois; $j++) {
    $date = $premierJour->setDate($anneeAff, $moisAff, $j);
    $estDisponible = $date >= $aujourdhui && (int) $date->format('N') <= 5;
    $cellules[] = ['date' => $date, 'disponible' => $estDisponible];
}
while (count($cellules) % 7 !== 0) {
    $cellules[] = null;
}
$grille = array_chunk($cellules, 7);

$jourSelectionne = null;
foreach ($cellules as $c) {
    if ($c && $c['disponible']) {
        $jourSelectionne = $c['date'];
        break;
    }
}
$jourSelectionneIso = $jourSelectionne ? $jourSelectionne->format('Y-m-d') : '';
$jourSelectionneLabel = $jourSelectionne
    ? $jourNomsComplets[(int) $jourSelectionne->format('N')] . ' ' . $jourSelectionne->format('j') . ' ' . strtolower($moisNoms[(int) $jourSelectionne->format('n')]) . ' ' . $jourSelectionne->format('Y')
    : 'Aucune date disponible ce mois-ci';

$creneaux = ['09:00', '10:00', '11:00', '14:00', '15:00', '16:00'];
$creneauxPris = $jourSelectionneIso !== '' ? creneaux_pris($packId, $jourSelectionneIso) : [];
$creneauSelectionne = $jourSelectionneIso !== '' ? premier_creneau_disponible($packId, $jourSelectionneIso, $creneaux) : $creneaux[0];

render_head('Pack Wellbeing — Choisissez votre rendez-vous');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'wellbeing',
    4,
    'Choisissez votre rendez-vous',
    'Sélectionnez la date, l\'heure et le format qui vous conviennent le mieux.',
    'calendar_month',
    []
);
render_stepper('wellbeing', 4);
?>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <?php if ($erreurs): ?>
  <div class="max-w-max-width-content mx-auto mb-space-md flex flex-col gap-space-2xs bg-error-container/40 rounded-xl p-space-sm">
    <?php foreach ($erreurs as $e): ?>
    <p class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[18px] text-error mt-0.5">error</span><?php echo htmlspecialchars($e); ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="post" id="rdvForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="jour" id="jourInput" value="<?php echo htmlspecialchars($jourSelectionneIso); ?>">
  <input type="hidden" name="heure" id="heureInput" value="<?php echo htmlspecialchars($creneauSelectionne); ?>">
  <input type="hidden" name="format" id="formatInput" value="ligne">

  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.5fr_1fr] gap-space-lg items-start">

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">1. Choisissez une date</h2>
        <div class="flex items-center justify-between mb-space-2xs">
          <a href="?mois=<?php echo $moisPrecedent; ?>" class="w-9 h-9 rounded-full hover:bg-surface-container-low flex items-center justify-center text-secondary" aria-label="Mois précédent"><span class="material-symbols-outlined text-[20px]">chevron_left</span></a>
          <span class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo $mois; ?></span>
          <a href="?mois=<?php echo $moisSuivant; ?>" class="w-9 h-9 rounded-full hover:bg-surface-container-low flex items-center justify-center text-secondary" aria-label="Mois suivant"><span class="material-symbols-outlined text-[20px]">chevron_right</span></a>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center mb-space-2xs">
          <?php foreach ($joursSemaine as $j): ?>
          <span class="font-label-md text-[11px] text-secondary font-bold"><?php echo $j; ?></span>
          <?php endforeach; ?>
        </div>
        <div class="grid grid-cols-7 gap-1" id="calendarGrid">
          <?php foreach ($grille as $semaine): foreach ($semaine as $cellule): ?>
            <?php if ($cellule === null): ?>
            <span></span>
            <?php else:
              $iso = $cellule['date']->format('Y-m-d');
              $estDisponible = $cellule['disponible'];
              $estSelectionne = $iso === $jourSelectionneIso;
              $label = $jourNomsComplets[(int) $cellule['date']->format('N')] . ' ' . $cellule['date']->format('j') . ' ' . strtolower($moisNoms[(int) $cellule['date']->format('n')]) . ' ' . $cellule['date']->format('Y');
            ?>
            <button type="button"
              <?php if ($estDisponible): ?>data-day="<?php echo $iso; ?>" data-label="<?php echo htmlspecialchars($label); ?>"<?php endif; ?>
              <?php echo $estDisponible ? '' : 'disabled'; ?>
              class="calendar-day aspect-square rounded-full flex items-center justify-center font-body-sm text-body-sm transition-colors
                <?php
                if ($estSelectionne) echo 'bg-primary-container text-white font-bold selected';
                elseif ($estDisponible) echo 'bg-primary-container/10 text-primary font-semibold hover:bg-primary-container/20 cursor-pointer';
                else echo 'text-outline-variant';
                ?>">
              <?php echo (int) $cellule['date']->format('j'); ?>
            </button>
            <?php endif; ?>
          <?php endforeach; endforeach; ?>
        </div>
        <span class="flex items-center gap-space-2xs font-body-sm text-[11px] text-secondary"><span class="w-3 h-3 rounded-full bg-primary-container/20"></span>Dates disponibles</span>

        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold pt-space-sm">2. Choisissez une heure</h2>
        <p class="font-body-sm text-body-sm text-secondary" id="selectedDateLabel"><?php echo htmlspecialchars($jourSelectionneLabel); ?></p>
        <div class="flex flex-wrap gap-space-sm" id="slotGroup">
          <?php foreach ($creneaux as $c): $pris = in_array($c, $creneauxPris, true); ?>
          <button type="button" data-value="<?php echo $c; ?>" <?php echo $pris ? 'disabled' : ''; ?> class="slot-btn px-space-md h-11 rounded-xl border-2 font-label-lg text-label-lg font-semibold transition-colors <?php echo $pris ? 'opacity-50 cursor-not-allowed border-outline-variant bg-surface-container-low text-on-surface' : ($c === $creneauSelectionne ? 'border-primary-container bg-primary-container text-white selected' : 'border-outline-variant hover:border-primary-container text-on-surface'); ?>"><?php echo $c; ?></button>
          <?php endforeach; ?>
        </div>
        <p class="flex items-center gap-1 font-body-sm text-[12px] text-secondary"><span class="material-symbols-outlined text-[14px]">schedule</span>Heure affichée dans le fuseau horaire sélectionné.</p>
      </div>

      <div class="bg-primary-container/5 rounded-xl p-space-lg flex flex-col gap-space-sm">
        <h3 class="flex items-center gap-space-xs font-label-lg text-label-lg text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-primary">eco</span>Un premier pas vers votre équilibre</h3>
        <p class="font-body-sm text-body-sm text-secondary">Ce rendez-vous marque le lancement de votre diagnostic Wellbeing. Ensemble, nous définirons vos priorités et élaborerons un plan d'action personnalisé et réaliste.</p>
        <div class="flex flex-wrap gap-space-sm pt-space-2xs">
          <span class="flex items-center gap-1 font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">headset_mic</span>Écoute bienveillante</span>
          <span class="flex items-center gap-1 font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">gps_fixed</span>Approche personnalisée</span>
          <span class="flex items-center gap-1 font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[14px] text-primary">lock</span>Confidentialité garantie</span>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Récapitulatif de votre rendez-vous</h3>
        <div class="flex items-center gap-space-xs pb-space-xs border-b border-outline-variant/60">
          <div class="w-11 h-11 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0">C</div>
          <div><p class="font-body-sm text-[11px] text-secondary">Consultante assignée</p><p class="font-label-lg text-label-lg text-on-surface font-semibold">Clara Martin</p><p class="font-body-sm text-[11px] text-secondary flex items-center gap-1">Coach Wellbeing certifiée <span class="material-symbols-outlined text-[14px] text-primary">verified</span></p></div>
        </div>
        <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary mt-0.5">event</span><div><p class="font-body-sm text-[12px] text-secondary">Type de rendez-vous</p><p class="font-label-lg text-label-lg text-on-surface font-semibold">Session de démarrage Wellbeing</p></div></div>
        <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-primary mt-0.5">schedule</span><div><p class="font-body-sm text-[12px] text-secondary">Durée</p><p class="font-label-lg text-label-lg text-on-surface font-semibold">60 minutes</p></div></div>

        <h4 class="font-label-lg text-label-lg text-on-surface font-bold pt-space-xs">3. Choisissez le format</h4>
        <div class="grid grid-cols-1 gap-space-xs" id="formatGroup">
          <button type="button" data-value="ligne" class="format-card selected relative flex items-center gap-space-sm p-space-sm rounded-xl border-2 border-primary-container bg-primary-container/5">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center check-badge"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <span class="material-symbols-outlined text-[22px] text-primary">computer</span>
            <span class="flex flex-col"><span class="font-label-lg text-label-lg text-on-surface font-bold">En ligne</span><span class="font-body-sm text-[11px] text-secondary">Visioconférence sécurisée</span></span>
          </button>
          <button type="button" data-value="hybride" class="format-card relative flex items-center gap-space-sm p-space-sm rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <span class="material-symbols-outlined text-[22px] text-primary">groups</span>
            <span class="flex flex-col"><span class="font-label-lg text-label-lg text-on-surface font-bold">Hybride</span><span class="font-body-sm text-[11px] text-secondary">Flexibilité selon vos besoins</span></span>
          </button>
        </div>

        <div class="flex items-center justify-between pt-space-xs border-t border-outline-variant/60"><span class="flex items-center gap-1 font-label-md text-label-md text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">public</span>Fuseau horaire</span><span class="font-body-sm text-[12px] text-secondary">Europe/Paris (CEST)</span></div>
        <div class="flex items-center justify-between"><span class="flex items-center gap-1 font-label-md text-label-md text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">notifications</span>Rappel</span><span class="font-body-sm text-[12px] text-secondary">Email et SMS</span></div>
        <div class="flex items-center justify-between"><span class="flex items-center gap-1 font-label-md text-label-md text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary">event_repeat</span>Reprogrammation</span><span class="font-body-sm text-[12px] text-secondary text-right">Gratuite jusqu'à 24h avant</span></div>
      </div>

      <div class="flex flex-col gap-space-xs">
        <button type="submit" id="confirmSlotBtn" class="h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Confirmer ce créneau</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <a href="<?php echo step_url(3, 'wellbeing'); ?>" class="h-12 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
          <span class="material-symbols-outlined text-[18px]">arrow_back</span>
          <span>Précédent</span>
        </a>
      </div>
    </div>
  </div>
  </form>
</section>

<script>
(function() {
  var calendarGrid = document.getElementById('calendarGrid');
  var slotGroup = document.getElementById('slotGroup');
  var formatGroup = document.getElementById('formatGroup');
  var selectedDateLabel = document.getElementById('selectedDateLabel');
  var jourInput = document.getElementById('jourInput');
  var heureInput = document.getElementById('heureInput');
  var formatInput = document.getElementById('formatInput');

  function selectSlotButton(btn) {
    slotGroup.querySelectorAll('.slot-btn').forEach(function(b) {
      b.classList.remove('border-primary-container', 'bg-primary-container', 'text-white', 'selected');
      b.classList.add('border-outline-variant', 'text-on-surface');
    });
    btn.classList.remove('border-outline-variant', 'text-on-surface');
    btn.classList.add('border-primary-container', 'bg-primary-container', 'text-white', 'selected');
    heureInput.value = btn.getAttribute('data-value');
  }

  function attachSlotHandler(btn) {
    btn.addEventListener('click', function() {
      if (btn.disabled) return;
      selectSlotButton(btn);
    });
  }

  function refreshCreneaux(jourIso) {
    if (!slotGroup || !jourIso) return;
    fetch('rendezvous-disponibilites.php?pack=wellbeing&jour=' + encodeURIComponent(jourIso))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var pris = data.pris || [];
        var btns = slotGroup.querySelectorAll('.slot-btn');
        var premierLibre = null;
        var selectionneEncoreLibre = false;
        btns.forEach(function(btn) {
          var valeur = btn.getAttribute('data-value');
          var estPris = pris.indexOf(valeur) !== -1;
          btn.disabled = estPris;
          btn.classList.toggle('opacity-50', estPris);
          btn.classList.toggle('cursor-not-allowed', estPris);
          btn.classList.toggle('bg-surface-container-low', estPris);
          if (!estPris && premierLibre === null) premierLibre = btn;
          if (!estPris && valeur === heureInput.value) selectionneEncoreLibre = true;
        });
        if (!selectionneEncoreLibre && premierLibre) {
          selectSlotButton(premierLibre);
        }
      })
      .catch(function() { /* En cas d'échec réseau, on laisse l'état déjà affiché. */ });
  }

  if (calendarGrid) {
    calendarGrid.querySelectorAll('.calendar-day[data-day]').forEach(function(day) {
      day.addEventListener('click', function() {
        calendarGrid.querySelectorAll('.calendar-day').forEach(function(d) {
          d.classList.remove('bg-primary-container', 'text-white', 'font-bold', 'selected');
          if (d.hasAttribute('data-day')) d.classList.add('bg-primary-container/10', 'text-primary', 'font-semibold');
        });
        day.classList.remove('bg-primary-container/10', 'text-primary', 'font-semibold');
        day.classList.add('bg-primary-container', 'text-white', 'font-bold', 'selected');
        var label = day.getAttribute('data-label');
        if (selectedDateLabel) selectedDateLabel.textContent = label;
        jourInput.value = day.getAttribute('data-day');
        refreshCreneaux(jourInput.value);
      });
    });
  }

  if (slotGroup) {
    slotGroup.querySelectorAll('.slot-btn').forEach(attachSlotHandler);
  }

  if (formatGroup) {
    formatGroup.querySelectorAll('.format-card').forEach(function(card) {
      card.addEventListener('click', function() {
        formatGroup.querySelectorAll('.format-card').forEach(function(c) {
          c.classList.remove('border-primary-container', 'bg-primary-container/5', 'selected');
          var badge = c.querySelector('.check-badge');
          if (badge) { badge.classList.add('hidden'); badge.classList.remove('flex'); }
        });
        card.classList.add('border-primary-container', 'bg-primary-container/5', 'selected');
        var badge = card.querySelector('.check-badge');
        if (badge) { badge.classList.remove('hidden'); badge.classList.add('flex'); }
        formatInput.value = card.getAttribute('data-value');
      });
    });
  }
})();
</script>

<?php render_footer(); ?>
