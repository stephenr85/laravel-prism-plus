<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\Providers\CohereRerankProvider;
use Rushing\PrismPlus\Providers\VoyageRerankProvider;

$documents = [
    'Berlin is the capital of Germany.',
    'Paris is the capital of France.',
    'The Seine runs through Paris.',
];

it('resolves the default rerank provider (voyage) from Prism credentials', function () {
    expect(app(PrismPlus::class)->rerankProvider())
        ->toBeInstanceOf(VoyageRerankProvider::class);
});

it('resolves cohere by name', function () {
    expect(app(PrismPlus::class)->rerankProvider('cohere'))
        ->toBeInstanceOf(CohereRerankProvider::class);
});

it('throws on an unknown rerank provider', function () {
    app(PrismPlus::class)->rerankProvider('nope');
})->throws(InvalidArgumentException::class);

it('normalizes Voyage: top_k param, `data` array, re-sorted desc', function () use ($documents) {
    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'object' => 'list',
            // Intentionally out of order to prove the driver sorts.
            'data' => [
                ['index' => 0, 'relevance_score' => 0.11],
                ['index' => 1, 'relevance_score' => 0.98],
                ['index' => 2, 'relevance_score' => 0.42],
            ],
            'model' => 'rerank-2.5',
            'usage' => ['total_tokens' => 26],
        ]),
    ]);

    $response = app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'What is the capital of France?', documents: $documents, topK: 3),
    );

    expect($response->provider)->toBe('voyageai')
        ->and($response->model)->toBe('rerank-2.5')
        ->and($response->order())->toBe([1, 2, 0])
        ->and($response->results[0]->score)->toBe(0.98)
        // Voyage didn't echo docs → resolved from the request array.
        ->and($response->results[0]->document)->toBe($documents[1])
        ->and($response->usage)->toBe(['total_tokens' => 26]);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/v1/rerank')
            && $request['model'] === 'rerank-2.5'
            && $request['top_k'] === 3           // Voyage spelling
            && ! array_key_exists('top_n', $request->data())
            && $request['query'] === 'What is the capital of France?'
            && $request['documents'][1] === 'Paris is the capital of France.'
            && $request->hasHeader('Authorization', 'Bearer test-voyage-key');
    });
});

it('normalizes Cohere: top_n param, `results` array, index mapped back to docs', function () use ($documents) {
    Http::fake([
        'api.cohere.com/*' => Http::response([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.30],
                ['index' => 1, 'relevance_score' => 0.99],
            ],
            'id' => 'abc-123',
            'meta' => ['billed_units' => ['search_units' => 1]],
        ]),
    ]);

    $response = app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'capital of France', documents: $documents, topK: 2),
        provider: 'cohere',
    );

    expect($response->provider)->toBe('cohere')
        ->and($response->model)->toBe('rerank-v4.0-pro')
        ->and($response->order())->toBe([1, 2])
        ->and($response->results[0]->document)->toBe($documents[1])
        ->and($response->usage)->toBe(['search_units' => 1]);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/v2/rerank')
            && $request['model'] === 'rerank-v4.0-pro'
            && $request['top_n'] === 2            // Cohere spelling
            && ! array_key_exists('top_k', $request->data())
            && $request->hasHeader('Authorization', 'Bearer test-cohere-key');
    });
});

it('honours a per-call model override', function () use ($documents) {
    Http::fake(['api.voyageai.com/*' => Http::response(['data' => [], 'model' => 'rerank-2.5-lite'])]);

    app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'q', documents: $documents, model: 'rerank-2.5-lite'),
    );

    Http::assertSent(fn ($request) => $request['model'] === 'rerank-2.5-lite');
});

it('the rerank contract is provider-agnostic', function () {
    expect(app(PrismPlus::class)->rerankProvider('voyageai'))->toBeInstanceOf(RerankProvider::class);
    expect(app(PrismPlus::class)->rerankProvider('cohere'))->toBeInstanceOf(RerankProvider::class);
});
