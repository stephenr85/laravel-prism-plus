<?php

namespace Rushing\PrismPlus;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\Prism;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Facades\PrismPlus as PrismPlusFacade;
use Rushing\PrismPlus\Serializers\ModelListingSerializer;
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
        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('PrismPlus', PrismPlusFacade::class);
        }

        $this->publishes([
            __DIR__.'/../config/prism-plus.php' => config_path('prism-plus.php'),
        ], 'prism-plus-config');

        $this->registerRerankCassetteSerializer();
        $this->registerModelListingCassetteSerializer();

        $this->describeCapabilityRegistry();
    }

    /**
     * Put the capability map into the host's {@see RegistryIndex} — declaring and indexing are two
     * acts (registry-kernel ticket 21 D1), and this is the second one: without it the class carries a
     * correct `#[IsRegistry]` that no `popcorn:registries`, doctor or conformance audit can see.
     *
     * Last in `boot()`, after the serializers, so anything this provider registers is already in
     * place. Host-defined capabilities registered from a host provider (splicewire-app's `retrieval`)
     * land on the same singleton afterwards and are visible through it.
     */
    protected function describeCapabilityRegistry(): void
    {
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(PrismPlusManager::class),
            by: self::class,
        );
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
        $manager = $this->app->make(CassetteManager::class);

        $manager->registerSerializer('rerank', new RerankSerializer);

        // Rerank is a non-Prism capability — it tapes through CassetteManager::tape() directly, not a
        // CassetteProvider decorator — so declare it directly tape-able. This satisfies cassette's
        // scope-disarmed guard without a decoy Prism provider armed just to make record/replay work.
        $manager->armCapability('rerank');
    }

    /**
     * Teach prism-cassette to tape the `models` (listing) capability — so the promotion UI and its
     * tests can run against deterministic recorded fixtures instead of live provider `/models` calls
     * (ADR-0129 §5). Mirrors {@see registerRerankCassetteSerializer()} exactly: listing is a non-Prism
     * capability that tapes through {@see CassetteManager::tape()} directly, so it is armed directly.
     */
    protected function registerModelListingCassetteSerializer(): void
    {
        $manager = $this->app->make(CassetteManager::class);

        $manager->registerSerializer('models', new ModelListingSerializer);
        $manager->armCapability('models');
    }
}
