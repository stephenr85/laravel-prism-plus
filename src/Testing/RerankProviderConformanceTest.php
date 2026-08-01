<?php

namespace Rushing\PrismPlus\Testing;

use Orchestra\Testbench\TestCase;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteServiceProvider;
use Rushing\PrismCassette\Exceptions\CassetteMissException;
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
 * held to.
 *
 * **The default lane is cassette replay.** Because prism-cassette is a hard dependency of prism-plus,
 * the kit composes the record/replay seam directly: {@see rerank()} records the provider's response
 * through `PrismPlus::rerank()` → `RecordingRerankProvider` → `CassetteManager` → `RerankSerializer`,
 * then REPLAYS it and runs the assertions against the replayed response — so conformance is verified
 * token-free (a concrete subclass arms an `Http::fake` for the record leg) and a replay miss fails
 * loud, never a silent empty rerank. Point the record leg at a live endpoint for the keyed CI lane.
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

    /**
     * Record the provider's response through the real cassette seam, then replay it and hand back the
     * REPLAYED response — so every assertion runs against a cassette, not a live call.
     */
    protected function rerank(bool $returnDocuments = false): RerankResponse
    {
        $request = new RerankRequest(
            query: $this->conformanceQuery(),
            documents: $this->conformanceDocuments(),
            topK: $this->conformanceTopK(),
            returnDocuments: $returnDocuments,
        );

        $manager = app(CassetteManager::class);
        $group = $this->conformanceGroup();

        // Record leg: fed by the concrete's armed Http::fake (token-free) or a live endpoint.
        $manager->group($group)->record()->play(
            fn () => app(PrismPlus::class)->rerank($request, $this->providerName()),
        );

        // Replay leg: the assertions below run against the cassette-replayed response.
        return $manager->group($group)->replay()->play(
            fn () => app(PrismPlus::class)->rerank($request, $this->providerName()),
        );
    }

    protected function conformanceGroup(): string
    {
        return 'conformance-'.$this->providerName();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            CassetteServiceProvider::class,
            PrismPlusServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // A throwaway file store for the record→replay round-trip. A subclass override MUST call
        // parent::getEnvironmentSetUp($app) so this survives alongside its own provider credentials.
        //
        // No decoy Prism provider is configured to satisfy cassette's scope-disarmed guard: PrismPlus's
        // service provider declares rerank directly tape-able via CassetteManager::armCapability(), so
        // the record/replay scopes are armed for the non-Prism rerank capability on their own.
        $app['config']->set('cassette.stores.file.path', sys_get_temp_dir().'/prism-plus-conformance');
    }

    public function test_conforms_to_the_rerank_contract(): void
    {
        $this->assertRerankConforms($this->rerank());
    }

    public function test_documents_are_echoed_when_requested(): void
    {
        $this->assertDocumentsEchoed($this->rerank(returnDocuments: true));
    }

    public function test_a_replay_miss_fails_loud(): void
    {
        $this->expectException(CassetteMissException::class);

        app(CassetteManager::class)->group($this->conformanceGroup().'-miss')->replay()->play(
            fn () => app(PrismPlus::class)->rerank(
                new RerankRequest(query: 'nothing recorded for conformance', documents: ['x', 'y'], topK: 2),
                $this->providerName(),
            ),
        );
    }
}
