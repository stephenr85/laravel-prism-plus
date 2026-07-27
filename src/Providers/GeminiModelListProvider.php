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
 * inputTokenLimit, outputTokenLimit, supportedGenerationMethods } ] }`; the `models/` prefix is
 * stripped to a bare id.
 *
 * Gemini's collection is broad — it also carries embedding, tuning, and legacy endpoints. Each row
 * declares its `supportedGenerationMethods`; a row that generates NO content (only `createTunedModel`
 * / `predict` and the like) is not an offerable model, so it is filtered out. A candidate that can
 * `generateContent` (text/multimodal) OR `embedContent` (embeddings) is kept — both are curatable
 * across the gate's modalities. A row that omits the field entirely is kept (fail-open).
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
            ->filter(static fn (array $row): bool => self::generatesContent($row))
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

    /**
     * A row is offerable if it can generate content — `generateContent` (text/multimodal) or
     * `embedContent` (embeddings). Tuning/legacy-only rows are dropped. A row with no declared
     * methods is kept (fail-open — a shape we don't recognize is surfaced, not silently hidden).
     *
     * @param  array<string, mixed>  $row
     */
    private static function generatesContent(array $row): bool
    {
        $methods = $row['supportedGenerationMethods'] ?? null;

        if (! is_array($methods) || $methods === []) {
            return true;
        }

        return in_array('generateContent', $methods, true)
            || in_array('embedContent', $methods, true);
    }
}
