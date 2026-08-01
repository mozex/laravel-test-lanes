<?php

declare(strict_types=1);

namespace Mozex\TestLanes\Exceptions;

use PDOException;
use RuntimeException;

class TestLanesException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function holderConnectionFailed(string $connection, array $config, PDOException $previous): self
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '');
        $database = (string) ($config['database'] ?? '');

        return new self(sprintf(
            'Test lanes could not open its lock-holder connection to [%s:%s/%s] for the [%s] connection: %s. '
            .'The holder is the package\'s own PDO handle, separate from Laravel\'s; check that the server is '
            .'running, the credentials are right, and the base database exists.',
            $host,
            $port,
            $database,
            $connection,
            $previous->getMessage(),
        ), 0, $previous);
    }

    public static function invalidPoolSize(int $size): self
    {
        return new self(sprintf(
            'The test-lanes pool size must be at least 1, but the configuration resolves to [%d]. '
            .'Check TEST_LANES_POOL_SIZE and config/test-lanes.php.',
            $size,
        ));
    }

    /**
     * @param  list<string>  $supported
     */
    public static function unsupportedDriver(string $driver, array $supported): self
    {
        return new self(sprintf(
            'Test lanes have no lock primitive for the [%s] driver, so concurrent runs would share databases. '
            .'Use one of [%s], or map your own AdvisoryLock class under "locks" in config/test-lanes.php.',
            $driver,
            implode(', ', $supported),
        ));
    }

    public static function urlConfiguredConnection(string $connection): self
    {
        return new self(sprintf(
            'Test lanes cannot read the URL-configured [%s] connection. Configure the connection '
            .'with discrete host, port, and database keys instead of a DB_URL-style value.',
            $connection,
        ));
    }

    public static function poolExhausted(int $poolSize): self
    {
        return new self(sprintf(
            'Every one of the %d test lanes is held by another process. Either more test processes '
            .'are running than the pool allows, or lanes are leaking. Raise TEST_LANES_POOL_SIZE if '
            .'the concurrency is real.',
            $poolSize,
        ));
    }
}
