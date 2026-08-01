<?php

namespace Rushing\PrismPlus\Providers;

use Rushing\Popcorn\Contracts\Invocable;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\Invocables\RerankInvocable;

/**
 * A typed {@see RerankProvider} facade over an arbitrary registry {@see Invocable} — the adapter the
 * deprecated `PrismPlusManager::rerankProvider()` shim returns when the registered provider is not a
 * {@see RerankInvocable} (e.g. a host-registered `LocalInvocable` or a
 * remote binding). It re-crosses the array boundary so a caller holding a typed driver still resolves
 * through the same registry entry `PrismPlus::rerank()` uses.
 */
class InvocableRerankProvider implements RerankProvider
{
    public function __construct(
        private Invocable $invocable,
    ) {}

    public function rerank(RerankRequest $request): RerankResponse
    {
        return RerankResponse::from($this->invocable->invoke($request->toArray()));
    }
}
