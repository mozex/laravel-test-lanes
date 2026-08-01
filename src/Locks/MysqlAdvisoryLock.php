<?php

declare(strict_types=1);

namespace Mozex\TestLanes\Locks;

use PDO;

/**
 * Also serves the mariadb driver: MariaDB speaks the mysql PDO protocol and
 * supports GET_LOCK with the same semantics.
 */
class MysqlAdvisoryLock implements AdvisoryLock
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
        );

        if (! empty($config['database'])) {
            $dsn .= ';dbname='.$config['database'];
        }

        return new PDO($dsn, self::credential($config, 'username'), self::credential($config, 'password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function tryAcquire(PDO $connection, int $namespace, int $lane): bool
    {
        $statement = $connection->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        $statement->execute([self::key($namespace, $lane)]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['acquired'] ?? 0) === 1;
    }

    public function release(PDO $connection, int $namespace, int $lane): void
    {
        $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([self::key($namespace, $lane)]);
    }

    protected static function key(int $namespace, int $lane): string
    {
        return $namespace.'-test-lane-'.$lane;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function credential(array $config, string $key): ?string
    {
        return isset($config[$key]) ? (string) $config[$key] : null;
    }
}
