<?php

namespace Rushing\PrismPlus\Replicate;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Replicate predictions transport — the async run loop for Replicate-hosted models
 * (create → poll `urls.get` → output). Replicate is where the singing voice-conversion models
 * live (FreeVC / RVC / so-vits-svc), which neither the fal queue nor ElevenLabs offer. Bearer
 * auth, JSON, model addressed by version hash.
 *
 * Contract (2026): `POST /v1/predictions {version, input}` → `{id, status, urls:{get,cancel}}`;
 * poll `GET urls.get` until `status ∈ succeeded|failed|canceled`; `output` is the result (a URI
 * string, or an array of them). A 422 at create is a synchronous input-schema rejection.
 */
class ReplicateClient
{
    public function __construct(
        private string $apiToken,
        private string $baseUrl = 'https://api.replicate.com/v1',
        private int $pollSeconds = 3,
        private int $maxPolls = 200,
    ) {}

    public static function fromConfig(): self
    {
        return new self((string) config('prism.providers.replicate.api_key', ''));
    }

    public function configured(): bool
    {
        return $this->apiToken !== '';
    }

    /**
     * Run a model version to completion and return its first output URL.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $version, array $input): string
    {
        $create = $this->client()->post($this->baseUrl.'/predictions', [
            'version' => $version,
            'input' => $input,
        ]);

        if ($create->status() === 422) {
            throw new RuntimeException('replicate ['.$version.'] rejected the input (422): '.$create->body());
        }
        $create->throw();

        $prediction = (array) $create->json();
        $pollUrl = (string) ($prediction['urls']['get'] ?? $this->baseUrl.'/predictions/'.($prediction['id'] ?? ''));

        for ($i = 0; $i < $this->maxPolls; $i++) {
            $status = (string) ($prediction['status'] ?? 'starting');
            if ($status === 'succeeded') {
                break;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException('replicate ['.$version.'] '.$status.': '.(string) ($prediction['error'] ?? ''));
            }
            sleep($this->pollSeconds);
            $prediction = (array) $this->client()->get($pollUrl)->throw()->json();
        }

        return $this->outputUrl($prediction);
    }

    /**
     * Pull the produced audio URL out of a prediction — `output` is a bare URI string, or an
     * array of them (first wins), or an object with a `url`.
     *
     * @param  array<string, mixed>  $prediction
     */
    private function outputUrl(array $prediction): string
    {
        $output = $prediction['output'] ?? null;

        $url = match (true) {
            is_string($output) => $output,
            is_array($output) && isset($output[0]) && is_string($output[0]) => $output[0],
            is_array($output) && isset($output['url']) => (string) $output['url'],
            default => null,
        };

        if ($url === null || $url === '') {
            throw new RuntimeException('replicate: prediction returned no output url ('.(string) ($prediction['status'] ?? '?').').');
        }

        return $url;
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken)->asJson()->timeout(120);
    }
}
