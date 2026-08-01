<?php

declare(strict_types=1);

namespace Mozex\TestLanes\Exceptions;

use RuntimeException;

class TestLanesException extends RuntimeException
{
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
