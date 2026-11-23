<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
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

        update_dossier_step((int) $dossier['id'], 6);
        log_activite((int) $dossier['id'], 'Rendez-vous planifié pour le ' . $jourChoisi . ' à ' . $heureChoisie);

        header('Location: ' . step_url(6, 'classic'));
        exit;
    }
}

$moisNoms = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
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

$jourNomsComplets = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
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

$creneaux = ['09:00', '10:00', '11:00', '14:00', '15:30', '16:30'];
$creneauxPris = $jourSelectionneIso !== '' ? creneaux_pris($packId, $jourSelectionneIso) : [];
$creneauSelectionne = $jourSelectionneIso !== '' ? premier_creneau_disponible($packId, $jourSelectionneIso, $creneaux) : $creneaux[0];

render_head('Pack Classic — Prise de rendez-vous');
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
@keyframes rdv-pulse{0%,100%{box-shadow:0 0 0 0 rgba(37,99,235,.35);}50%{box-shadow:0 0 0 6px rgba(37,99,235,0);}}
.calendar-day.selected{animation:rdv-pulse 2s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.calendar-day.selected{animation:none;}}
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
          Étape 5 sur <?php echo $totalEtapesClassic; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight">Prise de rendez-vous</h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold">Choisissez une date, une heure et le format de votre session</p>
      </div>

      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none rounded-2xl overflow-hidden shadow-lg border border-outline-variant/30">
        <img src="img_classic/rendevous.png" alt="Candidate et coach NexRaiz choisissant une date de rendez-vous" class="w-full h-full object-cover">
      </div>
    </div>
  </div>
