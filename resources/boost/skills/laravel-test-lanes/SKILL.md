---
name: laravel-test-lanes
description: Run concurrent Laravel test runs safely in one project using machine-unique lane databases, and clean them up with the test-lanes:cleanup Artisan command. Use when test runs collide or corrupt each other's databases, when the user asks whether two agents or terminals can run tests at the same time, when wiring TestLanes::register() into a TestCase, when lane databases pile up and need dropping, or when the database server runs out of connections during tests.
---

# Laravel Test Lanes

This project has `mozex/laravel-test-lanes` installed. Every test process claims a machine-unique lane through a database advisory lock, and Laravel's parallel-testing databases become `{base}_test_lane{n}`. Concurrent test runs in this checkout never share a database, so databases need nothing assigned or coordinated by hand. Anything else a run keeps once per checkout is still shared (see the first rule below).

## When to use this skill

- Test runs fail with dropped tables, `relation "..." does not exist`, or half-loaded schemas while another run is active.
- The user asks whether several agents, terminals, or CI-like jobs can run the suite at the same time.
- The package needs wiring into a project, or lane databases need cleaning up.
- The database server rejects connections (`too many clients already`) during parallel tests.

## Rules for working in a project that uses this package

- **The lanes isolate databases, not everything.** Never serialize runs or assign databases because of the database: the lanes handle that. What they don't reach still collides between concurrent runs:
  - a fixed temp file or port;
  - anything keyed on paratest's `TEST_TOKEN`;
  - a test-impact cache kept once per checkout. Pest's Tia deletes its worker partials when a parallel run starts and collects whatever is there when it ends, so overlapping runs can lose or take each other's results. Trust a result from a run nothing overlapped, or rerun without the cache.
  - the database server's connection limit (see Connection budget).
- **Never hardcode a parallel token or database suffix.** The lane is claimed at runtime; anything keyed on paratest's raw `TEST_TOKEN` or a fixed path is still shared between runs and needs its own scoping.
- **The package registers itself; do not add wiring.** The service provider calls `TestLanes::register()` during app boot whenever `APP_ENV=testing` (Laravel's phpunit.xml default). Only a suite running under a different environment name needs the manual form, in the base `TestCase`'s `createApplication()` after `parent::createApplication()` — never in `Pest.php`, where no container exists yet and the `ParallelTesting` facade throws "A facade root has not been set".
- **Serial runs are covered on purpose.** Registration forces `LARAVEL_PARALLEL_TESTING` so plain `php artisan test` gets a lane database too. Do not "clean up" that flag; without it every serial run shares the base database.
- **Lane databases are meant to persist between runs.** Reuse skips the migration cost, so do not drop them as routine hygiene.
- **Never recommend `--recreate-databases` or `--drop-databases` for lane databases.** Laravel's parallel runner re-registers the token resolver with the raw worker index inside its own process hooks, so those flags act on `{base}_test_{index}` databases no lane worker uses. `test-lanes:cleanup` is the way to drop lanes.

## Cleaning up lane databases

When a checkout is being torn down or a database renamed. The command takes the base database from the connection as the current environment configures it. Run from a shell, that's `.env`'s database rather than the test database `phpunit.xml` sets, and a bare run finds no lanes. So name the test database, with `app_testing` standing for the `DB_DATABASE` in `phpunit.xml`:

```bash
DB_DATABASE=app_testing php artisan test-lanes:cleanup
```

Only databases whose lane lock is free are dropped. A lane claimed by a live run is kept and reported, so cleaning the default connection mid-test is safe.

`--connection=name` targets another connection, but lanes are locked only under the default connection's base database. A second database the suite switches with the same token has no lock of its own, so cleaning it through `--connection` drops lanes in use. Run that only while no tests are running.

## Connection budget

- **Per worker:** each worker holds at least two connections, Laravel's own plus the lane's lock holder. Every other connection its tests open (a second database, a database-backed cache or queue) adds one more. When the count isn't obvious, read the server's peak during a full run: `Threads_connected` on MySQL, `pg_stat_activity` on Postgres.
- **How many workers:** Pest starts one process per core by default, and the database server may serve other projects' test runs at the same time.
- **Sizing:** `max_connections >= connections per worker x (workers x concurrent runs) + headroom`, or cap a run with `--processes`.
- **When it runs out:** Laravel misreads connection starvation as a missing database and tries to drop it, so raise the limit rather than retrying.

## Configuration

`config/test-lanes.php` (publish it with `php artisan test-lanes:install`):

- `enabled`: `TEST_LANES_ENABLED=false` switches the package off; runs behave as if it were absent.
- `pool_size`: lanes per base database (default 256). One lane per concurrent process, handed out lowest-first.
- `locks`: driver-to-implementation map. Postgres, MySQL, and MariaDB ship with the package. To add a driver, implement `Mozex\TestLanes\Locks\AdvisoryLock` (`connect`, `tryAcquire`, `release`) and map it here. Unknown drivers fail loudly by design; never work around that with a constant token.

## Constraints worth knowing

- The base test database must exist; the lock is taken on it.
- The connection needs discrete `host`/`port`/`database` keys. A `DB_URL`-style connection is refused.
- PgBouncer in transaction-pooling mode breaks session advisory locks; point the test connection at Postgres directly.
- Under `--parallel`, compiled Blade view directories named for lanes linger: the runner only cleans the ones named for the raw worker index. Harmless disk growth, safe to delete when tearing a checkout down.
