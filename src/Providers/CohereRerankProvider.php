<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\Data\RerankResult;

/**
 * Cohere Rerank v2. `POST {url}/rerank` where `{url}` already carries the `/v2`
 * segment.
 *
 * Verified against live docs (2026): the limit parameter is `top_n` (NOT
 * `top_k`), the response array is `results` (NOT `data`), documents are NEVER
 * echoed (map `index` back yourself), and `rerank-v3.5` is deprecated in favour
 * of `rerank-v4.0-pro` / `-fast` (whose scores are not comparable to v3.5).
 */
final class CohereRerankProvider implements RerankProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://api.cohere.com/v2',
        private readonly string $defaultModel = 'rerank-v4.0-pro',
    ) {}

    public function rerank(RerankRequest $request): RerankResponse
    {
        $model = $request->model ?? $this->defaultModel;

        $payload = array_filter([
            'model' => $model,
            'query' => $request->query,
            'documents' => $request->documents,
            'top_n' => $request->topK,
        ], static fn ($value): bool => $value !== null);

        $response = Http::withToken($this->apiKey)
            ->asJson()
            ->post(rtrim($this->url, '/').'/rerank', $payload)
            ->throw();

        $results = collect($response->json('results', []))
            ->map(fn (array $row): RerankResult => new RerankResult(
                index: (int) $row['index'],
                score: (float) $row['relevance_score'],
                // Cohere never echoes documents — resolve from the request.
                document: $request->documents[$row['index']] ?? null,
            ))
            ->sortByDesc('score')
            ->values()
            ->all();

        return new RerankResponse(
            provider: 'cohere',
            model: $model,
            results: $results,
            usage: (array) ($response->json('meta.billed_units') ?? []),
        );
    }
}
