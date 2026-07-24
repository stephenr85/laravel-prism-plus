<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\ValueObjects;

/**
 * One reranked hit: the document's original position in the request's
 * `documents` array, its relevance score, and (optionally) the document text.
 */
final class RerankResult
{
    public function __construct(
        public readonly int $index,
        public readonly float $score,
        public readonly ?string $document = null,
    ) {}
}
