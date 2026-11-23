<?php
/**
 * NexRaiz — Tâche planifiée « Opportunités » (Pack Classic).
 * Script CLI autonome, sans dépendance à $_SERVER/session. À exécuter
 * toutes les heures, par exemple via cPanel :
 *   php /chemin/vers/nexraiz_phase/cron/opportunites-cron.php
 *
 * Ordre des opérations : expiration des offres échues, import connecteur
 * (si JOB_API_ENABLED=1), notification des nouvelles offres au-dessus du
 * seuil, rappel d'échéance à J-3.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut être exécuté qu\'en ligne de commande.');
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/opportunites.php';

$debut = microtime(true);
$resume = [];

$resume['offres_expirees'] = opportunites_expirer_echues();

$config = opportunites_config();
if (!empty($config['job_api']['enabled'])) {
    $import = job_api_importer_executer();
    $resume['import_nouvelles'] = $import['nouvelles'];
    $resume['import_doublons'] = $import['ignorees_doublons'];
} else {
    $resume['import'] = 'desactive';
}

$resume['notifications_nouvelles_offres'] = opportunites_notifier_nouvelles_offres($config['seuil_notification_score']);
$resume['notifications_rappel_echeance'] = opportunites_notifier_echeances_proches(3);

$duree = round(microtime(true) - $debut, 2);
error_log('[opportunites-cron] ' . json_encode($resume, JSON_UNESCAPED_UNICODE) . " (durée: {$duree}s)");

echo "Opportunités — exécution terminée en {$duree}s :\n";
foreach ($resume as $cle => $valeur) {
    echo "  - {$cle} : {$valeur}\n";
}
