# Laravel Test Lanes

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-test-lanes.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-test-lanes)
[![Tests](https://img.shields.io/github/actions/workflow/status/mozex/laravel-test-lanes/checks.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/laravel-test-lanes/actions/workflows/checks.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-test-lanes/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-test-lanes?style=flat-square)](https://packagist.org/packages/mozex/laravel-test-lanes)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-test-lanes.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-test-lanes)

Two test runs started at the same time in one project corrupt each other's databases. Laravel numbers its parallel test databases by worker index, both runs count from 1, and every new worker drops and rebuilds the very tables the other run is using mid-test. This package ends that: each test process claims a machine-unique lane through a database advisory lock, so databases become `{base}_test_lane{n}` and never overlap. Two agents, two terminals, or you and an agent can run the suite in the same checkout at full parallel speed.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-test-lanes/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Installation](#installation)
- [The Problem](#the-problem)
- [How It Works](#how-it-works)
- [Serial Runs Are Covered Too](#serial-runs-are-covered-too)
- [Configuration](#configuration)
- [Cleaning Up Lane Databases](#cleaning-up-lane-databases)
- [Sizing the Connection Budget](#sizing-the-connection-budget)
- [What Is Not Isolated](#what-is-not-isolated)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://mozex.dev/docs) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## Installation

> **Requires [PHP 8.2+](https://php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/laravel-test-lanes/v1/requirements)

Install it as a dev dependency:

```bash
composer require mozex/laravel-test-lanes --dev
```

Then add one line to your base `TestCase`:

```php
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mozex\TestLanes\TestLanes;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        TestLanes::register();

        return $app;
    }
}
```

That's the whole setup. It works the same for Pest and PHPUnit, because both run through your base `TestCase`.

The placement matters. `ParallelTesting` is a facade, so registering the resolver needs a booted container, and `createApplication()` is the first point where one exists that still runs before Laravel's parallel-database callbacks. Registering earlier (in `Pest.php`, for example) throws "A facade root has not been set".

## The Problem

Laravel's parallel testing names each worker's database `{base}_test_{token}`, where the token is the paratest worker index. Those indexes always start at 1. Start a second run while the first is still going, in the same project, and both runs' worker 1 lands on `{base}_test_1`, both worker 2s on `{base}_test_2`, and so on.

The damage is not subtle. Each new worker process drops every table in its database and reloads the schema before testing. When that database is shared with a live run, the live run's tables vanish mid-test. Measured on a real project: the first of two simultaneous runs failed with 26 errors (`relation "users" does not exist`, inserts hitting half-loaded schemas), while the second run finished green on the freshly rebuilt tables. The failures always land on whoever started first.

Serial runs have it even worse: without `LARAVEL_PARALLEL_TESTING`, Laravel skips the database-switching machinery entirely, so every serial run on the machine shares the one base test database.

And no, paratest's `UNIQUE_TEST_TOKEN` does not save you. Laravel never reads it.

## How It Works

At the start of a run, each test process claims a lane: the lowest number in a pool (256 by default) whose advisory lock it can take on your database server. The lane becomes Laravel's parallel-testing token, so the process works in `{base}_test_lane{n}`. Laravel creates and migrates that database itself on first use, exactly as it does for its own parallel databases.

A lane claim is an advisory lock, not a lock file, and that choice carries the design:

- **Atomic.** Two processes can never hold the same lane, no matter how precisely they race. The second one just gets the next lane.
- **Self-healing.** The server drops the lock the moment the holding connection dies. A killed or crashed run frees its lane with no cleanup step and no stale state to reap.
- **Reusable.** Lanes are a small recycled pool rather than a unique id per run, so a lane's database keeps its migrated schema between runs. You pay the migration cost once per lane, not once per run.

The lock rides on a dedicated PDO connection that deliberately bypasses Laravel's connection manager, because Laravel is free to reconnect or purge its own handles mid-run.

Postgres, MySQL, and MariaDB are supported out of the box. Anything else fails loudly instead of silently sharing databases, and you can teach the package a new driver through the config.

There's a bonus: `Storage::fake()` also scopes its disk roots by the parallel token. With lanes registered, two concurrent runs stop deleting each other's fake-disk files too.

## Serial Runs Are Covered Too

`TestLanes::register()` forces `LARAVEL_PARALLEL_TESTING` on. That routes every run through the parallel-database machinery, including plain `php artisan test` or `vendor/bin/pest` without `--parallel`, so serial runs get their own lane database instead of sharing the base one. A serial run is just a run with one worker.

## Configuration

Publish the config file if you want to change the defaults:

```bash
php artisan vendor:publish --tag=test-lanes-config
```

```php
return [
    'enabled' => (bool) env('TEST_LANES_ENABLED', true),

    'pool_size' => (int) env('TEST_LANES_POOL_SIZE', 256),

    'locks' => [
        'pgsql' => PgsqlAdvisoryLock::class,
        'mysql' => MysqlAdvisoryLock::class,
        'mariadb' => MysqlAdvisoryLock::class,
    ],
];
```

Set `TEST_LANES_ENABLED=false` to switch the package off entirely; runs then behave exactly as they would without it. The pool costs nothing until processes actually claim lanes, so there's no reason to shrink it.

To support another database driver, implement the `Mozex\TestLanes\Locks\AdvisoryLock` interface (three methods: `connect`, `tryAcquire`, `release`) and map it in `locks`.

## Cleaning Up Lane Databases

Lane databases are kept on purpose: reusing a migrated database is the whole point. When reuse ends, because you're tearing down a checkout or renaming a database, drop them all at once:

```bash
php artisan test-lanes:cleanup
```

The command only drops databases whose lane lock is currently free. A lane claimed by a live test run is kept and reported, so running cleanup mid-test is safe. Pass `--connection=name` to clean a connection other than the default.

## Sizing the Connection Budget

Each test worker holds two database connections: Laravel's own (to the lane database) and the lock holder (to the base database). A 24-worker run costs about 50 connections, and two of those at once will blow through a stock Postgres `max_connections = 100`.

That failure is not clean, either. Under connection starvation Laravel misreads "too many clients" as "the database is missing" and tries to drop it. Size the server before you need it:

```
max_connections >= 2 x (workers x concurrent runs) + headroom
```

For example: up to 3 concurrent 24-worker runs wants `max_connections` of at least 150, so 200 is a comfortable setting.

## What Is Not Isolated

The lane claim scopes **databases** (and `Storage::fake()` roots). It cannot reach things your suite keys on a fixed path or on paratest's raw `TEST_TOKEN`, such as a hardcoded port or a shared temp file. Those still collide between concurrent runs and need their own per-run scoping.

Three more things worth knowing:

- The base test database must exist; it's where the lock is taken. It's the same database your serial runs used before this package, so in practice it already does.
- The connection must use discrete `host`/`port`/`database` keys. A `DB_URL`-style connection is refused rather than guessed at.
- PgBouncer in transaction-pooling mode silently breaks session advisory locks. Point your test connection at Postgres directly.

## Resources

Visit the [documentation site](https://mozex.dev/docs/laravel-test-lanes/v1) for searchable docs auto-updated from this repository.

- **[AI Integration](https://mozex.dev/docs/laravel-test-lanes/v1/ai-integration)**: Use this package with AI coding assistants via Context7 and Laravel Boost
- **[Requirements](https://mozex.dev/docs/laravel-test-lanes/v1/requirements)**: PHP, Laravel, and dependency versions
- **[Changelog](https://mozex.dev/docs/laravel-test-lanes/v1/changelog)**: Release history with linked pull requests and diffs
- **[Contributing](https://mozex.dev/docs/laravel-test-lanes/v1/contributing)**: Development setup, code quality, and PR guidelines
- **[Questions & Issues](https://mozex.dev/docs/laravel-test-lanes/v1/questions-and-issues)**: Bug reports, feature requests, and help
- **[Security](mailto:hello@mozex.dev)**: Report vulnerabilities directly via email

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
