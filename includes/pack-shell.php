<?php
/**
 * NexRaiz — Habillage commun (en-tête + sidebar) des pages dédiées d'un
 * pack d'accompagnement continu (Assistance, Booster, Wellbeing — tout pack
 * dont le client revient régulièrement sur un tableau de bord). Généralisé
 * depuis l'habillage initialement écrit pour le seul Pack Assistance, pour
 * éviter de dupliquer la même en-tête/sidebar pour chaque pack.
 *
 * @param array{sidebar_titre:string,icone:string,titre:string,texte:string,bouton_label:string,bouton_href:string} $promo
 */
function pack_header_debut(array $client, array $sidebarNav, array $promo): void
{
?>
<style>
#dashSearchResults{display:none;}
#dashSearchResults.is-open{display:flex;}
#avatarMenu{display:none;}
#avatarMenu.is-open{display:flex;}
</style>

<div class="flex w-full min-h-screen bg-surface">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs overflow-y-auto min-h-0">
    <a href="index.php" class="flex items-center gap-1.5 pb-space-xs">
      <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
    </a>
    <h2 class="font-headline-sm text-headline-sm font-bold text-inverse-on-surface px-space-xs pb-space-sm"><?php echo htmlspecialchars($promo['sidebar_titre']); ?></h2>
    <?php foreach ($sidebarNav as $item): ?>
    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo !empty($item['actif']) ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
      <?php if (!empty($item['badge'])): ?>
      <span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $item['badge']; ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <div class="mt-space-md rounded-2xl bg-gradient-to-br from-[#2563EB] to-[#13203F] p-space-md flex flex-col gap-space-2xs">
      <span class="material-symbols-outlined text-[#FFC700] text-[26px]"><?php echo htmlspecialchars($promo['icone']); ?></span>
      <p class="font-label-lg text-label-lg font-bold text-white"><?php echo htmlspecialchars($promo['titre']); ?></p>
      <p class="font-body-sm text-[11px] text-white/70"><?php echo htmlspecialchars($promo['texte']); ?></p>
      <a href="<?php echo htmlspecialchars($promo['bouton_href']); ?>" class="mt-space-2xs h-9 rounded-lg bg-[#FFC700] text-[#13203F] font-label-md text-label-md font-bold flex items-center justify-center gap-1 hover:opacity-90 transition-opacity"><span class="flex-1 text-center"><?php echo htmlspecialchars($promo['bouton_label']); ?></span> <span class="material-symbols-outlined text-[14px] shrink-0">arrow_forward</span></a>
    </div>
    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <p class="font-label-lg text-label-lg font-bold px-space-xs">Besoin d'aide ?</p>
      <p class="font-body-sm text-[12px] text-inverse-on-surface/70 px-space-xs">Notre équipe est là pour vous accompagner.</p>
      <a href="contact.php" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Nous contacter</a>
      <a href="logout.php" class="mx-space-xs h-10 rounded-xl flex items-center justify-center gap-1 font-label-md text-label-md text-inverse-on-surface/75 hover:bg-white/5 transition-colors"><span class="material-symbols-outlined text-[16px]">logout</span>Déconnexion</a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center gap-space-md px-gutter-mobile lg:px-gutter-desktop">
      <a href="index.php" class="flex items-center gap-1.5 shrink-0 lg:hidden">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </a>
      <div class="hidden sm:block flex-1 max-w-md relative" id="dashSearchWrapper">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-secondary text-[20px] pointer-events-none">search</span>
        <input id="dashSearch" type="text" autocomplete="off" placeholder="Rechercher une fonctionnalité... (Ctrl+K)" class="w-full h-11 pl-10 pr-space-sm rounded-xl border border-outline-variant bg-surface-container-low font-body-sm text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 transition-shadow">
        <div id="dashSearchResults" class="absolute left-0 right-0 top-full mt-2 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-2xs flex-col z-50 max-h-72 overflow-y-auto"></div>
      </div>
      <div class="flex items-center gap-space-xs shrink-0 ml-auto">
        <?php render_notification_bell((int) $client['id']); ?>
        <div class="relative" id="avatarMenuWrapper">
          <button id="avatarMenuBtn" type="button" class="flex items-center gap-space-2xs pl-1 pr-space-xs py-1 rounded-full hover:bg-surface-container-low transition-colors">
            <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[13px] font-bold shrink-0"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($client['prenom'] ?? '?', 0, 1) . mb_substr($client['nom'] ?? '', 0, 1))); ?></span>
            <span class="hidden md:flex flex-col items-start leading-none">
              <span class="font-label-md text-label-md text-on-surface font-bold"><?php echo htmlspecialchars(trim(($client['prenom'] ?? '') . ' ' . ($client['nom'] ?? ''))); ?></span>
              <span class="font-body-sm text-[11px] text-secondary">Client</span>
            </span>
            <span class="material-symbols-outlined text-[18px] text-secondary">expand_more</span>
          </button>
          <div id="avatarMenu" class="absolute right-0 top-full mt-2 w-52 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-2xs flex-col z-50">
            <a href="parametres.php" class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">settings</span>Paramètres</a>
            <a href="contact.php" class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">support_agent</span>Nous contacter</a>
            <a href="logout.php" class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-error hover:bg-error-container/10 transition-colors flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">logout</span>Déconnexion</a>
          </div>
        </div>
      </div>
    </header>
    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">
<?php
}

function pack_header_fin(array $sidebarNav): void
{
?>
    </main>
  </div>
</div>
<script>
(function () {
  var navItems = <?php echo json_encode(array_map(fn ($i) => ['label' => $i['label'], 'url' => $i['url']], array_filter($sidebarNav, fn ($i) => $i['url'] !== null)), JSON_UNESCAPED_UNICODE); ?>;
  var input = document.getElementById('dashSearch');
  var results = document.getElementById('dashSearchResults');
  if (input && results) {
    function render(query) {
      var q = query.trim().toLowerCase();
      var matches = q === '' ? navItems : navItems.filter(function (i) { return i.label.toLowerCase().indexOf(q) !== -1; });
      results.innerHTML = '';
      if (!matches.length) {
        results.classList.remove('is-open');
        return;
      }
      matches.forEach(function (i) {
        var a = document.createElement('a');
        a.href = i.url;
        a.className = 'px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors';
        a.textContent = i.label;
        results.appendChild(a);
      });
      results.classList.add('is-open');
    }
    input.addEventListener('focus', function () { render(input.value); });
    input.addEventListener('input', function () { render(input.value); });
    document.addEventListener('click', function (e) {
      if (!document.getElementById('dashSearchWrapper').contains(e.target)) { results.classList.remove('is-open'); }
    });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); input.focus(); input.select(); }
      if (e.key === 'Escape') { results.classList.remove('is-open'); input.blur(); }
    });
  }

  var avatarBtn = document.getElementById('avatarMenuBtn');
  var avatarMenu = document.getElementById('avatarMenu');
  if (avatarBtn && avatarMenu) {
    avatarBtn.addEventListener('click', function () { avatarMenu.classList.toggle('is-open'); });
    document.addEventListener('click', function (e) {
      if (!document.getElementById('avatarMenuWrapper').contains(e.target)) { avatarMenu.classList.remove('is-open'); }
    });
  }
})();
</script>
<?php
render_footer();
}
