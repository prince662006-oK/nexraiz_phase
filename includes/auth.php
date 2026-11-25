<?php
/**
 * NexRaiz — Authentification et gestion des dossiers client.
 * Session : $_SESSION['user'] (id, role, nom, prenom, email)
 *           $_SESSION['dossiers'][$packSlug] = dossier_id en cours pour ce pack
 */
require_once __DIR__ . '/db.php';

const NX_SESSION_IDLE_TIMEOUT_SEC = 1800; // Déconnexion après 30 min d'inactivité
const NX_LOGIN_MAX_ATTEMPTS = 5;
const NX_LOGIN_LOCKOUT_WINDOW_MIN = 15;

function nx_is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function nx_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Cookie de session : secure uniquement si la requête est déjà en
        // HTTPS (pour ne pas casser le développement local en HTTP sur
        // XAMPP), toujours httponly (inaccessible en JS) et samesite=Lax.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => nx_is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    nx_enforce_session_timeout();
}

/**
 * Force HTTPS en production. N'a d'effet que si la variable d'environnement
 * NEXRAIZ_FORCE_HTTPS est définie (désactivé par défaut pour ne pas casser
 * le développement local XAMPP en HTTP) — à activer sur le serveur de
 * production une fois le certificat TLS en place (section 7 du cahier des
 * charges : "utilisation obligatoire du protocole HTTPS").
 */
function nx_enforce_https(): void
{
    if (!getenv('NEXRAIZ_FORCE_HTTPS') || nx_is_https_request() || PHP_SAPI === 'cli') {
        return;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

/**
 * Ferme la session si elle est restée inactive plus de
 * NX_SESSION_IDLE_TIMEOUT_SEC (mesure de sécurité section 7 du cahier des
 * charges). Sinon, met à jour l'horodatage de dernière activité.
 */
function nx_enforce_session_timeout(): void
{
    if (!empty($_SESSION['user'])) {
        $derniereActivite = $_SESSION['_last_activity'] ?? time();
        if ((time() - $derniereActivite) > NX_SESSION_IDLE_TIMEOUT_SEC) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]);
            }
            session_destroy();
            session_start();
            return;
        }
    }
    $_SESSION['_last_activity'] = time();
}

function current_user(): ?array
{
    nx_session_start();
    return $_SESSION['user'] ?? null;
}

/**
 * Nombre de messages internes non lus reçus par un membre de l'équipe
 * (consultant, admin, super admin), tous expéditeurs confondus — utilisé
 * pour le badge "Messages internes" affiché dans chaque sidebar.
 */
function messages_internes_non_lus(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM messages_internes WHERE destinataire_id = ? AND lu = 0');
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

function require_login(string $redirectTo): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . $redirectTo);
        exit;
    }
    return $user;
}

/**
 * Comme require_login(), mais exige en plus que le rôle du compte fasse
 * partie de $roles (ex. ['consultant', 'admin']). Utilisé pour protéger
 * les espaces internes NexRaiz de l'accès par un compte client.
 */
function require_role(array $roles, string $redirectTo): array
{
    $user = require_login($redirectTo);
    // Le super-administrateur a accès à tout l'espace interne (consultant + admin),
    // quel que soit le pack demandé : il n'a pas besoin d'être listé explicitement
    // dans chaque appel à require_role() à travers le projet.
    if ($user['role'] === 'super_admin') {
        return $user;
    }
    if (!in_array($user['role'], $roles, true)) {
        header('Location: ' . $redirectTo);
        exit;
    }
    return $user;
}

/**
 * Sections restreignables de l'espace admin (voir render_admin_sidebar()
 * dans includes/consultant-sidebar-router.php, qui utilise la même liste
 * de clés). "dashboard" et "utilisateurs" ne sont volontairement pas
 * restreignables : un admin doit toujours pouvoir voir le tableau de bord
 * global et gérer les accès, pour ne jamais se retrouver bloqué de partout
 * sans porte de sortie.
 */
const ADMIN_SECTIONS_RESTREIGNABLES = ['dossiers', 'paiements', 'rendezvous', 'ressources', 'formations', 'modeles', 'actualites'];

