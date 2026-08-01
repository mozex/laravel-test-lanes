<?php

declare(strict_types=1);

namespace Mozex\TestLanes;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Mozex\TestLanes\Commands\CleanupCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TestLanesServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-test-lanes')
            ->hasConfigFile()
            ->hasCommand(CleanupCommand::class);
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
