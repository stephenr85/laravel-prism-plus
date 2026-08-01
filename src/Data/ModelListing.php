<?php

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The result of asking a provider what models it offers — a two-state sum type flattened into one
 * Data class so it survives the array boundary and rehydrates cleanly:
 *
 *   - {@see listed()}      — `supported: true`, a normalized {@see ModelDescriptor} per model.
 *   - {@see unsupported()} — `supported: false` + a `reason`; the provider has no model-listing
 *                            endpoint (VoyageAI, Perplexity). This is a VALUE, never an exception:
 *                            the host's curation gate reads it and falls back to the static floor.
 *
 * "Can't discover" is a first-class outcome, not an error — that is what lets the same capability
 * span a rich lister (Anthropic), a thin one (OpenAI-shape), and a provider with no listing at all.
 */
class ModelListing extends Data
{
    /**
     * @param  list<ModelDescriptor>  $models
     */
    public function __construct(
        public string $provider,
        public bool $supported,
        #[DataCollectionOf(ModelDescriptor::class)]
        public array $models = [],
        public ?string $reason = null,
    ) {}

    /**
     * @param  list<ModelDescriptor>  $models
     */
    public static function listed(string $provider, array $models): self
    {
        return new self(provider: $provider, supported: true, models: array_values($models));
    }

    public static function unsupported(string $provider, string $reason): self
    {
        return new self(provider: $provider, supported: false, models: [], reason: $reason);
    }

    /**
     * The listed model ids, in provider order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (ModelDescriptor $m): string => $m->id, $this->models);
    }
}
