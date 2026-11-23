<?php
/**
 * Éléments partagés par les pages de l'espace consultant du Pack Classic
 * (calcul de l'aperçu des dossiers + rendu de la sidebar de navigation).
 */

/**
 * Libellés/couleurs du statut d'analyse unifié — une seule règle de calcul,
 * réutilisée par classic_dossiers_apercu() (donc par toutes les pages qui
 * s'appuient dessus) pour que le tableau, les filtres et les cartes ne
 * puissent jamais afficher deux définitions différentes du même statut.
 */
function classic_statuts_analyse_labels(): array
{
    return [
        'cloture'            => ['Clôturé', 'bg-surface-container-high text-secondary'],
        'document_incomplet' => ['Document incomplet', 'bg-red-100 text-red-700'],
        'a_analyser'         => ['À analyser', 'bg-orange-100 text-orange-700'],
        'analyse_en_cours'   => ['Analyse en cours', 'bg-blue-100 text-blue-700'],
        'pret_a_publier'     => ['Prêt à publier', 'bg-purple-100 text-purple-700'],
        'publie'             => ['Livrable publié', 'bg-green-100 text-green-700'],
    ];
}

/**
 * Statut réel d'un type de document précis (cv/lettre) pour un dossier :
 * manquant -> à analyser (reçu, aucune correction commencée) -> en cours
 * (correction écrite, rien publié) -> publié (livrable disponible). Jamais
 * "publié" sans un vrai livrable "disponible" en base.
 */
function classic_statut_document(bool $present, bool $correctionExiste, ?string $livrableStatut): string
{
    if (!$present) return 'manquant';
    if (!$correctionExiste) return 'a_analyser';
    if ($livrableStatut === 'disponible') return 'publie';
    if ($livrableStatut === 'en_preparation') return 'pret_a_publier';
    return 'en_cours';
}

/**
 * Calcule, pour chaque dossier Pack Classic, les indicateurs utilisés par
 * la sidebar et par la page Analyses (statut calculé, poste, source, mois,
 * assignation, etc.). Requêtes légères, réexécutées à chaque page — le
 * volume de dossiers reste faible pour un usage interne.
 */
