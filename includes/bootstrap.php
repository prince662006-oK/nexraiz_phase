<?php
/**
 * NexRaiz — Pack Assistance : composants partagés du parcours client (8 étapes).
 * Centralise le <head>, l'en-tête de navigation, le hero d'étape, le stepper
 * horizontal et le pied de page pour que toutes les pages du parcours
 * restent cohérentes visuellement.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/livrables.php';
require_once __DIR__ . '/rendezvous.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/extraction.php';
require_once __DIR__ . '/pdf-cv.php';
nx_enforce_https(); // No-op tant que NEXRAIZ_FORCE_HTTPS n'est pas défini (voir includes/auth.php).
nx_session_start(); // Doit démarrer avant toute sortie HTML (render_head() écrit immédiatement).

const PACK_JOURNEYS = [
    'assistance' => [
        1 => ['label' => 'Découverte du Pack Assistance', 'url' => 'decouverte.php'],
        2 => ['label' => "Création de l'espace client", 'url' => 'espace-client.php'],
        3 => ['label' => 'Qualification du besoin', 'url' => 'qualification-besoin.php'],
        4 => ['label' => 'Prise de rendez-vous', 'url' => 'rendez-vous.php'],
        5 => ['label' => 'Consentement et confidentialité', 'url' => 'consentement.php'],
        6 => ['label' => 'Paiement', 'url' => 'paiement.php'],
        7 => ['label' => 'Confirmation', 'url' => 'confirmation.php'],
        8 => ['label' => 'Tableau de bord client', 'url' => 'tableau-bord.php'],
    ],
    'booster' => [
        1 => ['label' => 'Découverte du Pack Booster', 'url' => 'booster-decouverte.php'],
        2 => ['label' => "Création de l'espace client", 'url' => 'booster-espace-client.php'],
        3 => ['label' => 'Qualification du projet', 'url' => 'booster-qualification-projet.php'],
        4 => ['label' => 'Prise de rendez-vous', 'url' => 'booster-rendez-vous.php'],
        5 => ['label' => 'Consentement et cadre', 'url' => 'booster-consentement.php'],
        6 => ['label' => 'Paiement', 'url' => 'booster-paiement.php'],
        7 => ['label' => 'Confirmation', 'url' => 'booster-confirmation.php'],
        8 => ['label' => 'Tableau de bord client', 'url' => 'booster-tableau-bord.php'],
    ],
    'classic' => [
        1 => ['label' => 'Découverte du Pack Classic', 'url' => 'classic-decouverte.php'],
        2 => ['label' => "Création de l'espace candidat", 'url' => 'classic-espace-candidat.php'],
        3 => ['label' => 'Qualification du besoin', 'url' => 'classic-qualification-besoin.php'],
        4 => ['label' => 'Dépôt des documents', 'url' => 'classic-depot-documents.php'],
        5 => ['label' => 'Prise de rendez-vous', 'url' => 'classic-rendez-vous.php'],
        6 => ['label' => 'Consentement et confidentialité', 'url' => 'classic-consentement.php'],
        7 => ['label' => 'Paiement', 'url' => 'classic-paiement.php'],
        8 => ['label' => 'Tableau de bord candidat', 'url' => 'classic-tableau-bord.php'],
    ],
    'wellbeing' => [
        1 => ['label' => 'Découverte du Pack Wellbeing', 'url' => 'wellbeing-decouverte.php'],
        2 => ['label' => "Création de l'espace client", 'url' => 'wellbeing-espace-client.php'],
        3 => ['label' => 'Formulaire de situation', 'url' => 'wellbeing-formulaire-situation.php'],
        4 => ['label' => 'Prise de rendez-vous', 'url' => 'wellbeing-rendez-vous.php'],
        5 => ['label' => 'Consentement et cadre', 'url' => 'wellbeing-consentement.php'],
        6 => ['label' => 'Paiement', 'url' => 'wellbeing-paiement.php'],
        7 => ['label' => 'Confirmation', 'url' => 'wellbeing-confirmation.php'],
        8 => ['label' => 'Tableau de bord client', 'url' => 'wellbeing-tableau-bord.php'],
    ],
];

const PACK_LABELS = [
    'assistance' => 'Pack Assistance',
    'booster'    => 'Pack Booster',
    'classic'    => 'Pack Classic',
    'wellbeing'  => 'Pack Wellbeing',
];

/** @return array<int,array{label:string,url:string}> */
function pack_steps(string $packKey): array
{
    return PACK_JOURNEYS[$packKey] ?? PACK_JOURNEYS['assistance'];
}

function pack_label(string $packKey): string
{
    return PACK_LABELS[$packKey] ?? 'Pack';
}

function step_url(int $step, string $packKey = 'assistance'): string
{
    $steps = pack_steps($packKey);
    return $steps[$step]['url'] ?? $steps[1]['url'];
}

function step_label(int $step, string $packKey = 'assistance'): string
{
    return pack_steps($packKey)[$step]['label'] ?? '';
}

