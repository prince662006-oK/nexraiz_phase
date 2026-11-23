<?php
// Ancienne maquette (données factices) conservée pour référence visuelle ;
// admin-dashboard.php est la version réelle branchée sur la base de
// données et c'est elle qui est utilisée en production (voir
// redirect_to_dashboard() dans includes/auth.php). Ce fichier n'est plus
// lié depuis aucune page, mais reste accessible par URL directe : on lui
// ajoute donc le même contrôle d'accès que les autres pages admin.
require __DIR__ . '/includes/bootstrap.php';

require_role(['super_admin'], 'mon-espace.php');

$kpis = [
    ['icone' => 'diversity_3', 'valeur' => '1 248', 'label' => 'Clients actifs', 'delta' => '+86 ce mois-ci', 'delta_class' => 'text-green-700'],
    ['icone' => 'folder_open', 'valeur' => '342', 'label' => 'Dossiers en cours', 'delta' => 'Tous packs confondus', 'delta_class' => 'text-secondary'],
    ['icone' => 'badge', 'valeur' => '24', 'label' => 'Consultants actifs', 'delta' => '5 packs couverts', 'delta_class' => 'text-secondary'],
    ['icone' => 'payments', 'valeur' => '18,45 M', 'label' => 'Revenu du mois (FCFA)', 'delta' => '+12% vs mois dernier', 'delta_class' => 'text-green-700'],
    ['icone' => 'sentiment_satisfied', 'valeur' => '94 %', 'label' => 'Taux de satisfaction', 'delta' => '+2 pts vs trimestre dernier', 'delta_class' => 'text-green-700'],
    ['icone' => 'warning', 'valeur' => '7', 'label' => 'Dossiers en retard', 'delta' => 'Action requise', 'delta_class' => 'text-error'],
    ['icone' => 'person_add', 'valeur' => '156', 'label' => 'Nouveaux clients (30j)', 'delta' => '+18% vs période précédente', 'delta_class' => 'text-green-700'],
    ['icone' => 'trending_up', 'valeur' => '68 %', 'label' => 'Taux de conversion', 'delta' => 'Devis → inscription', 'delta_class' => 'text-secondary'],
];

$packs = [
    ['nom' => 'Assistance', 'icone' => 'support_agent', 'couleur' => 'bg-blue-100 text-blue-700', 'clients' => 187, 'dossiers' => 42, 'consultants' => 5, 'revenu' => '3,2 M', 'completion' => 78, 'lien' => 'consultant-diagnostic.php'],
    ['nom' => 'Booster', 'icone' => 'rocket_launch', 'couleur' => 'bg-orange-100 text-orange-700', 'clients' => 312, 'dossiers' => 68, 'consultants' => 7, 'revenu' => '5,1 M', 'completion' => 82, 'lien' => 'consultant-dossier-booster.php'],
    ['nom' => 'Classic', 'icone' => 'work', 'couleur' => 'bg-primary-container/10 text-primary', 'clients' => 428, 'dossiers' => 96, 'consultants' => 6, 'revenu' => '4,8 M', 'completion' => 85, 'lien' => 'consultant-analyse-classic.php'],
    ['nom' => 'Wellbeing', 'icone' => 'spa', 'couleur' => 'bg-purple-100 text-purple-700', 'clients' => 251, 'dossiers' => 58, 'consultants' => 4, 'revenu' => '3,65 M', 'completion' => 71, 'lien' => 'consultant-diagnostic-wellbeing.php'],
    ['nom' => 'Particulier', 'icone' => 'tune', 'couleur' => 'bg-surface-container-high text-secondary', 'clients' => 70, 'dossiers' => 12, 'consultants' => 2, 'revenu' => '1,7 M', 'completion' => 90, 'lien' => null],
];

$consultants = [
    ['nom' => 'Camille Martin', 'pack' => 'Booster', 'dossiers' => 14, 'charge' => 70, 'satisfaction' => 4.8, 'statut' => 'Disponible'],
    ['nom' => 'Julien Martin', 'pack' => 'Assistance', 'dossiers' => 9, 'charge' => 90, 'satisfaction' => 4.6, 'statut' => 'Occupé'],
    ['nom' => 'Sophie Martin', 'pack' => 'Wellbeing', 'dossiers' => 11, 'charge' => 55, 'satisfaction' => 4.9, 'statut' => 'Disponible'],
    ['nom' => 'Thomas Martin', 'pack' => 'Classic', 'dossiers' => 16, 'charge' => 80, 'satisfaction' => 4.7, 'statut' => 'Occupé'],
    ['nom' => 'Marie Dupont', 'pack' => 'Classic', 'dossiers' => 8, 'charge' => 40, 'satisfaction' => 4.5, 'statut' => 'Disponible'],
    ['nom' => 'Antoine G.', 'pack' => 'Assistance', 'dossiers' => 12, 'charge' => 60, 'satisfaction' => 4.4, 'statut' => 'Disponible'],
];

