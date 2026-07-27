<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Cassette;

/**
 * The taping subject handed to cassette's serializer seam for a `models` (listing) call.
 *
 * Listing takes no request payload today (the invocable's input slot is reserved for future
 * filters), so the identity that keys a cassette is the PROVIDER alone — a Groq recording must never
 * replay for an Anthropic call. `request` is kept as an (empty) array so the shape can grow to carry
 * filters later without breaking the serializer key, mirroring how {@see RerankCassetteSubject}
 * bundles provider + request.
 */
final class ModelListingCassetteSubject
{
    /**
     * @param  array<string, mixed>  $request  reserved for future listing filters; empty for now
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $request = [],
    ) {}
}
