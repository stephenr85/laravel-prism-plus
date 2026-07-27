<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * ElevenLabs `GET {url}/models` — audio (TTS/STT) models. Authenticates with `xi-api-key` and, unlike
 * the OpenAI shape, returns a TOP-LEVEL array (no `data` envelope) of `{ model_id, name, languages }`.
 */
final class ElevenLabsModelListProvider implements ModelListProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://api.elevenlabs.io/v1',
    ) {}

    public function listModels(): ModelListing
    {
        $response = Http::withHeaders(['xi-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(15)
            ->get(rtrim($this->url, '/').'/models')
            ->throw();

        $models = collect($response->json() ?? [])
            ->filter(static fn ($row): bool => is_array($row) && isset($row['model_id']))
            ->map(fn (array $row): ModelDescriptor => new ModelDescriptor(
                id: (string) $row['model_id'],
                provider: 'elevenlabs',
                displayName: isset($row['name']) ? (string) $row['name'] : null,
                raw: $row,
            ))
            ->values()
            ->all();

        return ModelListing::listed('elevenlabs', $models);
    }
}
