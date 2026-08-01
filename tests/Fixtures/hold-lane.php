<?php

/*
 * Claims a lane's advisory lock from a separate OS process and holds it until
 * the process is killed. The suite uses it to prove exclusion across process
 * boundaries and that a killed process frees its lane with no cleanup step.
 *
 * Arguments: driver host port database username password namespace lane
 */

declare(strict_types=1);

[, $driver, $host, $port, $database, $username, $password, $namespace, $lane] = $argv;

$dsn = $driver === 'pgsql'
    ? sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database)
    : sprintf('mysql:host=%s;port=%s', $host, $port);

$pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if ($driver === 'pgsql') {
    $statement = $pdo->prepare('SELECT pg_try_advisory_lock(?, ?) AS acquired');
    $statement->execute([(int) $namespace, (int) $lane]);
    $acquired = (bool) ($statement->fetch(PDO::FETCH_ASSOC)['acquired'] ?? false);
} else {
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0) AS acquired');
    $statement->execute([$namespace.'-test-lane-'.$lane]);
    $acquired = (int) ($statement->fetch(PDO::FETCH_ASSOC)['acquired'] ?? 0) === 1;
}

echo $acquired ? "HELD\n" : "REFUSED\n";

while (true) {
    sleep(1);
}
