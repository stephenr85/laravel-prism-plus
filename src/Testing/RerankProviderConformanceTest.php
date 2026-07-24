<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Testing;

use Orchestra\Testbench\TestCase;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\PrismPlusServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * The shipped rerank conformance kit (ticket 06). Once open registration lets anyone register a
 * rerank provider, this shared behavioral contract proves the provider honors the capability's
 * *semantics* — not just its shape. Data hydration catches a malformed shape at runtime; this catches
 * a semantically wrong implementation (mis-sorted, out-of-range indices, `topK` ignored).
 *
 * Deliberately **rerank-specific** (maintainer-confirmed), not generalized at the `Invocable` level.
 * A third-party provider author registers their provider under a capability+provider key, extends this
 * class, points {@see providerName()} at it, and gets the same bar the maintainer's Voyage/Cohere are
 * held to. Drives the typed `PrismPlus::rerank()` accessor so the provider is exercised through the
 * real registry path — which is also what lets a host wrap the same call in the cassette record/replay
 * seam (see the app-side cassette conformance lane) without changing a single assertion.
 *
 * Ships from `src/` (not `tests/`) so consumers can extend it. It is an abstract test — never run on
 * its own — so its reference to the dev-time testbench base is only resolved when a subclass runs.
 */
abstract class RerankProviderConformanceTest extends TestCase
{
    use AssertsRerankConformance;

    /**
     * The registered provider key under test (e.g. `voyageai`). A built-in is seeded by the package;
     * a third party registers theirs in {@see getEnvironmentSetUp()} / {@see setUp()} first.
     */
    abstract protected function providerName(): string;

    protected function rerank(bool $returnDocuments = false): RerankResponse
    {
        return app(PrismPlus::class)->rerank(
            new RerankRequest(
                query: $this->conformanceQuery(),
                documents: $this->conformanceDocuments(),
                topK: $this->conformanceTopK(),
                returnDocuments: $returnDocuments,
            ),
            $this->providerName(),
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            PrismPlusServiceProvider::class,
        ];
    }

    public function test_conforms_to_the_rerank_contract(): void
    {
        $this->assertRerankConforms($this->rerank());
    }

    public function test_documents_are_echoed_when_requested(): void
    {
        $this->assertDocumentsEchoed($this->rerank(returnDocuments: true));
    }
}
