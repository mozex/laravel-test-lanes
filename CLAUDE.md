# laravel-test-lanes

Gives every concurrently running test process its own set of test databases. A process claims a "lane" (the lowest free slot in a pool of 256) by taking an advisory lock on the project's database server, and that lane becomes Laravel's parallel-testing token, so databases are named `{base}_test_lane{n}` instead of the collision-prone `{base}_test_{worker-index}`. Serial runs are routed through the same machinery by forcing `LARAVEL_PARALLEL_TESTING`, so they stop sharing the one base database too.

## Architecture

- `src/TestLanes.php` - the whole claim lifecycle as a static class (state is inherently process-global: one lane, one holder connection per process). `register()` is the only wiring users call; `claim()` is invoked lazily through the token resolver.
- `src/Locks/` - `AdvisoryLock` interface (`connect`, `tryAcquire`, `release`) with Postgres (`pg_try_advisory_lock`) and MySQL (`GET_LOCK`) implementations. MariaDB reuses the MySQL class. The config `locks` map is the extension point for new drivers.
- `src/Commands/CleanupCommand.php` - drops `{base}_test_lane{n}` databases whose lock is free; claimed lanes are skipped, so it is safe mid-run.
- `src/TestLanesServiceProvider.php` - spatie package-tools plus a deep config re-merge (Laravel's publish merge is shallow; without the re-merge a published config would freeze the `locks` map and lose drivers we add later).

## Key design decisions

- **Exclusion over uniqueness.** Competing approaches mint a unique id per run, which forfeits database reuse (full migration cost every run) and leaks orphan databases when a run dies. A bounded pool with atomic leases keeps reuse and self-heals on crash.
- **Advisory lock, not a lock file.** Atomic under any race, and the server frees it when the holding connection dies, so a killed run needs no reaper.
- **The holder is a raw PDO handle** bypassing Laravel's connection manager on purpose: Laravel may reconnect or purge its own connections mid-run, which would drop the lease.
- **`crc32(database) & 0x7FFFFFFF`** namespaces locks per base database. The mask is load-bearing: crc32 is unsigned 32-bit, Postgres advisory keys are signed int4, real database names overflow (found by a failing run, not by reasoning).
- **`register()` must run in `TestCase::createApplication()`.** ParallelTesting is a facade; Pest.php has no container yet. This is documented in the README because getting it wrong throws "A facade root has not been set".
- **Unsupported drivers and DB_URL connections fail loudly.** A silent constant fallback would quietly reinstate the exact collision the package exists to prevent.

## Testing

- `composer test` runs lint, PHPStan (level 6), 100% type coverage, and the Pest suite.
- Lock tests run against real servers and skip when none answers. Defaults match the CI service containers (Postgres `postgres`/`postgres`, MySQL `root`/empty on 127.0.0.1). Override locally with `TEST_LANES_PGSQL_*` / `TEST_LANES_MYSQL_*` env vars (this machine's Herd needs `TEST_LANES_PGSQL_USERNAME=root TEST_LANES_PGSQL_PASSWORD=`).
- `tests/Fixtures/hold-lane.php` claims a lock from a separate OS process; `LocksTest` uses it to prove exclusion across processes and that killing the process frees the lane.
- Run the suite serially. Lane-number assertions (`toBe('lane1')`) assume no concurrent claimant on the maintenance database's namespace.
- The global `afterEach` (releases the lane, unsets `LARAVEL_PARALLEL_TESTING`) hangs off the `uses()` chain in `tests/Pest.php`. A bare `afterEach()` in Pest.php is NOT global and silently never runs.

## Development notes

- **v2 candidate, deferred on purpose: halve the connections.** Each worker currently holds two connections (Laravel's plus the holder), which makes the machine ceiling `max_connections >= 2 x workers x runs`. The holder could hand the lease to Laravel's lane connection via the `ParallelTesting::setUpTestDatabase()` callback, leaving one connection per worker. It is NOT a casual change: advisory locks are exclusive per session, so the handoff has an unlock window another process can win. It needs a global claim mutex (suggested: namespace, lane 0) held across the database switch, the lease retaken on the lane connection, then the mutex released and the holder closed. The failure mode is silent database sharing, so it needs a contention-forcing test, not an eyeball check.
- PgBouncer in transaction-pooling mode silently breaks session advisory locks. Documented in the README; detection was considered and skipped (no reliable probe).
- Known harmless quirk from the spec, with a second half found in review: Laravel's parallel runner (`RunsInParallel::forEachProcess()`) creates its own application and re-registers the token resolver with the RAW worker index before running the process hooks. Consequences: `TestViews` creates and cleans compiled-view directories under `test_{index}` while tests read `test_lane{n}` (Blade recompiles; dead cleanup path, quiet disk growth), and `--recreate-databases` / `--drop-databases` act on `{base}_test_{index}` databases no lane worker uses, leaving every lane database untouched. Misdirected rather than destructive, so both are documented (README constraints + Boost skill) instead of patched.
- `mozex/laravel-worktree` drops parallel-test leftovers (including lane databases) on `worktree:teardown` by pattern, without depending on this package. If the naming scheme ever changes, update that pattern too.
- The wubly project (`C:\Work\www\wubly`) is the origin of this design and its first consumer. The original in-repo class (`tests/TestLane.php`, whose spec comment carried the research record: prior art survey, measured connection budget, the three traps) was deleted at adoption on 2026-08-01 and survives in wubly's git history; everything load-bearing from it lives in this file and the README.
