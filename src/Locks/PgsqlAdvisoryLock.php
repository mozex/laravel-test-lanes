<?php

declare(strict_types=1);

namespace Mozex\TestLanes\Locks;

use PDO;

class PgsqlAdvisoryLock implements AdvisoryLock
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 5432,
            $config['database'] ?? 'postgres',
        );

        return new PDO($dsn, self::credential($config, 'username'), self::credential($config, 'password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function tryAcquire(PDO $connection, int $namespace, int $lane): bool
    {
        $statement = $connection->prepare('SELECT pg_try_advisory_lock(?, ?) AS acquired');
        $statement->execute([$namespace, $lane]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return (bool) ($row['acquired'] ?? false);
    }

    public function release(PDO $connection, int $namespace, int $lane): void
    {
        $statement = $connection->prepare('SELECT pg_advisory_unlock(?, ?)');
        $statement->execute([$namespace, $lane]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function credential(array $config, string $key): ?string
    {
        return isset($config[$key]) ? (string) $config[$key] : null;
    }
}
