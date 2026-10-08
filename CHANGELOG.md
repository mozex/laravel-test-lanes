# Changelog

All notable changes to `laravel-test-lanes` will be documented in this file.

## 1.2.0 - 2026-10-08

### What's Changed

* Drop Laravel 11 support
* Bump minimum `illuminate/*` requirements from `^11.0|^12.0|^13.0` to `^12.69|^13.30`
* Add the package banner and icon

Laravel 11 stopped receiving security fixes on March 12, 2026. Three advisories published since then ([GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq), [GHSA-crmm-hgp2-wgrp](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) and [GHSA-jh5r-qr3c-85q8](https://github.com/advisories/GHSA-jh5r-qr3c-85q8)) affect every Laravel 11 release and were fixed only in Laravel 12 and 13. The new minimums, 12.69.0 and 13.30.0, are the first releases that include all three fixes.

If your app is still on Laravel 11, Composer keeps you on 1.1.2.

**Full Changelog**: https://github.com/mozex/laravel-test-lanes/compare/1.1.2...1.2.0

## 1.1.2 - 2026-10-04

### What's Changed

* Improve package setup

**Full Changelog**: https://github.com/mozex/laravel-test-lanes/compare/1.1.1...1.1.2

## 1.1.1 - 2026-08-01

### What's Changed

* The lock-holder-connection and unsupported-driver errors now end by pointing at `TEST_LANES_ENABLED=false`, so a suite that never needed a database learns about the switch-off right where it fails.
* Documented a known `--parallel` quirk in the README and the Boost skill: compiled Blade view directories named for lanes linger, because Laravel's runner only cleans the ones named for the raw worker index.
* Declared `symfony/process` as a dev dependency and aligned the dependabot config, CI badge, and CLAUDE.md layout with the other mozex packages.

**Full Changelog**: https://github.com/mozex/laravel-test-lanes/compare/1.1.0...1.1.1

## 1.1.0 - 2026-08-01

### What's Changed

* The package is now a drop-in: its service provider registers the lane resolver on its own whenever tests run under `APP_ENV=testing` (Laravel's phpunit.xml default), so no `TestCase` changes are needed. Requiring the package is the opt-in. `TestLanes::register()` stays public as the manual fallback for suites running under a different environment name, and `TEST_LANES_ENABLED=false` still switches everything off.
* The in-memory SQLite no-op answer is no longer memoized, so an app that boots on `:memory:` and later points its default connection at a real server claims a real lane instead of inheriting the no-op.

**Full Changelog**: https://github.com/mozex/laravel-test-lanes/compare/1.0.0...1.1.0

## 1.0.0 - 2026-08-01

### What's Changed

* Every concurrent test process now claims a machine-unique lane through a database advisory lock, and the lane becomes Laravel's parallel-testing token. Databases are named `{base}_test_lane{n}`, so two runs started at the same time in one project never share a database, and a killed or crashed run frees its lane on its own.
* Serial runs are routed through the same machinery: `TestLanes::register()` forces `LARAVEL_PARALLEL_TESTING`, so a plain `php artisan test` gets its own lane database instead of sharing the base one.
* Postgres, MySQL, and MariaDB ship supported. Unknown drivers and `DB_URL`-style connections fail loudly instead of silently sharing databases, and the `locks` config map takes custom `AdvisoryLock` implementations for other drivers.
* An in-memory SQLite suite is left alone, mirroring Laravel's own behavior.
* Added `test-lanes:cleanup`, which drops lane databases whose locks are free and keeps any lane claimed by a live run, so it is safe to run mid-test.
* `Storage::fake()` roots are lane-scoped too, so concurrent runs stop deleting each other's fake-disk files.

**Full Changelog**: https://github.com/mozex/laravel-test-lanes/commits/1.0.0
