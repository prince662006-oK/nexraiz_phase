-- NexRaiz — Schéma de base de données
-- Couvre le parcours client (inscription, qualification, rendez-vous,
-- consentement, paiement) pour le Pack Assistance. Conçu pour être
-- réutilisé par les autres packs (qualifications reste volontairement
-- simple ; on l'étendra si un pack a besoin de champs très différents).

CREATE DATABASE IF NOT EXISTS nexraiz CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexraiz;

-- Comptes (clients, consultants, super admin)
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('client', 'consultant', 'admin', 'super_admin') NOT NULL DEFAULT 'client',
    pack_id       INT NULL COMMENT 'Pack de rattachement pour les consultants/admins (détermine le tableau de bord par défaut à la connexion)',
    actif         TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Compte actif (désactivable par un administrateur, sans suppression)',
    nom           VARCHAR(100) NOT NULL,
    prenom        VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    telephone     VARCHAR(30)  NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Référentiel des packs
CREATE TABLE IF NOT EXISTS packs (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50)  NOT NULL UNIQUE,
    nom  VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

INSERT INTO packs (slug, nom) VALUES
    ('assistance', 'Pack Assistance'),
    ('booster', 'Pack Booster'),
    ('classic', 'Pack Classic'),
    ('wellbeing', 'Pack Wellbeing'),
    ('particulier', 'Pack Particulier')
ON DUPLICATE KEY UPDATE nom = VALUES(nom);

-- ALTER ... ADD CONSTRAINT n'a pas d'équivalent "IF NOT EXISTS" portable ; ce script
-- doit pouvoir être rejoué sans erreur sur une base déjà initialisée (migrations
-- incrémentales). Vérifier via information_schema.TABLE_CONSTRAINTS (ancienne
-- approche) fait planter MariaDB 10.4.32 sur certaines installations (crash
-- reproductible dans fill_schema_proc/get_schema_tables_result dès que cette
-- requête est combinée à un SET @var = (sous-requête) — observé en
-- développement local, voir mysql_error.log). On évite complètement
-- information_schema : la procédure tente l'ALTER et avale silencieusement
-- l'erreur 1826 (ER_FK_DUP_NAME, contrainte déjà existante).
DROP PROCEDURE IF EXISTS nx_migration_fk_users_pack;
DELIMITER $$
CREATE PROCEDURE nx_migration_fk_users_pack()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1005, 1826 BEGIN END;
    ALTER TABLE users ADD CONSTRAINT fk_users_pack FOREIGN KEY (pack_id) REFERENCES packs(id) ON DELETE SET NULL;
END$$
DELIMITER ;
CALL nx_migration_fk_users_pack();
DROP PROCEDURE nx_migration_fk_users_pack;

-- Un dossier = une instance de parcours pour un client sur un pack donné
CREATE TABLE IF NOT EXISTS dossiers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    numero         VARCHAR(20) NOT NULL UNIQUE,
    user_id        INT NOT NULL,
    pack_id        INT NOT NULL,
    consultant_id  INT NULL,
    current_step   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    statut         ENUM('en_cours', 'en_attente', 'cloture') NOT NULL DEFAULT 'en_cours',
    notes_consultant TEXT NULL COMMENT 'Notes internes du consultant, non visibles par le client',
    source         VARCHAR(50) NOT NULL DEFAULT 'Site web' COMMENT 'Canal d''acquisition du dossier',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (pack_id) REFERENCES packs(id),
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Réponses du formulaire de qualification du besoin
-- situation_type/urgence/priorites/resume/documents_disponibles couvrent le
-- formulaire du Pack Assistance ; donnees_json accueille les réponses des
-- formulaires de qualification des autres packs (structure très différente
-- d'un pack à l'autre : Booster, Classic, Wellbeing...).
CREATE TABLE IF NOT EXISTS qualifications (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id             INT NOT NULL UNIQUE,
    situation_type         VARCHAR(100) NULL,
    urgence                VARCHAR(20)  NULL,
    priorites              TEXT NULL COMMENT 'Liste JSON des priorités sélectionnées',
    resume                 TEXT NULL,
    documents_disponibles  TEXT NULL COMMENT 'Liste JSON des documents cochés',
    donnees_json           TEXT NULL COMMENT 'Réponses brutes du formulaire, en JSON, pour les packs autres qu\'Assistance',
    diagnostic_valide_le   DATETIME NULL COMMENT 'Rempli quand le consultant valide le diagnostic (Pack Assistance)',
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Rendez-vous pris avec un consultant
CREATE TABLE IF NOT EXISTS rendezvous (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL,
    date_heure  DATETIME NOT NULL,
    format      ENUM('ligne', 'presentiel', 'a_definir', 'hybride') NOT NULL DEFAULT 'ligne',
    statut      ENUM('planifie', 'confirme', 'annule', 'termine') NOT NULL DEFAULT 'planifie',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Acceptation de la charte de consentement
CREATE TABLE IF NOT EXISTS consentements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL UNIQUE,
    accepte     TINYINT(1) NOT NULL DEFAULT 0,
    accepte_le  DATETIME NULL,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Paiements (Stripe pour carte, CinetPay pour mobile money / Afrique de l'Ouest)
CREATE TABLE IF NOT EXISTS paiements (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id            INT NOT NULL,
    montant               DECIMAL(10, 2) NOT NULL,
    devise                VARCHAR(10) NOT NULL DEFAULT 'XOF',
    methode               ENUM('stripe', 'cinetpay', 'neemii', 'virement') NOT NULL,
    reference_externe     VARCHAR(190) NULL COMMENT 'ID session Stripe ou transaction_id CinetPay',
    statut                ENUM('en_attente', 'paye', 'echoue', 'rembourse') NOT NULL DEFAULT 'en_attente',
    justificatif_nom      VARCHAR(255) NULL COMMENT 'Nom original du justificatif de virement déposé par le client',
    justificatif_chemin   VARCHAR(255) NULL COMMENT 'Chemin relatif vers le justificatif de virement',
    justificatif_taille_ko INT UNSIGNED NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Documents déposés par le client
CREATE TABLE IF NOT EXISTS documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id    INT NOT NULL,
    nom_original  VARCHAR(255) NOT NULL,
    -- Catégorie du document : pour le Pack Assistance, doit correspondre à
    -- une entrée de qualifications.documents_disponibles ; pour le Pack
    -- Classic, c'est le slot (cv, lettre, offre, portfolio) — réutilisé
    -- plutôt qu'une nouvelle colonne dédiée.
    categorie     VARCHAR(100) NULL,
    chemin        VARCHAR(255) NOT NULL COMMENT 'Chemin relatif public (legacy) ou "prive:..." pour un fichier hors webroot (voir includes/documents-classic.php)',
    taille_ko     INT UNSIGNED NULL,
    uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description   VARCHAR(255) NULL COMMENT 'Description facultative ajoutée par le client au dépôt',
    -- Statut de vérification (Pack Classic) : chaque nouveau dépôt crée une
    -- nouvelle ligne (jamais d'écrasement) — la dernière ligne par
    -- (dossier_id, categorie) est la version courante, les précédentes
    -- restent consultables comme historique.
    statut        ENUM('en_verification', 'valide', 'a_corriger') NOT NULL DEFAULT 'en_verification',
    motif_correction VARCHAR(255) NULL,
    valide_le     DATETIME NULL,
    valide_par    INT NULL,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (valide_par) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Points de vigilance identifiés par le consultant sur un dossier (Pack Assistance)
CREATE TABLE IF NOT EXISTS dossier_points_vigilance (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL,
    titre       VARCHAR(150) NOT NULL,
    texte       VARCHAR(255) NOT NULL,
    icone       VARCHAR(50) NOT NULL DEFAULT 'warning',
    couleur     VARCHAR(80) NOT NULL DEFAULT 'bg-orange-100 text-orange-700',
    -- Une alerte traitée reste dans l'historique (jamais supprimée
    -- silencieusement) : seul ce champ change.
    traite      TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Recommandations d'orientation externe (avocat, médecin du travail...) par dossier (Pack Assistance)
CREATE TABLE IF NOT EXISTS dossier_orientations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL,
    type        ENUM('avocat', 'medecin_travail', 'psychologue', 'expert_rh') NOT NULL,
    recommande  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dossier_type (dossier_id, type),
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Livrables produits par le consultant (CV corrigé, lettre améliorée...),
-- déposés depuis l'espace interne et visibles par le client sur son
-- tableau de bord.
CREATE TABLE IF NOT EXISTS livrables (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id    INT NOT NULL,
    titre         VARCHAR(255) NOT NULL,
    nom_original  VARCHAR(255) NOT NULL,
    chemin        VARCHAR(255) NOT NULL,
    taille_ko     INT UNSIGNED NULL,
    -- "en_preparation" : le consultant a déposé un fichier mais ne l'a pas
    -- encore confirmé comme terminé — invisible en téléchargement pour le
    -- candidat, qui ne voit que le titre et l'étape ("Livrable en
    -- préparation", cahier des charges section 3). "disponible" : envoyé
    -- explicitement par le consultant ("Livrable disponible"), le candidat
    -- peut le télécharger.
    statut        ENUM('en_preparation', 'disponible') NOT NULL DEFAULT 'en_preparation',
    envoye_le     DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Catégorie facultative (rapport, compte rendu, document professionnel,
    -- plan d'action, autre) — NULL si non déterminable, jamais attribuée
    -- automatiquement par défaut (Pack Classic).
    categorie     VARCHAR(30) NULL,
    description   TEXT NULL COMMENT 'Description/objectif du livrable pour le client (Pack Classic)',
    prochaine_etape VARCHAR(255) NULL,
    -- Versionnement minimal (Pack Classic) : chaque mise à jour d'un
    -- livrable déjà publié crée une NOUVELLE ligne (même dossier_id + titre)
    -- plutôt que d'écraser la précédente ; une seule ligne par
    -- (dossier_id, titre) a est_version_courante = 1 à un instant donné.
    version       INT NOT NULL DEFAULT 1,
    est_version_courante TINYINT(1) NOT NULL DEFAULT 1,
    -- Suivi réel des consultations côté client (jamais simulé) — Pack Classic.
    consultations_count INT NOT NULL DEFAULT 0,
    derniere_consultation_le DATETIME NULL,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Plan d'action (rempli par le consultant, visible par le client)
CREATE TABLE IF NOT EXISTS actions_plan (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id    INT NOT NULL,
    titre         VARCHAR(255) NOT NULL,
    description   TEXT NULL COMMENT 'Détail optionnel de l''action (au-delà du titre court)',
    proprietaire  VARCHAR(100) NULL,
    priorite      ENUM('normale', 'haute') NOT NULL DEFAULT 'normale',
    echeance      DATE NULL,
    -- "a_verifier" : le client a déclaré l'action terminée, en attente de
    -- validation par le consultant (Pack Classic, onglet Mes actions) —
    -- "termine" ne doit alors être posé que par le consultant, jamais
    -- directement par le client.
    statut        ENUM('a_faire', 'en_cours', 'termine', 'a_venir', 'a_verifier') NOT NULL DEFAULT 'a_faire',
    -- Motif renseigné par le consultant quand il renvoie une action
    -- "a_verifier" en correction plutôt que de la valider.
    motif_correction VARCHAR(255) NULL,
    -- Trace légère de la dernière transition de statut (pas d'historique
    -- complet : log_activite() en tient déjà une trace horodatée côté
    -- client/consultant si besoin de retracer l'ensemble des changements).
    statut_maj_le DATETIME NULL,
    statut_maj_par ENUM('client', 'consultant') NULL,
    -- Renseigné quand l'action provient de la confirmation d'un compte
    -- rendu structuré (voir comptes_rendus) — pas de contrainte FK dure,
    -- cohérent avec le reste du schéma (ex. livrables.chemin) ; la
    -- cohérence est garantie côté application (suppression en cascade
    -- gérée explicitement si un compte rendu est supprimé).
    compte_rendu_id INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Comptes rendus structurés d'intervention (Pack Assistance) : document
-- professionnel avec cycle de vie (brouillon -> à valider -> validé /
-- correction demandée -> archivé), distinct du résumé auto-généré
-- compte-rendu.php. Sépare strictement le contenu interne (jamais transmis
-- au client) du contenu partageable.
CREATE TABLE IF NOT EXISTS comptes_rendus (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id             INT NOT NULL,
    reference              VARCHAR(30) NOT NULL UNIQUE,
    auteur_id              INT NOT NULL,
    type_intervention      ENUM('entretien', 'appel', 'rendez_vous', 'reunion', 'intervention_rh', 'autre') NOT NULL DEFAULT 'entretien',
    date_intervention      DATETIME NOT NULL,
    participants           VARCHAR(255) NULL,
    objet                  VARCHAR(255) NULL,
    contexte               TEXT NULL,
    objectifs              TEXT NULL,
    resume_echanges        TEXT NULL,
    faits_rapportes        TEXT NULL COMMENT 'Faits tels que rapportés par le client, pas des faits établis',
    observations_internes  TEXT NULL COMMENT 'Jamais inclus dans le PDF partageable ni transmis au client',
    decisions              TEXT NULL,
    actions_proposees_json TEXT NULL COMMENT 'Liste JSON [{description, responsable, echeance}], confirmables en actions_plan',
    prochaine_etape        TEXT NULL,
    statut                 ENUM('brouillon', 'a_valider', 'valide', 'correction_demandee', 'archive') NOT NULL DEFAULT 'brouillon',
    motif_correction       VARCHAR(255) NULL,
    valide_par             INT NULL,
    valide_le              DATETIME NULL,
    partage_le             DATETIME NULL COMMENT 'Date de transmission du PDF partageable au client, si réalisée',
    pdf_chemin             VARCHAR(255) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (auteur_id) REFERENCES users(id),
    FOREIGN KEY (valide_par) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Corrections de documents (CV, lettre, LinkedIn) rédigées par le consultant.
-- Chaque enregistrement est une nouvelle version (historique conservé) ;
-- la version affichée au consultant est la plus récente par dossier/type.
CREATE TABLE IF NOT EXISTS corrections (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id       INT NOT NULL,
    -- Consultant auteur de cette version précise (atelier principal ou Studio) —
    -- NULL pour les lignes créées avant cette colonne, jamais déduit a posteriori.
    consultant_id    INT NULL,
    document_type    ENUM('cv', 'lettre', 'linkedin') NOT NULL,
    contenu_initial  TEXT NULL,
    contenu_corrige  TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Checklist qualité manuelle (ex. {"coordonnees":"verifie","dates_coherentes":"a_verifier",...}) —
    -- confirmée explicitement par le consultant, jamais cochée automatiquement par l'IA.
    checklist_json   TEXT NULL,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Éditeur visuel "Canva" du CV/lettre (Pack Classic) : conserve le brouillon
-- d'édition directement sur le document original (overlay de texte positionné
-- par-dessus le rendu PDF.js), jamais visible par le candidat tant que
-- statut = 'brouillon'. Une ligne par dossier/type ; edits_json contient la
-- liste des blocs de texte modifiés (page, position, police, ancien/nouveau
-- texte). pdf_final_chemin n'est renseigné qu'après validation (export réel).
CREATE TABLE IF NOT EXISTS canvas_editions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id         INT NOT NULL,
    document_type      ENUM('cv', 'lettre') NOT NULL,
    -- Dernier consultant à avoir enregistré ce brouillon (un seul "état
    -- courant" par dossier/type ; voir canvas_editions_historique pour
    -- l'historique des sauvegardes successives).
    consultant_id      INT NULL,
    source_chemin      VARCHAR(255) NOT NULL,
    edits_json         LONGTEXT NULL,
    statut             ENUM('brouillon', 'valide') NOT NULL DEFAULT 'brouillon',
    pdf_final_chemin   VARCHAR(255) NULL,
    validated_at       DATETIME NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dossier_type (dossier_id, document_type),
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Historique append-only des sauvegardes de l'éditeur visuel (contrairement à
-- canvas_editions, qui ne garde qu'un seul état courant par dossier/type) —
-- permet de restaurer une version antérieure même après avoir fermé puis
-- rouvert l'éditeur, pas seulement l'annuler/rétablir en mémoire de la
-- session en cours.
CREATE TABLE IF NOT EXISTS canvas_editions_historique (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id     INT NOT NULL,
    document_type  ENUM('cv', 'lettre') NOT NULL,
    consultant_id  INT NULL,
    edits_json     LONGTEXT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Recommandations libres du consultant sur un dossier (Pack Booster) :
-- points forts à valoriser, axes de progression, recommandations concrètes.
CREATE TABLE IF NOT EXISTS dossier_recommandations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL,
    categorie   ENUM('point_fort', 'axe_progression', 'recommandation') NOT NULL,
    texte       VARCHAR(255) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Annotations libres du consultant sur un dossier (catégorisées),
-- affichées sous la comparaison de documents.
CREATE TABLE IF NOT EXISTS correction_annotations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL,
    categorie   ENUM('contenu', 'competence', 'style') NOT NULL,
    texte       VARCHAR(255) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Messagerie entre le client et son consultant, par dossier
CREATE TABLE IF NOT EXISTS messages (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id            INT NOT NULL,
    expediteur_id         INT NOT NULL,
    expediteur_type       ENUM('client', 'consultant') NOT NULL,
    corps                 TEXT NOT NULL,
    piece_jointe_nom      VARCHAR(255) NULL COMMENT 'Nom original du fichier joint',
    piece_jointe_chemin   VARCHAR(255) NULL COMMENT 'Chemin relatif vers le fichier joint',
    piece_jointe_taille   INT UNSIGNED NULL COMMENT 'Taille en octets du fichier joint',
    lu_par_client         TINYINT(1) NOT NULL DEFAULT 0,
    lu_par_consultant     TINYINT(1) NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (expediteur_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Appels vocaux WebRTC (navigateur à navigateur) entre deux membres NexRaiz
-- (consultant ↔ client, ou super admin ↔ consultant/admin) : la table sert
-- de canal de signalisation (offre/réponse SDP, candidats ICE), interrogé
-- par sondage HTTP côté client (pas de serveur WebSocket dédié).
CREATE TABLE IF NOT EXISTS appels (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    appelant_id        INT NOT NULL,
    destinataire_id    INT NOT NULL,
    statut             ENUM('sonne','accepte','refuse','termine','manque') NOT NULL DEFAULT 'sonne',
    offer_sdp          MEDIUMTEXT NULL,
    answer_sdp         MEDIUMTEXT NULL,
    ice_appelant       MEDIUMTEXT NULL,
    ice_destinataire   MEDIUMTEXT NULL,
    started_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at        DATETIME NULL,
    ended_at           DATETIME NULL,
    FOREIGN KEY (appelant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (destinataire_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Messagerie interne entre membres de l'équipe NexRaiz (consultants,
-- administrateurs de pack, super administrateur), indépendante de tout
-- dossier client — utilisée pour la coordination transverse entre packs.
CREATE TABLE IF NOT EXISTS messages_internes (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    expediteur_id         INT NOT NULL,
    destinataire_id       INT NOT NULL,
    corps                 TEXT NOT NULL,
    piece_jointe_nom      VARCHAR(255) NULL,
    piece_jointe_chemin   VARCHAR(255) NULL,
    piece_jointe_taille   INT UNSIGNED NULL,
    lu               TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expediteur_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (destinataire_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Fil d'activité du dossier (visible sur le tableau de bord client)
CREATE TABLE IF NOT EXISTS activites (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id  INT NOT NULL,
    texte       VARCHAR(255) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Base de connaissances consultée par les consultants ("Ressources", "Base de
-- connaissances", "Guides & procédures", "Boîte à outils" des différentes
-- sidebars renvoient toutes vers cette même bibliothèque partagée).
CREATE TABLE IF NOT EXISTS ressources (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    pack_id               INT NULL COMMENT 'NULL = visible pour tous les packs',
    categorie             ENUM('base_connaissances', 'guide', 'outil', 'faq') NOT NULL DEFAULT 'base_connaissances',
    titre                 VARCHAR(255) NOT NULL,
    contenu               TEXT NULL COMMENT 'Contenu texte inline, optionnel si un fichier est associé',
    fichier_chemin        VARCHAR(255) NULL COMMENT 'Marquage "prive:..." — stockage protégé, jamais un chemin public',
    fichier_nom_original  VARCHAR(255) NULL,
    fichier_extension     VARCHAR(10) NULL,
    fichier_taille_ko     INT UNSIGNED NULL,
    statut                ENUM('publie', 'archive') NOT NULL DEFAULT 'publie',
    created_by            INT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pack_id) REFERENCES packs(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ressources_categorie (categorie),
    INDEX idx_ressources_statut (statut)
) ENGINE=InnoDB;

-- Modèles de documents téléchargeables ("Modèles de documents", "Outils &
-- modèles", "Modèles & ressources" renvoient toutes vers cette bibliothèque).
CREATE TABLE IF NOT EXISTS modeles_documents (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    pack_id      INT NULL COMMENT 'NULL = visible pour tous les packs',
    categorie    VARCHAR(30) NULL COMMENT 'compte_rendu, courrier, rapport, plan_action, autre — NULL = non catégorisé',
    titre        VARCHAR(255) NOT NULL,
    description  VARCHAR(255) NULL,
    chemin       VARCHAR(255) NOT NULL,
    taille_ko    INT UNSIGNED NULL,
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pack_id) REFERENCES packs(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Fil d'actualités internes ("Actualités" du Pack Assistance et pages
-- générales), gérées par les administrateurs.
CREATE TABLE IF NOT EXISTS actualites (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    pack_id     INT NULL COMMENT 'NULL = visible pour tous les packs',
    titre       VARCHAR(255) NOT NULL,
    contenu     TEXT NOT NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pack_id) REFERENCES packs(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Catalogue de formations et certifications ("Formations & certifications"
-- du Pack Booster), géré par les administrateurs.
CREATE TABLE IF NOT EXISTS formations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    pack_id      INT NULL COMMENT 'NULL = visible pour tous les packs',
    titre        VARCHAR(255) NOT NULL,
    organisme    VARCHAR(150) NULL,
    duree        VARCHAR(100) NULL,
    lien         VARCHAR(255) NULL,
    description  TEXT NULL,
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pack_id) REFERENCES packs(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Leçons d'une formation (contenu pédagogique interne, optionnel — une
-- formation sans leçon reste un simple lien externe, voir formations.lien).
CREATE TABLE IF NOT EXISTS formation_lecons (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    formation_id   INT NOT NULL,
    titre          VARCHAR(255) NOT NULL,
    description    TEXT NULL,
    ressource_lien VARCHAR(255) NULL COMMENT 'Lien externe vers la ressource de la leçon (vidéo, document...), optionnel',
    ordre          INT NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (formation_id) REFERENCES formations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Inscription d'un consultant à une formation (sert aussi de point
-- d'ancrage à sa progression). Pas de ligne = formation "à commencer".
CREATE TABLE IF NOT EXISTS formation_inscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    formation_id  INT NOT NULL,
    user_id       INT NOT NULL,
    statut        ENUM('en_cours', 'termine') NOT NULL DEFAULT 'en_cours',
    commence_le   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    termine_le    DATETIME NULL,
    UNIQUE KEY uniq_formation_user (formation_id, user_id),
    FOREIGN KEY (formation_id) REFERENCES formations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Leçons marquées terminées par un consultant inscrit — sert au calcul de
-- la progression réelle (leçons terminées / total des leçons).
CREATE TABLE IF NOT EXISTS formation_lecons_terminees (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inscription_id  INT NOT NULL,
    lecon_id        INT NOT NULL,
    termine_le      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_inscription_lecon (inscription_id, lecon_id),
    FOREIGN KEY (inscription_id) REFERENCES formation_inscriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (lecon_id) REFERENCES formation_lecons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Certifications professionnelles externes déclarées par un consultant —
-- jamais vérifiées automatiquement, toujours "en_attente" tant qu'un
-- administrateur ne les a pas validées.
CREATE TABLE IF NOT EXISTS certifications_externes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    nom                 VARCHAR(255) NOT NULL,
    organisme           VARCHAR(150) NULL,
    date_obtention      DATE NOT NULL,
    date_expiration     DATE NULL,
    identifiant         VARCHAR(150) NULL,
    lien_verification   VARCHAR(255) NULL,
    justificatif_chemin VARCHAR(255) NULL,
    justificatif_nom    VARCHAR(255) NULL,
    statut              ENUM('en_attente', 'validee', 'refusee') NOT NULL DEFAULT 'en_attente',
    motif_refus         VARCHAR(255) NULL,
    valide_par          INT NULL,
    valide_le           DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (valide_par) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Historique des tentatives de connexion (réussies et échouées), utilisé à
-- la fois pour verrouiller temporairement un compte après plusieurs échecs
-- et comme journal de sécurité des connexions (section 7 du cahier des
-- charges).
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    ip          VARCHAR(45) NULL,
    reussi      TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_date (email, created_at)
) ENGINE=InnoDB;

-- Journal des messages envoyés au chatbot d'orientation public, utilisé
-- uniquement pour limiter le débit par IP (évite qu'un script, sans cookie
-- de session, ne contourne la limite et ne consomme le quota Groq en
-- boucle) : aucune donnée personnelle, pas le contenu des messages.
CREATE TABLE IF NOT EXISTS chatbot_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chatbot_requests_ip_date (ip, created_at)
) ENGINE=InnoDB;

-- Notifications applicatives (paiement confirmé, changement de statut de
-- dossier, rendez-vous...), affichées via la cloche de notification.
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    type        VARCHAR(50) NOT NULL COMMENT 'paiement_confirme, statut_dossier, rendez_vous...',
    titre       VARCHAR(255) NOT NULL,
    corps       VARCHAR(500) NULL,
    lien        VARCHAR(255) NULL COMMENT 'URL relative vers la ressource concernée',
    lu          TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_lu (user_id, lu)
) ENGINE=InnoDB;

-- Jetons de réinitialisation de mot de passe (liens à usage unique, à durée
-- de vie limitée — voir includes/password-reset.php).
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_password_resets_token (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Évaluations Pack Wellbeing (Stress/Burnout, Gestion du temps, Équilibre
-- vie pro/perso) : un score global calculé et des dimensions détaillées par
-- type d'évaluation et par dossier, saisis par le consultant.
CREATE TABLE IF NOT EXISTS wellbeing_evaluations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id      INT NOT NULL,
    type            ENUM('stress_burnout','gestion_temps','equilibre_vie') NOT NULL,
    score           INT NOT NULL,
    dimensions_json TEXT NOT NULL,
    created_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dossier_type (dossier_id, type),
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Mes opportunités (Pack Classic) — profil de recherche, cache
-- d'analyse IA, offres, candidatures, notifications envoyées.
-- ============================================================

-- Profil de recherche d'emploi/stage/alternance/mission du client, un par
-- dossier Pack Classic (même relation 1:1 que `qualifications`). Les champs
-- multi-valeurs (types, métiers, secteurs, compétences, langues, villes)
-- sont stockés en JSON texte, décodés en tableau PHP par
-- includes/opportunites.php — jamais interrogés en SQL directement.
CREATE TABLE IF NOT EXISTS classic_profils_recherche (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id              INT NOT NULL UNIQUE,
    types_json              TEXT NULL COMMENT 'emploi/stage/alternance/mission, JSON array',
    metiers_json            TEXT NULL COMMENT 'Intitulés de poste visés, JSON array de chaînes',
    secteurs_json           TEXT NULL COMMENT 'Secteurs d''activité visés, JSON array',
    niveau_etudes           VARCHAR(100) NULL,
    annees_experience       SMALLINT UNSIGNED NULL,
    competences_json        TEXT NULL COMMENT 'Tags libres saisis par le client, JSON array',
    langues_json            TEXT NULL COMMENT 'JSON array de {langue, niveau}',
    villes_json             TEXT NULL COMMENT 'Villes/zones de mobilité souhaitées, JSON array',
    mobilite                ENUM('sur_place','regionale','nationale','internationale') NOT NULL DEFAULT 'sur_place',
    teletravail             ENUM('non','partiel','total') NOT NULL DEFAULT 'non',
    disponibilite_le        DATE NULL COMMENT 'Date de disponibilité déclarée par le client',
    cv_document_id          INT NULL COMMENT 'Document CV utilisé pour l''analyse (voir documents, slot=cv)',
    -- Consentements distincts et révocables (section 7 du cahier des charges
    -- : granularité du consentement). Le second concerne la visibilité par
    -- les futurs recruteurs partenaires (portail non construit ici).
    consent_matching        TINYINT(1) NOT NULL DEFAULT 0,
    consent_matching_le     DATETIME NULL,
    consent_recruteurs      TINYINT(1) NOT NULL DEFAULT 0,
    consent_recruteurs_le   DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (cv_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Cache de l'analyse IA (ou de repli sans IA) du profil + CV. Recalculée
-- uniquement quand profil_hash ou cv_hash change (voir
-- opportunites_analyser_profil()) — jamais à chaque affichage de page.
CREATE TABLE IF NOT EXISTS classic_analyses_ia_opportunites (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id                INT NOT NULL UNIQUE,
    profil_hash               VARCHAR(64) NOT NULL COMMENT 'sha256 du profil de recherche normalisé',
    cv_hash                   VARCHAR(64) NULL COMMENT 'sha256 du texte du CV utilisé, NULL si aucun CV',
    source                    ENUM('ia','secours') NOT NULL DEFAULT 'secours' COMMENT 'ia = réponse Anthropic exploitée, secours = extraction de mots-clés sans IA',
    mots_cles_fr_json         TEXT NULL,
    mots_cles_en_json         TEXT NULL,
    intitules_proches_json    TEXT NULL,
    competences_fortes_json   TEXT NULL,
    points_amelioration_json  TEXT NULL,
    conseils_candidature_json TEXT NULL COMMENT 'Jusqu''à 3 conseils, JSON array',
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- lettre_hash : ajouté après coup pour que l'analyse IA tienne aussi compte
-- de la lettre de motivation réelle du client (pas seulement le CV) et se
-- recalcule si elle change — colonne ajoutée via ALTER (pas dans le CREATE
-- TABLE ci-dessus) pour rester rejouable sans erreur sur une base déjà
-- initialisée. Même principe que fk_users_pack plus haut : on évite
-- information_schema (crash MariaDB reproductible) et on avale l'erreur
-- 1060 (ER_DUP_FIELDNAME, colonne déjà existante) via une procédure jetable.
DROP PROCEDURE IF EXISTS nx_migration_lettre_hash;
DELIMITER $$
CREATE PROCEDURE nx_migration_lettre_hash()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE classic_analyses_ia_opportunites ADD COLUMN lettre_hash VARCHAR(64) NULL COMMENT 'sha256 du texte de la lettre de motivation utilisée, NULL si aucune' AFTER cv_hash;
END$$
DELIMITER ;
CALL nx_migration_lettre_hash();
DROP PROCEDURE nx_migration_lettre_hash;

-- admin_permissions_json : NULL = l'administrateur a accès à toutes les
-- sections de l'espace admin (comportement historique, rien ne change pour
-- les comptes existants) ; un tableau JSON de clés de section ("dossiers",
-- "paiements", ...) restreint l'accès à celles-ci seulement. Ne concerne
-- jamais un super_admin, qui a toujours accès à tout. Même motif que les
-- deux migrations ci-dessus (évite information_schema, avale l'erreur 1060
-- si la colonne existe déjà).
DROP PROCEDURE IF EXISTS nx_migration_admin_permissions;
DELIMITER $$
CREATE PROCEDURE nx_migration_admin_permissions()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE users ADD COLUMN admin_permissions_json TEXT NULL COMMENT 'Tableau JSON des sections admin autorisées ; NULL = toutes (voir includes/auth.php : admin_a_permission())' AFTER pack_id;
END$$
DELIMITER ;
CALL nx_migration_admin_permissions();
DROP PROCEDURE nx_migration_admin_permissions;

-- Offres d'emploi/stage/alternance/mission. source/recruiter_id préparent le
-- futur portail recruteur (non construit ici) sans qu'aucune UI recruteur
-- n'existe encore — recruiter_id référence users(id) mais aucun rôle
-- 'recruteur' n'existe à ce jour dans users.role (hors périmètre).
CREATE TABLE IF NOT EXISTS opportunites (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    source                    ENUM('admin','api_partner','recruteur') NOT NULL DEFAULT 'admin',
    recruiter_id              INT NULL COMMENT 'Préparation du futur portail recruteur — inutilisé pour l''instant',
    titre                     VARCHAR(190) NOT NULL,
    type                      ENUM('emploi','stage','alternance','mission') NOT NULL,
    entreprise                VARCHAR(190) NULL COMMENT 'NULL = entreprise non communiquée (recrutement confidentiel)',
    secteur                   VARCHAR(100) NULL,
    ville                     VARCHAR(100) NULL,
    pays                      VARCHAR(100) NULL,
    teletravail               ENUM('non','partiel','total') NOT NULL DEFAULT 'non',
    niveau_etudes_min         VARCHAR(100) NULL,
    experience_min_annees     SMALLINT UNSIGNED NULL,
    competences_requises_json TEXT NULL COMMENT 'JSON array de compétences/mots-clés attendus',
    description               TEXT NOT NULL,
    lien_candidature          VARCHAR(500) NULL COMMENT 'URL externe de candidature, jamais une page NexRaiz',
    email_candidature         VARCHAR(190) NULL,
    salaire_indicatif         VARCHAR(100) NULL,
    date_limite               DATE NULL,
    statut                    ENUM('brouillon','publie','expire','depublie') NOT NULL DEFAULT 'brouillon',
    source_reference          VARCHAR(190) NULL COMMENT 'Identifiant chez le fournisseur externe, pour dédoublonnage (source=api_partner)',
    created_by                INT NULL,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (recruiter_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_opportunites_source_reference (source, source_reference),
    INDEX idx_opportunites_statut_date_limite (statut, date_limite),
    INDEX idx_opportunites_type_ville (type, ville)
) ENGINE=InnoDB;

-- Interactions + suivi de candidature du client sur une offre : une ligne
-- lazy-créée au premier "enregistrer"/"masquer"/"j'ai postulé". Le statut
-- de suivi (postule/entretien/refus/acceptee) est distinct des indicateurs
-- enregistre/masquee : masquer une offre n'efface jamais un suivi déjà en cours.
CREATE TABLE IF NOT EXISTS opportunites_candidatures (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    opportunite_id  INT NOT NULL,
    dossier_id      INT NOT NULL,
    enregistre      TINYINT(1) NOT NULL DEFAULT 0,
    enregistre_le   DATETIME NULL,
    masquee         TINYINT(1) NOT NULL DEFAULT 0,
    masquee_motif   VARCHAR(255) NULL,
    masquee_le      DATETIME NULL,
    statut          ENUM('non_postule','postule','entretien','refus','acceptee') NOT NULL DEFAULT 'non_postule',
    statut_le       DATE NULL,
    notes           TEXT NULL COMMENT 'Notes libres du client sur sa candidature',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_opportunite_dossier (opportunite_id, dossier_id),
    FOREIGN KEY (opportunite_id) REFERENCES opportunites(id) ON DELETE CASCADE,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Dédoublonnage des notifications (une seule fois par (offre, client, type)),
-- tenu par le cron et par la publication manuelle d'une offre.
CREATE TABLE IF NOT EXISTS opportunites_notifications_envoyees (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    opportunite_id  INT NOT NULL,
    dossier_id      INT NOT NULL,
    type            ENUM('nouvelle_offre','rappel_echeance') NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notif_offre_dossier_type (opportunite_id, dossier_id, type),
    FOREIGN KEY (opportunite_id) REFERENCES opportunites(id) ON DELETE CASCADE,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Suivi de lecture (pixel invisible) des emails de candidature envoyés via
-- « Postuler via NexRaiz ». `vu_at` reste NULL tant que le pixel n'a jamais
-- été chargé — indicatif seulement (beaucoup de clients mail bloquent les
-- images par défaut), jamais présenté comme une preuve de non-lecture.
CREATE TABLE IF NOT EXISTS opportunites_candidatures_suivi (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    opportunite_id  INT NOT NULL,
    dossier_id      INT NOT NULL,
    token           VARCHAR(64) NOT NULL,
    envoye_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vu_at           DATETIME NULL,
    UNIQUE KEY uniq_suivi_token (token),
    FOREIGN KEY (opportunite_id) REFERENCES opportunites(id) ON DELETE CASCADE,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Jetons des appareils/navigateurs abonnés aux notifications push (web et
-- app Android). Un jeton est unique tous utilisateurs confondus : s'il
-- change de propriétaire (déconnexion/reconnexion sur un appareil partagé),
-- l'upsert applicatif le réattribue plutôt que d'en garder deux lignes.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(255) NOT NULL,
    platform    ENUM('web','android') NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_push_token (token),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_push_user (user_id)
) ENGINE=InnoDB;

-- Cache du jeton d'accès Google (compte de service Firebase) pour l'envoi
-- de notifications push — une seule ligne, réécrite à chaque renouvellement,
-- pour éviter de re-signer un JWT à chaque notification envoyée.
CREATE TABLE IF NOT EXISTS fcm_token_cache (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    access_token  TEXT NOT NULL,
    expires_at    DATETIME NOT NULL
) ENGINE=InnoDB;

-- Comptes Google connectés par les utilisateurs (candidats) pour envoyer
-- leurs candidatures depuis leur propre Gmail plutôt que depuis l'adresse
-- technique de NexRaiz. Un seul compte par utilisateur ; se reconnecter
-- écrase la ligne existante.
CREATE TABLE IF NOT EXISTS google_comptes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    google_email   VARCHAR(190) NOT NULL,
    access_token   TEXT NOT NULL,
    refresh_token  TEXT NOT NULL,
    expires_at     DATETIME NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_google_comptes_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Les 5 offres [DÉMO] fictives qui occupaient auparavant cet espace ont été
-- retirées (voir docs/opportunites.md) : un client les prenait pour de
-- vraies offres, et une entreprise/offre inventée n'a par définition pas de
-- vrai lien de candidature qui fonctionne. Nettoyage pour les bases déjà
-- initialisées avant ce changement — sans effet sur une base neuve :
DELETE FROM opportunites WHERE source_reference IN ('demo-1', 'demo-2', 'demo-3', 'demo-4', 'demo-5');

-- Aucune offre n'est plus auto-seedée ici. La seule source légale de vraies
-- offres étant la saisie manuelle par un consultant (voir note en tête de
-- ce fichier et docs/opportunites.md), les offres réelles se créent et se
-- maintiennent exclusivement via `opportunites-admin.php` — jamais par un
-- script rejouable, pour éviter qu'une offre réelle mais expirée ne soit
-- réinsérée comme si elle était toujours d'actualité.

-- ============================================================
-- CV Builder structuré par étapes (Pack Classic) — 3e outil d'édition du
-- CV, strictement additif à `corrections` (atelier/Studio, texte libre) et
-- `canvas_editions` (éditeur visuel qui patche le PDF original) : ni l'un
-- ni l'autre n'est modifié ou lu par ce qui suit. Le PDF produit ici
-- devient simplement la version courante du même livrable « CV corrigé »
-- (voir includes/livrables-classic.php), exactement comme Studio et
-- Canvas le font déjà entre eux.
-- ============================================================

-- Une ligne par dossier : choix de modèle (fait AVANT de commencer),
-- infos personnelles (étape 1), "ce que tu cherches" (étape 2),
-- compétences/langues (étape 5, JSON — simples tags sans édition
-- individuelle, même convention que classic_profils_recherche.competences_json/
-- langues_json). Expériences et formations (étapes 3 et 4) sont des
-- entrées datées avec leur propre cycle ajouter/modifier/supprimer : elles
-- vivent dans deux tables normalisées séparées, pas en JSON ici.
CREATE TABLE IF NOT EXISTS classic_cv_builder (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id        INT NOT NULL UNIQUE,
    -- 0 = choix du modèle pas encore fait (écran de choix pas franchi) ;
    -- 1 à 6 = dernière étape atteinte. Totalement indépendant de
    -- dossiers.current_step (onboarding) — jamais le même compteur, jamais
    -- utilisé pour calculer la progression de l'onboarding.
    etape_courante    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    layout            ENUM('avec_photo', 'sans_photo') NULL,
    couleur_accent    VARCHAR(20) NULL COMMENT 'Clé de la palette fixe (cv_builder_palette_couleurs() dans includes/cv-builder-classic.php), jamais une valeur hex libre saisie par le consultant',
    photo_document_id INT NULL COMMENT 'Document du slot "photo" utilisé si layout=avec_photo (voir documents / classic_slots_documents())',
    titre_poste       VARCHAR(190) NULL COMMENT 'Intitulé affiché sous le nom sur le CV (ex. "Chef de projet digital")',
    ville             VARCHAR(100) NULL,
    telephone_affiche VARCHAR(30) NULL,
    email_affiche     VARCHAR(190) NULL,
    resume_profil     TEXT NULL COMMENT 'Pitch/accroche affiché en tête de CV',
    poste_recherche   VARCHAR(190) NULL,
    type_contrat      VARCHAR(100) NULL,
    secteur_vise      VARCHAR(190) NULL,
    mobilite          VARCHAR(100) NULL,
    disponibilite     VARCHAR(100) NULL,
    competences_json  TEXT NULL COMMENT 'JSON array de {libelle, niveau}',
    langues_json      TEXT NULL COMMENT 'JSON array de {langue, niveau}',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Étape 3 : expériences professionnelles — table normalisée (pas de JSON)
-- car chaque entrée a son propre cycle ajouter/modifier/supprimer avec un
-- ID stable référencé par les formulaires.
CREATE TABLE IF NOT EXISTS classic_cv_builder_experiences (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id   INT NOT NULL,
    poste        VARCHAR(190) NOT NULL,
    entreprise   VARCHAR(190) NULL,
    lieu         VARCHAR(100) NULL,
    date_debut   DATE NULL,
    date_fin     DATE NULL COMMENT 'NULL si poste_actuel = 1',
    poste_actuel TINYINT(1) NOT NULL DEFAULT 0,
    description  TEXT NULL,
    ordre        INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Étape 4 : formations — même logique que les expériences.
CREATE TABLE IF NOT EXISTS classic_cv_builder_formations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id    INT NOT NULL,
    diplome       VARCHAR(190) NOT NULL,
    etablissement VARCHAR(190) NULL,
    lieu          VARCHAR(100) NULL,
    date_debut    DATE NULL,
    date_fin      DATE NULL,
    description   TEXT NULL,
    ordre         INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
