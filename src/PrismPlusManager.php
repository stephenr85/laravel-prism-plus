<?php

declare(strict_types=1);

namespace Rushing\PrismPlus;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Providers\CohereRerankProvider;
use Rushing\PrismPlus\Providers\FalVideoProvider;
use Rushing\PrismPlus\Providers\RecordingRerankProvider;
use Rushing\PrismPlus\Providers\VoyageRerankProvider;

/**
 * A parallel manager mirroring `Prism\Prism\PrismManager`'s resolve/extend/
 * customCreators shape. It resolves NEW-modality drivers (rerank today) from the
 * SAME `config('prism.providers.*')` credential blocks Prism uses — so keys are
 * configured once — merged with any per-modality defaults from `prism-plus.php`.
 *
 * Why not reuse `PrismManager::extend`: that returns a `Provider` subclass whose
 * only callable methods are Prism's eight fixed modality slots; a rerank driver
 * registered there would have nowhere to expose `rerank()`.
 */
class PrismPlusManager
{
    /** @var array<string, Closure> */
    protected array $customCreators = [];

    /**
     * Custom VIDEO creators are kept separate from {@see $customCreators} (rerank):
     * both maps are keyed by bare provider name, and a single map would let a `fal`
     * video driver and a same-named rerank driver collide.
     *
     * @var array<string, Closure>
     */
    protected array $videoCreators = [];

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * Resolve a rerank driver by provider name (defaults to the configured
     * default provider). Mirrors `PrismManager::resolve`.
     *
     * @param  array<string, mixed>  $providerConfig  Per-call credential/config override.
     *
     * @throws InvalidArgumentException
     */
    public function rerankProvider(?string $name = null, array $providerConfig = []): RerankProvider
    {
        $name = $this->resolveName($name ?? $this->defaultRerankProvider());

        $config = array_merge($this->getConfig($name), $providerConfig);

        if (isset($this->customCreators[$name])) {
            return $this->recordable($name, $this->callCustomCreator($name, $config));
        }

        $factory = sprintf('create%sReranker', ucfirst($name));

        if (method_exists($this, $factory)) {
            return $this->recordable($name, $this->{$factory}($config));
        }

        throw new InvalidArgumentException("Rerank provider [{$name}] is not supported.");
    }

    /**
     * Soft-inject cassette record/replay around a resolved rerank driver. Only interposes the
     * recorder when prism-cassette is installed — absent, the bare driver is returned and PrismPlus
     * behaves exactly as before (no hard dependency; see {@see RecordingRerankProvider}).
     */
    protected function recordable(string $provider, RerankProvider $driver): RerankProvider
    {
        if (! class_exists(CassetteManager::class)) {
            return $driver;
        }

        return new RecordingRerankProvider($driver, $this->app, $provider);
    }

    /**
     * Register a custom rerank driver. Mirrors `PrismManager::extend`; the
     * closure receives `($app, $config)` and must return a {@see RerankProvider}.
     */
    public function extend(string $provider, Closure $callback): self
    {
        $this->customCreators[$this->resolveName($provider)] = $callback;

        return $this;
    }

    /**
     * Resolve an async video driver by provider name (defaults to the configured
     * default provider). Mirrors {@see RerankProvider()} — same credential-reuse
     * from `config('prism.providers.*')`, its own creator map.
     *
     * @param  array<string, mixed>  $providerConfig  Per-call credential/config override (BYO key).
     *
     * @throws InvalidArgumentException
     */
    public function videoProvider(?string $name = null, array $providerConfig = []): VideoProvider
    {
        $name = $this->resolveName($name ?? $this->defaultVideoProvider());

        $config = array_merge($this->getConfig($name), $providerConfig);

        if (isset($this->videoCreators[$name])) {
            return $this->videoCreators[$name]($this->app, $config);
        }

        $factory = sprintf('create%sVideoProvider', ucfirst($name));

        if (method_exists($this, $factory)) {
            return $this->{$factory}($config);
        }

        throw new InvalidArgumentException("Video provider [{$name}] is not supported.");
    }

    /**
     * Register a custom video driver. Mirrors {@see extend()}; the closure receives
     * `($app, $config)` and must return a {@see VideoProvider}.
     */
    public function extendVideo(string $provider, Closure $callback): self
    {
        $this->videoCreators[$this->resolveName($provider)] = $callback;

        return $this;
    }

    protected function defaultRerankProvider(): string
    {
        return (string) config('prism-plus.rerank.default_provider', 'voyageai');
    }

    protected function defaultVideoProvider(): string
    {
        return (string) config('prism-plus.video.default_provider', 'fal');
    }

    protected function resolveName(string $name): string
    {
        return strtolower($name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createVoyageaiReranker(array $config): VoyageRerankProvider
    {
        return new VoyageRerankProvider(
            apiKey: (string) ($config['api_key'] ?? ''),
            url: (string) ($config['url'] ?? 'https://api.voyageai.com/v1'),
            defaultModel: (string) config('prism-plus.rerank.providers.voyageai.model', 'rerank-2.5'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createCohereReranker(array $config): CohereRerankProvider
    {
        return new CohereRerankProvider(
            apiKey: (string) ($config['api_key'] ?? ''),
            url: (string) ($config['url'] ?? 'https://api.cohere.com/v2'),
            defaultModel: (string) config('prism-plus.rerank.providers.cohere.model', 'rerank-v4.0-pro'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFalVideoProvider(array $config): FalVideoProvider
    {
        return new FalVideoProvider(
            apiKey: (string) ($config['api_key'] ?? ''),
            url: (string) ($config['url'] ?? 'https://queue.fal.run'),
            defaultModel: (string) config('prism-plus.video.providers.fal.model', 'fal-ai/veo3'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callCustomCreator(string $provider, array $config): RerankProvider
    {
        return $this->customCreators[$provider]($this->app, $config);
    }

    /**
     * Read Prism's own provider credential block, so a key configured once for
     * Prism is reused verbatim by the new modality.
     *
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        return (array) config("prism.providers.{$name}", []);
    }
}
