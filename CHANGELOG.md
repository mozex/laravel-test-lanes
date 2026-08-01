# Changelog

All notable changes to `laravel-test-lanes` will be documented in this file.

## 1.0.0 - 2026-08-01

### What's Changed

* Every concurrent test process now claims a machine-unique lane through a database advisory lock, and the lane becomes Laravel's parallel-testing token. Databases are named `{base}_test_lane{n}`, so two runs started at the same time in one project never share a database, and a killed or crashed run frees its lane on its own.
* Serial runs are routed through the same machinery: `TestLanes::register()` forces `LARAVEL_PARALLEL_TESTING`, so a plain `php artisan test` gets its own lane database instead of sharing the base one.
* Postgres, MySQL, and MariaDB ship supported. Unknown drivers and `DB_URL`-style connections fail loudly instead of silently sharing databases, and the `locks` config map takes custom `AdvisoryLock` implementations for other drivers.
* An in-memory SQLite suite is left alone, mirroring Laravel's own behavior.
* Added `test-lanes:cleanup`, which drops lane databases whose locks are free and keeps any lane claimed by a live run, so it is safe to run mid-test.
* `Storage::fake()` roots are lane-scoped too, so concurrent runs stop deleting each other's fake-disk files.

**Full Changelog**: https://github.com/mozex/laravel-test-lanes/commits/1.0.0
