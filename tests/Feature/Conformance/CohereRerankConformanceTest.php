<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Tests\Feature\Conformance;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Testing\RerankProviderConformanceTest;

/**
 * Cohere held to the shipped conformance kit — same cassette-replay default lane as Voyage; this class
 * arms only the token-free `Http::fake()` record leg. Set `CONFORMANCE_LIVE=1` and `COHERE_API_KEY=…`
 * to point the record leg at the real endpoint (the keyed CI lane).
 */
final class CohereRerankConformanceTest extends RerankProviderConformanceTest
{
    protected function providerName(): string
    {
        return 'cohere';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app); // cassette store for the record→replay round-trip

        $app['config']->set('prism.providers.cohere', [
            'api_key' => (string) (getenv('COHERE_API_KEY') ?: 'test-cohere-key'),
            'url' => 'https://api.cohere.com/v2',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONFORMANCE_LIVE') === '1' && getenv('COHERE_API_KEY') !== false) {
            return;
        }

        // A conforming Cohere payload: `results` array, strictly descending, in-range unique indices.
        Http::fake([
            'api.cohere.com/*' => Http::response([
                'results' => [
                    ['index' => 1, 'relevance_score' => 0.97],
                    ['index' => 3, 'relevance_score' => 0.28],
                ],
                'id' => 'conformance-1',
                'meta' => ['billed_units' => ['search_units' => 1]],
            ]),
        ]);
    }
}
