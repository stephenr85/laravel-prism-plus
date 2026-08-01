<?php

namespace Rushing\PrismPlus\Contracts;

use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;

/**
 * A rerank driver. Deliberately NOT a `Prism\Prism\Providers\Provider` subclass:
 * Prism's base class is a closed, fixed-slot contract (text/structured/embeddings/
 * images/moderation/audio) with no rerank slot, so rerank lives in this parallel
 * PrismPlus-owned contract instead.
 */
interface RerankProvider
{
    public function rerank(RerankRequest $request): RerankResponse;
}
