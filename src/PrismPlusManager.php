<?php

declare(strict_types=1);

namespace Rushing\PrismPlus;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Invocables\RerankInvocable;
use Rushing\PrismPlus\Invocables\VideoInvocable;
use Rushing\PrismPlus\Providers\CohereRerankProvider;
use Rushing\PrismPlus\Providers\FalVideoProvider;
use Rushing\PrismPlus\Providers\InvocableRerankProvider;
use Rushing\PrismPlus\Providers\RecordingRerankProvider;
use Rushing\PrismPlus\Providers\VoyageRerankProvider;

/**
 * The PrismPlus **registry of registries**: a capability name maps to a popcorn
 * {@see InvocableRegistry}, and a provider name is a key within it holding an {@see Invocable}.
 * Two levels, both plain string-keyed maps — there is no reflection-string-to-method dispatch and no
 * per-capability copied estate. Adding a *provider* is a `register()` call; adding a *capability* is a
 * new key seeded with its first provider.
 *
 * Provider credentials are read from the SAME `config('prism.providers.*')` blocks Prism uses, so a
 * key configured once for Prism embeddings is reused verbatim by PrismPlus rerank/video.
 */
class PrismPlusManager
{
    /**
     * capability => registry of provider invocables. Seeded lazily on first {@see capability()}
     * access with the package's built-in providers; a host adds/overrides via {@see register()}.
     *
     * @var array<string, InvocableRegistry>
     */
    protected array $capabilities = [];

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * The registry for a capability — the middle of the registry-of-registries. Created and seeded
     * with the package's built-in providers on first access.
     */
    public function capability(string $capability): InvocableRegistry
    {
        $capability = $this->resolveName($capability);

        return $this->capabilities[$capability] ??= $this->seed($capability);
    }

    /**
     * Register a provider {@see Invocable} under a capability — the host-facing open-registration
     * seam. Re-registering under the same capability+provider key overrides the prior binding
     * (popcorn semantics), so a default swaps for a tenant-specific binding without callers changing.
     * Registering the first provider under a brand-new capability key *is* adding a capability.
     */
    public function register(string $capability, Invocable $invocable): static
    {
        $this->capability($capability)->register($invocable);

        return $this;
    }

    /**
     * Forget a provider registration under a capability — the teardown half of a per-tenant overlay,
     * so nothing bleeds across tenants on a shared worker. A no-op if the capability/provider is absent.
     */
    public function forget(string $capability, string $provider): static
    {
        $capability = $this->resolveName($capability);

        if (isset($this->capabilities[$capability])) {
            $this->capabilities[$capability]->forget($this->resolveName($provider));
        }

        return $this;
    }

    /**
     * The default provider for a capability, from `config('prism-plus.defaults.<capability>')`.
     */
    public function defaultProvider(string $capability): string
    {
        $capability = $this->resolveName($capability);

        $default = config("prism-plus.defaults.{$capability}");

        if (! is_string($default) || $default === '') {
            throw new InvalidArgumentException("No default provider configured for capability [{$capability}].");
        }

        return $this->resolveName($default);
    }

    /**
     * Seed a capability's registry with the package's built-in providers. A capability with no
     * built-ins (a host-defined one) gets an empty registry the host then registers into.
     */
    protected function seed(string $capability): InvocableRegistry
    {
        $registry = new InvocableRegistry;

        match ($capability) {
            'rerank' => $registry
                ->register($this->rerankInvocable('voyageai'))
                ->register($this->rerankInvocable('cohere')),
            'video' => $registry
                ->register($this->videoInvocable('fal')),
            default => null,
        };

        return $registry;
    }

    protected function rerankInvocable(string $provider): RerankInvocable
    {
        return new RerankInvocable(
            $provider,
            fn (): RerankProvider => $this->recordable($provider, $this->makeRerankDriver($provider)),
        );
    }

    protected function videoInvocable(string $provider): VideoInvocable
    {
        return new VideoInvocable(
            $provider,
            fn (): VideoProvider => $this->makeVideoDriver($provider),
        );
    }

