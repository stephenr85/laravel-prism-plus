<?php

namespace Rushing\PrismPlus\Tests\Feature\Conformance;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Testing\RerankProviderConformanceTest;

/**
 * Voyage held to the shipped conformance kit — the maintainer's own provider on the exact bar a third
 * party is. The kit's default lane is cassette replay (prism-cassette is a hard dependency): the base
 * records the response through the real record/replay seam, then replays it and asserts against the
 * replayed response. This class only arms the RECORD leg — a token-free `Http::fake()` of a conforming
 * Voyage payload — so conformance is verified with no API key and no network.
 *
 * Set `CONFORMANCE_LIVE=1` and `VOYAGEAI_API_KEY=…` to point the record leg at the real endpoint (the
 * keyed CI lane), validating the vendor's real response contract instead of a faked one.
 */
class VoyageRerankConformanceTest extends RerankProviderConformanceTest
{
    protected function providerName(): string
    {
        return 'voyageai';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app); // cassette store for the record→replay round-trip

        $app['config']->set('prism.providers.voyageai', [
            'api_key' => (string) (getenv('VOYAGEAI_API_KEY') ?: 'test-voyage-key'),
            'url' => 'https://api.voyageai.com/v1',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONFORMANCE_LIVE') === '1' && getenv('VOYAGEAI_API_KEY') !== false) {
            return; // hit the real endpoint
        }

        // A conforming Voyage payload: at most topK hits, strictly descending, in-range unique indices.
        Http::fake([
            'api.voyageai.com/*' => Http::response([
                'object' => 'list',
                'data' => [
                    ['index' => 1, 'relevance_score' => 0.95],
                    ['index' => 3, 'relevance_score' => 0.31],
                ],
                'model' => 'rerank-2.5',
                'usage' => ['total_tokens' => 18],
            ]),
        ]);
    }
}
