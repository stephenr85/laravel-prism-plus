<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Tests\Feature\Conformance;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Testing\RerankProviderConformanceTest;

/**
 * Voyage held to the shipped conformance kit — the maintainer's own provider on the exact bar a third
 * party is. The package deliberately ships without prism-cassette (see RerankCassetteSoftInjectTest),
 * so its token-free lane is an `Http::fake()` of a conforming Voyage payload rather than a cassette
 * replay; the cassette-replayed lane that composes the record/replay seam lives in the host app
 * (tests/Feature/Tlc/RerankConformanceCassetteTest.php), where prism-cassette is installed.
 *
 * Set `CONFORMANCE_LIVE=1` and `VOYAGEAI_API_KEY=…` to run the same assertions against the real
 * endpoint (the keyed CI lane), validating the fixture's assumptions about the vendor's real contract.
 */
final class VoyageRerankConformanceTest extends RerankProviderConformanceTest
{
    protected function providerName(): string
    {
        return 'voyageai';
    }

    protected function getEnvironmentSetUp($app): void
    {
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