$alertes = [
    ['icone' => 'schedule', 'couleur' => 'text-error', 'texte' => '7 dossiers en retard nécessitent une action immédiate.'],
    ['icone' => 'report', 'couleur' => 'text-orange-600', 'texte' => '3 réclamations clients en attente de traitement.'],
    ['icone' => 'credit_card_off', 'couleur' => 'text-orange-600', 'texte' => '2 paiements en échec à relancer.'],
    ['icone' => 'person_alert', 'couleur' => 'text-error', 'texte' => '1 consultant en surcharge (charge &gt; 90%).'],
];

$activites = [
    ['texte' => 'Nouveau dossier créé', 'pack' => 'Pack Classic', 'quand' => 'Il y a 12 min'],
    ['texte' => 'Paiement confirmé', 'pack' => 'Pack Booster', 'quand' => 'Il y a 34 min'],
    ['texte' => 'Consultant assigné', 'pack' => 'Pack Wellbeing', 'quand' => 'Il y a 1h'],
    ['texte' => 'Dossier clôturé', 'pack' => 'Pack Assistance', 'quand' => 'Il y a 2h'],
    ['texte' => 'Nouveau consultant ajouté', 'pack' => 'Équipe', 'quand' => 'Il y a 3h'],
];

$revenusMensuels = [
    ['mois' => 'Avr', 'valeur' => 12.8],
    ['mois' => 'Mai', 'valeur' => 14.2],
    ['mois' => 'Juin', 'valeur' => 13.6],
    ['mois' => 'Juil', 'valeur' => 15.9],
    ['mois' => 'Août', 'valeur' => 16.4],
    ['mois' => 'Sept', 'valeur' => 18.45],
];
$revenuMax = max(array_column($revenusMensuels, 'valeur'));

$sidebarNav = [
    ['icone' => 'space_dashboard', 'label' => 'Tableau de bord', 'actif' => true],
    ['icone' => 'inventory_2', 'label' => 'Packs'],
    ['icone' => 'badge', 'label' => 'Consultants'],
    ['icone' => 'diversity_3', 'label' => 'Clients'],
    ['icone' => 'folder', 'label' => 'Dossiers'],
    ['icone' => 'payments', 'label' => 'Finances'],
    ['icone' => 'bar_chart', 'label' => 'Rapports'],
    ['icone' => 'admin_panel_settings', 'label' => 'Sécurité & accès'],
    ['icone' => 'settings', 'label' => 'Paramètres système'],
];

