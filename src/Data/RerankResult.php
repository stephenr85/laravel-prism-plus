<?php

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One reranked hit: the document's original position in the request's
 * `documents` array, its relevance score, and (optionally) the document text.
 */
#[TypeScript]
class RerankResult extends Data
{
    public function __construct(
        public int $index,
        public float $score,
        public ?string $document = null,
    ) {}
}
