<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-oauth.php';

$user = require_login('mon-espace.php');

if (!google_oauth_configure()) {
    header('Location: parametres.php?google=indisponible');
    exit;
}

header('Location: ' . google_oauth_url((int) $user['id']));
exit;
