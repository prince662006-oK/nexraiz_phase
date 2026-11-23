<?php
/**
 * NexRaiz — Connexion base de données (PDO / MySQL).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('NEXRAIZ_DB_HOST') ?: '127.0.0.1';
        $name = getenv('NEXRAIZ_DB_NAME') ?: 'nexraiz';
        $user = getenv('NEXRAIZ_DB_USER') ?: 'root';
        $pass = getenv('NEXRAIZ_DB_PASS') ?: '';

        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
