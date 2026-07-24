<?php

declare(strict_types=1);

namespace Rushing\PrismPlus;

use Illuminate\Support\ServiceProvider;
use Prism\Prism\Prism;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Serializers\RerankSerializer;

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

        $this->registerRerankCassetteSerializer();
    }

    /**
     * Teach prism-cassette to tape the rerank capability. prism-cassette is a hard dependency of
     * prism-plus, so this always registers — the serializer, which owns PrismPlus's
     * RerankRequest/RerankResponse types ("who owns the response type owns the serializer"), plugs
     * into cassette's public extension seam.
     *
     * Registers DIRECTLY on the resolved manager (not via afterResolving): CassetteServiceProvider
     * resolves and caches the CassetteManager singleton during its own boot (armProviders), so an
     * afterResolving callback attached in a boot() can miss that already-resolved instance. Resolving
     * it here in boot() is safe and order-independent — make() either returns the cached singleton or
     * resolves it (firing cassette's own tts/stt registration).
     */
    protected function registerRerankCassetteSerializer(): void
    {
        $this->app->make(CassetteManager::class)->registerSerializer('rerank', new RerankSerializer);
    }
}
