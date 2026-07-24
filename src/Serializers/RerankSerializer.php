<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Serializers;

use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\Contracts\CassetteSerializer;
use Rushing\PrismCassette\Contracts\RefinesEventMetering;
use Rushing\PrismCassette\Events\CassetteResolved;
use Rushing\PrismPlus\ValueObjects\RerankCassetteSubject;
use Rushing\PrismPlus\ValueObjects\RerankRequest;
use Rushing\PrismPlus\ValueObjects\RerankResponse;
use Rushing\PrismPlus\ValueObjects\RerankResult;

/**
 * Tapes PrismPlus rerank through cassette's capability serializer seam. Ships HERE (not in cassette)
 * because the request/response types are PrismPlus's — "who owns the response type owns the
 * serializer" — and registers into cassette via {@see CassetteManager::registerSerializer()},
 * guarded by `class_exists` so PrismPlus never hard-depends on cassette.
 *
 * The taping subject is a {@see RerankCassetteSubject} (provider + request), never the bare
 * provider-agnostic {@see RerankRequest}, so a Voyage cassette can
 * never replay for a same-input Cohere call.
 *
 * The request's `model` may be null ("provider default"), so it drives only the cassette KEY and the
 * replay-miss preview; the metering event instead carries the RESOLVED model + raw vendor usage from
 * the response via {@see RefinesEventMetering}. Rerank usage has no token-based Prism shape (Voyage
 * bills `total_tokens`; Cohere `search_units`), so {@see Usage()} is a best-effort synthesis and
 * {@see rawUsage()} carries the vendor array verbatim onto {@see CassetteResolved::$rawUsage}.
 */
final class RerankSerializer implements CassetteSerializer, RefinesEventMetering
{
    public function key(object $request): string
    {
        /** @var RerankCassetteSubject $request */
        $payload = [
            'type' => 'rerank',
            'provider' => $request->provider,
            'query' => $request->request->query,
            'documents' => $request->request->documents,
            'top_k' => $request->request->topK,
            'model' => $request->request->model,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function provider(object $request): string
    {
        /** @var RerankCassetteSubject $request */
        return $request->provider;
    }

    public function model(object $request): string
    {
        /** @var RerankCassetteSubject $request */
        // The REQUESTED model — drives the key + replay-miss preview only; null ("provider default")
        // yields ''. The metering event reports the RESOLVED model via resolvedModel() instead.
        return $request->request->model ?? '';
    }

    public function resolvedModel(object $response): string
    {
        /** @var RerankResponse $response */
        return $response->model;
    }

    public function preview(object $request): string
    {
        /** @var RerankCassetteSubject $request */
        return substr($request->request->query, 0, 80);
    }

    public function serialize(object $request, object $response, string $recordedAt): array
    {
        /** @var RerankCassetteSubject $request */
        /** @var RerankResponse $response */
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'rerank',
                'provider' => $request->provider,
                'query' => $request->request->query,
                'documents' => $request->request->documents,
                'top_k' => $request->request->topK,
                'model' => $request->request->model,
                'return_documents' => $request->request->returnDocuments,
            ],
            'response' => [
                'provider' => $response->provider,
                'model' => $response->model,
                'results' => array_map(static fn (RerankResult $r): array => [
                    'index' => $r->index,
                    'score' => $r->score,
                    'document' => $r->document,
                ], $response->results),
                'usage' => $response->usage,
            ],
        ];
    }

    public function hydrate(array $data): object
    {
        $r = $data['response'];

        return new RerankResponse(
            provider: $r['provider'],
            model: $r['model'],
            results: array_map(static fn (array $row): RerankResult => new RerankResult(
                index: (int) $row['index'],
                score: (float) $row['score'],
                document: $row['document'] ?? null,
            ), $r['results']),
            usage: $r['usage'] ?? [],
        );
    }

    public function usage(object $response): Usage
    {
        /** @var RerankResponse $response */
        // Voyage reports total_tokens; Cohere reports search_units (no tokens) → zero.
        return new Usage(
            promptTokens: (int) ($response->usage['total_tokens'] ?? 0),
            completionTokens: 0,
        );
    }

    public function rawUsage(object $response): array
    {
        /** @var RerankResponse $response */
        return $response->usage;
    }
}
