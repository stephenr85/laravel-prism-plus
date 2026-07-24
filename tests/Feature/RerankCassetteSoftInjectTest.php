<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\Providers\CohereRerankProvider;
use Rushing\PrismPlus\Providers\VoyageRerankProvider;

/*
 * Soft-inject acceptance: with prism-cassette NOT installed (it is neither required nor in this
 * package's vendor), rerank taping is inert — the resolved driver is the bare provider and calls run
 * live. The record→replay round-trip is exercised in the app suite, where cassette IS present.
 */

it('resolves the bare driver (no recording wrapper) when cassette is absent', function () {
    expect(class_exists(CassetteManager::class))->toBeFalse();

    expect(app(PrismPlus::class)->rerankProvider())->toBeInstanceOf(VoyageRerankProvider::class);
    expect(app(PrismPlus::class)->rerankProvider('cohere'))->toBeInstanceOf(CohereRerankProvider::class);
});

it('runs rerank live (passthrough) when cassette is absent', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'data' => [
                ['index' => 1, 'relevance_score' => 0.9],
                ['index' => 0, 'relevance_score' => 0.1],
            ],
            'model' => 'rerank-2.5',
            'usage' => ['total_tokens' => 7],
        ]),
    ]);

    $response = app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'q', documents: ['a', 'b'], topK: 2),
    );

    expect($response->order())->toBe([1, 0])
        ->and($response->usage)->toBe(['total_tokens' => 7]);

    Http::assertSentCount(1);
});
