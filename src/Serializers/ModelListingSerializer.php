<?php

namespace Rushing\PrismPlus\Serializers;

use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\Contracts\CassetteSerializer;
use Rushing\PrismPlus\Cassette\ModelListingCassetteSubject;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * Tapes the PrismPlus `models` (listing) capability through cassette's serializer seam — so the
 * host's promotion UI and its tests can run against deterministic recorded fixtures instead of live
 * provider `/models` calls (ADR-0129 §5 deferred this from the first slice; this is that follow-up).
 *
 * Ships HERE, not in cassette, because the response type is PrismPlus's own {@see ModelListing}
 * ("who owns the response type owns the serializer"), and registers into cassette via
 * {@see CassetteManager::registerSerializer()} — mirroring
 * {@see RerankSerializer} verbatim.
 *
 * Listing is UNMETERED (unlike rerank), so this does NOT implement RefinesEventMetering and
 * {@see Usage()} is a zero {@see Usage} — there is no token cost to a `/models` read. The cassette
 * KEY is the provider alone: a listing call carries no request payload, so a Groq recording can
 * never replay for an Anthropic call, and that is the whole identity.
 */
class ModelListingSerializer implements CassetteSerializer
{
    public function key(object $request): string
    {
        /** @var ModelListingCassetteSubject $request */
        $payload = [
            'type' => 'models',
            'provider' => $request->provider,
            'request' => $request->request,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function provider(object $request): string
    {
        /** @var ModelListingCassetteSubject $request */
        return $request->provider;
    }

    public function model(object $request): string
    {
        // Listing is not a per-model call — there is no requested model.
        return '';
    }

    public function preview(object $request): string
    {
        /** @var ModelListingCassetteSubject $request */
        return "models:{$request->provider}";
    }

    public function serialize(object $request, object $response, string $recordedAt): array
    {
        /** @var ModelListingCassetteSubject $request */
        /** @var ModelListing $response */
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'models',
                'provider' => $request->provider,
                'request' => $request->request,
            ],
            'response' => $response->toArray(),
        ];
    }

    public function hydrate(array $data): object
    {
        return ModelListing::from($data['response']);
    }

    public function usage(object $response): Usage
    {
        // A `/models` read has no token usage.
        return new Usage(promptTokens: 0, completionTokens: 0);
    }
}
