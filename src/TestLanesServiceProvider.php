<?php

declare(strict_types=1);

namespace Mozex\TestLanes;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Process;
use Mozex\TestLanes\Commands\CleanupCommand;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class TestLanesServiceProvider extends PackageServiceProvider
{
    protected string $repository = 'https://github.com/mozex/laravel-test-lanes';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-test-lanes')
            ->hasConfigFile()
            ->hasCommand(CleanupCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->endWith(fn (InstallCommand $command) => $this->askToStar($command));
            });
    }

    /**
     * A person gets the question, defaulting to yes. A run nobody can answer
     * (--no-interaction, or no terminal on stdin, as with CI and AI agents)
     * takes that default without asking, so it gets a note explaining the
     * browser tab instead.
     */
    protected function askToStar(InstallCommand $command): void
    {
        if (! $this->isInteractive($command)) {
            $command->info('If laravel-test-lanes saves you time, please consider starring it on GitHub: '.$this->repository);

            $this->openInBrowser();

            return;
        }

        if (! $command->confirm('Would you like to show some love by starring laravel-test-lanes on GitHub?', true)) {
            return;
        }

        if ($this->openInBrowser()) {
            return;
        }

        $command->info("You'll find laravel-test-lanes at ".$this->repository);
    }

    /**
     * Laravel's own rule for prompts (stdin must be a terminal, except under
     * unit tests, where the console output is faked), except that
     * --no-interaction always wins, which keeps that path testable.
     */
    protected function isInteractive(InstallCommand $command): bool
    {
        if ($command->option('no-interaction') === true) {
            return false;
        }

        if ($this->app->runningUnitTests()) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Best effort: any failure returns false. On Linux the opener runs in the
     * background, because xdg-open without a detected desktop runs the browser
     * in the foreground and would hold the command until the browser closes.
     */
    protected function openInBrowser(): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $this->repository],
            'Windows' => ['cmd', '/c', 'start', '', $this->repository],
            default => ['sh', '-c', 'command -v xdg-open > /dev/null && (xdg-open "$1" > /dev/null 2>&1 &)', 'sh', $this->repository],
        };

        try {
            return Process::run($command)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The package is a drop-in: requiring it is the opt-in, so the provider
     * wires the lanes itself and no TestCase changes are needed. Providers
     * boot inside createApplication(), which runs before Laravel's parallel
     * testing callbacks read the token, so the timing always holds. The gate
     * is runningUnitTests() (APP_ENV=testing, Laravel's phpunit.xml default);
     * suites running under another environment name call TestLanes::register()
     * from their base TestCase's createApplication() instead.
     */
    public function packageBooted(): void
    {
        if ($this->app->runningUnitTests()) {
            TestLanes::register();
        }
    }

    /**
     * Laravel merges a published config with a shallow array_merge, so a
     * user's "locks" block would replace ours wholesale and silently lose
     * drivers we add later. Re-merge here after that shallow pass: nested
     * option groups fill in from the defaults while the user's own values
     * win.
     */
    public function packageRegistered(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/test-lanes.php';

        /** @var array<string, mixed> $published */
        $published = $config->get('test-lanes', []);

        $config->set('test-lanes', $this->mergeConfig($defaults, $published));
    }

    /**
     * Deep-merge maps, replace lists. A nested option group fills in from the
     * defaults, while a sequential list the user provided is kept as theirs
     * rather than being index-merged with ours.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    protected function mergeConfig(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $recurse = is_array($value) && ! array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($defaults[$key]);

            /** @var array<string, mixed> $default */
            $default = $recurse ? $defaults[$key] : [];

            /** @var array<string, mixed> $override */
            $override = $recurse ? $value : [];

            $defaults[$key] = $recurse ? $this->mergeConfig($default, $override) : $value;
        }

        return $defaults;
    }
}