/**
 * Un super_admin a toujours accès à tout. Un admin a accès à tout tant que
 * users.admin_permissions_json est NULL (comportement historique, par
 * défaut pour tous les comptes existants) ; sinon, seulement aux sections
 * listées dans ce tableau JSON. Lu en base à chaque appel (pas depuis la
 * session, qui ne contient qu'un instantané limité posé à la connexion —
 * voir login_user()) : une restriction posée par un super_admin doit
 * s'appliquer immédiatement, sans attendre une reconnexion.
 */
function admin_a_permission(array $user, string $section): bool
{
    if ($user['role'] === 'super_admin') {
        return true;
    }
    $stmt = db()->prepare('SELECT admin_permissions_json FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $json = $stmt->fetchColumn();
    if ($json === null || $json === false) {
        return true;
    }
    $permissions = json_decode($json, true);
    return is_array($permissions) && in_array($section, $permissions, true);
}

/**
 * Comme require_role(), en plus restreint : exige que le compte ait accès
 * à la section $section de l'espace admin (voir admin_a_permission()).
 * À appeler juste après require_role(['admin', 'super_admin'], ...).
 */
function require_permission_section(array $user, string $section, string $redirectTo): void
{
    if (!admin_a_permission($user, $section)) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

function logout(): void
{
    nx_session_start();
    $_SESSION = [];

    // Supprime aussi le cookie de session côté navigateur : sans ça, le
    // cookie PHPSESSID reste envoyé par le navigateur (bien qu'invalide côté
    // serveur) et certains navigateurs peuvent réafficher une page mise en
    // cache localement où l'utilisateur apparaissait encore connecté.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Crée un compte client.
 * @param bool $connecter Si true (comportement historique), connecte
 * immédiatement la session courante sur ce nouveau compte — correct pour une
 * auto-inscription candidat. Si false, ne touche pas à la session en cours :
 * obligatoire quand c'est un consultant/admin qui crée le compte pour un
 * tiers (pages "Nouveau dossier", import CSV) — sinon le consultant se
 * retrouvait déconnecté de son propre compte et reconnecté sous celui du
 * client qu'il venait de créer.
 * @throws RuntimeException si l'email existe déjà.
 */
function register_client(string $nom, string $prenom, string $email, string $telephone, string $password, bool $connecter = true): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Un compte existe déjà avec cet email.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (role, nom, prenom, email, telephone, password_hash) VALUES ("client", ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$nom, $prenom, $email, $telephone, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();

    $user = ['id' => $userId, 'role' => 'client', 'nom' => $nom, 'prenom' => $prenom, 'email' => $email];
    if ($connecter) {
        login_user($user);
    }
    return $user;
}

function login_user(array $user): void
{
    nx_session_start();
    // Régénère l'identifiant de session à la connexion pour empêcher toute
    // fixation de session (un identifiant émis avant l'authentification ne
    // doit jamais devenir une session authentifiée).
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['_last_activity'] = time();
}

/**
 * Redirige un utilisateur déjà authentifié vers l'espace qui lui correspond
 * (tableau de bord consultant/admin selon son pack de rattachement, ou
 * l'étape courante de son dossier actif pour un client) et arrête
 * l'exécution. Utilisée juste après la connexion, mais aussi dès qu'on
 * détecte une session déjà active (ex. visite de mon-espace.php) pour ne
 * jamais renvoyer un utilisateur connecté vers l'écran de connexion.
 */
function redirect_to_dashboard(array $user): void
{
    if ($user['role'] === 'super_admin') {
        header('Location: admin-dashboard.php');
        exit;
    }

    if ($user['role'] === 'consultant' || $user['role'] === 'admin') {
        $tableauxParPack = [
            'classic'     => 'consultant-tableau-bord-classic.php',
            'assistance'  => 'consultant-diagnostic.php',
            'booster'     => 'consultant-dossiers-booster.php',
            'wellbeing'   => 'consultant-dossiers-wellbeing.php',
        ];
        $packSlug = null;
        if (!empty($user['pack_id'])) {
            $stmt = db()->prepare('SELECT slug FROM packs WHERE id = ?');
            $stmt->execute([$user['pack_id']]);
            $packSlug = $stmt->fetchColumn() ?: null;
        }
        header('Location: ' . ($tableauxParPack[$packSlug] ?? 'consultant-tableau-bord-classic.php'));
        exit;
    }

    // Un client peut avoir un dossier actif dans plusieurs packs à la fois
    // (voir client_dossier_existant()) : on charge TOUS ses dossiers actifs
    // en session (pas seulement le plus récent) pour que require_dossier()
    // les retrouve directement sur chaque pack, sans repasser par la page
    // d'inscription de ce pack. La redirection initiale, elle, reste le
    // dossier le plus récemment mis à jour.
    $stmt = db()->prepare(
        "SELECT d.id, d.current_step, p.slug FROM dossiers d
         JOIN packs p ON p.id = d.pack_id
         WHERE d.user_id = ? AND d.statut != 'cloture'
         ORDER BY d.updated_at DESC"
    );
    $stmt->execute([$user['id']]);
    $dossiersActifs = $stmt->fetchAll();

    if ($dossiersActifs) {
        nx_session_start();
        foreach ($dossiersActifs as $d) {
            $_SESSION['dossiers'][$d['slug']] = (int) $d['id'];
        }
        $dossierActif = $dossiersActifs[0];
        header('Location: ' . step_url((int) $dossierActif['current_step'], $dossierActif['slug']));
    } else {
        header('Location: index.php');
    }
    exit;
}

/**
 * IP réelle du visiteur. Derrière le tunnel ngrok (ou tout reverse proxy),
 * REMOTE_ADDR est celle du proxy local, pas celle du visiteur : on préfère
 * donc X-Forwarded-For quand il est présent (ngrok l'ajoute toujours), en ne
 * gardant que la première IP de la liste (la plus proche du visiteur
 * d'origine). Ce choix suppose un unique proxy de confiance devant le
 * serveur (notre cas) ; un en-tête falsifié par un client direct n'aurait
 * d'effet que sur sa propre limite de débit, jamais sur celle d'un tiers.
 */
function nx_client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $premiere = trim(explode(',', $xff)[0]);
        if (filter_var($premiere, FILTER_VALIDATE_IP)) {
            return $premiere;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
}

/**
 * Nombre de tentatives échouées récentes pour cet email, tous IP confondus,
 * sur la fenêtre NX_LOGIN_LOCKOUT_WINDOW_MIN.
 */
function nx_echecs_recents(string $email): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND reussi = 0 AND created_at > (NOW() - INTERVAL ' . NX_LOGIN_LOCKOUT_WINDOW_MIN . ' MINUTE)'
    );
    $stmt->execute([$email]);
    return (int) $stmt->fetchColumn();
}

function nx_enregistrer_tentative_connexion(string $email, bool $reussi): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip, reussi) VALUES (?, ?, ?)');
    $stmt->execute([$email, $ip, $reussi ? 1 : 0]);
}

