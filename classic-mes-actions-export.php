<?php
/**
 * NexRaiz — Export CSV de la liste « Mes actions » (Pack Classic), avec les
 * mêmes filtres que la page. Toujours scopé au dossier du client connecté
 * via require_dossier() — jamais un autre identifiant de dossier.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$dossier = require_dossier('classic', 'classic-espace-candidat.php');
$dossierId = (int) $dossier['id'];

$stmt = db()->prepare('SELECT * FROM actions_plan WHERE dossier_id = ? ORDER BY (echeance IS NULL), echeance ASC, id DESC');
$stmt->execute([$dossierId]);
$toutesActions = $stmt->fetchAll();

$aujourdhui = strtotime('today');
foreach ($toutesActions as &$a) {
    $a['en_retard'] = in_array($a['statut'], ['a_faire', 'en_cours', 'a_venir'], true) && $a['echeance'] && strtotime($a['echeance']) < $aujourdhui;
}
unset($a);

$filtre = $_GET['filtre'] ?? '';
$prioriteFiltre = $_GET['priorite'] ?? '';
$echeanceFiltre = $_GET['echeance'] ?? '';
$recherche = trim($_GET['q'] ?? '');

$actionsExport = array_values(array_filter($toutesActions, function ($a) use ($filtre, $prioriteFiltre, $echeanceFiltre, $recherche) {
    if ($filtre === 'en_retard' && !$a['en_retard']) return false;
    if ($filtre !== '' && $filtre !== 'en_retard' && $a['statut'] !== $filtre) return false;
    if ($prioriteFiltre !== '' && $a['priorite'] !== $prioriteFiltre) return false;
    if ($echeanceFiltre === 'sans_echeance' && $a['echeance']) return false;
    if ($echeanceFiltre === 'avec_echeance' && !$a['echeance']) return false;
    if ($recherche !== '' && stripos($a['titre'] . ' ' . ($a['description'] ?? ''), $recherche) === false) return false;
    return true;
}));

$statutLabels = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'a_venir' => 'Prévu', 'a_verifier' => 'En attente de validation', 'termine' => 'Terminée'];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mes-actions-' . $dossier['numero'] . '.csv"');
$sortie = fopen('php://output', 'w');
fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8, pour un affichage correct des accents dans Excel.
fputcsv($sortie, ['Titre', 'Description', 'Statut', 'Priorité', 'Échéance', 'En retard'], ';');
foreach ($actionsExport as $a) {
    fputcsv($sortie, [
        $a['titre'],
        $a['description'] ?? '',
        $statutLabels[$a['statut']] ?? $a['statut'],
        $a['priorite'] === 'haute' ? 'Haute' : 'Normale',
        $a['echeance'] ? date('d/m/Y', strtotime($a['echeance'])) : 'Sans échéance',
        $a['en_retard'] ? 'Oui' : 'Non',
    ], ';');
}
fclose($sortie);
exit;
