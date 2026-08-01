<?php

declare(strict_types=1);

namespace Mozex\TestLanes\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Mozex\TestLanes\Exceptions\TestLanesException;
use Mozex\TestLanes\TestLanes;
use PDO;
use PDOException;

/**
 * Lane databases are never dropped while runs come and go, because reusing a
 * migrated database is the whole point. This command is for the moments reuse
 * ends: a checkout being torn down, a database rename, or reclaiming disk. A
 * lane whose lock is currently held belongs to a live test process and is
 * skipped, so running this mid-test is safe.
 */
class CleanupCommand extends Command
{
    protected $signature = 'test-lanes:cleanup
        {--connection= : The connection whose lane databases should be dropped (defaults to the default connection)}';

    protected $description = 'Drop lane databases left behind by finished test runs';

    public function handle(): int
    {
        $name = is_string($this->option('connection')) ? $this->option('connection') : DB::getDefaultConnection();

        /** @var array<string, mixed>|null $config */
        $config = Config::get('database.connections.'.$name);

        if (! is_array($config)) {
            $this->error("The [{$name}] connection is not configured.");

            return self::FAILURE;
        }

        if (! empty($config['url'])) {
            throw TestLanesException::urlConfiguredConnection($name);
        }

        $driver = (string) ($config['driver'] ?? '');
        $lock = TestLanes::lock($driver);

        try {
            $holder = $lock->connect($config);
        } catch (PDOException $exception) {
            throw TestLanesException::holderConnectionFailed($name, $config, $exception);
        }

        $base = (string) ($config['database'] ?? '');
        $namespace = TestLanes::namespaceFor($base);

        $dropped = 0;
        $kept = 0;

        foreach ($this->laneDatabases($holder, $driver, $base) as [$database, $lane]) {
            if (! $lock->tryAcquire($holder, $namespace, $lane)) {
                $this->warn("Kept [{$database}]: its lane is claimed by a running test process.");
                $kept++;

                continue;
            }

            $holder->exec($this->dropStatement($driver, $database));
            $lock->release($holder, $namespace, $lane);
            $this->line("Dropped [{$database}].");
            $dropped++;
        }

        $summary = sprintf('Dropped %d lane database%s', $dropped, $dropped === 1 ? '' : 's');
        $this->info($kept > 0 ? "{$summary}, kept {$kept} in use." : "{$summary}.");

        return self::SUCCESS;
    }

    /**
     * The lane databases of this base, with their lane numbers. Databases
     * that merely look similar (paratest's own "{base}_test_{n}", or a
     * non-numeric suffix) are not ours and are left alone.
     *
     * @return list<array{0: string, 1: int}>
     */
    protected function laneDatabases(PDO $holder, string $driver, string $base): array
    {
        $prefix = $base.'_test_lane';
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $prefix).'%';

        $statement = $holder->prepare(
            $driver === 'pgsql'
                ? 'SELECT datname FROM pg_database WHERE datname LIKE ?'
                : 'SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE ?',
        );
        $statement->execute([$pattern]);

        $lanes = [];

        /** @var string $database */
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $database) {
            $suffix = substr($database, strlen($prefix));

            if (ctype_digit($suffix)) {
                $lanes[] = [$database, (int) $suffix];
            }
        }

        return $lanes;
    }

    protected function dropStatement(string $driver, string $database): string
    {
        return $driver === 'pgsql'
            ? sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', str_replace('"', '""', $database))
            : sprintf('DROP DATABASE IF EXISTS `%s`', str_replace('`', '``', $database));
    }
}
