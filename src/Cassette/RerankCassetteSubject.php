<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Cassette;

use Rushing\PrismPlus\Data\RerankRequest;

/**
 * The taping subject handed to cassette's serializer seam for a rerank call.
 *
 * {@see RerankRequest} is deliberately provider-agnostic — it never leaks a vendor spelling — but a
 * cassette key MUST include the provider, or a Voyage recording would wrongly replay for a Cohere
 * call with the same query/documents. The recording decorator (which alone knows which driver is
 * about to run) bundles the resolved provider name with the request here, so the serializer keys and
 * meters on the full (provider, request) identity without polluting the public request VO.
 */
final class RerankCassetteSubject
{
    public function __construct(
        public readonly string $provider,
        public readonly RerankRequest $request,
    ) {}
}
