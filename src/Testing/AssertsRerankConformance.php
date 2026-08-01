<?php

namespace Rushing\PrismPlus\Testing;

use PHPUnit\Framework\Assert;
use Rushing\PrismPlus\Data\RerankResponse;

/**
 * The rerank conformance assertions, factored out of {@see RerankProviderConformanceTest} so the exact
 * same behavioral contract can be applied from any test base — the package's testbench-based kit (the
 * token-free `Http::fake` lane) AND a host application's own TestCase (a cassette-replayed lane that
 * composes the landed record/replay seam). One contract, asserted the same way regardless of how the
 * response was sourced.
 */
trait AssertsRerankConformance
{
    /**
     * The conformance corpus: a query with a clear best answer and some near-misses. Override to
     * exercise a provider against a different fixed corpus (keep it small and deterministic).
     *
     * @return list<string>
     */
    protected function conformanceDocuments(): array
    {
        return [
            'Berlin is the capital of Germany.',
            'Paris is the capital of France.',
            'The Seine runs through Paris.',
            'Baguettes are a French bread.',
        ];
    }

    protected function conformanceQuery(): string
    {
        return 'What is the capital of France?';
    }

    protected function conformanceTopK(): int
    {
        return 2;
    }

    /**
     * The core rerank contract: at most topK results, strictly descending scores, in-range and unique
     * indices, and never an empty result for a matching corpus.
     */
    protected function assertRerankConforms(RerankResponse $response): void
    {
        Assert::assertNotEmpty(
            $response->results,
            'A conforming provider never returns an empty result for a matching corpus.',
        );
        Assert::assertLessThanOrEqual(
            $this->conformanceTopK(),
            count($response->results),
            'A conforming provider returns at most topK results.',
        );

        $scores = array_map(static fn ($r): float => $r->score, $response->results);
        for ($i = 1, $n = count($scores); $i < $n; $i++) {
            Assert::assertGreaterThan(
                $scores[$i],
                $scores[$i - 1],
                "A conforming provider returns results in strictly descending score order (position {$i}).",
            );
        }

        $indices = array_map(static fn ($r): int => $r->index, $response->results);
        $docCount = count($this->conformanceDocuments());
        foreach ($indices as $index) {
            Assert::assertGreaterThanOrEqual(0, $index, 'A result index is never negative.');
            Assert::assertLessThan($docCount, $index, 'A result index is always within the documents array.');
        }
        Assert::assertCount(count($indices), array_unique($indices), 'A conforming provider never repeats a document index.');
    }

    /**
     * When documents are requested, every result carries its source document text. (The normalized VO
     * always backfills the text from the request by index, so the negative direction — no text unless
     * requested — is not observable on the response; the vendor-echo distinction lives below it.)
     */
    protected function assertDocumentsEchoed(RerankResponse $response): void
    {
        foreach ($response->results as $result) {
            Assert::assertNotNull($result->document, 'When returnDocuments is set, every result carries its document text.');
            Assert::assertSame(
                $this->conformanceDocuments()[$result->index],
                $result->document,
                'An echoed document matches the source document at its index.',
            );
        }
    }
}
