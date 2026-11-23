<?php
/**
 * NexRaiz — « Postuler via NexRaiz » (Pack Classic). Page dédiée ouverte
 * depuis classic-mes-opportunites.php : détail complet de l'offre, contrôle
 * qualité, lettre de motivation déjà préparée par l'IA (ou repli honnête
 * sans IA), et validation de l'envoi direct à l'entreprise. Accès réservé
 * aux clients ayant un dossier Pack Classic actif ET le consentement
 * « matching » coché.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/opportunites.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];
$dossierFerme = $dossier['statut'] === 'cloture';
$utilisateur = current_user();

$profil = opportunites_profil_dossier($dossierId);
$aAccepte = opportunites_profil_actif($profil);

$offreId = (int) ($_GET['offre'] ?? $_POST['opportunite_id'] ?? 0);
$stmtOffre = db()->prepare('SELECT * FROM opportunites WHERE id = ? AND statut = "publie"');
$stmtOffre->execute([$offreId]);
$ligneOffre = $stmtOffre->fetch();

if (!$ligneOffre || !$aAccepte || $dossierFerme) {
    header('Location: classic-mes-opportunites.php');
    exit;
}
$offre = opportunites_decoder_offre($ligneOffre);

$texteLettre = null;
$sourceLettre = null;
$erreurCandidature = '';
$nomClient = trim(($utilisateur['prenom'] ?? '') . ' ' . ($utilisateur['nom'] ?? ''));
$texteLettreClient = opportunites_texte_lettre_dossier($dossierId);

function nx_generer_lettre_pour_offre(array $profil, int $dossierId, array $offre, string $nomClient): array
{
    return opportunites_generer_lettre_ia(
        $profil ?: [],
        opportunites_texte_cv_dossier($dossierId),
        opportunites_texte_lettre_dossier($dossierId),
        $offre,
        $nomClient
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'postuler_nexraiz') {
        $resultatEnvoi = opportunites_candidater_via_nexraiz($offre['id'], $dossierId, $_POST['lettre'] ?? '', $utilisateur);
        if ($resultatEnvoi['ok']) {
            header('Location: classic-mes-opportunites.php?vue=recommandees&candidature_envoyee=' . $offre['id']);
            exit;
        }
        $erreurCandidature = $resultatEnvoi['erreur'];
        $texteLettre = $_POST['lettre'] ?? ''; // on ne perd pas le texte saisi en cas d'échec.
    } elseif ($action === 'generer_lettre') {
        $resultat = nx_generer_lettre_pour_offre($profil, $dossierId, $offre, $nomClient);
        $texteLettre = $resultat['texte'];
        $sourceLettre = $resultat['source'];
    }
}

if ($texteLettre === null) {
    // Premier chargement (GET) : la lettre est déjà préparée automatiquement,
    // le client n'a rien à cliquer pour l'obtenir.
    $resultat = nx_generer_lettre_pour_offre($profil, $dossierId, $offre, $nomClient);
    $texteLettre = $resultat['texte'];
    $sourceLettre = $resultat['source'];
}

$cvFichier = opportunites_fichier_cv_dossier($dossierId);
$hostLienCandidature = $offre['lien_candidature'] ? (parse_url($offre['lien_candidature'], PHP_URL_HOST) ?: $offre['lien_candidature']) : null;
$calcul = calculer_score_match($profil, $offre, opportunites_config()['poids_score']);

$stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE dossier_id = ? AND expediteur_type = 'consultant' AND lu_par_client = 0");
$stmt->execute([$dossierId]);
$messagesNonLus = (int) $stmt->fetchColumn();

$sidebarNav = [
    ['icone' => 'home', 'label' => 'Tableau de bord', 'url' => 'classic-tableau-bord.php'],
    ['icone' => 'checklist', 'label' => 'Mes actions', 'url' => 'classic-mes-actions.php'],
    ['icone' => 'description', 'label' => 'Mes documents', 'url' => 'classic-depot-documents.php'],
    ['icone' => 'card_giftcard', 'label' => 'Mes livrables', 'url' => 'classic-mes-livrables.php'],
    ['icone' => 'work', 'label' => 'Mes opportunités', 'url' => 'classic-mes-opportunites.php', 'actif' => true],
    ['icone' => 'event', 'label' => 'Rendez-vous', 'url' => 'classic-rendez-vous.php'],
    ['icone' => 'chat', 'label' => 'Messages', 'url' => 'messages.php?dossier=' . $dossierId, 'badge' => $messagesNonLus ?: null],
    ['icone' => 'settings', 'label' => 'Paramètres', 'url' => 'parametres.php'],
];

$palettes = [
    ['bg' => '#E5EEFF', 'text' => '#1E3E62'],
    ['bg' => '#FFF4CC', 'text' => '#8A6D00'],
    ['bg' => '#DCE9FF', 'text' => '#2563EB'],
];
$pal = $palettes[$offre['id'] % 3];
$typeMeta = [
    'emploi'     => ['icone' => 'work', 'label' => 'Emploi', 'bg' => '#DCE9FF', 'text' => '#1E40AF'],
    'stage'      => ['icone' => 'school', 'label' => 'Stage', 'bg' => '#FFF4CC', 'text' => '#8A6D00'],
    'alternance' => ['icone' => 'swap_horiz', 'label' => 'Alternance', 'bg' => '#EDE3FF', 'text' => '#5B21B6'],
    'mission'    => ['icone' => 'bolt', 'label' => 'Mission', 'bg' => '#DCFCE7', 'text' => '#15803D'],
];
$tm = $typeMeta[$offre['type']] ?? $typeMeta['emploi'];

render_head('Pack Classic — Postuler via NexRaiz');
?>
<header class="sticky top-0 z-40 bg-surface-container-lowest/95 backdrop-blur-xl border-b border-outline-variant/50">
  <div class="h-16 px-gutter-mobile lg:px-gutter-desktop flex items-center justify-between gap-space-md">
    <a href="index.php" class="flex items-center gap-2 shrink-0">
      <img src="uploads/LOGO0.png" alt="NexRaiz" class="h-9 w-auto">
      <span class="hidden md:flex flex-col leading-none">
        <span class="font-headline-sm text-headline-sm font-bold text-on-surface">NexRaiz</span>
        <span class="font-body-sm text-[10px] text-secondary">Votre avenir, notre priorité</span>
      </span>
    </a>
    <div class="flex items-center gap-space-xs shrink-0">
      <?php render_notification_bell((int) $utilisateur['id']); ?>
      <span class="w-9 h-9 rounded-full bg-gradient-to-br from-[#2563EB] to-[#1E3E62] text-white flex items-center justify-center font-label-md text-[13px] font-bold shrink-0"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($utilisateur['prenom'], 0, 1) . mb_substr($utilisateur['nom'], 0, 1))); ?></span>
    </div>
  </div>
</header>

<div class="flex w-full min-h-screen bg-surface">

  <aside class="hidden lg:flex flex-col w-64 shrink-0 bg-inverse-surface text-inverse-on-surface p-space-md gap-space-2xs">
    <h2 class="font-headline-sm text-headline-sm font-bold text-inverse-on-surface px-space-xs pb-space-sm">Pack Classic</h2>
    <?php foreach ($sidebarNav as $item): ?>
    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl transition-colors <?php echo !empty($item['actif']) ? 'bg-white/10 text-white font-bold' : 'text-inverse-on-surface/75 hover:bg-white/5'; ?>">
      <span class="material-symbols-outlined text-[20px]"><?php echo $item['icone']; ?></span>
      <span class="font-label-lg text-label-lg flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
      <?php if (!empty($item['badge'])): ?>
      <span class="w-5 h-5 rounded-full bg-primary-container text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $item['badge']; ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <div class="mt-auto pt-space-md border-t border-white/10 flex flex-col gap-space-xs">
      <a href="messages.php?dossier=<?php echo $dossierId; ?>" class="mx-space-xs h-10 rounded-xl border border-white/20 flex items-center justify-center font-label-md text-label-md text-inverse-on-surface hover:bg-white/5 transition-colors">Contacter mon consultant</a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <main class="flex-1 flex flex-col gap-space-md p-gutter-mobile lg:p-gutter-desktop max-w-6xl">
      <a href="classic-mes-opportunites.php?vue=recommandees&offre=<?php echo $offre['id']; ?>#panneau-detail" class="font-label-md text-label-md text-primary flex items-center gap-1 w-fit"><span class="material-symbols-outlined text-[18px]">arrow_back</span>Retour à mes opportunités</a>

      <div class="bg-surface-container-low rounded-xl p-space-sm">
        <p class="font-body-sm text-[11px] text-secondary"><?php echo htmlspecialchars(OPPORTUNITES_DISCLAIMER); ?></p>
      </div>

      <?php if ($erreurCandidature !== ''): ?>
      <div class="bg-error-container/10 border border-error/30 rounded-xl p-space-sm font-body-sm text-body-sm text-error flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">error</span><?php echo htmlspecialchars($erreurCandidature); ?></div>
      <?php endif; ?>

      <div class="grid grid-cols-1 lg:grid-cols-[1fr_1.2fr] gap-space-md items-start">

        <!-- Colonne gauche : informations sur l'offre -->
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden flex flex-col gap-space-md">

          <div class="flex items-start gap-space-sm p-space-lg pb-space-md" style="background:linear-gradient(135deg,<?php echo $tm['bg']; ?>,#F8F9FF)">
            <span class="w-14 h-14 rounded-2xl flex items-center justify-center font-label-lg text-label-lg font-bold shrink-0 bg-white shadow-sm" style="color:<?php echo $pal['text']; ?>"><?php echo htmlspecialchars(opportunites_initiales_entreprise($offre['entreprise'])); ?></span>
            <div>
              <span class="font-label-md text-[10px] font-bold px-space-xs py-0.5 rounded-full inline-flex items-center gap-0.5 bg-white/70 mb-1" style="color:<?php echo $tm['text']; ?>"><span class="material-symbols-outlined text-[13px]"><?php echo $tm['icone']; ?></span><?php echo htmlspecialchars($tm['label']); ?></span>
              <h1 class="font-headline-sm text-headline-sm text-[#13203F] font-bold"><?php echo htmlspecialchars($offre['titre']); ?></h1>
              <p class="font-body-sm text-body-sm text-[#444653]"><?php echo htmlspecialchars($offre['entreprise'] ?: 'Entreprise non communiquée'); ?><?php echo $offre['ville'] ? ' · ' . htmlspecialchars($offre['ville']) : ''; ?> · <?php echo htmlspecialchars(opportunites_publication_relative($offre['created_at'])); ?></p>
            </div>
          </div>

          <div class="px-space-lg flex flex-col gap-space-md">

          <div class="rounded-2xl p-space-md flex items-center gap-space-md" style="background:linear-gradient(135deg,#F8F9FF,#EAF1FF)">
            <div class="w-16 h-16 rounded-full flex items-center justify-center shrink-0" style="background:conic-gradient(#2563EB <?php echo $calcul['score'] * 3.6; ?>deg, #E2E8F0 0deg)">
              <span class="w-12 h-12 rounded-full bg-surface-container-lowest flex items-center justify-center font-label-lg text-label-lg font-bold text-[#13203F]"><?php echo $calcul['score']; ?>%</span>
            </div>
            <div>
              <p class="font-label-lg text-label-lg text-on-surface font-bold"><?php echo htmlspecialchars(opportunites_libelle_score($calcul['score'])); ?></p>
              <p class="font-body-sm text-[11px] text-secondary">Score calculé automatiquement à partir de votre profil</p>
            </div>
          </div>

          <div class="flex flex-col gap-space-sm">
            <p class="font-label-md text-label-md text-[#13203F] font-bold uppercase tracking-wide flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-[#2563EB]">work</span>Le poste</p>
            <div class="flex flex-wrap gap-1">
              <?php if ($offre['secteur']): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">category</span><?php echo htmlspecialchars($offre['secteur']); ?></span><?php endif; ?>
              <?php if ($offre['niveau_etudes_min']): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">school</span><?php echo htmlspecialchars($offre['niveau_etudes_min']); ?></span><?php endif; ?>
              <?php if ($offre['experience_min_annees']): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">work_history</span><?php echo (int) $offre['experience_min_annees']; ?> an(s) d'expérience</span><?php endif; ?>
              <?php if ($offre['teletravail'] !== 'non'): ?><span class="font-body-sm text-[11px] text-on-surface bg-surface-container-low px-space-xs py-0.5 rounded-full flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">home_work</span><?php echo $offre['teletravail'] === 'total' ? 'Télétravail total' : 'Télétravail partiel'; ?></span><?php endif; ?>
            </div>
            <div class="grid grid-cols-1 gap-y-1">
              <?php if ($offre['date_limite']): ?><p class="font-body-sm text-body-sm text-on-surface flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[16px] text-secondary">event</span>Date limite : <strong><?php echo htmlspecialchars(date('d/m/Y', strtotime($offre['date_limite']))); ?></strong></p><?php endif; ?>
              <?php if ($offre['salaire_indicatif']): ?><p class="font-body-sm text-body-sm text-on-surface flex items-center gap-space-2xs"><span class="material-symbols-outlined text-[16px] text-secondary">payments</span><strong><?php echo htmlspecialchars($offre['salaire_indicatif']); ?></strong></p><?php endif; ?>
            </div>
            <p class="font-body-sm text-body-sm text-on-surface whitespace-pre-line pt-space-2xs"><?php echo nl2br(htmlspecialchars($offre['description'])); ?></p>
            <?php if (!empty($offre['competences_requises'])): ?>
            <div class="flex flex-wrap gap-1 pt-space-2xs">
              <?php foreach ($offre['competences_requises'] as $comp): ?>
              <span class="font-body-sm text-[11px] text-primary bg-primary-container/10 border border-primary-container/30 px-space-xs py-0.5 rounded-full"><?php echo htmlspecialchars($comp); ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <div class="rounded-2xl bg-surface-container-low p-space-md flex flex-col gap-space-xs">
            <p class="font-label-md text-label-md text-[#13203F] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-green-700">verified_user</span>Contrôle qualité</p>
            <ul class="flex flex-col gap-space-2xs">
              <?php if ($hostLienCandidature): ?>
              <li class="flex items-start gap-space-2xs font-body-sm text-[13px] text-on-surface"><span class="material-symbols-outlined text-[18px] text-green-700 shrink-0">check_circle</span>Annonce réelle vérifiée sur <a href="<?php echo htmlspecialchars($offre['lien_candidature']); ?>" target="_blank" rel="noopener noreferrer" class="text-primary underline"><?php echo htmlspecialchars($hostLienCandidature); ?></a></li>
              <?php else: ?>
              <li class="flex items-start gap-space-2xs font-body-sm text-[13px] text-on-surface"><span class="material-symbols-outlined text-[18px] text-green-700 shrink-0">check_circle</span>Offre publiée par votre consultant NexRaiz</li>
              <?php endif; ?>
              <?php if ($offre['email_candidature']): ?>
              <li class="flex items-start gap-space-2xs font-body-sm text-[13px] text-on-surface"><span class="material-symbols-outlined text-[18px] <?php echo $cvFichier ? 'text-green-700' : 'text-[#8A6D00]'; ?> shrink-0"><?php echo $cvFichier ? 'check_circle' : 'warning'; ?></span><?php echo $cvFichier ? 'Votre CV (' . htmlspecialchars($cvFichier['nom']) . ') sera joint automatiquement' : 'Aucun CV déposé — déposez-le dans « Mes documents » avant l\'envoi'; ?></li>
              <?php endif; ?>
              <?php if ($sourceLettre === 'secours'): ?>
              <li class="flex items-start gap-space-2xs font-body-sm text-[13px] text-on-surface"><span class="material-symbols-outlined text-[18px] text-[#8A6D00] shrink-0">warning</span>Lettre simplifiée (IA non configurée) — relisez-la attentivement avant l'envoi</li>
              <?php else: ?>
              <li class="flex items-start gap-space-2xs font-body-sm text-[13px] text-on-surface"><span class="material-symbols-outlined text-[18px] text-green-700 shrink-0">check_circle</span>Lettre adaptée par l'IA à partir de votre CV<?php echo $texteLettreClient ? ' et de votre lettre déposée' : ''; ?></li>
              <?php endif; ?>
            </ul>
          </div>

          <details class="font-body-sm text-[11px] text-secondary border-t border-outline-variant/40 pt-space-sm">
            <summary class="cursor-pointer text-primary font-label-md text-[12px] font-bold">Détail du score</summary>
            <ul class="pt-1 flex flex-col gap-0.5">
              <?php foreach ($calcul['details'] as $d): ?>
              <li><?php echo htmlspecialchars($d['label']); ?> : <?php echo $d['obtenu']; ?>/<?php echo $d['sur']; ?></li>
              <?php endforeach; ?>
            </ul>
          </details>

          </div>
          <div class="pb-space-lg"></div>
        </div>

        <!-- Colonne droite : lettre de motivation + envoi -->
        <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-md sticky top-space-md">

          <div class="rounded-2xl p-space-md flex flex-col gap-space-xs" style="background:linear-gradient(135deg,#E5EEFF,#F8F9FF)">
            <p class="font-label-md text-label-md text-[#13203F] font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-[#2563EB]"><?php echo $offre['email_candidature'] ? 'send' : 'content_copy'; ?></span>Envoi</p>
            <?php if ($offre['email_candidature']): ?>
            <p class="font-body-sm text-[12px] text-secondary">Relisez la lettre ci-dessous, modifiez-la si besoin, puis validez. NexRaiz enverra le message à <strong><?php echo htmlspecialchars($offre['email_candidature']); ?></strong> avec votre propre adresse en réponse directe.</p>
            <?php else: ?>
            <p class="font-body-sm text-[12px] text-secondary">Cette offre ne communique pas d'adresse e-mail de candidature vérifiée — NexRaiz ne peut donc pas l'envoyer à votre place (pour éviter d'envoyer votre candidature au mauvais endroit). Copiez la lettre ci-dessous, puis postulez vous-même via <a href="<?php echo htmlspecialchars($offre['lien_candidature'] ?: '#'); ?>" target="_blank" rel="noopener noreferrer" class="text-primary underline font-semibold">l'annonce sur <?php echo htmlspecialchars($hostLienCandidature ?: 'le site externe'); ?></a>.</p>
            <?php endif; ?>
          </div>

          <form method="post" class="flex flex-col gap-space-xs">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="opportunite_id" value="<?php echo $offre['id']; ?>">
            <label class="font-label-md text-label-md text-[#13203F] font-bold">Votre lettre de motivation (adaptée à cette offre)</label>
            <textarea id="texte-lettre" name="lettre" rows="16" placeholder="Votre lettre de motivation pour cette offre..." class="w-full p-space-sm rounded-xl border border-outline-variant font-body-sm text-[13px] resize-y"><?php echo htmlspecialchars($texteLettre); ?></textarea>
            <div class="flex flex-wrap gap-space-xs items-center">
              <?php if ($offre['email_candidature']): ?>
              <button type="submit" name="action" value="postuler_nexraiz" onclick="return confirm('Envoyer cette candidature à <?php echo htmlspecialchars(addslashes($offre['email_candidature'])); ?> maintenant ?');" class="h-12 px-space-xl rounded-full text-white font-label-lg text-label-lg font-bold flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity" style="background:linear-gradient(135deg,#2563EB,#13203F)"><span class="material-symbols-outlined text-[18px]">send</span>Valider et envoyer ma candidature</button>
              <?php else: ?>
              <button type="button" onclick="var t=document.getElementById('texte-lettre');t.select();navigator.clipboard.writeText(t.value);this.textContent='Copié !';" class="h-12 px-space-xl rounded-full text-white font-label-lg text-label-lg font-bold flex items-center justify-center gap-space-2xs shadow-sm hover:opacity-90 transition-opacity" style="background:linear-gradient(135deg,#2563EB,#13203F)"><span class="material-symbols-outlined text-[18px]">content_copy</span>Copier la lettre</button>
              <?php if ($offre['lien_candidature']): ?>
              <a href="<?php echo htmlspecialchars($offre['lien_candidature']); ?>" target="_blank" rel="noopener noreferrer" class="h-12 px-space-md rounded-full border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[16px]">open_in_new</span>Postuler sur le site externe</a>
              <?php endif; ?>
              <?php endif; ?>
              <button type="submit" name="action" value="generer_lettre" formnovalidate class="h-12 px-space-md rounded-full border border-outline-variant font-label-md text-label-md text-on-surface flex items-center gap-1 hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[16px]">refresh</span>Régénérer avec l'IA</button>
            </div>
          </form>

        </div>

      </div>
    </main>
  </div>
</div>
</body>
</html>
