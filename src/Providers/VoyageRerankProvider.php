<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\Data\RerankResult;

/**
 * Voyage AI reranker. `POST {url}/rerank`.
 *
 * Verified against live docs (2026): the limit parameter is `top_k` (NOT
 * `top_n`), the response array is `data` (NOT `results`), and the current
 * model family is `rerank-2.5`. Documents are echoed only when
 * `return_documents: true`.
 */
final class VoyageRerankProvider implements RerankProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://api.voyageai.com/v1',
        private readonly string $defaultModel = 'rerank-2.5',
    ) {}

    public function rerank(RerankRequest $request): RerankResponse
    {
        $model = $request->model ?? $this->defaultModel;

        $payload = array_filter([
            'model' => $model,
            'query' => $request->query,
            'documents' => $request->documents,
            'top_k' => $request->topK,
            'return_documents' => $request->returnDocuments,
        ], static fn ($value): bool => $value !== null);

        $response = Http::withToken($this->apiKey)
            ->asJson()
            ->post(rtrim($this->url, '/').'/rerank', $payload)
            ->throw();

        $results = collect($response->json('data', []))
            ->map(fn (array $row): RerankResult => new RerankResult(
                index: (int) $row['index'],
                score: (float) $row['relevance_score'],
                // Voyage echoes `document` only with return_documents; otherwise
                // resolve it from the request the caller already holds.
                document: $row['document'] ?? ($request->documents[$row['index']] ?? null),
            ))
            ->sortByDesc('score')
            ->values()
            ->all();

        return new RerankResponse(
            provider: 'voyageai',
            model: (string) ($response->json('model') ?? $model),
            results: $results,
            usage: (array) $response->json('usage', []),
        );
    }
}
