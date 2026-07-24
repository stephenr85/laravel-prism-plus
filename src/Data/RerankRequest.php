<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A provider-portable rerank request: score `documents` by relevance to `query`
 * and return them best-first. Normalized shape — each driver maps `topK` onto
 * its own vendor parameter (Voyage `top_k`, Cohere `top_n`); this class never
 * leaks a vendor spelling.
 *
 * A `spatie/laravel-data` class (the portfolio's DTO substrate): `RerankRequest::from($array)`
 * hydrates the array-in half of the registry's array→array boundary, `->toArray()` dehydrates it.
 * Deliberately no `final`/`readonly` — the maintainer's extension-friendly style.
 */
#[TypeScript]
class RerankRequest extends Data
{
    /**
     * @param  list<string>  $documents  Plain strings — both Voyage and Cohere take strings.
     * @param  int|null  $topK  Keep only the best K (null = all).
     * @param  string|null  $model  Override the provider's default rerank model.
     * @param  bool  $returnDocuments  Ask the provider to echo the document text where supported.
     */
    public function __construct(
        public string $query,
        public array $documents,
        public ?int $topK = null,
        public ?string $model = null,
        public bool $returnDocuments = false,
    ) {}

    public function withModel(?string $model): static
    {
        return new static(
            query: $this->query,
            documents: $this->documents,
            topK: $this->topK,
            model: $model,
            returnDocuments: $this->returnDocuments,
        );
    }
}
