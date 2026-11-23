<?php
/**
 * NexRaiz — Amorce des tests « Opportunités ». Script autonome (pas de
 * PHPUnit : aucune dépendance de dev n'existe dans ce projet). Ouvre une
 * transaction PDO jamais commitée : toutes les données créées par les
 * tests disparaissent au rollback de fin de script, sans toucher aux
 * données réelles.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/opportunites.php';

db()->beginTransaction();
register_shutdown_function(function () {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
});

$GLOBALS['__tests_resultats'] = [];

function test_cas(string $libelle, bool $condition): void
{
    $GLOBALS['__tests_resultats'][] = ['libelle' => $libelle, 'ok' => $condition];
}

/** Crée un utilisateur client + dossier Pack Classic isolés, pour un test. */
function test_creer_dossier_classic(): int
{
    static $compteur = 0;
    $compteur++;
    $packId = get_pack_id('classic');
    $suffixe = $compteur . '-' . bin2hex(random_bytes(4));

    $stmt = db()->prepare('INSERT INTO users (role, nom, prenom, email, password_hash) VALUES ("client", "Test", "Opportunites", ?, "x")');
    $stmt->execute(["test-opportunites-{$suffixe}@example.test"]);
    $userId = (int) db()->lastInsertId();

    $stmt = db()->prepare('INSERT INTO dossiers (numero, user_id, pack_id) VALUES (?, ?, ?)');
    $stmt->execute(["TEST-{$suffixe}", $userId, $packId]);
    return (int) db()->lastInsertId();
}

/** Crée une ligne `documents` factice (slot CV), pour simuler un CV déjà déposé/analysé. */
function test_creer_document_cv(int $dossierId): int
{
    $stmt = db()->prepare("INSERT INTO documents (dossier_id, nom_original, categorie, chemin) VALUES (?, 'cv_test.pdf', 'cv', 'prive:test/cv_test.pdf')");
    $stmt->execute([$dossierId]);
    return (int) db()->lastInsertId();
}

/** Crée (une seule fois par run) un consultant de test, pour servir de created_by. */
function test_creer_consultant(): int
{
    static $id = null;
    if ($id === null) {
        $suffixe = bin2hex(random_bytes(4));
        $stmt = db()->prepare('INSERT INTO users (role, nom, prenom, email, password_hash) VALUES ("consultant", "Test", "Consultant", ?, "x")');
        $stmt->execute(["test-consultant-{$suffixe}@example.test"]);
        $id = (int) db()->lastInsertId();
    }
    return $id;
}

/** Crée une offre publiée, avec des champs par défaut raisonnables surchargeables. */
function test_creer_offre(array $champs = []): int
{
    $d = array_merge([
        'titre' => 'Assistant RH', 'type' => 'emploi', 'entreprise' => null, 'secteur' => null,
        'ville' => 'Abidjan', 'pays' => "Côte d'Ivoire", 'teletravail' => 'non', 'niveau_etudes_min' => 'BAC+2',
        'experience_min_annees' => 1, 'competences_requises' => ['recrutement', 'excel'],
        'description' => 'Offre de test.', 'lien_candidature' => null, 'email_candidature' => null,
        'salaire_indicatif' => null, 'date_limite' => null,
    ], $champs);
    $resultat = opportunites_admin_creer($d, test_creer_consultant());
    if (!empty($resultat['erreurs'])) {
        throw new RuntimeException('Création offre de test invalide : ' . implode(' | ', $resultat['erreurs']));
    }
    opportunites_admin_publier($resultat['id']);
    return $resultat['id'];
}