function classic_dossiers_apercu(int $packId, array $consultant): array
{
    $stmt = db()->prepare(
        'SELECT ds.id, ds.numero, ds.statut, ds.consultant_id, ds.source, ds.created_at, ds.updated_at, u.nom, u.prenom, q.donnees_json
         FROM dossiers ds
         JOIN users u ON u.id = ds.user_id
         LEFT JOIN qualifications q ON q.dossier_id = ds.id
         WHERE ds.pack_id = ?
         ORDER BY ds.updated_at DESC'
    );
    $stmt->execute([$packId]);
    $dossiersBruts = $stmt->fetchAll();

    // Détection CV/lettre : nouveau champ categorie (dépôts faits depuis la
    // refonte "Mes documents") + repli sur l'ancien repérage par chemin pour
    // les documents déposés avant cette refonte (categorie alors NULL).
    $stmt = db()->prepare('SELECT dossier_id, categorie, chemin FROM documents doc JOIN dossiers ds ON ds.id = doc.dossier_id WHERE ds.pack_id = ?');
    $stmt->execute([$packId]);
    $documentsParDossier = [];
    foreach ($stmt->fetchAll() as $d) {
        $slot = $d['categorie'] ?: (preg_match('#/(cv|lettre|offre|portfolio)/#', $d['chemin'], $m) ? $m[1] : null);
        if ($slot) {
            $documentsParDossier[$d['dossier_id']][$slot] = true;
        }
    }

    $stmt = db()->prepare("SELECT dossier_id, COUNT(*) AS nb FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0 GROUP BY dossier_id");
    $stmt->execute([$packId]);
    $messagesNonLusParDossier = [];
    foreach ($stmt->fetchAll() as $row) {
        $messagesNonLusParDossier[$row['dossier_id']] = (int) $row['nb'];
    }

    // Dernière correction par (dossier, type) — preuve réelle qu'une analyse
    // a été commencée, jamais déduite de la seule présence d'un fichier.
    $stmt = db()->prepare('SELECT c.dossier_id, c.document_type, MAX(c.created_at) AS derniere FROM corrections c JOIN dossiers ds ON ds.id = c.dossier_id WHERE ds.pack_id = ? GROUP BY c.dossier_id, c.document_type');
    $stmt->execute([$packId]);
    $correctionsParDossier = [];
    foreach ($stmt->fetchAll() as $row) {
        $correctionsParDossier[$row['dossier_id']][$row['document_type']] = $row['derniere'];
    }

    // Version courante des livrables "CV corrigé"/"Lettre de motivation
    // corrigée" (jamais une ancienne version remplacée).
    $stmt = db()->prepare("SELECT liv.dossier_id, liv.titre, liv.statut FROM livrables liv JOIN dossiers ds ON ds.id = liv.dossier_id WHERE ds.pack_id = ? AND liv.titre IN ('CV corrigé', 'Lettre de motivation corrigée') AND liv.est_version_courante = 1");
    $stmt->execute([$packId]);
    $livrablesCorrectionParDossier = [];
    foreach ($stmt->fetchAll() as $row) {
        $cle = $row['titre'] === 'CV corrigé' ? 'cv' : 'lettre';
        $livrablesCorrectionParDossier[$row['dossier_id']][$cle] = $row['statut'];
    }

    // Signal réel pour "À valider" : au moins une action du plan que le
    // client a déclarée terminée et qui attend la validation du consultant
    // (voir actions_plan.statut = 'a_verifier', Pack Classic — Mes actions).
    $stmt = db()->prepare("SELECT DISTINCT ap.dossier_id FROM actions_plan ap JOIN dossiers ds ON ds.id = ap.dossier_id WHERE ds.pack_id = ? AND ap.statut = 'a_verifier'");
    $stmt->execute([$packId]);
    $dossiersAValider = array_fill_keys(array_column($stmt->fetchAll(), 'dossier_id'), true);

    return array_map(function ($d) use ($documentsParDossier, $messagesNonLusParDossier, $correctionsParDossier, $livrablesCorrectionParDossier, $dossiersAValider, $consultant) {
        $qualification = json_decode((string) $d['donnees_json'], true) ?: [];
        $slots = $documentsParDossier[$d['id']] ?? [];
        $aCv = !empty($slots['cv']);
        $aLettre = !empty($slots['lettre']);
        $aLinkedin = !empty($qualification['linkedin_url']);
        $corrections = $correctionsParDossier[$d['id']] ?? [];
        $livrablesCorrection = $livrablesCorrectionParDossier[$d['id']] ?? [];
        $estCloture = $d['statut'] === 'cloture';

        $statutCv = classic_statut_document($aCv, isset($corrections['cv']), $livrablesCorrection['cv'] ?? null);
        $statutLettre = classic_statut_document($aLettre, isset($corrections['lettre']), $livrablesCorrection['lettre'] ?? null);
        $statutLinkedin = !$aLinkedin ? 'manquant' : (isset($corrections['linkedin']) ? 'recommandations_redigees' : 'a_analyser');

        // Statut global = combinaison des statuts cv/lettre, du moins avancé
        // au plus avancé (le dossier n'est "publié" que si les deux le sont,
        // mais "prêt à publier" dès que l'un des deux l'est).
        $statutsDoc = [$statutCv, $statutLettre];
        if ($estCloture) {
            $statutCle = 'cloture';
        } elseif (!$aCv || !$aLettre) {
            $statutCle = 'document_incomplet';
        } elseif (!isset($corrections['cv']) && !isset($corrections['lettre'])) {
            $statutCle = 'a_analyser';
        } elseif ($statutCv === 'publie' && $statutLettre === 'publie') {
            $statutCle = 'publie';
        } elseif (in_array('pret_a_publier', $statutsDoc, true) || in_array('publie', $statutsDoc, true)) {
            $statutCle = 'pret_a_publier';
        } else {
            $statutCle = 'analyse_en_cours';
        }
        [$statutLabel, ] = classic_statuts_analyse_labels()[$statutCle];

        $prenom = $d['prenom'];
        $nom = $d['nom'];
        return [
            'id' => $d['id'],
            'numero' => $d['numero'],
            'created_at' => $d['created_at'],
            'updated_at' => $d['updated_at'],
            'initiales' => strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1)),
            'nom' => $prenom . ' ' . $nom,
            'poste' => $qualification['poste_vise'] ?? 'Non renseigné',
            'cv' => $aCv,
            'lettre' => $aLettre,
            'linkedin' => $aLinkedin,
            'statut' => $statutLabel,
            'statut_cle' => $statutCle,
            'statut_cv' => $statutCv,
            'statut_lettre' => $statutLettre,
            'statut_linkedin' => $statutLinkedin,
            'messages_non_lus' => $messagesNonLusParDossier[$d['id']] ?? 0,
            'source' => $d['source'],
            'mois' => date('Y-m', strtotime($d['created_at'])),
            'consultant_id' => $d['consultant_id'] ? (int) $d['consultant_id'] : null,
            'est_mienne' => (int) $d['consultant_id'] === (int) $consultant['id'],
            'est_cloturee' => $estCloture,
            'est_prete' => $statutCle === 'pret_a_publier',
            'est_a_valider' => !$estCloture && !empty($dossiersAValider[$d['id']]),
            'est_en_cours' => !$estCloture && in_array($statutCle, ['a_analyser', 'analyse_en_cours'], true),
        ];
    }, $dossiersBruts);
}