/**
 * @return array|null Le user (id, role, pack_id, nom, prenom, email) si les identifiants sont valides.
 */
/**
 * @throws RuntimeException si le compte est temporairement verrouillé après
 * trop de tentatives échouées, ou si le compte existe, que le mot de passe
 * est correct, mais que le compte a été désactivé par un administrateur.
 */
function attempt_login(string $email, string $password): ?array
{
    if (nx_echecs_recents($email) >= NX_LOGIN_MAX_ATTEMPTS) {
        throw new RuntimeException(
            'Trop de tentatives de connexion pour cet email. Merci de réessayer dans ' . NX_LOGIN_LOCKOUT_WINDOW_MIN . ' minutes, ou de réinitialiser votre mot de passe.'
        );
    }

    $stmt = db()->prepare('SELECT id, role, pack_id, actif, nom, prenom, email, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    $motDePasseValide = $row && password_verify($password, $row['password_hash']);
    nx_enregistrer_tentative_connexion($email, $motDePasseValide);

    if (!$motDePasseValide) {
        return null;
    }
    if (!$row['actif']) {
        throw new RuntimeException('Ce compte a été désactivé. Contactez un administrateur NexRaiz.');
    }
    unset($row['password_hash']);
    return $row;
}

function get_pack_id(string $packSlug): ?int
{
    $stmt = db()->prepare('SELECT id FROM packs WHERE slug = ?');
    $stmt->execute([$packSlug]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function generate_dossier_numero(string $packSlug): string
{
    $prefixes = ['assistance' => 'PA', 'booster' => 'PB', 'classic' => 'PC', 'wellbeing' => 'PW', 'particulier' => 'PP'];
    $prefix = $prefixes[$packSlug] ?? 'NX';
    return sprintf('%s-%s-%04d', $prefix, date('Y'), random_int(1, 9999));
}

/**
 * Dossier actif existant d'un client pour un pack donné, sans en créer un
 * nouveau — utilisé pour décider, à l'arrivée sur la page d'inscription
 * d'un pack, si un client déjà connecté a déjà souscrit à ce pack (auquel
 * cas on le redirige directement) ou doit se voir proposer de l'ajouter à
 * son compte existant (voir client_dossier_existant() dans les pages
 * {pack}-espace-client.php / classic-espace-candidat.php).
 */
function client_dossier_existant(int $userId, string $packSlug): ?array
{
    $packId = get_pack_id($packSlug);
    if (!$packId) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT * FROM dossiers WHERE user_id = ? AND pack_id = ? AND statut != 'cloture' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId, $packId]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupère le dossier "en cours" du client pour ce pack, ou en crée un nouveau.
 */
function get_or_create_dossier(int $userId, string $packSlug): array
{
    $packId = get_pack_id($packSlug);
    if (!$packId) {
        throw new RuntimeException("Pack inconnu : {$packSlug}");
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM dossiers WHERE user_id = ? AND pack_id = ? AND statut != 'cloture' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId, $packId]);
    $dossier = $stmt->fetch();
    if ($dossier) {
        nx_session_start();
        $_SESSION['dossiers'][$packSlug] = (int) $dossier['id'];
        return $dossier;
    }

    $numero = generate_dossier_numero($packSlug);
    $stmt = $pdo->prepare('INSERT INTO dossiers (numero, user_id, pack_id, current_step) VALUES (?, ?, ?, 2)');
    $stmt->execute([$numero, $userId, $packId]);
    $dossierId = (int) $pdo->lastInsertId();

    log_activite($dossierId, 'Dossier créé');

    nx_session_start();
    $_SESSION['dossiers'][$packSlug] = $dossierId;

    $stmt = $pdo->prepare('SELECT * FROM dossiers WHERE id = ?');
    $stmt->execute([$dossierId]);
    return $stmt->fetch();
}

/**
 * Récupère le dossier actif pour ce pack depuis la session, redirige vers
 * l'inscription si aucun dossier n'est en cours.
 */
function require_dossier(string $packSlug, string $redirectTo): array
{
    $user = require_login($redirectTo);
    nx_session_start();
    $dossierId = $_SESSION['dossiers'][$packSlug] ?? null;

    $pdo = db();
    if ($dossierId) {
        $stmt = $pdo->prepare('SELECT * FROM dossiers WHERE id = ? AND user_id = ?');
        $stmt->execute([$dossierId, $user['id']]);
        $dossier = $stmt->fetch();
        if ($dossier) {
            return $dossier;
        }
    }

    header('Location: ' . $redirectTo);
    exit;
}

function update_dossier_step(int $dossierId, int $step): void
{
    $stmt = db()->prepare('UPDATE dossiers SET current_step = GREATEST(current_step, ?) WHERE id = ?');
    $stmt->execute([$step, $dossierId]);
}

function log_activite(int $dossierId, string $texte): void
{
    $stmt = db()->prepare('INSERT INTO activites (dossier_id, texte) VALUES (?, ?)');
    $stmt->execute([$dossierId, $texte]);
}
