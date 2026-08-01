<?php

declare(strict_types=1);

use Mozex\TestLanes\TestLanes;
use Symfony\Component\Process\Process;

it('grants a lane to only one session at a time', function (string $driver): void {
    skipUnlessServerAvailable($driver);

    $config = serverConfig($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor('laravel-test-lanes-exclusion-'.getmypid());

    $first = $lock->connect($config);
    $second = $lock->connect($config);

    expect($lock->tryAcquire($first, $namespace, 1))->toBeTrue()
        ->and($lock->tryAcquire($second, $namespace, 1))->toBeFalse()
        ->and($lock->tryAcquire($second, $namespace, 2))->toBeTrue();

    $lock->release($first, $namespace, 1);

    expect($lock->tryAcquire($second, $namespace, 1))->toBeTrue();

    $lock->release($second, $namespace, 1);
    $lock->release($second, $namespace, 2);
})->with(['pgsql', 'mysql']);

it('frees the lock when the holding session dies', function (string $driver): void {
    skipUnlessServerAvailable($driver);

    $config = serverConfig($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor('laravel-test-lanes-death-'.getmypid());

    $dying = $lock->connect($config);
    $observer = $lock->connect($config);

    expect($lock->tryAcquire($dying, $namespace, 1))->toBeTrue()
        ->and($lock->tryAcquire($observer, $namespace, 1))->toBeFalse();

    $dying = null;

    expect(acquireEventually($lock, $observer, $namespace, 1))->toBeTrue();

    $lock->release($observer, $namespace, 1);
})->with(['pgsql', 'mysql']);

it('frees the lane when the holding process is killed, with no cleanup step', function (string $driver): void {
    skipUnlessServerAvailable($driver);

    $config = serverConfig($driver);
    $lock = TestLanes::lock($driver);
    $namespace = TestLanes::namespaceFor('laravel-test-lanes-kill-'.getmypid());

    $process = new Process([
        PHP_BINARY,
        __DIR__.'/Fixtures/hold-lane.php',
        $driver,
        (string) $config['host'],
        (string) $config['port'],
        (string) $config['database'],
        (string) $config['username'],
        (string) $config['password'],
        (string) $namespace,
        '1',
    ]);
    $process->setTimeout(15);

    $process->start();
    $process->waitUntil(
        fn (string $type, string $output): bool => str_contains($output, 'HELD') || str_contains($output, 'REFUSED'),
    );

    expect($process->getOutput())->toContain('HELD');

    $observer = $lock->connect($config);

    expect($lock->tryAcquire($observer, $namespace, 1))->toBeFalse();

    $process->stop(0);

    expect(acquireEventually($lock, $observer, $namespace, 1))->toBeTrue();

    $lock->release($observer, $namespace, 1);
})->with(['pgsql', 'mysql']);