    /**
     * Build a typed rerank driver from Prism's own credential block (plus an optional per-call BYO
     * override). Replaces the deleted `create*Reranker`/`method_exists` dispatch.
     *
     * @param  array<string, mixed>  $providerConfig
     */
    protected function makeRerankDriver(string $provider, array $providerConfig = []): RerankProvider
    {
        $config = array_merge($this->getConfig($provider), $providerConfig);

        return match ($provider) {
            'voyageai' => new VoyageRerankProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                url: (string) ($config['url'] ?? 'https://api.voyageai.com/v1'),
                defaultModel: (string) config('prism-plus.rerank.providers.voyageai.model', 'rerank-2.5'),
            ),
            'cohere' => new CohereRerankProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                url: (string) ($config['url'] ?? 'https://api.cohere.com/v2'),
                defaultModel: (string) config('prism-plus.rerank.providers.cohere.model', 'rerank-v4.0-pro'),
            ),
            default => throw new InvalidArgumentException("Rerank provider [{$provider}] is not supported."),
        };
    }

    /**
     * Build a typed video driver from Prism's own credential block (plus an optional per-call BYO
     * override). Replaces the deleted `create*VideoProvider`/`method_exists` dispatch.
     *
     * @param  array<string, mixed>  $providerConfig
     */
    protected function makeVideoDriver(string $provider, array $providerConfig = []): VideoProvider
    {
        $config = array_merge($this->getConfig($provider), $providerConfig);

        return match ($provider) {
            'fal' => new FalVideoProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                url: (string) ($config['url'] ?? 'https://queue.fal.run'),
                defaultModel: (string) config('prism-plus.video.providers.fal.model', 'fal-ai/veo3'),
            ),
            default => throw new InvalidArgumentException("Video provider [{$provider}] is not supported."),
        };
    }

    /**
     * Wrap a resolved rerank driver in cassette record/replay. Fixture recording is a first-class
     * prism-plus capability — prism-cassette is a hard dependency (the "plus": Prism plus, among other
     * goodies, universally-useful fixture recording) — so every resolved driver is wrapped, whether the
     * caller reaches it through `PrismPlus::rerank()` or holds it directly via `rerankProvider()`. The
     * wrapper is inert unless a cassette is armed (passthrough runs the driver live), so this changes
     * nothing for callers who never record; see {@see RecordingRerankProvider}.
     */
    protected function recordable(string $provider, RerankProvider $driver): RerankProvider
    {
        return new RecordingRerankProvider($driver, $this->app, $provider);
    }

    /**
     * @deprecated Register a provider into the rerank capability registry, or call `PrismPlus::rerank()`.
     * A thin shim over the registry: returns the typed rerank driver for the registered provider so
     * existing callers holding the driver directly (`rerankProvider($p)->rerank(...)`) keep working.
     *
     * @param  array<string, mixed>  $providerConfig  Per-call BYO credential override.
     *
     * @throws InvalidArgumentException
     */
    public function rerankProvider(?string $name = null, array $providerConfig = []): RerankProvider
    {
        $name = $this->resolveName($name ?? $this->defaultProvider('rerank'));

        $invocable = $this->capability('rerank')->get($name);

        // A per-call BYO credential override can't reuse a registered singleton driver — rebuild a
        // fresh typed driver with the merged config (recordable-wrapped, as before).
        if ($providerConfig !== [] && $invocable instanceof RerankInvocable) {
            return $this->recordable($name, $this->makeRerankDriver($name, $providerConfig));
        }

        if ($invocable instanceof RerankInvocable) {
            return $invocable->driver();
        }

        // A host-registered raw invocable (LocalInvocable fake / remote binding) — adapt it back to
        // a typed driver so this deprecated surface still resolves through the same registry entry.
        return new InvocableRerankProvider($invocable);
    }

    /**
     * @deprecated Register a provider into the rerank capability registry instead.
     * Register a custom rerank driver. The closure receives `($app, $config)` and must return a
     * {@see RerankProvider}; it is wrapped as an array-boundary {@see RerankInvocable} in the registry.
     */
    public function extend(string $provider, Closure $callback): self
    {
        $provider = $this->resolveName($provider);

        $this->register('rerank', new RerankInvocable(
            $provider,
            fn (): RerankProvider => $this->recordable($provider, $callback($this->app, $this->getConfig($provider))),
        ));

        return $this;
    }

    /**
     * @deprecated Register a provider into the video capability registry, or call `PrismPlus::video()`.
     * A thin shim over the registry: returns the typed video driver for the registered provider so
     * callers can drive the async follow-ups (`status()`/`retrieve()`/`cancel()`) it holds.
     *
     * @param  array<string, mixed>  $providerConfig  Per-call BYO credential override.
     *
     * @throws InvalidArgumentException
     */
    public function videoProvider(?string $name = null, array $providerConfig = []): VideoProvider
    {
        $name = $this->resolveName($name ?? $this->defaultProvider('video'));

        $invocable = $this->capability('video')->get($name);

        if ($providerConfig !== [] && $invocable instanceof VideoInvocable) {
            return $this->makeVideoDriver($name, $providerConfig);
        }

        if ($invocable instanceof VideoInvocable) {
            return $invocable->driver();
        }

        throw new InvalidArgumentException("Video provider [{$name}] does not expose a typed driver for status/retrieve/cancel.");
    }

    /**
     * @deprecated Register a provider into the video capability registry instead.
     * Register a custom video driver. The closure receives `($app, $config)` and must return a
     * {@see VideoProvider}; it is wrapped as an array-boundary {@see VideoInvocable} in the registry.
     */
    public function extendVideo(string $provider, Closure $callback): self
    {
        $provider = $this->resolveName($provider);

        $this->register('video', new VideoInvocable(
            $provider,
            fn (): VideoProvider => $callback($this->app, $this->getConfig($provider)),
        ));

        return $this;
    }

    protected function resolveName(string $name): string
    {
        return strtolower($name);
    }

    /**
     * Read Prism's own provider credential block, so a key configured once for
     * Prism is reused verbatim by the new capability.
     *
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        return (array) config("prism.providers.{$name}", []);
    }
}
