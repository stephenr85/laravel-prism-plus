<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\ModelListProvider;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;

/**
 * Anthropic's `GET {url}/models` — the rich end of the spectrum. Authenticates with `x-api-key` +
 * `anthropic-version` (not Bearer), and each row carries `display_name`, `max_input_tokens`,
 * `max_tokens`, and a `capabilities` tree. `image_input.supported` maps to the normalized
 * `accepts: ['image']`.
 */
final class AnthropicModelListProvider implements ModelListProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://api.anthropic.com/v1',
        private readonly string $version = '2023-06-01',
    ) {}

    public function listModels(): ModelListing
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->version,
        ])
            ->acceptJson()
            ->timeout(15)
            ->get(rtrim($this->url, '/').'/models')
            ->throw();

        $models = collect($response->json('data', []))
            ->filter(static fn ($row): bool => is_array($row) && isset($row['id']))
            ->map(function (array $row): ModelDescriptor {
                $capabilities = (array) ($row['capabilities'] ?? []);

                $accepts = [];
                if (($capabilities['image_input']['supported'] ?? false) === true) {
                    $accepts[] = 'image';
                }

                return new ModelDescriptor(
                    id: (string) $row['id'],
                    provider: 'anthropic',
                    displayName: isset($row['display_name']) ? (string) $row['display_name'] : null,
                    contextWindow: isset($row['max_input_tokens']) ? (int) $row['max_input_tokens'] : null,
                    maxOutput: isset($row['max_tokens']) ? (int) $row['max_tokens'] : null,
                    accepts: $accepts,
                    raw: $row,
                );
            })
            ->values()
            ->all();

        return ModelListing::listed('anthropic', $models);
    }
}
