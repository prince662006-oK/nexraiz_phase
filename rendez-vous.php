<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('assistance', 'espace-client.php');
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

        header('Location: ' . step_url(5, 'assistance'));
        exit;
    }
}

// Récapitulatif : réponses de l'étape "Qualification du besoin".
$stmt = db()->prepare('SELECT situation_type, urgence FROM qualifications WHERE dossier_id = ?');
$stmt->execute([$dossier['id']]);
$qualification = $stmt->fetch() ?: ['situation_type' => 'Non renseigné', 'urgence' => 'faible'];
$urgenceLabels = ['faible' => 'Faible', 'moyen' => 'Modéré', 'eleve' => 'Élevé', 'critique' => 'Critique'];

// Calendrier du mois affiché (navigation via ?mois=AAAA-MM), aujourd'hui par défaut.
$moisNoms = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
$jourNomsComplets = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
$joursSemaine = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];

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
$decalageDebut = (int) $premierJour->format('N') - 1; // 0 = lundi

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

$creneaux = ['09:00', '10:30', '14:00', '16:00'];
$creneauxPris = $jourSelectionneIso !== '' ? creneaux_pris($packId, $jourSelectionneIso) : [];
$creneauSelectionne = $jourSelectionneIso !== '' ? premier_creneau_disponible($packId, $jourSelectionneIso, $creneaux) : $creneaux[0];

