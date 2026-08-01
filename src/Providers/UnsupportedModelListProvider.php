<?php

namespace Rushing\PrismPlus\Providers;

use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * The honest "this provider cannot be discovered" driver — for OTB providers that expose no
 * model-listing endpoint (VoyageAI, Perplexity). It is a registered, first-class member of the
 * `models` capability, NOT a missing entry: calling it returns {@see ModelListing::unsupported()}
 * with a reason, so a caller iterating the whole roster gets a uniform value and falls back to its
 * static floor for these providers. Discovery degrading to a curated list is a value, not an error.
 */
class UnsupportedModelListProvider implements ModelListProvider
{
    public function __construct(
        private string $provider,
        private string $reason,
    ) {}

    public function listModels(): ModelListing
    {
        return ModelListing::unsupported($this->provider, $this->reason);
    }
}
