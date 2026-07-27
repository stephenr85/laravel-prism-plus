<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * Google Gemini's models collection. Prism configures Gemini's base URL AS the models collection
 * (`.../v1beta/models`), so this GETs the base directly rather than appending `/models`.
 * Authenticates with `x-goog-api-key`, returns `{ models: [ { name: "models/…", displayName,
 * inputTokenLimit, outputTokenLimit } ] }`; the `models/` prefix is stripped to a bare id.
 */
final class GeminiModelListProvider implements ModelListProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://generativelanguage.googleapis.com/v1beta/models',
    ) {}

    public function listModels(): ModelListing
    {
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(15)
            ->get(rtrim($this->url, '/'))
            ->throw();

        $models = collect($response->json('models', []))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['name']))
            ->map(fn (array $row): ModelDescriptor => new ModelDescriptor(
                id: (string) preg_replace('#^models/#', '', (string) $row['name']),
                provider: 'gemini',
                displayName: isset($row['displayName']) ? (string) $row['displayName'] : null,
                contextWindow: isset($row['inputTokenLimit']) ? (int) $row['inputTokenLimit'] : null,
                maxOutput: isset($row['outputTokenLimit']) ? (int) $row['outputTokenLimit'] : null,
                raw: $row,
            ))
            ->values()
            ->all();

        return ModelListing::listed('gemini', $models);
    }
}
