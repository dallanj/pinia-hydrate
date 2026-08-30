<?php

namespace Dallanj\PiniaHydrate;

use Dallanj\PiniaHydrate\Console\MakePiniaHydratorCommand;
use Dallanj\PiniaHydrate\Contracts\Hydrator;
use Illuminate\Support\ServiceProvider;

final class PiniaHydrateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pinia-hydrate.php', 'pinia-hydrate');
        $this->app->singleton(
            ModuleRegistry::class,
            fn () => new ModuleRegistry(config('pinia-hydrate.modules', [])),
        );
        $this->app->singleton(StateNormalizer::class);

        // Hydration state is request-specific and must never leak between
        // long-lived Octane requests.
        $this->app->scoped(HydrationFactory::class);
        $this->app->scoped(Hydrator::class, fn ($app) => $app->make(HydrationFactory::class));
        $this->app->alias(Hydrator::class, 'pinia-hydrate');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakePiniaHydratorCommand::class]);
            $this->publishes([
                __DIR__.'/../config/pinia-hydrate.php' => config_path('pinia-hydrate.php'),
            ], 'pinia-hydrate-config');
        }
    }
}
