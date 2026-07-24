<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Contracts\Foundation\Application;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Cassette\RerankCassetteSubject;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\PrismPlusManager;

/**
 * Wraps a resolved {@see RerankProvider} so rerank calls record/replay through prism-cassette — the
 * interception seam Prism can't give rerank (Prism has no rerank slot, so a rerank call never passes
 * through cassette's provider decorator). {@see PrismPlusManager} interposes it around every resolved
 * driver: prism-cassette is a hard dependency of prism-plus, so recording is always available and
 * inert unless a cassette is armed (an un-scoped call runs the inner driver live via passthrough).
 *
 * Wrapping the resolved provider (not just PrismPlus::rerank()) means callers who hold the driver
 * directly via rerankProvider()->rerank() are taped too — no bypass. {@see inner()} exposes the
 * wrapped vendor driver for introspection.
 */
final class RecordingRerankProvider implements RerankProvider
{
    public function __construct(
        private readonly RerankProvider $inner,
        private readonly Application $app,
        private readonly string $provider,
    ) {}

    /**
     * The wrapped vendor driver (e.g. the concrete {@see VoyageRerankProvider}). Recording is a
     * transparent decorator, so the underlying provider is available for callers that need its type.
     */
    public function inner(): RerankProvider
    {
        return $this->inner;
    }

    public function rerank(RerankRequest $request): RerankResponse
    {
        /** @var CassetteManager $manager */
        $manager = $this->app->make(CassetteManager::class);

        $subject = new RerankCassetteSubject($this->provider, $request);

        /** @var RerankResponse $response */
        $response = $manager->tape(
            'rerank',
            $subject,
            fn (): RerankResponse => $this->inner->rerank($request),
        );

        return $response;
    }
}
