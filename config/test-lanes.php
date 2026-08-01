<?php

declare(strict_types=1);

use Mozex\TestLanes\Locks\MysqlAdvisoryLock;
use Mozex\TestLanes\Locks\PgsqlAdvisoryLock;

return [
    /*
     * When disabled, TestLanes::register() does nothing: no token resolver
     * is registered and serial runs are not routed through the parallel
     * testing machinery. Runs then behave exactly as they would without
     * the package.
     */
    'enabled' => (bool) env('TEST_LANES_ENABLED', true),

    /*
     * How many lanes exist per base database. One lane is claimed per
     * concurrent test process, not per run: two full-speed 24-worker runs
     * already claim 48. Lanes are handed out lowest-first and their
     * databases are created lazily by Laravel, so a generous pool costs
     * nothing until that many processes actually run at once.
     */
    'pool_size' => (int) env('TEST_LANES_POOL_SIZE', 256),

    /*
     * The advisory-lock implementation per database driver. A driver
     * missing from this map fails loudly rather than silently sharing
     * databases. Map your own Mozex\TestLanes\Locks\AdvisoryLock
     * implementation here to teach the package another driver.
     */
    'locks' => [
        'pgsql' => PgsqlAdvisoryLock::class,
        'mysql' => MysqlAdvisoryLock::class,
        'mariadb' => MysqlAdvisoryLock::class,
    ],
];
