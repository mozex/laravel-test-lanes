<?php

declare(strict_types=1);

use Mozex\TestLanes\Exceptions\TestLanesException;
use Mozex\TestLanes\TestLanes;

function createDatabase(PDO $server, string $driver, string $name): void
{
    if ($driver === 'pgsql') {
        $exists = $server->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$name]);

        if ($exists->fetchColumn() === false) {
            $server->exec(sprintf('CREATE DATABASE "%s"', $name));
        }

        return;
    }

    $server->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $name));
}

function dropDatabase(PDO $server, string $driver, string $name): void
{
    $server->exec(
        $driver === 'pgsql'
            ? sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $name)
            : sprintf('DROP DATABASE IF EXISTS `%s`', $name),
    );
}

function databaseExists(PDO $server, string $driver, string $name): bool
{
    $statement = $server->prepare(
        $driver === 'pgsql'
            ? 'SELECT 1 FROM pg_database WHERE datname = ?'
            : 'SELECT 1 FROM information_schema.schemata WHERE schema_name = ?',
    );
    $statement->execute([$name]);

    return $statement->fetchColumn() !== false;
}

it('drops free lane databases and keeps claimed ones', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);

    $config = serverConfig($driver);
    $base = (string) $config['database'];
    $server = connectServer($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor($base);

    createDatabase($server, $driver, "{$base}_test_lane1");
    createDatabase($server, $driver, "{$base}_test_lane2");
    createDatabase($server, $driver, "{$base}_test_7");

    $holder = $lock->connect($config);
    expect($lock->tryAcquire($holder, $namespace, 2))->toBeTrue();

    try {
        $this->artisan('test-lanes:cleanup')
            ->expectsOutputToContain("Dropped [{$base}_test_lane1].")
            ->expectsOutputToContain("Kept [{$base}_test_lane2]")
            ->assertSuccessful();
    } finally {
        $lock->release($holder, $namespace, 2);
    }

    expect(databaseExists($server, $driver, "{$base}_test_lane1"))->toBeFalse()
        ->and(databaseExists($server, $driver, "{$base}_test_lane2"))->toBeTrue()
        // Paratest-style databases are not ours and are left alone.
        ->and(databaseExists($server, $driver, "{$base}_test_7"))->toBeTrue();

    $this->artisan('test-lanes:cleanup')->assertSuccessful();

    expect(databaseExists($server, $driver, "{$base}_test_lane2"))->toBeFalse();

    dropDatabase($server, $driver, "{$base}_test_7");
})->with(['pgsql', 'mysql']);

it('cleans a named connection while the default stays untouched', function (): void {
    skipUnlessServerAvailable('pgsql');

    $config = serverConfig('pgsql');
    $base = (string) $config['database'];
    $server = connectServer('pgsql');

    config()->set('database.connections.lanes_other', $config);
    createDatabase($server, 'pgsql', "{$base}_test_lane9");

    $this->artisan('test-lanes:cleanup', ['--connection' => 'lanes_other'])->assertSuccessful();

    expect(databaseExists($server, 'pgsql', "{$base}_test_lane9"))->toBeFalse();
});

it('fails for a connection nobody configured', function (): void {
    $this->artisan('test-lanes:cleanup', ['--connection' => 'missing'])
        ->expectsOutputToContain('The [missing] connection is not configured.')
        ->assertFailed();
});

it('refuses a url-configured connection', function (): void {
    config()->set('database.connections.lanes_url', [
        'driver' => 'pgsql',
        'url' => 'pgsql://user:secret@db.example.com:5432/app',
    ]);

    $this->artisan('test-lanes:cleanup', ['--connection' => 'lanes_url'])->run();
})->throws(TestLanesException::class, 'URL-configured [lanes_url] connection');

it('refuses a driver with no lock implementation', function (): void {
    config()->set('database.connections.lanes_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $this->artisan('test-lanes:cleanup', ['--connection' => 'lanes_sqlite'])->run();
})->throws(TestLanesException::class, 'no lock primitive for the [sqlite] driver');
