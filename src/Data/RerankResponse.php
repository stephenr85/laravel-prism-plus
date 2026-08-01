<?php

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A normalized rerank response: results ordered best-first, regardless of which
 * vendor produced them (Voyage returns them under `data`, Cohere under
 * `results`; both arrive already sorted, but the drivers sort defensively).
 *
 * `RerankResponse::from($array)` rehydrates the array-out half of the registry boundary — the typed
 * facade accessor's single rehydration point — and `->toArray()` dehydrates it back.
 */
#[TypeScript]
class RerankResponse extends Data
{
    /**
     * @param  list<RerankResult>  $results  Ordered by descending score.
     * @param  array<string, mixed>  $usage  Provider-reported usage/billing, verbatim.
     */
    public function __construct(
        public string $provider,
        public string $model,
        #[DataCollectionOf(RerankResult::class)]
        public array $results,
        public array $usage = [],
    ) {}

    /**
     * The original document indices, best-first — feed this straight back to
     * whatever array you passed in as `documents` to reorder it.
     *
     * @return list<int>
     */
    public function order(): array
    {
        return array_map(static fn (RerankResult $r): int => $r->index, $this->results);
    }
}