</section>
<?php render_stepper('classic', 5); ?>

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

  <div class="max-w-max-width-content mx-auto grid grid-cols-1 lg:grid-cols-[1fr_1fr_0.9fr] gap-space-lg items-start">

    <div class="reveal-left bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
        <span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[12px] font-bold">1</span>
        Choisissez une date
      </h2>
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
            class="calendar-day aspect-square rounded-lg flex items-center justify-center font-body-sm text-body-sm transition-all duration-200
              <?php
              if ($estSelectionne) echo 'bg-primary-container text-white font-bold selected shadow-sm';
              elseif ($estDisponible) echo 'bg-primary-container/10 text-primary font-semibold hover:bg-primary-container/20 hover:-translate-y-0.5 cursor-pointer';
              else echo 'text-outline-variant';
              ?>">
            <?php echo (int) $cellule['date']->format('j'); ?>
          </button>
          <?php endif; ?>
        <?php endforeach; endforeach; ?>
      </div>
      <span class="flex items-center gap-space-2xs font-body-sm text-[11px] text-secondary mt-space-2xs"><span class="w-3 h-3 rounded-full bg-primary-container/20"></span>Dates disponibles</span>
    </div>

    <div class="reveal-up bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-sm" style="transition-delay:80ms">
      <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold">
        <span class="w-8 h-8 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center font-label-md text-[12px] font-bold">2</span>
        Choisissez un horaire
      </h2>
      <p class="font-body-sm text-body-sm text-secondary" id="selectedDateLabel"><?php echo htmlspecialchars($jourSelectionneLabel); ?></p>
      <div class="flex flex-col gap-space-xs" id="slotGroup">
        <?php foreach ($creneaux as $c): $pris = in_array($c, $creneauxPris, true); ?>
        <button type="button" data-value="<?php echo $c; ?>" <?php echo $pris ? 'disabled' : ''; ?> class="slot-btn flex items-center justify-between px-space-md h-11 rounded-xl border-2 transition-all duration-200 font-label-lg text-label-lg font-semibold <?php echo $pris ? 'opacity-50 cursor-not-allowed border-outline-variant bg-surface-container-low text-on-surface' : ($c === $creneauSelectionne ? 'border-primary-container bg-primary-container text-white selected shadow-sm' : 'border-outline-variant hover:border-primary-container hover:-translate-y-0.5 text-on-surface'); ?>">
          <span><?php echo $c; ?></span>
          <?php if ($pris): ?><span class="slot-pris-badge font-label-md text-[11px] text-secondary">Déjà pris</span>
          <?php elseif ($c === $creneauSelectionne): ?><span class="material-symbols-outlined text-[18px]">check</span><?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
      <p class="flex items-center gap-1 font-body-sm text-[12px] text-secondary">Durée estimée : 45 à 60 minutes <span class="material-symbols-outlined text-[14px]">info</span></p>
    </div>

    <div class="reveal-right flex flex-col gap-space-md" style="transition-delay:160ms">
      <div class="bg-gradient-to-br from-[#0B192C] to-[#1E3E62] text-white rounded-xl p-space-md shadow-sm flex flex-col gap-space-sm">
        <h3 class="font-headline-sm text-headline-sm text-white font-bold">Récapitulatif de votre choix</h3>
        <div class="flex flex-col gap-space-xs">
          <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-[#F0A83E] mt-0.5">calendar_today</span><div><p class="font-body-sm text-[12px] text-white/60">Date</p><p class="font-label-lg text-label-lg text-white font-semibold" id="recapDate"><?php echo htmlspecialchars($jourSelectionneLabel); ?></p></div></div>
          <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-[#F0A83E] mt-0.5">schedule</span><div><p class="font-body-sm text-[12px] text-white/60">Heure</p><p class="font-label-lg text-label-lg text-white font-semibold" id="recapHeure"><?php echo htmlspecialchars($creneauSelectionne); ?></p></div></div>
          <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-[#F0A83E] mt-0.5">videocam</span><div><p class="font-body-sm text-[12px] text-white/60">Format</p><p class="font-label-lg text-label-lg text-white font-semibold" id="recapFormat">En ligne</p></div></div>
          <div class="flex items-start gap-space-xs"><span class="material-symbols-outlined text-[18px] text-[#F0A83E] mt-0.5">timer</span><div><p class="font-body-sm text-[12px] text-white/60">Durée estimée</p><p class="font-label-lg text-label-lg text-white font-semibold">45 à 60 minutes</p></div></div>
        </div>
        <div class="flex items-center gap-space-xs pt-space-xs border-t border-white/10">
          <div class="w-10 h-10 rounded-full bg-white/10 text-[#F0A83E] flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0">T</div>
          <div><p class="font-body-sm text-[12px] text-white/60">Votre consultant</p><p class="font-label-lg text-label-lg text-white font-semibold">À définir</p><p class="font-body-sm text-[11px] text-white/60">Spécialiste carrière &amp; recrutement</p></div>
        </div>
      </div>

      <p class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary bg-surface-container-lowest rounded-xl p-space-sm shadow-sm"><span class="material-symbols-outlined text-[16px] text-primary">lock</span>Vos informations sont sécurisées et confidentielles.</p>

      <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col gap-space-xs">
        <h3 class="flex items-center gap-space-xs font-label-lg text-label-lg text-on-surface font-bold"><span class="material-symbols-outlined text-[18px] text-primary">gps_fixed</span>Ce rendez-vous permet de</h3>
        <ul class="flex flex-col gap-space-2xs">
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span>Analyser vos documents</li>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span>Clarifier votre objectif</li>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span>Préparer les corrections</li>
          <li class="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary mt-0.5">check</span>Organiser la suite du parcours</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="reveal-up max-w-max-width-content mx-auto flex flex-col gap-space-md">
    <h2 class="flex items-center gap-space-xs font-headline-md text-headline-md text-on-surface font-bold">
      <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#1E3E62] to-[#0B192C] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">videocam</span></span>
      3. Choisissez le format de votre session
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm" id="formatGroup">
      <button type="button" data-value="ligne" class="format-card selected relative flex flex-col items-center text-center gap-space-2xs p-space-md rounded-xl border-2 border-primary-container bg-primary-container/5 transition-all duration-200 hover:-translate-y-0.5">
        <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center check-badge"><span class="material-symbols-outlined text-[14px]">check</span></span>
        <div class="w-11 h-11 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">person</span></div>
        <span class="font-label-lg text-label-lg text-on-surface font-bold">En ligne</span>
        <span class="font-body-sm text-[12px] text-secondary">Échange en visioconférence depuis votre espace candidat.</span>
      </button>
      <button type="button" data-value="hybride" class="format-card relative flex flex-col items-center text-center gap-space-2xs p-space-md rounded-xl border-2 border-outline-variant hover:border-primary-container hover:-translate-y-0.5 transition-all duration-200">
        <span class="absolute top-2 right-2 w-5 h-5 rounded-full bg-primary-container text-white items-center justify-center check-badge hidden"><span class="material-symbols-outlined text-[14px]">check</span></span>
        <div class="w-11 h-11 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">groups</span></div>
        <span class="font-label-lg text-label-lg text-on-surface font-bold">Hybride</span>
        <span class="font-body-sm text-[12px] text-secondary">Un format adapté selon vos préférences et besoins.</span>
      </button>
    </div>
  </div>
</section>

<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="reveal-scale max-w-max-width-content mx-auto flex flex-col gap-space-xs">
    <div class="flex flex-col sm:flex-row gap-space-sm">
      <button type="submit" id="confirmSlotBtn" class="h-12 px-space-lg rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:bg-primary hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
        <span class="material-symbols-outlined text-[18px]">event_available</span>
        <span>Confirmer ce créneau</span>
      </button>
    </div>
    <div class="flex flex-col sm:flex-row sm:items-center gap-space-xs sm:gap-space-lg">
      <span class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[16px] text-primary">lock</span>Réservation gratuite et sans engagement</span>
      <span class="flex items-center gap-space-2xs font-label-md text-[11px] text-secondary"><span class="material-symbols-outlined text-[16px] text-primary">info</span>Vous pourrez modifier si besoin</span>
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
      b.classList.remove('border-primary-container', 'bg-primary-container', 'text-white', 'selected');
      b.classList.add('border-outline-variant', 'text-on-surface');
      var chk = b.querySelector('.material-symbols-outlined');
      if (chk) chk.remove();
    });
    btn.classList.remove('border-outline-variant', 'text-on-surface');
    btn.classList.add('border-primary-container', 'bg-primary-container', 'text-white', 'selected');
    var icon = document.createElement('span');
    icon.className = 'material-symbols-outlined text-[18px]';
    icon.textContent = 'check';
    btn.appendChild(icon);
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
    fetch('rendezvous-disponibilites.php?pack=classic&jour=' + encodeURIComponent(jourIso))
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
          d.classList.remove('bg-primary-container', 'text-white', 'font-bold', 'selected', 'shadow-sm');
          if (d.hasAttribute('data-day')) d.classList.add('bg-primary-container/10', 'text-primary', 'font-semibold');
        });
        day.classList.remove('bg-primary-container/10', 'text-primary', 'font-semibold');
        day.classList.add('bg-primary-container', 'text-white', 'font-bold', 'selected', 'shadow-sm');
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
    var formatLabels = { ligne: 'En ligne', hybride: 'Hybride' };
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
