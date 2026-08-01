<?php

namespace Rushing\PrismPlus\Fal;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one fal.ai async-queue transport core, shared by every fal modality driver in
 * prism-plus (video / music / voice-transform). fal fronts its whole model roster behind a
 * single queue API, so the submit → poll → retrieve mechanics — and the `Authorization: Key`
 * auth, the `{url}/{model}` submit shape, and the `.../requests/{id}` fallback URLs — are
 * identical regardless of what a given model generates. This collapses what used to be the
 * same loop re-rolled in `FalVideoProvider` and the app's `FalMusicClient` into one place.
 *
 * fal queue contract (2026): submit `POST {url}/{model}` (header `Authorization: Key {key}`,
 * NOT Bearer) → `{request_id, status_url, response_url, cancel_url, queue_position}`; poll
 * `GET {status_url}?logs=1` → `status ∈ IN_QUEUE|IN_PROGRESS|COMPLETED`; retrieve
 * `GET {response_url}`; cancel `PUT {cancel_url}` (best-effort). A 422 at submit is a
 * synchronous schema rejection (no generation billed).
 */
class FalQueue
{
    public function __construct(
        private string $apiKey,
        private string $url = 'https://queue.fal.run',
    ) {}

    /** The authorized fal HTTP client — `Authorization: Key {key}` (fal's scheme, not Bearer). */
    public function client(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])->asJson();
    }

    /** `POST {url}/{model}` with an optional query (e.g. `?fal_webhook=`); returns the raw client Response. */
    public function submit(string $model, array $body, array $query = []): Response
    {
        return $this->client()
            ->withQueryParameters($query)
            ->post($this->submitUrl($model), $body);
    }

    /** `GET {url}` with an optional query. */
    public function get(string $url, array $query = []): Response
    {
        return $this->client()->get($url, $query);
    }

    /** `PUT {url}` (best-effort, e.g. cancel). */
    public function put(string $url): Response
    {
        return $this->client()->put($url);
    }

    public function submitUrl(string $model): string
    {
        return rtrim($this->url, '/').'/'.ltrim($model, '/');
    }

    public function requestUrl(string $model, string $jobId): string
    {
        return $this->submitUrl($model).'/requests/'.$jobId;
    }

    /**
     * Normalize fal's queue status spelling to a stable lifecycle token
     * (`queued`/`processing`/`completed`/`failed`) each modality maps to its own enum.
     */
    public static function normalizeStatus(string $vendorStatus): string
    {
        return match (strtoupper($vendorStatus)) {
            'IN_QUEUE' => 'queued',
            'COMPLETED', 'OK' => 'completed',
            'ERROR', 'FAILED' => 'failed',
            default => 'processing', // IN_PROGRESS + any unknown non-terminal spelling
        };
    }
}
