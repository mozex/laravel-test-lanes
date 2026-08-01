<?php

declare(strict_types=1);

namespace Mozex\TestLanes\Locks;

use PDO;

interface AdvisoryLock
{
    /**
     * Open a PDO handle Laravel does not manage, so the lock outlives any
     * reconnect or purge on the framework's own connections.
     *
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO;

    /**
     * Take a lane's lock without waiting. Returns false when another process
     * already holds it.
     */
    public function tryAcquire(PDO $connection, int $namespace, int $lane): bool;

    /**
     * Release a lane's lock taken on this connection.
     */
    public function release(PDO $connection, int $namespace, int $lane): void;
}