function render_head(string $title, string $description = "Le Pack Assistance NexRaiz vous accompagne dans une situation professionnelle sensible."): void
{
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?php echo htmlspecialchars($title); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($description); ?>">
<link rel="icon" href="uploads/LOGO0.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>@layer base{html,body{width:100vw;margin:0;padding:0;}body{overscroll-behavior:none;}.pb-safe{padding-bottom:env(safe-area-inset-bottom,0px);}.pt-safe{padding-top:env(safe-area-inset-top,0px);}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}.no-scrollbar::-webkit-scrollbar{display:none;}
@keyframes stepper-bird-fly{0%,100%{transform:translate(-50%,0) rotate(-8deg);}50%{transform:translate(-50%,-7px) rotate(8deg);}}
.stepper-bird{animation:stepper-bird-fly 1.6s ease-in-out infinite;display:inline-block;}
@media(prefers-reduced-motion:reduce){.stepper-bird{animation:none;}}
.nx-mobile-nav-open{display:flex!important;position:fixed;top:64px;left:0;right:0;flex-direction:column;background:#fff;padding:.5rem;box-shadow:0 10px 25px rgba(11,25,44,.15);border-bottom:1px solid rgba(0,0,0,.08);z-index:40;gap:.25rem;max-height:calc(100vh - 64px);overflow-y:auto;}
.nx-mobile-nav-open a{width:100%;}
.nx-sidebar-open{display:flex!important;position:fixed!important;top:0;left:0;bottom:0;z-index:60;box-shadow:0 0 30px rgba(0,0,0,.3);overflow-y:auto;}
.nx-sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(11,25,44,.5);z-index:59;}
.nx-sidebar-backdrop-visible{display:block;}
</style>
<script src="https://cdn.tailwindcss.com"></script>
<script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "primary-container": "#14304f", "background": "#faf8ff", "outline": "#737686", "inverse-on-surface": "#eef0ff", "on-secondary": "#ffffff", "on-primary-fixed": "#00174b", "surface-container-high": "#e2e7ff", "on-error": "#ffffff", "on-tertiary-container": "#ffede1", "on-secondary-container": "#58657b", "surface-container-low": "#f2f3ff", "inverse-primary": "#b4c5ff", "secondary-fixed": "#d6e3fe", "on-secondary-fixed-variant": "#3a475c", "on-tertiary-fixed-variant": "#6e3900", "tertiary-fixed-dim": "#ffb77d", "on-primary-container": "#eeefff", "error-container": "#ffdad6", "on-primary": "#ffffff", "on-secondary-fixed": "#0e1c2f", "surface-tint": "#1E3E62", "secondary-fixed-dim": "#bac7e1", "tertiary-container": "#a65900", "tertiary-fixed": "#ffdcc3", "on-background": "#131b2e", "on-tertiary": "#ffffff", "surface-bright": "#faf8ff", "on-error-container": "#93000a", "surface-container": "#eaedff", "primary-fixed-dim": "#b4c5ff", "outline-variant": "#c3c6d7", "primary": "#1E3E62", "surface-container-highest": "#dae2fd", "surface-dim": "#d2d9f4", "tertiary": "#824500", "error": "#ba1a1a", "on-primary-fixed-variant": "#003ea8", "surface-variant": "#dae2fd", "surface-container-lowest": "#ffffff", "surface": "#faf8ff", "secondary": "#525f75", "on-tertiary-fixed": "#2f1500", "primary-fixed": "#dbe1ff", "secondary-container": "#d6e3fe", "on-surface-variant": "#434655", "on-surface": "#131b2e", "inverse-surface": "#0B192C" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-2xs": "0.25rem", "space-3xl": "4.5rem", "max-width-content": "80rem", "space-sm": "0.75rem", "space-xl": "2rem", "gutter-mobile": "1rem", "space-xs": "0.5rem", "space-4xl": "6rem", "space-2xl": "3rem", "space-md": "1rem", "max-width-reading": "45rem", "gutter-desktop": "1.5rem", "space-lg": "1.5rem" }, "fontFamily": { "headline-xl": ["Plus Jakarta Sans"], "headline-md": ["Plus Jakarta Sans"], "headline-lg": ["Plus Jakarta Sans"], "headline-sm": ["Plus Jakarta Sans"], "label-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "label-md": ["Plus Jakarta Sans"], "body-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Plus Jakarta Sans"], "display-lg-mobile": ["Plus Jakarta Sans"], "body-lg": ["Plus Jakarta Sans"], "display-lg": ["Plus Jakarta Sans"], "label-badge": ["Plus Jakarta Sans"] }, "fontSize": { "headline-xl": ["2.25rem", { "lineHeight": "2.75rem", "letterSpacing": "-0.02em", "fontWeight": "700" }], "headline-md": ["1.375rem", { "lineHeight": "1.875rem", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg": ["1.75rem", { "lineHeight": "2.25rem", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-sm": ["1.125rem", { "lineHeight": "1.625rem", "letterSpacing": "-0.005em", "fontWeight": "600" }], "label-lg": ["0.875rem", { "lineHeight": "1.25rem", "letterSpacing": "0.01em", "fontWeight": "600" }], "body-md": ["1rem", { "lineHeight": "1.5rem", "letterSpacing": "0em", "fontWeight": "400" }], "label-md": ["0.75rem", { "lineHeight": "1rem", "letterSpacing": "0.02em", "fontWeight": "600" }], "body-sm": ["0.875rem", { "lineHeight": "1.375rem", "letterSpacing": "0.005em", "fontWeight": "400" }], "headline-xl-mobile": ["1.75rem", { "lineHeight": "2.25rem", "letterSpacing": "-0.015em", "fontWeight": "700" }], "display-lg-mobile": ["2.25rem", { "lineHeight": "2.75rem", "letterSpacing": "-0.025em", "fontWeight": "800" }], "body-lg": ["1.125rem", { "lineHeight": "1.75rem", "letterSpacing": "0em", "fontWeight": "400" }], "display-lg": ["3.5rem", { "lineHeight": "4rem", "letterSpacing": "-0.03em", "fontWeight": "800" }], "label-badge": ["0.6875rem", { "lineHeight": "0.875rem", "letterSpacing": "0.04em", "fontWeight": "700" }] } } } }</script>
</head>
<body class="bg-surface font-body-md text-body-md text-on-surface flex flex-col min-h-screen">
<script>
// Ajoute un bouton burger aux en-têtes d'espace (consultant/admin/client) dont
// la nav d'onglets est "hidden md:flex" sans aucun équivalent mobile — ces
// pages ont chacune leur propre en-tête HTML (pas render_top_header(), qui a
// déjà son propre menu mobile #mobileMenuToggle), donc on le fait une seule
// fois ici plutôt que de dupliquer la même logique dans ~20 fichiers.
document.addEventListener('DOMContentLoaded', function() {
  if (document.getElementById('mobileMenuToggle')) return;
  var header = document.querySelector('header');
  if (!header) return;

  var nav = header.querySelector('nav.hidden');
  if (nav) {
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Ouvrir le menu');
  btn.setAttribute('aria-expanded', 'false');
  btn.className = 'md:hidden w-10 h-10 flex items-center justify-center text-on-surface hover:text-primary transition-colors shrink-0';
  btn.innerHTML = '<span class="material-symbols-outlined text-[24px]">menu</span>';
  nav.parentElement.insertBefore(btn, nav.nextSibling);

  function fermer() {
    nav.classList.remove('nx-mobile-nav-open');
    btn.setAttribute('aria-expanded', 'false');
    btn.querySelector('span').textContent = 'menu';
  }
  btn.addEventListener('click', function() {
    var ouvert = nav.classList.toggle('nx-mobile-nav-open');
    btn.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
    btn.querySelector('span').textContent = ouvert ? 'close' : 'menu';
  });
  nav.querySelectorAll('a').forEach(function(a) { a.addEventListener('click', fermer); });
  document.addEventListener('click', function(e) {
    if (nav.classList.contains('nx-mobile-nav-open') && !nav.contains(e.target) && !btn.contains(e.target)) {
      fermer();
    }
  });
  }

  // Tableaux de bord client (Classic/Assistance/Booster/Wellbeing) : la
  // navigation vit dans une sidebar "hidden lg:flex" plutôt qu'un nav
  // d'en-tête — même principe, bouton burger + tiroir recouvrant au toucher.
  var aside = document.querySelector('aside.hidden');
  if (aside) {
    var headerRow = header.querySelector('div') || header;
    var asideBtn = document.createElement('button');
    asideBtn.type = 'button';
    asideBtn.setAttribute('aria-label', 'Ouvrir le menu');
    asideBtn.setAttribute('aria-expanded', 'false');
    asideBtn.className = 'lg:hidden w-10 h-10 flex items-center justify-center text-on-surface hover:text-primary transition-colors shrink-0';
    asideBtn.innerHTML = '<span class="material-symbols-outlined text-[24px]">menu</span>';
    headerRow.insertBefore(asideBtn, headerRow.firstChild);

    var backdrop = document.createElement('div');
    backdrop.className = 'nx-sidebar-backdrop';
    document.body.appendChild(backdrop);

    function fermerAside() {
      aside.classList.remove('nx-sidebar-open');
      backdrop.classList.remove('nx-sidebar-backdrop-visible');
      asideBtn.setAttribute('aria-expanded', 'false');
    }
    asideBtn.addEventListener('click', function() {
      var ouvert = aside.classList.toggle('nx-sidebar-open');
      backdrop.classList.toggle('nx-sidebar-backdrop-visible', ouvert);
      asideBtn.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
    });
    backdrop.addEventListener('click', fermerAside);
    aside.querySelectorAll('a').forEach(function(a) { a.addEventListener('click', fermerAside); });
  }
});
</script>
<?php
}

