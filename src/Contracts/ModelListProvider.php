<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Contracts;

use Rushing\PrismPlus\Data\ModelListing;

/**
 * List the models a provider offers — a capability Prism has no slot for (Prism exposes only a
 * Prism-*Server* `/models` route, no cross-provider client-side listing). A model-listing driver
 * borrows the same `config('prism.providers.*')` credential a Prism call uses; it is neither the
 * invocation socket (that stays Prism) nor the credential socket alone — it is the third,
 * DISCOVERY socket.
 *
 * {@see listModels()} never throws for the "this provider has no listing endpoint" case — that is
 * returned as {@see ModelListing::unsupported()}, a value the caller's curation gate reads.
 */
interface ModelListProvider
{
    public function listModels(): ModelListing;
}
