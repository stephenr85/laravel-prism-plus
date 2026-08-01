<?php

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * Ollama lists the models INSTALLED on the local daemon via `GET {url}/api/tags` (no auth) — a
 * different shape and a different meaning from the hosted providers: this is "what's pulled here",
 * not "what the vendor offers". Returns `{ models: [ { name, model, size, details } ] }`.
 */
class OllamaModelListProvider implements ModelListProvider
{
    public function __construct(
        private string $url = 'http://localhost:11434',
    ) {}

    public function listModels(): ModelListing
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim($this->url, '/').'/api/tags')
            ->throw();

        $models = collect($response->json('models', []))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['name']))
            ->map(fn (array $row): ModelDescriptor => new ModelDescriptor(
                id: (string) $row['name'],
                provider: 'ollama',
                displayName: isset($row['model']) ? (string) $row['model'] : null,
                raw: $row,
            ))
            ->values()
            ->all();

        return ModelListing::listed('ollama', $models);
    }
}