/**
 * Cloche de notifications affichée dans l'en-tête pour tout utilisateur
 * connecté (client, consultant, admin) : badge du nombre de notifications
 * non lues + menu déroulant listant les plus récentes.
 */
function render_notification_bell(int $userId): void
{
    $notifications = notifications_recentes($userId, 8);
    $nbNonLues = notifications_non_lues($userId);
?>
<div class="relative" id="notifBellWrapper">
  <button type="button" id="notifBellToggle" aria-label="Notifications" class="relative w-9 h-9 rounded-full flex items-center justify-center text-on-surface hover:bg-surface-container-low transition-colors">
    <span class="material-symbols-outlined text-[22px]">notifications</span>
    <?php if ($nbNonLues > 0): ?>
    <span class="absolute top-0.5 right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $nbNonLues > 9 ? '9+' : $nbNonLues; ?></span>
    <?php endif; ?>
  </button>
  <div id="notifBellPanel" class="hidden absolute right-0 top-full mt-2 w-80 max-w-[90vw] bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 flex flex-col overflow-hidden z-50">
    <div class="px-space-sm py-space-xs border-b border-outline-variant/40 flex items-center justify-between">
      <span class="font-label-lg text-label-lg text-on-surface font-bold">Notifications</span>
      <?php if ($nbNonLues > 0): ?>
      <a href="notifications.php?marquer_lu=1" class="font-label-md text-[11px] text-primary hover:underline">Tout marquer comme lu</a>
      <?php endif; ?>
    </div>
    <div class="max-h-80 overflow-y-auto">
      <?php if (!$notifications): ?>
      <p class="px-space-sm py-space-md font-body-sm text-body-sm text-secondary text-center">Aucune notification pour le moment.</p>
      <?php else: ?>
      <?php foreach ($notifications as $n): ?>
      <a href="<?php echo htmlspecialchars($n['lien'] ?: 'notifications.php'); ?>" class="flex flex-col gap-0.5 px-space-sm py-space-sm border-b border-outline-variant/20 last:border-0 hover:bg-surface-container-low transition-colors <?php echo $n['lu'] ? '' : 'bg-primary-container/5'; ?>">
        <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($n['titre']); ?></span>
        <?php if ($n['corps']): ?><span class="font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($n['corps']); ?></span><?php endif; ?>
        <span class="font-body-sm text-[11px] text-outline"><?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($n['created_at']))); ?></span>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <a href="notifications.php" class="px-space-sm py-space-xs text-center font-label-md text-label-md text-primary border-t border-outline-variant/40 hover:bg-surface-container-low transition-colors">Voir toutes les notifications</a>
  </div>
