<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Contracts\Foundation\Application;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\PrismPlusManager;
use Rushing\PrismPlus\ValueObjects\RerankCassetteSubject;
use Rushing\PrismPlus\ValueObjects\RerankRequest;
use Rushing\PrismPlus\ValueObjects\RerankResponse;

/**
 * Wraps a resolved {@see RerankProvider} so rerank calls record/replay through prism-cassette — the
 * interception seam Prism can't give rerank (Prism has no rerank slot, so a rerank call never passes
 * through cassette's provider decorator). Interposed by {@see PrismPlusManager}
 * ONLY when prism-cassette is installed, so absent cassette leaves PrismPlus behaving exactly as
 * before (the driver is returned bare) — a soft-inject with no hard dependency.
 *
 * Wrapping the resolved provider (not just PrismPlus::rerank()) means callers who hold the driver
 * directly via rerankProvider()->rerank() are taped too — no bypass.
 */
final class RecordingRerankProvider implements RerankProvider
{
    public function __construct(
        private readonly RerankProvider $inner,
        private readonly Application $app,
        private readonly string $provider,
    ) {}

    public function rerank(RerankRequest $request): RerankResponse
    {
        $managerClass = CassetteManager::class;

        // Defensive: the manager only wraps when cassette is installed, but if its container binding
        // is somehow absent, run live rather than fail.
        if (! class_exists($managerClass) || ! $this->app->bound($managerClass)) {
            return $this->inner->rerank($request);
        }

        /** @var CassetteManager $manager */
        $manager = $this->app->make($managerClass);

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
