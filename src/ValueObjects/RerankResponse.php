<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\ValueObjects;

/**
 * A normalized rerank response: results ordered best-first, regardless of which
 * vendor produced them (Voyage returns them under `data`, Cohere under
 * `results`; both arrive already sorted, but the drivers sort defensively).
 */
final class RerankResponse
{
    /**
     * @param  list<RerankResult>  $results  Ordered by descending score.
     * @param  array<string, mixed>  $usage  Provider-reported usage/billing, verbatim.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $results,
        public readonly array $usage = [],
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
