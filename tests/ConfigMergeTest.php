<?php

declare(strict_types=1);

use Mozex\TestLanes\Locks\MysqlAdvisoryLock;
use Mozex\TestLanes\Locks\PgsqlAdvisoryLock;
use Mozex\TestLanes\TestLanesServiceProvider;

it('fills missing keys from the defaults after a shallow publish', function (): void {
    config()->set('test-lanes', ['pool_size' => 8]);

    (new TestLanesServiceProvider(app()))->packageRegistered();

    expect(config('test-lanes.pool_size'))->toBe(8)
        ->and(config('test-lanes.enabled'))->toBeTrue()
        ->and(config('test-lanes.locks.pgsql'))->toBe(PgsqlAdvisoryLock::class);
});

it('keeps user overrides inside nested maps while filling the rest', function (): void {
    config()->set('test-lanes', ['locks' => ['pgsql' => 'App\Locks\CustomLock']]);

    (new TestLanesServiceProvider(app()))->packageRegistered();

    expect(config('test-lanes.locks.pgsql'))->toBe('App\Locks\CustomLock')
        ->and(config('test-lanes.locks.mysql'))->toBe(MysqlAdvisoryLock::class)
        ->and(config('test-lanes.locks.mariadb'))->toBe(MysqlAdvisoryLock::class);
});
