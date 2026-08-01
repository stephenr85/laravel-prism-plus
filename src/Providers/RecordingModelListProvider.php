<?php

namespace Rushing\PrismPlus\Providers;

use Illuminate\Contracts\Foundation\Application;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Cassette\ModelListingCassetteSubject;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelListing;
use Rushing\PrismPlus\PrismPlusManager;

/**
 * Wraps a resolved {@see ModelListProvider} so `models` (listing) calls record/replay through
 * prism-cassette — the interception seam Prism can't give listing (Prism has no listing slot, so a
 * `/models` call never passes through cassette's provider decorator). {@see PrismPlusManager}
 * interposes it around every resolved driver: prism-cassette is a hard dependency of prism-plus, so
 * recording is always available and inert unless a cassette is armed (an un-scoped call runs the
 * inner driver live via passthrough).
 *
 * Mirrors {@see RecordingRerankProvider} verbatim. {@see inner()} exposes the wrapped vendor driver
 * for introspection.
 */
class RecordingModelListProvider implements ModelListProvider
{
    public function __construct(
        private ModelListProvider $inner,
        private Application $app,
        private string $provider,
    ) {}

    /** The wrapped vendor driver — recording is a transparent decorator. */
    public function inner(): ModelListProvider
    {
        return $this->inner;
    }

    public function listModels(): ModelListing
    {
        /** @var CassetteManager $manager */
        $manager = $this->app->make(CassetteManager::class);

        $subject = new ModelListingCassetteSubject($this->provider);

        /** @var ModelListing $listing */
        $listing = $manager->tape(
            'models',
            $subject,
            fn (): ModelListing => $this->inner->listModels(),
        );

        return $listing;
    }
}
