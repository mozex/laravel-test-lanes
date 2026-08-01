<?php

declare(strict_types=1);

use Illuminate\Support\Facades\ParallelTesting;
use Mozex\TestLanes\Exceptions\TestLanesException;
use Mozex\TestLanes\Locks\MysqlAdvisoryLock;
use Mozex\TestLanes\Locks\PgsqlAdvisoryLock;
use Mozex\TestLanes\TestLanes;

it('claims the lowest free lane', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);

    expect(TestLanes::claim())->toBe('lane1');
})->with(['pgsql', 'mysql']);

it('returns the held lane on repeated claims', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);

    expect(TestLanes::claim())->toBe(TestLanes::claim());
})->with(['pgsql', 'mysql']);

it('claims the next lane when the first is held elsewhere', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);

    $config = serverConfig($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor((string) $config['database']);

    $other = $lock->connect($config);
    expect(acquireEventually($lock, $other, $namespace, 1))->toBeTrue();

    try {
        expect(TestLanes::claim())->toBe('lane2');
    } finally {
        $lock->release($other, $namespace, 1);
    }
})->with(['pgsql', 'mysql']);

it('fails loudly when every lane is held', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);
    config()->set('test-lanes.pool_size', 1);

    $config = serverConfig($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor((string) $config['database']);

    $other = $lock->connect($config);
    expect(acquireEventually($lock, $other, $namespace, 1))->toBeTrue();

    try {
        TestLanes::claim();
    } finally {
        $lock->release($other, $namespace, 1);
    }
})->with(['pgsql', 'mysql'])->throws(TestLanesException::class, 'Every one of the 1 test lanes');

it('frees the lane on release so the next claim starts over', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);

    $config = serverConfig($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor((string) $config['database']);

    expect(TestLanes::claim())->toBe('lane1');

    TestLanes::release();

    $other = $lock->connect($config);

    expect(acquireEventually($lock, $other, $namespace, 1))->toBeTrue();

    $lock->release($other, $namespace, 1);
})->with(['pgsql', 'mysql']);

it('refuses a driver with no lock implementation', function (): void {
    // A file-backed sqlite database is real shared state with no server to
    // lock on, so it must fail loudly rather than silently share databases.
    config()->set('database.connections.lanes_file', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/lanes-test.sqlite',
    ]);
    config()->set('database.default', 'lanes_file');

    TestLanes::claim();
})->throws(TestLanesException::class, 'no lock primitive for the [sqlite] driver');

it('stays out of the way of an in-memory sqlite suite', function (): void {
    // Testbench's default connection is sqlite :memory:, which Laravel's own
    // machinery would leave alone; an empty token mirrors that. The parallel
    // callbacks must not fire, exactly as if the package were absent.
    expect(TestLanes::claim())->toBe('');

    $fired = false;

    ParallelTesting::setUpTestCase(function () use (&$fired): void {
        $fired = true;
    });

    TestLanes::register();
    ParallelTesting::callSetUpTestCaseCallbacks($this);

    expect($fired)->toBeFalse();
});

it('refuses a url-configured connection', function (): void {
    config()->set('database.connections.lanes_testing', [
        'driver' => 'pgsql',
        'url' => 'pgsql://user:secret@db.example.com:5432/app',
    ]);
    config()->set('database.default', 'lanes_testing');

    TestLanes::claim();
})->throws(TestLanesException::class, 'URL-configured [lanes_testing] connection');

it('masks the namespace into the positive int4 range', function (): void {
    // crc32('a') is 3904355907, which overflows a signed int4 without the mask.
    expect(TestLanes::namespaceFor('a'))->toBe(1756872259)
        ->and(TestLanes::namespaceFor('a'))->toBe(crc32('a') & 0x7FFFFFFF)
        ->and(TestLanes::namespaceFor('laravel_test_lanes'))->toBeLessThanOrEqual(0x7FFFFFFF)
        ->and(TestLanes::namespaceFor('laravel_test_lanes'))->toBeGreaterThanOrEqual(0);
});

it('reads the pool size from the config', function (): void {
    config()->set('test-lanes.pool_size', 12);

    expect(TestLanes::poolSize())->toBe(12);
});

it('resolves the configured lock for each driver', function (): void {
    expect(TestLanes::lock('pgsql'))->toBeInstanceOf(PgsqlAdvisoryLock::class)
        ->and(TestLanes::lock('mysql'))->toBeInstanceOf(MysqlAdvisoryLock::class)
        ->and(TestLanes::lock('mariadb'))->toBeInstanceOf(MysqlAdvisoryLock::class);
});

it('registers a resolver that routes serial runs through the parallel machinery', function (string $driver): void {
    skipUnlessServerAvailable($driver);
    useServerConnection($driver);

    // The load-bearing behavior is that the setUpTestCase callbacks (the
    // gate every parallel-database action runs behind) actually fire with
    // the lane as the token, in a run that never passed --parallel.
    $seen = null;

    ParallelTesting::setUpTestCase(function (string $token) use (&$seen): void {
        $seen = $token;
    });

    TestLanes::register();
    ParallelTesting::callSetUpTestCaseCallbacks($this);

    // The callbacks only fire when the machinery considers the run parallel,
    // so a lane-shaped $seen proves the whole chain in one assertion.
    expect($_SERVER['LARAVEL_PARALLEL_TESTING'] ?? null)->toBe(1)
        ->and(ParallelTesting::token())->toMatch('/^lane\d+$/')
        ->and($seen)->toMatch('/^lane\d+$/');
})->with(['pgsql', 'mysql']);

it('reports an unreachable lock server with package context', function (): void {
    config()->set('database.connections.lanes_dead', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'postgres',
        'username' => 'nobody',
        'password' => 'nothing',
    ]);
    config()->set('database.default', 'lanes_dead');

    TestLanes::claim();
})->throws(TestLanesException::class, 'lock-holder connection');

it('rejects a pool size below one', function (): void {
    config()->set('test-lanes.pool_size', 0);

    TestLanes::poolSize();
})->throws(TestLanesException::class, 'must be at least 1');

it('falls back to the built-in lock map when the config never loaded', function (): void {
    // An app that cached its config before installing the package boots with
    // no test-lanes config at all; the code defaults keep drivers working.
    config()->set('test-lanes', []);

    expect(TestLanes::lock('pgsql'))->toBeInstanceOf(PgsqlAdvisoryLock::class)
        ->and(TestLanes::lock('mariadb'))->toBeInstanceOf(MysqlAdvisoryLock::class);
});

it('does nothing when disabled', function (): void {
    config()->set('test-lanes.enabled', false);

    TestLanes::register();

    expect($_SERVER)->not->toHaveKey('LARAVEL_PARALLEL_TESTING');
});