/**
 * Nombre total de messages candidats non lus par les consultants, tous
 * dossiers Pack Classic confondus (utilisé pour le badge du menu Messages).
 */
function classic_messages_non_lus_total(int $packId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM messages msg JOIN dossiers ds ON ds.id = msg.dossier_id
         WHERE ds.pack_id = ? AND msg.expediteur_type = 'client' AND msg.lu_par_consultant = 0"
    );
    $stmt->execute([$packId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Rend la sidebar de navigation/filtres commune à toutes les pages de
 * l'espace consultant Pack Classic. Les filtres renvoient tous vers
 * consultant-analyse-classic.php (seule page qui applique un filtre/liste) ;
 * ils restent donc actifs et utiles depuis n'importe quelle page.
 */
function render_classic_sidebar(array $dossiers, int $packId): void
{
    $nbMessagesNonLus = classic_messages_non_lus_total($packId);
    $surPageMessages = basename($_SERVER['SCRIPT_NAME']) === 'consultant-messages-classic.php';
    $vuesSidebar = [
        'mes'       => ['icone' => 'assignment_ind', 'label' => 'Mes analyses',     'champ' => 'est_mienne'],
        'en_cours'  => ['icone' => 'hourglass_top',   'label' => 'Analyses en cours', 'champ' => 'est_en_cours'],
        'pret'      => ['icone' => 'check_circle',    'label' => 'Analyses prêtes',   'champ' => 'est_prete'],
        'a_valider' => ['icone' => 'task_alt',        'label' => 'À valider',         'champ' => 'est_a_valider'],
        'archivees' => ['icone' => 'archive',         'label' => 'Archivées',         'champ' => 'est_cloturee'],
    ];
    $vueActive = ($_GET['vue'] ?? '') ;
    if (!isset($vuesSidebar[$vueActive])) {
        $vueActive = '';
    }
    $posteFiltre = trim($_GET['poste'] ?? '');
    $sourceFiltre = trim($_GET['source'] ?? '');
    $moisFiltre = trim($_GET['mois'] ?? '');
    $surPageAnalyses = basename($_SERVER['SCRIPT_NAME']) === 'consultant-analyse-classic.php';

    $postesDisponibles = array_values(array_unique(array_map(fn ($d) => $d['poste'], $dossiers)));
    sort($postesDisponibles);
    $sourcesDisponibles = array_values(array_unique(array_map(fn ($d) => $d['source'], $dossiers)));
    sort($sourcesDisponibles);
    $moisDisponibles = array_values(array_unique(array_map(fn ($d) => $d['mois'], $dossiers)));
    rsort($moisDisponibles);
    $moisNomsCourts = [1 => 'Janv.', 2 => 'Févr.', 3 => 'Mars', 4 => 'Avr.', 5 => 'Mai', 6 => 'Juin', 7 => 'Juil.', 8 => 'Août', 9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Déc.'];
    ?>
    <aside class="hidden lg:flex flex-col w-64 shrink-0 border-r border-outline-variant/50 bg-surface-container-lowest p-space-md gap-space-2xs overflow-y-auto min-h-0">
      <a href="consultant-analyse-classic.php" class="flex items-center gap-space-xs px-space-xs py-space-sm rounded-xl <?php echo $surPageAnalyses && $vueActive === '' && $posteFiltre === '' && $sourceFiltre === '' && $moisFiltre === '' ? 'bg-primary-container text-white font-bold' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low'; ?> mb-space-xs"><span class="material-symbols-outlined text-[18px]">diversity_3</span>Espace NexRaiz : Analyse</a>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pb-space-2xs">Menu</span>
      <a href="consultant-messages-classic.php" class="flex items-center justify-between px-space-xs py-space-sm rounded-xl transition-colors <?php echo $surPageMessages ? 'bg-primary-container text-white font-bold' : 'text-secondary hover:bg-surface-container-low'; ?>">
        <span class="flex items-center gap-space-xs font-label-lg text-label-lg"><span class="material-symbols-outlined text-[20px]">chat</span>Messages</span>
        <?php if ($nbMessagesNonLus > 0): ?>
        <span class="min-w-[20px] h-5 px-1.5 rounded-full <?php echo $surPageMessages ? 'bg-white text-primary' : 'bg-error text-white'; ?> font-label-md text-[11px] font-bold flex items-center justify-center"><?php echo $nbMessagesNonLus; ?></span>
        <?php else: ?>
        <span class="font-label-md text-[11px] <?php echo $surPageMessages ? 'text-white/80' : 'text-secondary'; ?>">0</span>
        <?php endif; ?>
      </a>
      <?php foreach ($vuesSidebar as $slug => $info):
        $nbPourVue = count(array_filter($dossiers, fn ($d) => !empty($d[$info['champ']])));
      ?>
      <a href="consultant-analyse-classic.php?vue=<?php echo $slug; ?>" class="flex items-center justify-between px-space-xs py-space-sm rounded-xl transition-colors <?php echo $vueActive === $slug ? 'bg-primary-container text-white font-bold' : 'text-secondary hover:bg-surface-container-low'; ?>">
        <span class="flex items-center gap-space-xs font-label-lg text-label-lg"><span class="material-symbols-outlined text-[20px]"><?php echo $info['icone']; ?></span><?php echo htmlspecialchars($info['label']); ?></span>
        <span class="font-label-md text-[11px] <?php echo $vueActive === $slug ? 'text-white/80' : 'text-secondary'; ?>"><?php echo $nbPourVue; ?></span>
      </a>
      <?php endforeach; ?>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pt-space-sm pb-space-2xs">Filtres rapides</span>
      <form method="get" action="consultant-analyse-classic.php" class="flex flex-col gap-space-xs px-space-xs">
        <select name="poste" onchange="this.form.submit()" class="w-full h-10 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Tous les postes</option>
          <?php foreach ($postesDisponibles as $p): ?>
          <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $posteFiltre === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="source" onchange="this.form.submit()" class="w-full h-10 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Toutes les sources</option>
          <?php foreach ($sourcesDisponibles as $s): ?>
          <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $sourceFiltre === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="mois" onchange="this.form.submit()" class="w-full h-10 px-space-xs rounded-lg border border-outline-variant bg-surface-container-lowest font-body-sm text-body-sm focus:outline-none focus:border-primary">
          <option value="">Toutes les dates</option>
          <?php foreach ($moisDisponibles as $m): [$annee, $moisNum] = explode('-', $m); ?>
          <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $moisFiltre === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($moisNomsCourts[(int) $moisNum] . ' ' . $annee); ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pt-space-sm pb-space-2xs">Actions rapides</span>
      <a href="consultant-importer-dossier-classic.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors" title="Import CSV en masse de candidats"><span class="material-symbols-outlined text-[18px]">upload_file</span><span class="font-label-md text-label-md">Importer un dossier</span></a>
      <a href="consultant-deposer-documents-classic.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors" title="Déposer des fichiers sur un dossier existant"><span class="material-symbols-outlined text-[18px]">attach_file</span><span class="font-label-md text-label-md">Déposer des documents</span></a>
      <a href="consultant-nouveau-dossier-classic.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">add_circle</span><span class="font-label-md text-label-md">Nouveau dossier</span></a>
      <a href="consultant-analyse-classic.php?export=csv" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">ios_share</span><span class="font-label-md text-label-md">Exporter un rapport</span></a>
      <a href="opportunites-admin.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">work</span><span class="font-label-md text-label-md">Opportunités</span></a>
      <a href="consultant-opportunites-classic.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">fact_check</span><span class="font-label-md text-label-md">Suivi candidats</span></a>
      <a href="admin-messages.php" class="flex items-center justify-between px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors">
        <span class="flex items-center gap-space-xs font-label-md text-label-md"><span class="material-symbols-outlined text-[18px]">forum</span>Messages internes</span>
        <?php $nbMessagesInternes = messages_internes_non_lus((int) current_user()['id']); ?>
        <?php if ($nbMessagesInternes > 0): ?>
        <span class="w-5 h-5 rounded-full bg-error text-white flex items-center justify-center font-label-md text-[10px] font-bold"><?php echo $nbMessagesInternes; ?></span>
        <?php endif; ?>
      </a>

      <span class="font-label-md text-[10px] uppercase tracking-wider text-secondary px-space-xs pt-space-sm pb-space-2xs">Ressources</span>
      <a href="consultant-calendrier-classic.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">calendar_month</span><span class="font-label-md text-label-md">Calendrier</span></a>
      <a href="ressources.php?pack=classic" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">menu_book</span><span class="font-label-md text-label-md">Base de connaissances</span></a>
      <a href="modeles-documents.php?pack=classic" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">description</span><span class="font-label-md text-label-md">Modèles de documents</span></a>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <a href="admin-utilisateurs.php" class="flex items-center gap-space-xs px-space-xs py-space-xs rounded-lg text-on-surface hover:bg-surface-container-low transition-colors"><span class="material-symbols-outlined text-[18px]">manage_accounts</span><span class="font-label-md text-label-md">Utilisateurs</span></a>
      <?php endif; ?>

      <div class="mt-auto pt-space-md flex flex-col gap-space-xs bg-surface-container-low rounded-xl p-space-sm">
        <div class="w-9 h-9 rounded-full bg-primary-container/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">help</span></div>
        <p class="font-label-lg text-label-lg text-on-surface font-bold">Besoin d'aide ?</p>
        <p class="font-body-sm text-[11px] text-secondary">Consultez nos guides ou contactez le support.</p>
        <a href="contact.php" class="h-9 rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface hover:bg-surface-container-lowest transition-colors flex items-center justify-center">Voir l'aide</a>
      </div>
    </aside>
    <?php
}
