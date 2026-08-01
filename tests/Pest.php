<?php

declare(strict_types=1);

use Mozex\TestLanes\Locks\AdvisoryLock;
use Mozex\TestLanes\TestLanes;
use Mozex\TestLanes\Tests\TestCase;

uses(TestCase::class)
    ->afterEach(function (): void {
        TestLanes::release();

        unset($_SERVER['LARAVEL_PARALLEL_TESTING']);
    })
    ->in(__DIR__);

/**
 * Connection details for the real servers the suite exercises locks against.
 * The defaults match the CI service containers; override them through the
 * TEST_LANES_* variables to point at a local server with other credentials.
 *
 * @return array<string, mixed>
 */
function serverConfig(string $driver): array
{
    if ($driver === 'pgsql') {
        return [
            'driver' => 'pgsql',
            'host' => env('TEST_LANES_PGSQL_HOST', '127.0.0.1'),
            'port' => (int) env('TEST_LANES_PGSQL_PORT', 5432),
            'database' => env('TEST_LANES_PGSQL_DATABASE', 'postgres'),
            'username' => env('TEST_LANES_PGSQL_USERNAME', 'postgres'),
            'password' => env('TEST_LANES_PGSQL_PASSWORD', 'postgres'),
        ];
    }

    return [
        'driver' => 'mysql',
        'host' => env('TEST_LANES_MYSQL_HOST', '127.0.0.1'),
        'port' => (int) env('TEST_LANES_MYSQL_PORT', 3306),
        'database' => env('TEST_LANES_MYSQL_DATABASE', 'mysql'),
        'username' => env('TEST_LANES_MYSQL_USERNAME', 'root'),
        'password' => env('TEST_LANES_MYSQL_PASSWORD', ''),
    ];
}

function connectServer(string $driver): PDO
{
    return TestLanes::lock($driver)->connect(serverConfig($driver));
}

function skipUnlessServerAvailable(string $driver): void
{
    try {
        connectServer($driver);
    } catch (PDOException $exception) {
        test()->markTestSkipped("No {$driver} server answered: {$exception->getMessage()}");
    }
}

/**
 * The lock server frees a lane when its holding session ends, but tearing a
 * session down is asynchronous on some servers, so poll briefly instead of
 * asserting on the first try.
 */
function acquireEventually(AdvisoryLock $lock, PDO $connection, int $namespace, int $lane): bool
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if ($lock->tryAcquire($connection, $namespace, $lane)) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

/**
 * Point the application's default connection at a real server, the way a
 * consuming project's phpunit.xml would.
 */
function useServerConnection(string $driver): void
{
    config()->set('database.connections.lanes_testing', serverConfig($driver));
    config()->set('database.default', 'lanes_testing');
}
