<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * The de-facto model-listing wheel: `GET {url}/models` with `Authorization: Bearer`, returning
 * `{ data: [ { id, owned_by?, created?, context_window? } ] }`. Aped by OpenAI, Groq, xAI, Mistral,
 * DeepSeek, and Z, so one adapter serves them all — the provider name is injected, and context window
 * is read from whichever of the near-synonym keys the provider happens to use.
 */
final class OpenAiShapeModelListProvider implements ModelListProvider
{
    public function __construct(
        private readonly string $provider,
        private readonly string $apiKey,
        private readonly string $url,
    ) {}

    public function listModels(): ModelListing
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(15)
            ->get(rtrim($this->url, '/').'/models')
            ->throw();

        $models = collect($response->json('data', []))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(fn (array $row): ModelDescriptor => new ModelDescriptor(
                id: (string) $row['id'],
                provider: $this->provider,
                displayName: isset($row['name']) ? (string) $row['name'] : null,
                contextWindow: $this->intOrNull(
                    $row['context_window'] ?? $row['max_context_length'] ?? $row['context_length'] ?? null,
                ),
                maxOutput: $this->intOrNull(
                    $row['max_output_tokens'] ?? $row['max_completion_tokens'] ?? null,
                ),
                raw: $row,
            ))
            ->values()
            ->all();

        return ModelListing::listed($this->provider, $models);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
