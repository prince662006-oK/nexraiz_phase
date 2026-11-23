<?php
require __DIR__ . '/includes/bootstrap.php';

render_head('Testez votre niveau de stress — Pack Wellbeing', "Un auto-diagnostic gratuit et sans inscription en 2 minutes : faites le point sur votre niveau de stress professionnel et découvrez si un accompagnement Pack Wellbeing pourrait vous aider.");
render_top_header();
?>
<style>
main{overflow-x:hidden;}
.reveal-up,.reveal-scale{opacity:0;transition:opacity .7s cubic-bezier(.22,.61,.36,1),transform .7s cubic-bezier(.22,.61,.36,1);}
.reveal-up{transform:translateY(24px);}
.reveal-scale{transform:scale(.94);}
.reveal-up.is-visible,.reveal-scale.is-visible{opacity:1;transform:translate(0,0) scale(1);}
@media (prefers-reduced-motion:reduce){.reveal-up,.reveal-scale{transition:none;opacity:1;transform:none;}}
.quiz-step{display:none;}
.quiz-step.is-active{display:flex;}
.quiz-option{transition:border-color .2s,background-color .2s,transform .15s;}
.quiz-option:hover{transform:translateY(-1px);}
.quiz-option.is-selected{border-color:#2F6F5E;background-color:#EEF7F2;}
.quiz-screen{display:none;}
.quiz-screen.is-active{display:flex;}
@keyframes well-pulse{0%,100%{box-shadow:0 0 0 0 rgba(37,99,235,.35);}50%{box-shadow:0 0 0 7px rgba(37,99,235,0);}}
.well-pulse{animation:well-pulse 2.4s ease-in-out infinite;}
@media (prefers-reduced-motion:reduce){.well-pulse{animation:none;}}
</style>
<main class="flex flex-col relative w-full pt-16 pb-space-4xl md:pb-space-2xl bg-surface min-h-[calc(100vh-5rem)]">

<!-- Intro -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-2xl pb-space-md bg-gradient-to-br from-[#EDF7F2] to-surface">
  <div class="max-w-2xl mx-auto flex flex-col items-center text-center gap-space-md">
    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="<?php echo htmlspecialchars(step_url(1, 'wellbeing')); ?>" class="hover:text-primary transition-colors">Pack Wellbeing</a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <span class="text-on-surface font-semibold">Test de stress</span>
    </nav>
    <span class="inline-flex items-center gap-space-2xs px-space-sm py-1.5 rounded-full bg-tertiary-fixed text-tertiary font-label-md text-label-md font-bold">
      <span class="material-symbols-outlined text-[18px]">timer</span>
      2 minutes · Gratuit · Sans inscription
    </span>
    <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-display-lg md:text-display-lg text-on-surface font-bold tracking-tight">
      Testez votre niveau de <span class="text-primary">stress professionnel</span>
    </h1>
    <p class="font-body-md text-body-md md:font-body-lg md:text-body-lg text-secondary max-w-max-width-reading">
      6 questions simples pour faire le point. Vos réponses ne sont ni enregistrées ni transmises — ce résultat reste entre vous et vous.
    </p>
  </div>
</section>

<!-- Quiz -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-xl">
  <div class="max-w-2xl mx-auto">

    <div id="quizCard" class="reveal-scale is-visible bg-surface-container-lowest rounded-2xl shadow-lg p-space-lg md:p-space-xl flex flex-col gap-space-lg">

      <!-- Progression -->
      <div id="quizProgressWrap" class="flex flex-col gap-space-2xs">
        <div class="flex items-center justify-between font-label-md text-label-md text-secondary">
          <span id="quizProgressLabel">Question 1 sur 6</span>
          <span id="quizProgressPercent">0%</span>
        </div>
        <div class="w-full h-2 rounded-full bg-surface-container-low overflow-hidden">
          <div id="quizProgressBar" class="h-full rounded-full bg-gradient-to-r from-[#2F6F5E] to-[#2563EB] transition-all duration-300" style="width:0%"></div>
        </div>
      </div>

      <!-- Questions -->
      <div id="quizSteps"></div>

      <!-- Résultat -->
      <div id="quizResult" class="quiz-screen flex-col gap-space-lg">
        <div class="flex flex-col items-center text-center gap-space-sm">
          <span id="resultIcon" class="w-16 h-16 rounded-full flex items-center justify-center"><span class="material-symbols-outlined text-[32px]">spa</span></span>
          <span id="resultTag" class="font-label-badge text-label-badge uppercase tracking-wider font-bold"></span>
          <h2 id="resultTitle" class="font-headline-xl-mobile text-headline-xl-mobile text-on-surface font-bold"></h2>
          <p id="resultText" class="font-body-md text-body-md text-secondary max-w-md"></p>
        </div>
        <div class="w-full h-3 rounded-full bg-surface-container-low overflow-hidden">
          <div id="resultBar" class="h-full rounded-full transition-all duration-700" style="width:0%"></div>
        </div>
        <div class="flex flex-col sm:flex-row gap-space-sm">
          <a href="<?php echo htmlspecialchars(step_url(1, 'wellbeing')); ?>" id="resultCta" class="well-pulse flex-1 h-12 px-space-lg rounded-xl bg-[#2563EB] text-white font-label-lg text-label-lg flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200">
            <span>Découvrir le Pack Wellbeing</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
          </a>
          <button type="button" onclick="quizRestart()" class="h-12 px-space-lg rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors">
            Refaire le test
          </button>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- Autres outils gratuits -->
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-2xl bg-surface-container-low">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-lg">
    <div class="reveal-up flex flex-col gap-space-2xs text-center">
      <span class="font-label-badge text-label-badge uppercase tracking-wider text-tertiary font-bold">Un avant-goût gratuit</span>
      <h2 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold">D'autres outils pour commencer</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-space-md">
      <div class="reveal-up bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm">
        <span class="w-12 h-12 rounded-full bg-gradient-to-br from-[#2F6F5E] to-[#163830] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">self_improvement</span></span>
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Exercice du jour</h3>
        <p class="font-body-sm text-body-sm text-secondary leading-relaxed">Avant de dormir, notez 3 choses qui se sont bien passées aujourd'hui — même petites. Ce simple réflexe réduit les ruminations et améliore la qualité du sommeil en quelques semaines.</p>
      </div>
      <div class="reveal-up bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm" style="transition-delay:80ms">
        <span class="w-12 h-12 rounded-full bg-gradient-to-br from-[#F0A83E] to-[#824500] text-white flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">timer</span></span>
        <h3 class="font-label-lg text-label-lg text-on-surface font-bold">La pause des 3 minutes</h3>
        <p class="font-body-sm text-body-sm text-secondary leading-relaxed">Entre deux réunions : fermez les yeux, respirez lentement pendant 1 minute, puis notez ce que vous ressentez pendant 2 minutes. Un micro-reset suffit souvent à faire retomber la pression.</p>
      </div>
      <div class="reveal-up bg-surface-container-lowest rounded-2xl p-space-lg shadow-sm flex flex-col gap-space-sm opacity-70" style="transition-delay:160ms">
        <span class="w-12 h-12 rounded-full bg-surface-container-low text-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]">podcasts</span></span>
        <div class="flex items-center gap-space-2xs">
          <h3 class="font-label-lg text-label-lg text-on-surface font-bold">Capsules audio &amp; vidéo</h3>
          <span class="px-space-2xs py-0.5 rounded-full bg-surface-container-low text-secondary font-label-md text-[10px] font-bold uppercase">Bientôt</span>
        </div>
        <p class="font-body-sm text-body-sm text-secondary leading-relaxed">De courtes capsules audio et animations pour approfondir chaque sujet arrivent prochainement dans cet espace.</p>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var questions = [
    { text: "Je me sens débordé(e) par ma charge de travail.", icon: 'work' },
    { text: "J'ai du mal à décrocher du travail en dehors des heures de bureau.", icon: 'bedtime' },
    { text: "Je me sens irritable ou à fleur de peau ces derniers temps.", icon: 'mood_bad' },
    { text: "J'ai des difficultés à me concentrer ou à prendre des décisions.", icon: 'psychology' },
    { text: "Je ressens une fatigue qui ne passe pas malgré le repos.", icon: 'battery_alert' },
    { text: "Je repousse des tâches importantes car je me sens dépassé(e).", icon: 'checklist' }
  ];
  var choices = [
    { label: 'Jamais', points: 0 },
    { label: 'Parfois', points: 1 },
    { label: 'Souvent', points: 2 },
    { label: 'Très souvent', points: 3 }
  ];
  var maxScore = questions.length * 3;
  var answers = new Array(questions.length).fill(null);
  var current = 0;

  var stepsEl = document.getElementById('quizSteps');
  var progressWrap = document.getElementById('quizProgressWrap');
  var progressLabel = document.getElementById('quizProgressLabel');
  var progressPercent = document.getElementById('quizProgressPercent');
  var progressBar = document.getElementById('quizProgressBar');
  var resultEl = document.getElementById('quizResult');

  function buildSteps() {
    stepsEl.innerHTML = questions.map(function (q, i) {
      var options = choices.map(function (c, ci) {
        return '<button type="button" class="quiz-option w-full text-left px-space-md py-space-sm rounded-xl border-2 border-outline-variant hover:border-[#2F6F5E] font-label-lg text-label-lg text-on-surface" data-q="' + i + '" data-points="' + c.points + '">' + c.label + '</button>';
      }).join('');
      return '<div class="quiz-step flex-col gap-space-md' + (i === 0 ? ' is-active' : '') + '" data-step="' + i + '">' +
        '<div class="flex items-center gap-space-sm">' +
          '<span class="w-10 h-10 rounded-full bg-[#EEF1FC] text-primary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[20px]">' + q.icon + '</span></span>' +
          '<p class="font-headline-sm text-headline-sm text-on-surface font-bold">' + q.text + '</p>' +
        '</div>' +
        '<div class="flex flex-col gap-space-xs">' + options + '</div>' +
        (i > 0 ? '<button type="button" class="self-start font-label-md text-label-md text-secondary hover:text-primary transition-colors" onclick="quizBack()">&larr; Question précédente</button>' : '') +
      '</div>';
    }).join('');

    stepsEl.querySelectorAll('.quiz-option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var q = parseInt(btn.getAttribute('data-q'), 10);
        var points = parseInt(btn.getAttribute('data-points'), 10);
        answers[q] = points;
        var step = btn.closest('.quiz-step');
        step.querySelectorAll('.quiz-option').forEach(function (o) { o.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        setTimeout(function () { goTo(q + 1); }, 220);
      });
    });
  }

  function updateProgress() {
    var answered = answers.filter(function (a) { return a !== null; }).length;
    var pct = Math.round((Math.min(current, questions.length) / questions.length) * 100);
    progressLabel.textContent = current < questions.length ? ('Question ' + (current + 1) + ' sur ' + questions.length) : 'Terminé';
    progressPercent.textContent = pct + '%';
    progressBar.style.width = pct + '%';
  }

  function goTo(i) {
    if (i >= questions.length) {
      showResult();
      return;
    }
    current = i;
    stepsEl.querySelectorAll('.quiz-step').forEach(function (s) {
      s.classList.toggle('is-active', parseInt(s.getAttribute('data-step'), 10) === i);
    });
    updateProgress();
  }

  window.quizBack = function () {
    if (current > 0) goTo(current - 1);
  };

  window.quizRestart = function () {
    answers = new Array(questions.length).fill(null);
    current = 0;
    resultEl.classList.remove('is-active');
    progressWrap.classList.remove('hidden');
    stepsEl.classList.remove('hidden');
    buildSteps();
    goTo(0);
  };

  function showResult() {
    current = questions.length;
    updateProgress();
    var score = answers.reduce(function (sum, p) { return sum + (p || 0); }, 0);
    var pct = Math.round((score / maxScore) * 100);

    var tier;
    if (pct <= 33) {
      tier = {
        tag: 'Niveau faible', color: '#2F6F5E',
        title: 'Vous semblez bien gérer votre charge actuelle',
        text: "Belle nouvelle : vos réponses ne montrent pas de signaux de stress marqués en ce moment. Continuez à cultiver ces bonnes habitudes — un accompagnement Pack Wellbeing peut aussi vous aider à les renforcer durablement.",
        icon: 'sentiment_satisfied', cta: 'Découvrir le Pack Wellbeing'
      };
    } else if (pct <= 66) {
      tier = {
        tag: 'Niveau modéré', color: '#F0A83E',
        title: 'Certains signaux de stress sont présents',
        text: "Ce n'est pas encore critique, mais ces signes méritent votre attention avant qu'ils ne s'installent. Un accompagnement structuré peut vous aider à les anticiper et à reprendre la main.",
        icon: 'sentiment_neutral', cta: 'Découvrir le Pack Wellbeing'
      };
    } else {
      tier = {
        tag: 'Niveau élevé', color: '#B3261E',
        title: 'Votre niveau de stress semble élevé',
        text: "Vous n'êtes pas seul(e) face à ça. Le Pack Wellbeing propose un accompagnement personnalisé avec un coach dédié pour vous aider à retrouver un équilibre durable, à votre rythme.",
        icon: 'sentiment_very_dissatisfied', cta: 'Découvrir le Pack Wellbeing'
      };
    }

    document.getElementById('resultIcon').style.background = tier.color;
    document.getElementById('resultIcon').style.color = '#fff';
    document.getElementById('resultIcon').querySelector('.material-symbols-outlined').textContent = tier.icon;
    var resultTag = document.getElementById('resultTag');
    resultTag.textContent = tier.tag;
    resultTag.style.color = tier.color;
    document.getElementById('resultTitle').textContent = tier.title;
    document.getElementById('resultText').textContent = tier.text;
    document.getElementById('resultBar').style.width = pct + '%';
    document.getElementById('resultBar').style.backgroundColor = tier.color;
    document.getElementById('resultCta').querySelector('span').textContent = tier.cta;

    progressWrap.classList.add('hidden');
    stepsEl.classList.add('hidden');
    resultEl.classList.add('is-active');
  }

  buildSteps();
})();
</script>

<?php render_footer(); ?>
