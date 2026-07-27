<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * OpenRouter's `GET {url}/models` — the richest listing (the endpoint is public; the key is sent when
 * present but not required). Rows carry `context_length`, a `pricing` block, and
 * `architecture.input_modalities`, from which the normalized `accepts` is derived (`image` → 'image',
 * `file` → 'document'). Pricing rides along untouched in `raw` for a curation gate to consume.
 */
final class OpenRouterModelListProvider implements ModelListProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://openrouter.ai/api/v1',
    ) {}

    public function listModels(): ModelListing
    {
        $request = Http::acceptJson()->timeout(15);

        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->get(rtrim($this->url, '/').'/models')->throw();

        $models = collect($response->json('data', []))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(function (array $row): ModelDescriptor {
                $modalities = (array) ($row['architecture']['input_modalities'] ?? []);

                $accepts = array_values(array_filter(array_map(
                    static fn ($modality): ?string => match ($modality) {
                        'image' => 'image',
                        'file' => 'document',
                        default => null,
                    },
                    $modalities,
                )));

                return new ModelDescriptor(
                    id: (string) $row['id'],
                    provider: 'openrouter',
                    displayName: isset($row['name']) ? (string) $row['name'] : null,
                    contextWindow: isset($row['context_length']) ? (int) $row['context_length'] : null,
                    maxOutput: isset($row['top_provider']['max_completion_tokens'])
                        ? (int) $row['top_provider']['max_completion_tokens']
                        : null,
                    accepts: $accepts,
                    raw: $row,
                );
            })
            ->values()
            ->all();

        return ModelListing::listed('openrouter', $models);
    }
}
