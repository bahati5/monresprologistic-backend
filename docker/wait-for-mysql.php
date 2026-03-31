<?php

/**
 * Attend que MySQL accepte les connexions (utile au démarrage Docker).
 * Variables : DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_DATABASE (optionnel).
 */

$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$db = getenv('DB_DATABASE') ?: null;

$maxAttempts = (int) (getenv('DB_WAIT_ATTEMPTS') ?: 45);
$sleepSeconds = (int) (getenv('DB_WAIT_SLEEP') ?: 2);

$dsn = "mysql:host={$host};port={$port}".($db ? ";dbname={$db}" : '');

for ($i = 1; $i <= $maxAttempts; $i++) {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
        fwrite(STDERR, "[wait-for-mysql] Connexion OK (tentative {$i}).\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[wait-for-mysql] Tentative {$i}/{$maxAttempts} : ".$e->getMessage()."\n");
        sleep($sleepSeconds);
    }
}

fwrite(STDERR, "[wait-for-mysql] Échec : MySQL injoignable sur {$host}:{$port}\n");
exit(1);