</div>
<script>
(function() {
  var toggle = document.getElementById('notifBellToggle');
  var panel = document.getElementById('notifBellPanel');
  var wrapper = document.getElementById('notifBellWrapper');
  if (!toggle || !panel || !wrapper) return;
  toggle.addEventListener('click', function(e) {
    e.stopPropagation();
    panel.classList.toggle('hidden');
  });
  document.addEventListener('click', function(e) {
    if (!wrapper.contains(e.target)) panel.classList.add('hidden');
  });
})();
</script>
<?php
}

function render_top_header(string $active = 'packs'): void
{
    // Le bouton "Commencer" doit démarrer le pack que le visiteur est en
    // train de consulter, pas toujours le Pack Assistance : on déduit le
    // pack courant depuis le fichier affiché (il correspond toujours à une
    // étape de PACK_JOURNEYS sur les pages de parcours), et on ne retombe
    // sur la découverte du Pack Assistance que si la page actuelle n'est
    // rattachée à aucun pack (accueil, contact, mon espace...).
    $fichierCourant = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $packCourant = null;
    foreach (PACK_JOURNEYS as $slug => $etapes) {
        foreach ($etapes as $etape) {
            if ($etape['url'] === $fichierCourant) {
                $packCourant = $slug;
                break 2;
            }
        }
    }
    $urlCommencer = $packCourant ? step_url(1, $packCourant) : 'decouverte.php';

    $navItems = [
        'accueil' => ['label' => 'Accueil', 'url' => 'index.php'],
        'packs'   => ['label' => 'Nos Packs', 'url' => 'index.php#accompagnements'],
        'marche'  => ['label' => 'Comment ça marche', 'url' => 'comment-ca-marche.php'],
        'espace'  => ['label' => 'Mon espace', 'url' => 'mon-espace.php'],
        'contact' => ['label' => 'Contact', 'url' => 'contact.php'],
    ];
?>
<header class="fixed top-0 w-full z-50 pt-safe bg-surface-container-lowest/95 backdrop-blur-xl border-b border-outline-variant/50">
  <div class="absolute top-0 left-0 h-[3px] w-full bg-gradient-to-r from-[#0B192C] to-[#1E3E62]"></div>
  <div class="h-16 px-gutter-mobile lg:px-gutter-desktop max-w-max-width-content mx-auto flex items-center justify-between">
    <div class="flex items-center gap-space-xs">
      <button aria-controls="mobileMenu" aria-expanded="false" aria-label="Ouvrir le menu" class="md:hidden w-11 h-11 flex items-center justify-center text-on-surface hover:text-primary transition-colors" id="mobileMenuToggle">
        <span class="material-symbols-outlined text-[24px]" id="mobileMenuIcon">menu</span>
      </button>
      <a href="index.php" class="flex items-center gap-1.5">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-11 w-auto">
      </a>
    </div>

    <nav class="hidden md:flex items-center gap-space-lg">
      <?php foreach ($navItems as $key => $item): ?>
        <?php if ($key === 'packs'): ?>
        <div class="relative group">
          <a class="flex items-center gap-0.5 font-label-lg text-label-lg pb-1 transition-colors <?php echo $key === $active ? 'text-primary font-bold border-b-2 border-primary' : 'text-secondary hover:text-on-surface border-b-2 border-transparent'; ?>" href="<?php echo $item['url']; ?>">
            <span><?php echo htmlspecialchars($item['label']); ?></span>
            <span class="material-symbols-outlined text-[18px] transition-transform group-hover:rotate-180">expand_more</span>
          </a>
          <div class="absolute left-0 top-full pt-2 hidden group-hover:block">
            <div class="w-56 bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/40 p-space-2xs flex flex-col">
              <?php foreach (PACK_LABELS as $slug => $label): ?>
              <a class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors" href="<?php echo htmlspecialchars(step_url(1, $slug)); ?>"><?php echo htmlspecialchars($label); ?></a>
              <?php endforeach; ?>
              <a class="px-space-sm py-space-xs rounded-lg font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors" href="pack-detail.php?pack=particulier">Pack Particulier</a>
            </div>
          </div>
        </div>
        <?php else: ?>
      <a class="font-label-lg text-label-lg pb-1 transition-colors <?php echo $key === $active ? 'text-primary font-bold border-b-2 border-primary' : 'text-secondary hover:text-on-surface border-b-2 border-transparent'; ?>" href="<?php echo $item['url']; ?>"><?php echo htmlspecialchars($item['label']); ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="flex items-center gap-space-sm">
      <?php $utilisateurConnecte = current_user(); ?>
      <?php if ($utilisateurConnecte): ?>
      <?php render_notification_bell((int) $utilisateurConnecte['id']); ?>
      <a href="mon-espace.php" class="hidden sm:flex items-center gap-1 font-label-md text-label-md text-on-surface hover:text-primary transition-colors">
        <span class="w-7 h-7 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-label-md text-[11px] font-bold"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($utilisateurConnecte['prenom'], 0, 1))); ?></span>
        <span><?php echo htmlspecialchars($utilisateurConnecte['prenom']); ?></span>
      </a>
      <a class="h-9 px-space-md rounded-xl border border-outline-variant text-on-surface flex items-center justify-center font-label-md text-label-md hover:bg-surface-container-low transition-colors" href="logout.php">Déconnexion</a>
      <?php else: ?>
      <a class="h-9 px-space-md rounded-xl bg-[#2563EB] text-white flex items-center justify-center font-label-md text-label-md shadow-sm hover:opacity-90 transition-opacity gap-1" href="<?php echo htmlspecialchars($urlCommencer); ?>">
        <span>Commencer</span>
        <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="md:hidden hidden flex-col gap-space-2xs px-gutter-mobile pb-space-sm border-t border-outline-variant/50" id="mobileMenu">
    <?php foreach ($navItems as $key => $item): ?>
    <a class="h-11 flex items-center font-label-lg text-label-lg transition-colors <?php echo $key === $active ? 'text-primary font-bold' : 'text-secondary hover:text-on-surface'; ?>" href="<?php echo $item['url']; ?>"><?php echo htmlspecialchars($item['label']); ?></a>
    <?php endforeach; ?>
  </div>
