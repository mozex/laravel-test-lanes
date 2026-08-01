<?php

declare(strict_types=1);

namespace Mozex\TestLanes;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Mozex\TestLanes\Exceptions\TestLanesException;
use Mozex\TestLanes\Locks\AdvisoryLock;
use Mozex\TestLanes\Locks\MysqlAdvisoryLock;
use Mozex\TestLanes\Locks\PgsqlAdvisoryLock;
use PDO;
use PDOException;

/**
 * Gives every concurrently running test process its own set of databases.
 *
 * Laravel names parallel test databases "{base}_test_{token}", where the token
 * is the paratest worker index. Two runs started at the same time - two
 * terminals, two agents, an agent and a human - both number their workers from
 * 1, so they land on the same databases and corrupt each other. This claims a
 * lane that is unique across every process on the machine and hands it back as
 * the token, so the databases become "{base}_test_lane{n}" and never overlap.
 *
 * The claim is a database advisory lock rather than a lock file: it is atomic,
 * and the server drops it when the connection dies, so a killed or crashed run
 * frees its lane with no stale state to reap. The lock is held on a dedicated
 * PDO handle that deliberately bypasses Laravel's connection manager, which is
 * free to reconnect or purge its own handles mid-run.
 *
 * Lanes are a small reused pool rather than a per-run identifier: a unique id
 * per run would migrate a fresh database every time and leave them all behind
 * forever.
 */
class TestLanes
{
    protected static ?PDO $holder = null;

    protected static ?string $lane = null;

    /**
     * Route every test run through Laravel's parallel-database machinery,
     * keyed by a lane this process owns alone. Serial runs are opted in
     * deliberately: without LARAVEL_PARALLEL_TESTING, Laravel skips the
     * machinery entirely and every serial process shares the one base
     * database.
     *
     * The service provider calls this automatically when tests run under
     * APP_ENV=testing, so most suites never touch it. Call it manually from
     * your base TestCase's createApplication() when your suite runs under
     * another environment name. ParallelTesting is a facade, so it needs a
     * booted container either way; createApplication() is the first point
     * where one exists that still precedes the parallel-database callbacks.
     */
    public static function register(): void
    {
        if (Config::get('test-lanes.enabled', true) === false) {
            return;
        }

        $_SERVER['LARAVEL_PARALLEL_TESTING'] = 1;

        ParallelTesting::resolveTokenUsing(fn (): string => self::claim());
    }

    /**
     * Claim this process's lane, or return the one it already holds.
     */
    public static function claim(): string
    {
        if (self::$lane !== null) {
            return self::$lane;
        }

        $connection = DB::connection();

        // Laravel's parallel machinery leaves an in-memory SQLite suite
        // alone, since every process already has a private database. Mirror
        // that instead of failing the driver check: an empty token switches
        // the machinery off for a suite the package has nothing to protect.
        // Deliberately NOT memoized: a test that boots on :memory: and then
        // points the default connection at a real server must claim a real
        // lane on its next call, not inherit the no-op answer.
        if ((string) $connection->getDatabaseName() === ':memory:') {
            return '';
        }

        $lock = self::lock($connection->getDriverName());
        $config = self::connectionConfig();

        try {
            $holder = $lock->connect($config);
        } catch (PDOException $exception) {
            throw TestLanesException::holderConnectionFailed(DB::getDefaultConnection(), $config, $exception);
        }

        $namespace = self::namespaceFor((string) $connection->getDatabaseName());
        $pool = self::poolSize();

        for ($lane = 1; $lane <= $pool; $lane++) {
            if ($lock->tryAcquire($holder, $namespace, $lane)) {
                self::$holder = $holder;

                return self::$lane = 'lane'.$lane;
            }
        }

        throw TestLanesException::poolExhausted($pool);
    }

    /**
     * Give the claimed lane back. Closing the holder connection is all it
     * takes: the server releases the advisory lock the moment the session
     * ends, exactly as it does when a run crashes.
     *
     * Meant for this package's own test suite. The token resolver stays
     * registered, so the very next token resolution claims a lane again,
     * possibly a different one.
     */
    public static function release(): void
    {
        self::$holder = null;
        self::$lane = null;
    }

    /**
     * The advisory-lock namespace for a base database name, masked into the
     * positive int4 range: crc32() is unsigned 32-bit and Postgres advisory
     * lock keys are signed, so real database names overflow without the mask.
     */
    public static function namespaceFor(string $database): int
    {
        return crc32($database) & 0x7FFFFFFF;
    }

    /**
     * The advisory-lock implementation configured for a database driver.
     * Deliberately loud on unknown drivers: falling back to a constant lane
     * would silently reinstate the very collision this package prevents.
     */
    public static function lock(string $driver): AdvisoryLock
    {
        // The code fallback matters when the app cached its config before
        // this package was installed: the merge never ran, and an empty map
        // here would misreport every driver as unsupported.
        /** @var array<string, class-string<AdvisoryLock>> $locks */
        $locks = Config::get('test-lanes.locks', [
            'pgsql' => PgsqlAdvisoryLock::class,
            'mysql' => MysqlAdvisoryLock::class,
            'mariadb' => MysqlAdvisoryLock::class,
        ]);

        if (! isset($locks[$driver])) {
            throw TestLanesException::unsupportedDriver($driver, array_keys($locks));
        }

        return new $locks[$driver];
    }

    public static function poolSize(): int
    {
        $size = (int) Config::get('test-lanes.pool_size', 256);

        if ($size < 1) {
            throw TestLanesException::invalidPoolSize($size);
        }

        return $size;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        $name = DB::getDefaultConnection();

        /** @var array<string, mixed> $config */
        $config = Config::get('database.connections.'.$name, []);

        // Laravel's own switchToDatabase() branches on `url` when it is set.
        // Building a DSN from the discrete keys would then point at the
        // defaults instead, taking the lock against the wrong server and
        // losing exclusion silently. Refuse rather than guess.
        if (! empty($config['url'])) {
            throw TestLanesException::urlConfiguredConnection($name);
        }

        return $config;
    }
}