render_head('Super Admin — Vue d\'ensemble NexRaiz', 'Pilotage global de la plateforme NexRaiz : packs, consultants, clients et finances.');
?>
<div class="flex min-h-screen">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs">
    <a href="index.php" class="flex items-center gap-1.5 pb-space-sm">
      <span class="inline-flex bg-white rounded-lg px-2 py-1"><img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto"></span>
    </a>
    <?php foreach ($sidebarNav as $item): ?>
    <a href="#" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo !empty($item['actif']) ? 'bg-white/10 text-white font-bold border-l-2 border-[#F0A83E]' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg"><?php echo htmlspecialchars($item['label']); ?></span>
    </a>
    <?php endforeach; ?>

    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <p class="font-label-lg text-label-lg font-bold">Accès complet</p>
      <p class="font-body-sm text-[11px] text-inverse-on-surface/70">Vous consultez l'ensemble des packs, consultants et dossiers de la plateforme.</p>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 shrink-0 border-b border-outline-variant/50 bg-surface-container-lowest flex items-center justify-between px-gutter-mobile lg:px-gutter-desktop">
      <div class="flex items-center gap-space-xs lg:hidden">
        <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      </div>
      <div class="flex-1 max-w-md relative hidden md:block">
        <span class="material-symbols-outlined absolute left-space-sm top-1/2 -translate-y-1/2 text-outline text-[18px]">search</span>
        <input type="search" placeholder="Rechercher un pack, un consultant, un client..." class="w-full h-10 pl-10 pr-space-sm rounded-xl bg-surface-container-low font-body-sm text-body-sm focus:outline-none">
      </div>
      <div class="flex items-center gap-space-md ml-auto shrink-0">
        <button type="button" class="relative w-9 h-9 rounded-full hover:bg-surface-container-low flex items-center justify-center text-secondary" aria-label="Notifications">
          <span class="material-symbols-outlined text-[22px]">notifications</span>
          <span class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full bg-error text-white font-label-md text-[9px] font-bold flex items-center justify-center">7</span>
        </button>
        <div class="flex items-center gap-space-xs">
          <div class="w-9 h-9 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-label-md font-bold">SA</div>
          <div class="hidden sm:flex flex-col leading-tight">
            <span class="font-label-md text-label-md text-on-surface font-semibold">Léa Kouadio</span>
            <span class="font-body-sm text-[11px] text-secondary">Super Administrateur</span>
          </div>
          <span class="material-symbols-outlined text-[18px] text-secondary">expand_more</span>
        </div>
      </div>
    </header>

    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop">

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
        <div>
          <h1 class="font-headline-lg text-headline-lg text-on-surface font-bold">Vue d'ensemble de la plateforme</h1>
          <p class="font-body-sm text-body-sm text-secondary">Tous les packs, consultants et dossiers NexRaiz, en un seul endroit.</p>
        </div>
        <button type="button" class="h-11 px-space-md rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-2xs hover:bg-surface-container-low transition-colors shrink-0">
          <span class="material-symbols-outlined text-[18px]">ios_share</span>
          <span>Exporter un rapport global</span>
        </button>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <?php foreach ($kpis as $k): ?>
        <div class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm flex flex-col gap-space-xs">
          <div class="w-11 h-11 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[22px]"><?php echo $k['icone']; ?></span></div>
          <p class="font-headline-lg text-headline-lg text-on-surface font-bold"><?php echo $k['valeur']; ?></p>
          <p class="font-label-lg text-label-lg text-on-surface font-semibold"><?php echo htmlspecialchars($k['label']); ?></p>
          <p class="font-body-sm text-[11px] <?php echo $k['delta_class']; ?>"><?php echo $k['delta']; ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
        <div class="flex items-center justify-between">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Répartition par Pack</h2>
          <span class="font-body-sm text-[12px] text-secondary">Données du mois en cours</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/60">
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Pack</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Clients actifs</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dossiers en cours</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultants</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Revenu (FCFA)</th>
                <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Complétion</th>
                <th class="w-10"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($packs as $p): ?>
              <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                <td class="py-space-sm pr-space-sm">
                  <span class="flex items-center gap-space-xs">
                    <span class="w-8 h-8 rounded-full <?php echo $p['couleur']; ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[16px]"><?php echo $p['icone']; ?></span></span>
                    <span class="font-label-md text-label-md text-on-surface font-semibold">Pack <?php echo htmlspecialchars($p['nom']); ?></span>
                  </span>
                </td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $p['clients']; ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $p['dossiers']; ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $p['consultants']; ?></td>
                <td class="py-space-sm pr-space-sm font-body-sm text-body-sm text-on-surface"><?php echo $p['revenu']; ?> M</td>
                <td class="py-space-sm pr-space-sm">
                  <div class="flex items-center gap-space-xs w-32">
                    <div class="flex-1 h-1.5 rounded-full bg-surface-container-low overflow-hidden"><div class="h-full rounded-full bg-primary-container" style="width:<?php echo $p['completion']; ?>%"></div></div>
                    <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo $p['completion']; ?>%</span>
                  </div>
                </td>
                <td class="py-space-sm text-right">
                  <?php if ($p['lien']): ?>
                  <a href="<?php echo htmlspecialchars($p['lien']); ?>" class="font-label-md text-label-md text-primary flex items-center gap-1 justify-end">Détail <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
                  <?php else: ?>
                  <span class="font-body-sm text-[11px] text-secondary">Parcours à venir</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-sm items-start">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
          <div class="flex items-center justify-between">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Revenu mensuel (M FCFA)</h2>
            <span class="font-body-sm text-[12px] text-green-700 font-semibold">+12% sur 6 mois</span>
          </div>
          <div class="flex items-end gap-space-sm h-40">
            <?php foreach ($revenusMensuels as $r): ?>
            <div class="flex-1 flex flex-col items-center gap-space-2xs">
              <span class="font-body-sm text-[11px] text-secondary"><?php echo $r['valeur']; ?></span>
              <div class="w-full rounded-t-lg bg-primary-container" style="height:<?php echo round(($r['valeur'] / $revenuMax) * 100); ?>px"></div>
              <span class="font-label-md text-label-md text-secondary"><?php echo $r['mois']; ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="flex items-center gap-space-xs font-headline-sm text-headline-sm text-on-surface font-bold"><span class="material-symbols-outlined text-[20px] text-error">warning</span>Alertes &amp; priorités</h2>
          <?php foreach ($alertes as $a): ?>
          <div class="flex items-start gap-space-xs">
            <span class="material-symbols-outlined text-[18px] <?php echo $a['couleur']; ?> mt-0.5"><?php echo $a['icone']; ?></span>
            <p class="font-body-sm text-body-sm text-on-surface"><?php echo $a['texte']; ?></p>
          </div>
          <?php endforeach; ?>
          <button type="button" class="h-10 rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors mt-space-xs">Voir toutes les alertes</button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-space-sm items-start">
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <div class="flex items-center justify-between">
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Consultants</h2>
            <a href="#" class="font-label-md text-label-md text-primary flex items-center gap-1">Gérer les consultants <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse">
              <thead>
                <tr class="border-b border-outline-variant/60">
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Consultant</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Pack</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Dossiers</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Charge</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs pr-space-sm">Satisfaction</th>
                  <th class="text-left font-label-md text-[11px] uppercase tracking-wider text-secondary font-bold py-space-xs">Statut</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($consultants as $c): ?>
                <tr class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                  <td class="py-space-sm pr-space-sm">
                    <span class="flex items-center gap-space-xs">
                      <span class="w-7 h-7 rounded-full bg-primary-container/20 text-primary flex items-center justify-center font-label-md text-[10px] font-bold shrink-0"><?php echo strtoupper(substr($c['nom'], 0, 1) . substr(strrchr($c['nom'], ' '), 1, 1)); ?></span>
                      <span class="font-label-md text-label-md text-on-surface font-semibold"><?php echo htmlspecialchars($c['nom']); ?></span>
                    </span>
                  </td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-secondary"><?php echo htmlspecialchars($c['pack']); ?></td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface"><?php echo $c['dossiers']; ?></td>
                  <td class="py-space-sm pr-space-sm">
                    <div class="flex items-center gap-space-xs w-20">
                      <div class="flex-1 h-1.5 rounded-full bg-surface-container-low overflow-hidden"><div class="h-full rounded-full <?php echo $c['charge'] >= 85 ? 'bg-error' : 'bg-primary-container'; ?>" style="width:<?php echo $c['charge']; ?>%"></div></div>
                      <span class="font-body-sm text-[11px] text-secondary shrink-0"><?php echo $c['charge']; ?>%</span>
                    </div>
                  </td>
                  <td class="py-space-sm pr-space-sm font-body-sm text-[12px] text-on-surface flex items-center gap-1"><span class="material-symbols-outlined text-[14px] text-primary">star</span><?php echo $c['satisfaction']; ?>/5</td>
                  <td class="py-space-sm"><span class="inline-flex whitespace-nowrap px-space-xs py-0.5 rounded-full font-label-md text-[11px] font-bold <?php echo $c['statut'] === 'Disponible' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'; ?>"><?php echo $c['statut']; ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Activité récente</h2>
          <?php foreach ($activites as $act): ?>
          <div class="flex items-start gap-space-xs">
            <span class="w-1.5 h-1.5 rounded-full bg-primary-container mt-1.5 shrink-0"></span>
            <div class="flex-1"><p class="font-body-sm text-[12px] text-on-surface"><?php echo htmlspecialchars($act['texte']); ?></p><p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars($act['pack']); ?></p></div>
            <span class="font-body-sm text-[10px] text-secondary shrink-0"><?php echo htmlspecialchars($act['quand']); ?></span>
          </div>
          <?php endforeach; ?>
          <button type="button" class="h-9 rounded-lg border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-low transition-colors mt-space-xs">Voir toute l'activité</button>
        </div>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-space-sm">
        <button type="button" class="h-14 rounded-xl bg-primary-container text-white font-label-lg text-label-lg flex items-center justify-center gap-space-xs shadow-sm hover:opacity-90 transition-opacity"><span class="material-symbols-outlined text-[20px]">person_add</span>Ajouter un consultant</button>
        <button type="button" class="h-14 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">inventory_2</span>Gérer les packs</button>
        <button type="button" class="h-14 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">bar_chart</span>Rapports détaillés</button>
        <button type="button" class="h-14 rounded-xl border border-outline-variant text-on-surface font-label-lg text-label-lg flex items-center justify-center gap-space-xs hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[20px] text-primary">admin_panel_settings</span>Paramètres système</button>
      </div>

    </main>

    <footer class="w-full bg-inverse-surface text-inverse-on-surface px-gutter-mobile lg:px-gutter-desktop py-space-md">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-xs font-body-sm text-[11px] text-inverse-on-surface/70 max-w-max-width-content mx-auto w-full">
        <span>© <?php echo date('Y'); ?> NexRaiz – Espace Super Admin – Accès restreint</span>
        <span>Dernière synchronisation : aujourd'hui à 09:15</span>
      </div>
    </footer>
  </div>
</div>
</body>
</html>