</header>
<script>
(function() {
  const menuToggle = document.getElementById('mobileMenuToggle');
  const menuIcon = document.getElementById('mobileMenuIcon');
  const mobileMenu = document.getElementById('mobileMenu');
  if (menuToggle && mobileMenu && menuIcon) {
    menuToggle.addEventListener('click', () => {
      const isOpen = mobileMenu.classList.toggle('flex');
      mobileMenu.classList.toggle('hidden', !isOpen);
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      menuIcon.textContent = isOpen ? 'close' : 'menu';
    });
    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        mobileMenu.classList.add('hidden');
        mobileMenu.classList.remove('flex');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuIcon.textContent = 'menu';
      });
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth >= 768) {
        mobileMenu.classList.add('hidden');
        mobileMenu.classList.remove('flex');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuIcon.textContent = 'menu';
      }
    });
  }
})();
</script>
<?php
}

/**
 * @param array<int,string> $floatingIcons Jusqu'à 3 icônes flottantes autour du visuel (vide = pas de visuel).
 * @param array<int,array{label:string,url?:string}> $breadcrumbExtra Fils d'ariane additionnels après "Parcours client".
 */
function render_hero(
    string $packKey,
    int $step,
    string $title,
    string $subtitle,
    string $icon = 'support_agent',
    array $floatingIcons = ['verified', 'person', 'description'],
    array $breadcrumbExtra = []
): void {
    $totalSteps = count(pack_steps($packKey));
    $packLabel = pack_label($packKey);
    $packHomeUrl = step_url(1, $packKey);
?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop pt-space-md">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-md">

    <nav aria-label="Fil d'ariane" class="flex items-center gap-space-2xs font-body-sm text-body-sm text-secondary flex-wrap">
      <a href="index.php" class="flex items-center text-secondary hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-[18px]">home</span>
      </a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <a href="<?php echo htmlspecialchars($packHomeUrl); ?>" class="hover:text-primary transition-colors"><?php echo htmlspecialchars($packLabel); ?></a>
      <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
      <?php if (empty($breadcrumbExtra)): ?>
        <span class="text-on-surface font-semibold">Parcours client</span>
      <?php else: ?>
        <span class="hover:text-primary transition-colors">Parcours client</span>
        <?php foreach ($breadcrumbExtra as $crumb): ?>
          <span class="material-symbols-outlined text-[16px] text-outline-variant">chevron_right</span>
          <span class="text-on-surface font-semibold"><?php echo htmlspecialchars($crumb['label']); ?></span>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>

    <div class="grid grid-cols-1 <?php echo $floatingIcons ? 'lg:grid-cols-[1.4fr_1fr]' : ''; ?> gap-space-lg items-center">
      <div class="flex flex-col gap-space-xs">
        <span class="inline-flex self-start items-center px-space-sm py-1.5 rounded-full bg-primary-container text-white font-label-md text-label-md font-bold">
          Étape <?php echo $step; ?> sur <?php echo $totalSteps; ?>
        </span>
        <h1 class="font-headline-xl-mobile text-headline-xl-mobile md:font-headline-xl md:text-headline-xl text-on-surface font-bold tracking-tight"><?php echo $title; ?></h1>
        <p class="font-headline-sm text-headline-sm text-primary font-semibold"><?php echo $subtitle; ?></p>
      </div>

      <?php if ($floatingIcons): ?>
      <div class="relative w-full h-48 md:h-56 mx-auto max-w-sm lg:max-w-none">
        <div class="absolute inset-0 rounded-3xl bg-gradient-to-br from-primary-container/15 via-surface-container-low to-tertiary-fixed/10"></div>
        <div class="absolute inset-0 flex items-center justify-center">
          <div class="w-24 h-24 md:w-28 md:h-28 rounded-full bg-surface-container-lowest shadow-lg flex items-center justify-center text-primary">
            <span class="material-symbols-outlined text-[52px]"><?php echo htmlspecialchars($icon); ?></span>
          </div>
        </div>
        <?php
        $positions = ['top-4 right-6', 'bottom-6 left-8', 'top-10 left-4'];
        foreach (array_slice($floatingIcons, 0, 3) as $i => $fIcon):
        ?>
        <div class="absolute <?php echo $positions[$i] ?? 'top-4 right-6'; ?> w-11 h-11 rounded-full bg-surface-container-lowest shadow-md flex items-center justify-center text-primary-container">
          <span class="material-symbols-outlined text-[22px]"><?php echo htmlspecialchars($fIcon); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
}

