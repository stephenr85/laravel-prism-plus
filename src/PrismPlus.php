<?php

declare(strict_types=1);

namespace Rushing\PrismPlus;

use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Prism;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\ValueObjects\RerankRequest;
use Rushing\PrismPlus\ValueObjects\RerankResponse;

/**
 * The PrismPlus entry point. Prism stays the invocation engine: every modality
 * Prism already owns is DELEGATED to a plain `Prism\Prism\Prism` untouched.
 * Modalities Prism has no slot for (rerank today; audio/video later) are hosted
 * here as parallel first-class modalities via {@see PrismPlusManager}.
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

    public function audio(): PendingAudioRequest
    {
        return $this->prism->audio();
    }

    public function moderation(): PendingModerationRequest
    {
        return $this->prism->moderation();
    }

    /**
     * Rerank `documents` by relevance to a query — a modality Prism has no slot
     * for. Resolves the driver from Prism's own provider credentials.
     */
    public function rerank(RerankRequest $request, ?string $provider = null): RerankResponse
    {
        return $this->rerankProvider($provider)->rerank($request);
    }

    /**
     * The resolved rerank driver, for callers that want to hold it directly.
     */
    public function rerankProvider(?string $provider = null): RerankProvider
    {
        return $this->manager->rerankProvider($provider);
    }
}
