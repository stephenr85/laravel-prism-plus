<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\ValueObjects;

/**
 * A provider-portable rerank request: score `documents` by relevance to `query`
 * and return them best-first. Normalized shape — each driver maps `topK` onto
 * its own vendor parameter (Voyage `top_k`, Cohere `top_n`); this class never
 * leaks a vendor spelling.
 */
final class RerankRequest
{
    /**
     * @param  list<string>  $documents  Plain strings — both Voyage and Cohere take strings.
     * @param  int|null  $topK  Keep only the best K (null = all).
     * @param  string|null  $model  Override the provider's default rerank model.
     * @param  bool  $returnDocuments  Ask the provider to echo the document text where supported.
     */
    public function __construct(
        public readonly string $query,
        public readonly array $documents,
        public readonly ?int $topK = null,
        public readonly ?string $model = null,
        public readonly bool $returnDocuments = false,
    ) {}

    public function withModel(?string $model): self
    {
        return new self(
            query: $this->query,
            documents: $this->documents,
            topK: $this->topK,
            model: $model,
            returnDocuments: $this->returnDocuments,
        );
    }
}