function render_stepper(string $packKey, int $current): void
{
    $steps = pack_steps($packKey);
?>
<section class="w-full px-gutter-mobile lg:px-gutter-desktop py-space-md">
  <div class="max-w-max-width-content mx-auto">
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md pt-12 overflow-x-auto no-scrollbar">
      <div class="flex items-start gap-0 min-w-[860px] md:min-w-0">
        <?php $i = 0; $total = count($steps); foreach ($steps as $n => $step): $i++; ?>
        <div class="flex flex-col items-center flex-1 gap-space-2xs relative">
          <?php if ($i > 1): ?>
          <div class="hidden md:block absolute top-4 right-1/2 w-full h-0 border-t-2 border-dashed <?php echo $n <= $current ? 'border-primary-container/60' : 'border-outline-variant'; ?>" style="left:-50%"></div>
          <?php endif; ?>
          <?php if ($n === $current): ?>
          <span class="stepper-bird absolute -top-8 left-1/2 text-[28px] select-none pointer-events-none" style="transform:translate(-50%,0)" aria-hidden="true">🐦</span>
          <?php endif; ?>
          <div class="relative z-10 w-9 h-9 rounded-full flex items-center justify-center font-label-lg text-label-lg font-bold
            <?php
            if ($n < $current) echo 'bg-primary-container text-white';
            elseif ($n === $current) echo 'bg-primary-container text-white ring-4 ring-primary-container/20';
            else echo 'bg-surface-container text-secondary';
            ?>">
            <?php if ($n < $current): ?>
              <span class="material-symbols-outlined text-[18px]">check</span>
            <?php else: ?>
              <?php echo $n; ?>
            <?php endif; ?>
          </div>
          <span class="font-label-md text-label-md text-center leading-tight max-w-[7.5rem] <?php echo $n === $current ? 'text-on-surface font-bold' : 'text-secondary'; ?>"><?php echo htmlspecialchars($step['label']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php
}

/**
 * Bulle de chat flottante : chatbot d'orientation (choix du pack) affiché
 * sur toutes les pages publiques. CSS auto-contenu (pas de dépendance à
 * Tailwind) pour pouvoir être injecté aussi bien sur les pages du design
 * system que sur index.php, qui a sa propre feuille de style.
 */
function render_chatbot_widget(): void
{
    $jetonCsrf = json_encode(csrf_token());
?>
<style>
#nx-chatbot-launcher{position:fixed;bottom:20px;right:20px;width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#1E3E62,#2563EB);box-shadow:0 6px 20px rgba(30,62,98,.35);border:none;cursor:pointer;z-index:9998;display:flex;align-items:center;justify-content:center;transition:transform .15s ease;}
#nx-chatbot-launcher:hover{transform:scale(1.06);}
#nx-chatbot-launcher svg{width:26px;height:26px;}
#nx-chatbot-panel{position:fixed;bottom:88px;right:20px;width:350px;max-width:calc(100vw - 24px);height:min(500px,70vh);background:#fff;border-radius:18px;box-shadow:0 12px 40px rgba(11,25,44,.25);display:none;flex-direction:column;overflow:hidden;z-index:9999;font-family:'Plus Jakarta Sans',Poppins,Arial,sans-serif;}
#nx-chatbot-panel.is-open{display:flex;}
#nx-chatbot-head{background:linear-gradient(135deg,#1E3E62,#2563EB);color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
#nx-chatbot-head .nx-title{font-weight:700;font-size:14px;}
#nx-chatbot-head .nx-sub{font-size:11px;opacity:.8;display:flex;align-items:center;gap:5px;}
#nx-chatbot-head .nx-sub::before{content:"";width:7px;height:7px;border-radius:50%;background:#4ADE80;display:inline-block;}
#nx-chatbot-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:15px;line-height:1;display:flex;align-items:center;justify-content:center;}
#nx-chatbot-messages{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#F7F8FC;}
.nx-msg{max-width:82%;padding:9px 12px;border-radius:14px;font-size:13px;line-height:1.45;white-space:pre-wrap;}
.nx-msg.bot{align-self:flex-start;background:#fff;color:#131b2e;border:1px solid #E4E7F2;border-bottom-left-radius:4px;}
.nx-msg.user{align-self:flex-end;background:#2563EB;color:#fff;border-bottom-right-radius:4px;}
.nx-msg.err{align-self:center;background:#FFDAD6;color:#8c1d18;font-size:12px;}
#nx-chatbot-typing{align-self:flex-start;display:none;padding:9px 13px;background:#fff;border:1px solid #E4E7F2;border-radius:14px;border-bottom-left-radius:4px;}
#nx-chatbot-typing span{display:inline-block;width:6px;height:6px;margin-right:3px;border-radius:50%;background:#9aa3b5;animation:nx-bounce 1s infinite ease-in-out;}
#nx-chatbot-typing span:nth-child(2){animation-delay:.15s;}
#nx-chatbot-typing span:nth-child(3){animation-delay:.3s;margin-right:0;}
@keyframes nx-bounce{0%,80%,100%{transform:translateY(0);opacity:.5;}40%{transform:translateY(-4px);opacity:1;}}
#nx-chatbot-form{flex-shrink:0;display:flex;gap:8px;padding:10px;border-top:1px solid #E4E7F2;background:#fff;}
#nx-chatbot-input{flex:1;border:1px solid #D8DCE8;border-radius:12px;padding:9px 12px;font-size:13px;font-family:inherit;resize:none;outline:none;}
#nx-chatbot-input:focus{border-color:#2563EB;}
#nx-chatbot-send{background:#1E3E62;color:#fff;border:none;border-radius:12px;width:38px;flex-shrink:0;cursor:pointer;display:flex;align-items:center;justify-content:center;}
#nx-chatbot-send:disabled{opacity:.5;cursor:default;}
@media(max-width:480px){#nx-chatbot-panel{right:12px;left:12px;width:auto;bottom:84px;}#nx-chatbot-launcher{right:16px;bottom:16px;}}
</style>
<button id="nx-chatbot-launcher" type="button" aria-label="Ouvrir l'assistant NexRaiz">
  <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
</button>
<div id="nx-chatbot-panel" role="dialog" aria-label="Assistant NexRaiz">
  <div id="nx-chatbot-head">
    <div><div class="nx-title">Assistant NexRaiz</div><div class="nx-sub">En ligne</div></div>
    <button id="nx-chatbot-close" type="button" aria-label="Fermer">✕</button>
  </div>
  <div id="nx-chatbot-messages">
    <div class="nx-msg bot">Bonjour 👋 Je peux vous aider à trouver le pack NexRaiz le plus adapté à votre situation. Dites-moi un peu plus sur ce qui vous amène aujourd'hui.</div>
    <div id="nx-chatbot-typing"><span></span><span></span><span></span></div>
  </div>
  <form id="nx-chatbot-form">
    <textarea id="nx-chatbot-input" rows="1" maxlength="500" placeholder="Écrivez votre message..." aria-label="Votre message"></textarea>
    <button id="nx-chatbot-send" type="submit" aria-label="Envoyer"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg></button>
  </form>
</div>
<script>
(function() {
  var csrf = <?php echo $jetonCsrf; ?>;
  var launcher = document.getElementById('nx-chatbot-launcher');
  var panel = document.getElementById('nx-chatbot-panel');
  var closeBtn = document.getElementById('nx-chatbot-close');
  var messages = document.getElementById('nx-chatbot-messages');
  var typing = document.getElementById('nx-chatbot-typing');
  var form = document.getElementById('nx-chatbot-form');
  var input = document.getElementById('nx-chatbot-input');
  var sendBtn = document.getElementById('nx-chatbot-send');
  var historique = [];
  var enCours = false;

  function toggle(ouvrir) {
    panel.classList.toggle('is-open', ouvrir);
    if (ouvrir) {
      messages.scrollTop = messages.scrollHeight;
      // Le focus programmatique ouvre aussitôt le clavier virtuel sur
      // mobile, ce qui décale brutalement la mise en page (le panneau est
      // en position fixed près du bas de l'écran) : on ne force le focus
      // qu'avec un pointeur fin (souris/trackpad), jamais au toucher.
      if (window.matchMedia('(pointer: fine)').matches) {
        input.focus();
      }
    }
  }
  launcher.addEventListener('click', function() { toggle(!panel.classList.contains('is-open')); });
  closeBtn.addEventListener('click', function() { toggle(false); });

  function ajouterMessage(texte, role) {
    var div = document.createElement('div');
    div.className = 'nx-msg ' + role;
    div.textContent = texte;
    messages.insertBefore(div, typing);
    messages.scrollTop = messages.scrollHeight;
  }

  input.addEventListener('input', function() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 90) + 'px';
  });
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var texte = input.value.trim();
    if (!texte || enCours) return;
    ajouterMessage(texte, 'user');
    historique.push({ role: 'user', content: texte });
    input.value = '';
    input.style.height = 'auto';
    enCours = true;
    sendBtn.disabled = true;
    typing.style.display = 'block';
    messages.scrollTop = messages.scrollHeight;

    fetch('chatbot-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: texte, historique: historique, _csrf: csrf })
    }).then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
      .then(function(res) {
        typing.style.display = 'none';
        if (res.ok && res.data.reponse) {
          ajouterMessage(res.data.reponse, 'bot');
          historique.push({ role: 'assistant', content: res.data.reponse });
        } else {
          ajouterMessage(res.data.error || "Désolé, une erreur est survenue. Réessayez dans un instant.", 'err');
        }
      })
      .catch(function() {
        typing.style.display = 'none';
        ajouterMessage("Impossible de contacter l'assistant pour le moment. Vérifiez votre connexion et réessayez.", 'err');
      })
      .finally(function() { enCours = false; sendBtn.disabled = false; });
  });
})();
</script>
<?php
}

