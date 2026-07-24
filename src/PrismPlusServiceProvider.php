<?php

declare(strict_types=1);

namespace Rushing\PrismPlus;

use Illuminate\Support\ServiceProvider;
use Prism\Prism\Prism;

class PrismPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prism-plus.php', 'prism-plus');

        $this->app->singleton(PrismPlusManager::class);

        $this->app->singleton(PrismPlus::class, static fn ($app): PrismPlus => new PrismPlus(
            $app->make(PrismPlusManager::class),
            // Prism is plain-instantiable and stays the invocation engine.
            new Prism,
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/prism-plus.php' => config_path('prism-plus.php'),
        ], 'prism-plus-config');
    }
}
