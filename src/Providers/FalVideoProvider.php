<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Enums\VideoJobStatus;
use Rushing\PrismPlus\ValueObjects\VideoJob;
use Rushing\PrismPlus\ValueObjects\VideoRequest;
use Rushing\PrismPlus\ValueObjects\VideoResult;

/**
 * fal.ai queue video driver — the recommended aggregator to start with, because one
 * queue API fronts many models (Kling / Luma / Veo / Sora-family / etc.), so a single
 * integration reaches the whole roster.
 *
 * Verified against fal.ai's live queue docs (2026):
 *  - **submit**  `POST {url}/{model}` (e.g. `https://queue.fal.run/fal-ai/veo3`), auth
 *    header `Authorization: Key {key}` (NOT Bearer), body = model-specific args.
 *    Returns `request_id`, `status_url`, `response_url`, `cancel_url`, `queue_position`.
 *    A webhook is opted-in with the `?fal_webhook=` query param (payload:
 *    `{request_id, gateway_request_id, status: OK|ERROR, payload}`).
 *  - **status**  `GET {status_url}?logs=1` → `status` ∈ `IN_QUEUE|IN_PROGRESS|COMPLETED`
 *    (+ `queue_position`, `logs`). There is no vendor FAILED state on this endpoint;
 *    failure surfaces as an error body on retrieve / a webhook `status: ERROR`.
 *  - **retrieve**  `GET {response_url}` → a video model returns a `video` object carrying
 *    `url` (fal media host, short-lived — download promptly).
 *  - **cancel**  `PUT {cancel_url}` → `202 CANCELLATION_REQUESTED` (best-effort).
 *
 * Body param NAMES are model-specific (`duration` vs `num_frames`, `image_url` vs
 * `image`); this driver maps the common normalized fields and merges
 * {@see VideoRequest::$providerOptions} verbatim for anything a given model needs on top.
 */
final class FalVideoProvider implements VideoProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url = 'https://queue.fal.run',
        private readonly string $defaultModel = 'fal-ai/veo3',
    ) {}

    public function generate(VideoRequest $request): VideoJob
    {
        $model = $request->model ?? $this->defaultModel;

        $body = array_merge(array_filter([
            'prompt' => $request->prompt,
            'image_url' => $this->imageReferenceUrl($request),
            'duration' => $request->seconds,
            'resolution' => $request->resolution,
        ], static fn ($value): bool => $value !== null), $request->providerOptions);

        $query = $request->webhookUrl !== null ? ['fal_webhook' => $request->webhookUrl] : [];

        $response = $this->client()
            ->withQueryParameters($query)
            ->post($this->submitUrl($model), $body)
            ->throw();

        $data = (array) $response->json();
        $jobId = (string) ($data['request_id'] ?? '');

        return new VideoJob(
            provider: 'fal',
            jobId: $jobId,
            status: $this->mapStatus($data['status'] ?? 'IN_QUEUE'),
            model: $model,
            statusUrl: $data['status_url'] ?? $this->requestUrl($model, $jobId).'/status',
            responseUrl: $data['response_url'] ?? $this->requestUrl($model, $jobId),
            cancelUrl: $data['cancel_url'] ?? $this->requestUrl($model, $jobId).'/cancel',
            queuePosition: isset($data['queue_position']) ? (int) $data['queue_position'] : null,
            raw: $data,
        );
    }

    public function status(VideoJob $job): VideoJob
    {
        $statusUrl = $job->statusUrl ?? $this->requestUrl($job->model ?? $this->defaultModel, $job->jobId).'/status';

        $response = $this->client()->get($statusUrl, ['logs' => 1])->throw();
        $data = (array) $response->json();

        return $job->withStatus(
            status: $this->mapStatus($data['status'] ?? 'IN_PROGRESS'),
            queuePosition: isset($data['queue_position']) ? (int) $data['queue_position'] : null,
        );
    }

    public function retrieve(VideoJob $job): VideoResult
    {
        $responseUrl = $job->responseUrl ?? $this->requestUrl($job->model ?? $this->defaultModel, $job->jobId);

        $response = $this->client()->get($responseUrl);

        if ($response->failed()) {
            throw new RuntimeException("fal.ai video job [{$job->jobId}] failed to retrieve: HTTP {$response->status()}.");
        }

        $data = (array) $response->json();

        // A vendor error can arrive with a 200 body carrying `detail`/`error`.
        if (isset($data['detail']) || isset($data['error'])) {
            $message = is_string($data['detail'] ?? null) ? $data['detail'] : ($data['error'] ?? 'unknown error');
            throw new RuntimeException("fal.ai video job [{$job->jobId}] errored: {$message}");
        }

        $video = $this->extractVideo($data);

        return new VideoResult(
            provider: 'fal',
            jobId: $job->jobId,
            url: $video['url'] ?? null,
            mimeType: $video['content_type'] ?? null,
            seconds: isset($video['duration']) ? (int) round((float) $video['duration']) : null,
            resolution: $this->extractResolution($video),
            raw: $data,
        );
    }

    public function cancel(VideoJob $job): void
    {
        $cancelUrl = $job->cancelUrl ?? $this->requestUrl($job->model ?? $this->defaultModel, $job->jobId).'/cancel';

        // Best-effort: 202 (requested), 400 (already completed), 404 (unknown) are all
        // acceptable outcomes — never throw on a cancel.
        $this->client()->put($cancelUrl);
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])->asJson();
    }

    private function submitUrl(string $model): string
    {
        return rtrim($this->url, '/').'/'.ltrim($model, '/');
    }

    private function requestUrl(string $model, string $jobId): string
    {
        return $this->submitUrl($model).'/requests/'.$jobId;
    }

    private function mapStatus(string $vendorStatus): VideoJobStatus
    {
        return match (strtoupper($vendorStatus)) {
            'IN_QUEUE' => VideoJobStatus::Queued,
            'COMPLETED', 'OK' => VideoJobStatus::Completed,
            'ERROR', 'FAILED' => VideoJobStatus::Failed,
            // IN_PROGRESS and any unknown non-terminal spelling.
            default => VideoJobStatus::Processing,
        };
    }

    /**
     * Resolve an image-to-video seed frame to something fal accepts as `image_url`:
     * a hosted URL passes through; a local/base64 Media becomes a `data:` URI.
     */
    private function imageReferenceUrl(VideoRequest $request): ?string
    {
        $media = $request->imageReference;

        if ($media === null) {
            return null;
        }

        if ($media->hasUrl()) {
            return $media->url();
        }

        $base64 = $media->base64();

        if ($base64 !== null) {
            return 'data:'.($media->mimeType() ?? 'image/png').';base64,'.$base64;
        }

        return null;
    }

    /**
     * fal video models return the clip under a `video` object; be defensive about the
     * exact nesting, which varies by model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractVideo(array $data): array
    {
        if (isset($data['video']) && is_array($data['video'])) {
            return $data['video'];
        }

        if (isset($data['videos'][0]) && is_array($data['videos'][0])) {
            return $data['videos'][0];
        }

        if (isset($data['output']['video']) && is_array($data['output']['video'])) {
            return $data['output']['video'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $video
     */
    private function extractResolution(array $video): ?string
    {
        if (isset($video['resolution'])) {
            return (string) $video['resolution'];
        }

        if (isset($video['width'], $video['height'])) {
            return $video['width'].'x'.$video['height'];
        }

        return null;
    }
}
