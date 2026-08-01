<?php

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;

/**
 * One normalized model entry from a provider's listing — the "wagon" built over the loose
 * `GET /models` envelope wheel. Shaped to what a model catalog consumes (id, context window,
 * accepted input kinds) rather than to any one provider's ontology; every provider fills what it can
 * and leaves the rest null. `raw` carries the provider's row verbatim so nothing is lost in
 * normalization — a curation gate can reach into it for provider-specific fields.
 *
 * A DISCOVERED CANDIDATE, not an OFFERED model: this shape stays in prism-plus's world. The host's
 * curation gate translates an approved descriptor into its own offered-catalog entry, supplying the
 * policy fields (pricing, metering, routing) this descriptor deliberately does not carry.
 */
class ModelDescriptor extends Data
{
    /**
     * @param  list<string>  $accepts  input kinds the model accepts, normalized (e.g. ['image', 'document'])
     * @param  array<string, mixed>  $raw  the provider's listing row, verbatim
     */
    public function __construct(
        public string $id,
        public string $provider,
        public ?string $displayName = null,
        public ?int $contextWindow = null,
        public ?int $maxOutput = null,
        public array $accepts = [],
        public array $raw = [],
    ) {}
}
