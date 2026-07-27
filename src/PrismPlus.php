<?php

declare(strict_types=1);

namespace Rushing\PrismPlus;

use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Prism;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Rushing\PrismPlus\Audio\PendingAudioRequest;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\Data\VideoJob;
use Rushing\PrismPlus\Data\VideoRequest;

/**
 * The PrismPlus entry point. Prism stays the invocation engine: every modality
 * Prism already owns is DELEGATED to a plain `Prism\Prism\Prism` untouched.
 * Capabilities Prism has no slot for (rerank, video) are hosted here as parallel
 * first-class capabilities via the {@see PrismPlusManager} registry of registries.
 *
 * The load-bearing rule that decides what enters the registry:
 *
 *   **PrismPlus capabilities are array-boundary invocables; anything that needs a stream stays a raw
 *   Prism delegate on this facade.**
 *
 * A capability enters the registry only if it is a request→response over popcorn's `array in / array
 * out` seam — that array boundary is exactly what lets a local PHP driver, an MCP tool, or a tenant
 * webhook answer it interchangeably. Streaming text with tool-calls cannot be modeled as a single
 * array→array hop, so `text()`/`structured()`/`embeddings()`/`image()`/`moderation()` remain raw
 * `$this->prism->*()` delegates and never touch the registry. Rerank and the video *submit* step do
 * fit the boundary and go through the registry; the video async follow-ups (status/retrieve/cancel)
 * take a full handle rather than a request, so they stay on the typed driver via {@see VideoProvider()}.
 */
class PrismPlus
{
    public function __construct(
        protected PrismPlusManager $manager,
        protected Prism $prism,
    ) {}

    public function text(): PendingTextRequest
    {
        return $this->prism->text();
    }

    public function structured(): PendingStructuredRequest
    {
        return $this->prism->structured();
    }

    public function embeddings(): PendingEmbeddingRequest
    {
        return $this->prism->embeddings();
    }

    public function image(): PendingImageRequest
    {
        return $this->prism->image();
    }

    /**
     * Audio (TTS + STT). Unlike the other existing modalities this is NOT a raw delegate: it returns
     * PrismPlus's {@see PendingAudioRequest} decorator, which normalizes the provider-shaped `voice`
     * and `outputFormat` warts over Prism's own audio drivers.
     */
    public function audio(): PendingAudioRequest
    {
        return new PendingAudioRequest($this->prism->audio());
    }

    public function moderation(): PendingModerationRequest
    {
        return $this->prism->moderation();
    }

    /**
     * Rerank `documents` by relevance to a query — a capability Prism has no slot for. THE single,
     * drift-safe call boundary where the request and response types are paired: resolve the `rerank`
     * capability registry, pick the provider (argument, else `config('prism-plus.defaults.rerank')`),
     * invoke over the array boundary with `$request->toArray()`, and rehydrate via
     * `RerankResponse::from()`. Reuses Prism's own `config('prism.providers.*')` credentials.
     */
    public function rerank(RerankRequest $request, ?string $provider = null): RerankResponse
    {
        $provider = $provider ?? $this->manager->defaultProvider('rerank');

        return RerankResponse::from(
            $this->manager->capability('rerank')->invoke(strtolower($provider), $request->toArray()),
        );
    }

    /**
     * The resolved rerank driver, for callers that want to hold it directly.
     */
    public function rerankProvider(?string $provider = null): RerankProvider
    {
        return $this->manager->rerankProvider($provider);
    }

    /**
     * Submit an async video-generation job — a modality Prism has no slot for, and
     * fundamentally async (submit → poll/webhook → retrieve). Returns a job handle
     * immediately; never blocks on completion. Poll/retrieve/cancel via the driver
     * from {@see VideoProvider()} (the app persists the handle and drives it from a
     * queued worker).
     */
    public function video(VideoRequest $request, ?string $provider = null): VideoJob
    {
        $provider = $provider ?? $this->manager->defaultProvider('video');

        // Only the submit step (request→response) crosses the registry; the async follow-ups
        // (status/retrieve/cancel) take the full handle and stay on the typed driver via videoProvider().
        return VideoJob::fromArray(
            $this->manager->capability('video')->invoke(strtolower($provider), $request->toArray()),
        );
    }

    /**
     * The resolved video driver, for callers that hold it directly to poll/retrieve/
     * cancel. `$providerConfig` threads a per-call (BYO) credential override.
     *
     * @param  array<string, mixed>  $providerConfig
     */
    public function videoProvider(?string $provider = null, array $providerConfig = []): VideoProvider
    {
        return $this->manager->videoProvider($provider, $providerConfig);
    }

    /**
     * List the models a provider offers — a capability Prism has no slot for (Prism has no
     * cross-provider client-side listing). Resolve the `models` capability registry, invoke the named
     * provider over the array boundary, and rehydrate a {@see ModelListing}. Reuses Prism's own
     * `config('prism.providers.*')` credentials.
     *
     * The result is a two-state value: `supported` with normalized {@see ModelDescriptor}s,
     * or `unsupported` with a reason (VoyageAI, Perplexity) — never a thrown error for the "no
     * listing endpoint" case, so a caller iterating {@see modelProviders()} handles the whole roster
     * uniformly.
     */
    public function models(string $provider): ModelListing
    {
        return ModelListing::from(
            $this->manager->capability('models')->invoke(strtolower($provider), []),
        );
    }

    /**
     * The Prism OTB providers with a registered model-listing driver — the roster to iterate when
     * discovering candidates across every configured provider.
     *
     * @return list<string>
     */
    public function modelProviders(): array
    {
        return PrismPlusManager::MODEL_PROVIDERS;
    }
}
