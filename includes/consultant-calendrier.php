<?php
/**
 * Rendu partagé du calendrier consultant (rendez-vous réels du pack), utilisé
 * par consultant-calendrier-classic.php / -assistance.php / -booster.php.
 * Navigation par mois, un point par jour ayant un rendez-vous, liste
 * chronologique complète du mois en dessous, avec le statut réel de chaque
 * rendez-vous (planifié / confirmé / annulé / terminé).
 *
 * @param array<int,array{id:int,numero:string,client:string}>|null $dossiersPourCreation
 * Si fourni (non null), affiche un bouton "Nouveau rendez-vous" permettant de
 * planifier un rendez-vous pour un des dossiers listés (le POST est géré par
 * la page appelante, voir consultant-calendrier-assistance.php) — laissé à
 * null pour les packs où cette action n'est pas encore activée, afin de ne
 * rien changer à leur comportement existant.
 */
function render_consultant_calendrier(int $packId, string $dossierPageFile, ?array $dossiersPourCreation = null): void
{
    $moisNoms = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
    $joursSemaine = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
    $formatLabels = ['ligne' => 'En ligne', 'presentiel' => 'En présentiel', 'a_definir' => 'À définir', 'hybride' => 'Hybride'];
    $statutLabels = [
        'planifie' => ['En attente', 'bg-orange-100 text-orange-700'],
        'confirme' => ['Confirmé', 'bg-green-100 text-green-700'],
        'annule'   => ['Annulé', 'bg-surface-container-high text-secondary line-through'],
        'termine'  => ['Terminé', 'bg-surface-container-high text-secondary'],
    ];

    $moisParam = $_GET['mois'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $moisParam)) {
        $moisParam = date('Y-m');
    }
    [$annee, $moisNum] = array_map('intval', explode('-', $moisParam));
    $premierJour = new DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $moisNum));
    $mois = $moisNoms[(int) $premierJour->format('n')] . ' ' . $premierJour->format('Y');
    $moisPrecedent = $premierJour->modify('-1 month')->format('Y-m');
    $moisSuivant = $premierJour->modify('+1 month')->format('Y-m');
    $debutMois = sprintf('%04d-%02d-01 00:00:00', $annee, $moisNum);
    $finMois = (new DateTimeImmutable($debutMois))->modify('+1 month')->format('Y-m-d 00:00:00');

    $stmt = db()->prepare(
        "SELECT rdv.id, rdv.date_heure, rdv.format, rdv.statut, ds.id AS dossier_id, ds.numero, u.nom, u.prenom
         FROM rendezvous rdv
         JOIN dossiers ds ON ds.id = rdv.dossier_id
         JOIN users u ON u.id = ds.user_id
         WHERE ds.pack_id = ? AND rdv.date_heure >= ? AND rdv.date_heure < ?
         ORDER BY rdv.date_heure ASC"
    );
    $stmt->execute([$packId, $debutMois, $finMois]);
    $rendezvousDuMois = $stmt->fetchAll();

    $parJour = [];
    foreach ($rendezvousDuMois as $r) {
        $jour = (int) date('j', strtotime($r['date_heure']));
        $parJour[$jour][] = $r;
    }

    $nbJoursMois = (int) $premierJour->format('t');
    $decalageDebut = (int) $premierJour->format('N') - 1;
    $aujourdhui = new DateTimeImmutable('today');
    $cellules = [];
    for ($i = 0; $i < $decalageDebut; $i++) {
        $cellules[] = null;
    }
    for ($j = 1; $j <= $nbJoursMois; $j++) {
        $cellules[] = $j;
    }
    while (count($cellules) % 7 !== 0) {
        $cellules[] = null;
    }
    $grille = array_chunk($cellules, 7);
    ?>
    <?php if ($dossiersPourCreation !== null): ?>
    <div class="flex items-center justify-between flex-wrap gap-space-xs">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Calendrier</h2>
      <button type="button" onclick="document.getElementById('formNouveauRdv').classList.toggle('hidden')" class="h-10 px-space-sm rounded-lg bg-primary text-white font-label-md text-label-md flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[16px]">add</span>Nouveau rendez-vous</button>
    </div>
    <form method="post" id="formNouveauRdv" class="hidden bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="creer_rdv">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
        <label class="flex flex-col gap-0.5">
          <span class="font-label-md text-[12px] text-secondary">Dossier</span>
          <select name="dossier_id" required class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <option value="">— Choisir —</option>
            <?php foreach ($dossiersPourCreation as $d): ?>
            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['client'] . ' · ' . $d['numero']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="flex flex-col gap-0.5">
          <span class="font-label-md text-[12px] text-secondary">Format</span>
          <select name="format" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
            <?php foreach ($formatLabels as $val => $label): ?>
            <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="flex flex-col gap-0.5">
          <span class="font-label-md text-[12px] text-secondary">Date</span>
          <input type="date" name="jour" required min="<?php echo date('Y-m-d'); ?>" class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
        </label>
        <label class="flex flex-col gap-0.5">
          <span class="font-label-md text-[12px] text-secondary">Heure</span>
          <input type="time" name="heure" required class="h-10 px-space-xs rounded-lg border border-outline-variant font-body-sm text-[13px]">
        </label>
      </div>
      <button type="submit" class="self-start h-10 px-space-md rounded-lg bg-primary text-white font-label-md text-label-md">Planifier (confirmé)</button>
    </form>
    <?php endif; ?>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <div class="flex items-center justify-between">
        <a href="?mois=<?php echo $moisPrecedent; ?>" class="w-9 h-9 rounded-full hover:bg-surface-container-low flex items-center justify-center text-secondary" aria-label="Mois précédent"><span class="material-symbols-outlined text-[20px]">chevron_left</span></a>
        <span class="font-headline-sm text-headline-sm text-on-surface font-bold"><?php echo $mois; ?></span>
        <a href="?mois=<?php echo $moisSuivant; ?>" class="w-9 h-9 rounded-full hover:bg-surface-container-low flex items-center justify-center text-secondary" aria-label="Mois suivant"><span class="material-symbols-outlined text-[20px]">chevron_right</span></a>
      </div>
      <div class="grid grid-cols-7 gap-1 text-center">
        <?php foreach ($joursSemaine as $j): ?>
        <span class="font-label-md text-[11px] text-secondary font-bold py-space-2xs"><?php echo $j; ?></span>
        <?php endforeach; ?>
      </div>
      <?php foreach ($grille as $semaine): ?>
      <div class="grid grid-cols-7 gap-1">
        <?php foreach ($semaine as $jour): ?>
        <?php if ($jour === null): ?>
        <span></span>
        <?php else:
          $estAujourdhui = $jour === (int) $aujourdhui->format('j') && $moisParam === $aujourdhui->format('Y-m');
          $nb = count($parJour[$jour] ?? []);
        ?>
        <div class="aspect-square rounded-lg flex flex-col items-center justify-center gap-0.5 <?php echo $estAujourdhui ? 'bg-primary-container/10 border-2 border-primary-container' : 'border border-outline-variant/40'; ?>">
          <span class="font-body-sm text-body-sm <?php echo $estAujourdhui ? 'text-primary font-bold' : 'text-on-surface'; ?>"><?php echo $jour; ?></span>
          <?php if ($nb > 0): ?>
          <span class="w-1.5 h-1.5 rounded-full bg-primary-container"></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-sm">
      <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Rendez-vous de <?php echo $mois; ?></h2>
      <?php if (!$rendezvousDuMois): ?>
      <p class="font-body-sm text-body-sm text-secondary text-center py-space-md">Aucun rendez-vous ce mois-ci.</p>
      <?php endif; ?>
      <?php foreach ($rendezvousDuMois as $r): ?>
      <div class="flex items-center justify-between gap-space-xs bg-surface-container-low rounded-xl p-space-sm flex-wrap">
        <div class="flex items-center gap-space-xs min-w-0">
          <div class="w-11 h-11 rounded-xl bg-primary-container/10 text-primary flex flex-col items-center justify-center shrink-0 font-label-md text-[11px] font-bold leading-tight">
            <span><?php echo date('d', strtotime($r['date_heure'])); ?></span>
            <span class="text-[9px] font-normal uppercase"><?php echo date('M', strtotime($r['date_heure'])); ?></span>
          </div>
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface font-semibold truncate"><?php echo htmlspecialchars($r['prenom'] . ' ' . $r['nom']); ?></p>
            <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(date('H:i', strtotime($r['date_heure'])) . ' · ' . ($formatLabels[$r['format']] ?? $r['format'])); ?></p>
          </div>
        </div>
        <div class="flex items-center gap-space-xs shrink-0">
          <?php [$labelStatutRdv, $classeStatutRdv] = $statutLabels[$r['statut']] ?? [$r['statut'], 'bg-surface-container-high text-secondary']; ?>
          <span class="px-space-xs py-0.5 rounded-full font-label-md text-[10px] font-semibold <?php echo $classeStatutRdv; ?>"><?php echo htmlspecialchars($labelStatutRdv); ?></span>
          <a href="<?php echo htmlspecialchars($dossierPageFile); ?>?dossier=<?php echo $r['dossier_id']; ?>" class="h-9 px-space-sm rounded-lg border border-outline-variant font-label-md text-[12px] text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center gap-1">Ouvrir le dossier <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php
}