render_head('Pack Assistance — Prise de rendez-vous');
render_top_header();
?>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<?php
render_hero(
    'assistance',
    4,
    'Prise de rendez-vous',
    'Choisissez votre créneau et votre format d\'accompagnement',
    'calendar_month',
    ['event_available', 'schedule']
);
render_stepper('assistance', 4);
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

  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-lg items-start">

    <div class="flex flex-col gap-space-lg">
      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold mb-space-md">Sélectionnez une date et un horaire disponibles</h2>
        <div class="grid grid-cols-1 md:grid-cols-[1.3fr_1fr] gap-space-lg">

          <div>
            <div class="flex items-center justify-between mb-space-sm">
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
                  class="calendar-day aspect-square rounded-lg flex items-center justify-center font-body-sm text-body-sm transition-colors
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
            <div class="flex items-center gap-space-md mt-space-sm">
              <span class="flex items-center gap-space-2xs font-body-sm text-[11px] text-secondary"><span class="w-3 h-3 rounded bg-primary-container/10"></span>Créneaux disponibles</span>
              <span class="flex items-center gap-space-2xs font-body-sm text-[11px] text-secondary"><span class="w-3 h-3 rounded bg-primary-container"></span>Date sélectionnée</span>
            </div>
          </div>

          <div class="flex flex-col gap-space-xs">
            <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Créneaux disponibles</h3>
            <p class="font-body-sm text-body-sm text-secondary" id="selectedDateLabel"><?php echo htmlspecialchars($jourSelectionneLabel); ?></p>
            <div class="flex flex-col gap-space-xs" id="slotGroup">
              <?php foreach ($creneaux as $c): $pris = in_array($c, $creneauxPris, true); ?>
              <button type="button" data-value="<?php echo $c; ?>" <?php echo $pris ? 'disabled' : ''; ?> class="slot-btn flex items-center justify-between px-space-md h-11 rounded-xl border-2 transition-colors <?php echo $pris ? 'opacity-50 cursor-not-allowed border-outline-variant bg-surface-container-low' : ($c === $creneauSelectionne ? 'border-primary-container bg-primary-container/5 selected' : 'border-outline-variant hover:border-primary-container'); ?>">
                <span class="flex items-center gap-space-xs font-label-lg text-label-lg text-on-surface font-semibold"><span class="material-symbols-outlined text-[18px] text-primary">event</span><?php echo $c; ?></span>
                <?php if ($pris): ?><span class="font-label-md text-[11px] text-secondary">Déjà pris</span>
                <?php elseif ($c === $creneauSelectionne): ?><span class="material-symbols-outlined text-[20px] text-primary-container">check_circle</span><?php endif; ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Format souhaité</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm" id="formatGroup">
          <button type="button" data-value="ligne" class="format-card selected relative flex flex-col items-center text-center gap-space-2xs p-space-md rounded-xl border-2 border-primary-container bg-primary-container/5">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center check-badge"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">videocam</span></div>
            <span class="font-label-lg text-label-lg text-on-surface font-bold">En ligne</span>
            <span class="font-body-sm text-[12px] text-secondary">Par visioconférence (Zoom ou Google Meet)</span>
          </button>
          <button type="button" data-value="a_definir" class="format-card relative flex flex-col items-center text-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container transition-colors">
            <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
            <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">groups</span></div>
            <span class="font-label-lg text-label-lg text-on-surface font-bold">À définir avec NexRaiz</span>
            <span class="font-body-sm text-[12px] text-secondary">Nous vous recontacterons pour convenir du format.</span>
          </button>
        </div>

        <div class="flex items-center justify-between gap-space-sm bg-surface-container-low rounded-xl p-space-sm mt-space-xs">
          <div class="flex items-center gap-space-xs">
            <span class="material-symbols-outlined text-[20px] text-primary">schedule</span>
            <div>
              <p class="font-label-lg text-label-lg text-on-surface font-semibold">Durée de la première session</p>
              <p class="font-body-sm text-[12px] text-secondary">60 minutes d'échange avec votre consultant NexRaiz pour analyser votre situation et définir un plan d'action personnalisé.</p>
            </div>
          </div>
          <span class="shrink-0 px-space-sm py-1 rounded-full bg-primary-container/10 text-primary font-label-md text-label-md font-bold">60 min</span>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-space-md">
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-bold">Récapitulatif de votre demande</h3>
        <div class="flex flex-col divide-y divide-outline-variant/60">
          <div class="flex items-start gap-space-xs py-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">account_balance_wallet</span>
            <div><p class="font-body-sm text-[12px] text-secondary">Situation concernée</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($qualification['situation_type'] ?: 'Non renseigné'); ?></p></div>
          </div>
          <div class="flex items-start gap-space-xs py-space-xs">
            <span class="material-symbols-outlined text-[18px] text-orange-500 mt-0.5">warning</span>
            <div><p class="font-body-sm text-[12px] text-secondary">Niveau d'urgence</p><p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($urgenceLabels[$qualification['urgence']] ?? 'Non renseigné'); ?></p></div>
          </div>
          <div class="flex items-start gap-space-xs py-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">calendar_today</span>
            <div><p class="font-body-sm text-[12px] text-secondary">Date</p><p class="font-label-lg text-label-lg text-on-surface font-semibold" id="recapDate"><?php echo htmlspecialchars($jourSelectionneLabel); ?></p></div>
          </div>
          <div class="flex items-start gap-space-xs py-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">schedule</span>
            <div><p class="font-body-sm text-[12px] text-secondary">Heure</p><p class="font-label-lg text-label-lg text-on-surface font-semibold" id="recapHeure"><?php echo htmlspecialchars($creneauSelectionne); ?></p></div>
          </div>
          <div class="flex items-start gap-space-xs py-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">computer</span>
            <div><p class="font-body-sm text-[12px] text-secondary">Format</p><p class="font-label-lg text-label-lg text-on-surface font-semibold" id="recapFormat">En ligne</p></div>
          </div>
          <div class="flex items-start gap-space-xs py-space-xs">
            <span class="material-symbols-outlined text-[18px] text-primary mt-0.5">person</span>
            <div><p class="font-body-sm text-[12px] text-secondary">Consultant NexRaiz</p><p class="font-label-lg text-label-lg text-on-surface font-semibold">À définir</p></div>
          </div>
        </div>
      </div>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex items-start gap-space-xs">
        <span class="material-symbols-outlined text-[20px] text-primary mt-0.5">event_repeat</span>
        <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Créneaux flexibles</p><p class="font-body-sm text-body-sm text-secondary">De nouveaux créneaux sont ajoutés chaque jour. Choisissez celui qui vous convient le mieux.</p></div>
      </div>
      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex items-start gap-space-xs">
        <span class="material-symbols-outlined text-[20px] text-primary mt-0.5">sync</span>
        <div><p class="font-label-lg text-label-lg text-on-surface font-semibold">Modification possible avant paiement</p><p class="font-body-sm text-body-sm text-secondary">Vous pourrez modifier la date, l'heure ou le format à l'étape suivante, avant de finaliser le paiement.</p></div>
      </div>

      <div class="flex flex-col gap-space-xs">
        <button type="submit" id="confirmSlotBtn" class="w-full h-12 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary transition-colors">
          <span>Confirmer ce créneau</span>
          <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </button>
        <div class="flex items-center justify-center gap-space-2xs pt-space-2xs">
          <span class="material-symbols-outlined text-[16px] text-primary">verified</span>
          <span class="font-label-md text-[11px] text-secondary">Espace sécurisé et conforme au RGPD</span>
        </div>
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
  var recapDate = document.getElementById('recapDate');
  var recapHeure = document.getElementById('recapHeure');
  var recapFormat = document.getElementById('recapFormat');
  var selectedDateLabel = document.getElementById('selectedDateLabel');
  var jourInput = document.getElementById('jourInput');
  var heureInput = document.getElementById('heureInput');
  var formatInput = document.getElementById('formatInput');

  function selectSlotButton(btn) {
    slotGroup.querySelectorAll('.slot-btn').forEach(function(b) {
      b.classList.remove('border-primary-container', 'bg-primary-container/5', 'selected');
      var chk = b.querySelector('.material-symbols-outlined.text-primary-container');
      if (chk) chk.remove();
    });
    btn.classList.add('border-primary-container', 'bg-primary-container/5', 'selected');
    if (!btn.querySelector('.text-primary-container')) {
      var icon = document.createElement('span');
      icon.className = 'material-symbols-outlined text-[20px] text-primary-container';
      icon.textContent = 'check_circle';
      btn.appendChild(icon);
    }
    if (recapHeure) recapHeure.textContent = btn.getAttribute('data-value');
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
    fetch('rendezvous-disponibilites.php?pack=assistance&jour=' + encodeURIComponent(jourIso))
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
          var badge = btn.querySelector('.slot-pris-badge');
          if (estPris) {
            if (!badge) {
              badge = document.createElement('span');
              badge.className = 'slot-pris-badge font-label-md text-[11px] text-secondary';
              badge.textContent = 'Déjà pris';
              btn.appendChild(badge);
            }
          } else if (badge) {
            badge.remove();
          }
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
        if (recapDate) recapDate.textContent = label;
        jourInput.value = day.getAttribute('data-day');
        refreshCreneaux(jourInput.value);
      });
    });
  }

  if (slotGroup) {
    slotGroup.querySelectorAll('.slot-btn').forEach(attachSlotHandler);
  }

  if (formatGroup) {
    var formatLabels = { ligne: 'En ligne', a_definir: 'À définir avec NexRaiz' };
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
        if (recapFormat) recapFormat.textContent = formatLabels[card.getAttribute('data-value')] || '';
        formatInput.value = card.getAttribute('data-value');
      });
    });
  }
})();
</script>

<?php render_footer(); ?>
