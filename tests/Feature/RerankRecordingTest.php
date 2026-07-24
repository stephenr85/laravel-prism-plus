<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\Providers\RecordingRerankProvider;
use Rushing\PrismPlus\Providers\VoyageRerankProvider;

/*
 * prism-cassette is a hard dependency of prism-plus — fixture recording is a first-class "plus", not a
 * soft-inject — so every resolved rerank driver is recording-wrapped. The wrapping is transparent: an
 * un-scoped call runs the wrapped vendor driver LIVE via passthrough, so callers who never record are
 * unaffected. The record→replay round-trip proper is exercised in the app suite and the conformance
 * cassette lane, where a store + serializer + fixtures are wired.
 */

it('wraps the resolved driver in the recording decorator (cassette is a hard dependency)', function () {
    expect(class_exists(CassetteManager::class))->toBeTrue();

    $provider = app(PrismPlus::class)->rerankProvider();

    expect($provider)->toBeInstanceOf(RecordingRerankProvider::class)
        ->and($provider->inner())->toBeInstanceOf(VoyageRerankProvider::class);
});

it('runs rerank live via passthrough when no cassette is armed', function () {
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