function render_footer(): void
{
?>
<footer class="w-full bg-gradient-to-br from-[#0B192C] via-[#14304f] to-[#2563EB] text-inverse-on-surface mt-space-3xl px-gutter-mobile lg:px-gutter-desktop py-space-2xl">
  <div class="max-w-max-width-content mx-auto flex flex-col gap-space-xl">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-space-lg">
      <div class="col-span-2 md:col-span-1 flex flex-col gap-space-xs">
        <span class="flex items-center gap-1.5">
          <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
        </span>
        <p class="font-body-sm text-body-sm text-surface-container-high/80 leading-relaxed">NexRaiz accompagne les professionnels à chaque étape de leur évolution pour développer leur potentiel et construire un avenir qui leur ressemble.</p>
      </div>
      <div class="flex flex-col gap-space-xs">
        <h4 class="font-label-md text-label-md uppercase tracking-wider text-inverse-primary font-bold">Navigation</h4>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="index.php">Accueil</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="index.php#accompagnements">Nos Packs</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="comment-ca-marche.php">Comment ça marche</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="mon-espace.php">Mon espace</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="contact.php">Contact</a>
      </div>
      <div class="flex flex-col gap-space-xs">
        <h4 class="font-label-md text-label-md uppercase tracking-wider text-inverse-primary font-bold">Nos Packs</h4>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="decouverte.php">Pack Assistance</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="booster-decouverte.php">Pack Booster</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="classic-decouverte.php">Pack Classic</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="wellbeing-decouverte.php">Pack Wellbeing</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="pack-detail.php?pack=particulier">Pack Particulier</a>
      </div>
      <div class="flex flex-col gap-space-xs">
        <h4 class="font-label-md text-label-md uppercase tracking-wider text-inverse-primary font-bold">Ressources</h4>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Blog</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Guides &amp; conseils</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">FAQ</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Webinaires</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Témoignages</a>
      </div>
      <div class="flex flex-col gap-space-xs">
        <h4 class="font-label-md text-label-md uppercase tracking-wider text-inverse-primary font-bold">Informations</h4>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Mentions légales</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Politique de confidentialité</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">CGV</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Cookies</a>
        <a class="font-body-sm text-body-sm text-surface-container-high hover:text-white transition-colors" href="#">Plan du site</a>
      </div>
    </div>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-space-md pt-space-md border-t border-white/10">
      <div class="flex items-center gap-space-sm">
        <a aria-label="LinkedIn" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-inverse-on-surface hover:bg-white/20 transition-colors" href="#"><span class="material-symbols-outlined text-[18px]">share</span></a>
        <a aria-label="Facebook" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-inverse-on-surface hover:bg-white/20 transition-colors" href="#"><span class="material-symbols-outlined text-[18px]">public</span></a>
        <a aria-label="Instagram" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-inverse-on-surface hover:bg-white/20 transition-colors" href="#"><span class="material-symbols-outlined text-[18px]">photo_camera</span></a>
        <a aria-label="YouTube" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-inverse-on-surface hover:bg-white/20 transition-colors" href="#"><span class="material-symbols-outlined text-[18px]">smart_display</span></a>
      </div>
      <p class="font-body-sm text-body-sm text-surface-container-high/60">© <?php echo date('Y'); ?> NexRaiz – Tous droits réservés</p>
    </div>
  </div>
</footer>
</main>
<?php render_chatbot_widget(); ?>
</body>
</html>
<?php
}
