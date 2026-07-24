<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\ValueObjects;

use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Enums\VideoJobStatus;

/**
 * A provider-portable handle to an in-flight (or finished) video job. Returned by
 * {@see VideoProvider::generate()} and refreshed by
 * `status()`.
 *
 * It is deliberately **fully serializable** ({@see toArray()} / {@see fromArray()}):
 * the app persists it to a `video_jobs` row and rehydrates it inside a queued poll
 * worker, so the handle — not a bare id — is the unit of continuation. That matters
 * because async vendors encode everything the poll/retrieve/cancel calls need in the
 * URLs returned at submit time (fal.ai `status_url`/`response_url`/`cancel_url`), and
 * a bare job id can't reconstruct them.
 */
final class VideoJob
{
    /**
     * @param  string  $provider  The driver name that owns this job (e.g. `fal`).
     * @param  string  $jobId  The vendor's request/job id.
     * @param  VideoJobStatus  $status  Normalized lifecycle state.
     * @param  string|null  $model  The model the job was submitted to.
     * @param  string|null  $statusUrl  Vendor poll URL (opaque; carried verbatim).
     * @param  string|null  $responseUrl  Vendor result URL (opaque; carried verbatim).
     * @param  string|null  $cancelUrl  Vendor cancel URL (opaque; carried verbatim).
     * @param  int|null  $queuePosition  Position in the vendor queue, where reported.
     * @param  string|null  $error  A failure message once the job is Failed.
     * @param  array<string, mixed>  $raw  The last raw vendor payload, for debugging.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $jobId,
        public readonly VideoJobStatus $status,
        public readonly ?string $model = null,
        public readonly ?string $statusUrl = null,
        public readonly ?string $responseUrl = null,
        public readonly ?string $cancelUrl = null,
        public readonly ?int $queuePosition = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Same job, new lifecycle state (+ optional queue position / error) — used by a
     * driver's `status()` to refresh a rehydrated handle without losing its URLs.
     */
    public function withStatus(VideoJobStatus $status, ?int $queuePosition = null, ?string $error = null): self
    {
        return new self(
            provider: $this->provider,
            jobId: $this->jobId,
            status: $status,
            model: $this->model,
            statusUrl: $this->statusUrl,
            responseUrl: $this->responseUrl,
            cancelUrl: $this->cancelUrl,
            queuePosition: $queuePosition ?? $this->queuePosition,
            error: $error ?? $this->error,
            raw: $this->raw,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'job_id' => $this->jobId,
            'status' => $this->status->value,
            'model' => $this->model,
            'status_url' => $this->statusUrl,
            'response_url' => $this->responseUrl,
            'cancel_url' => $this->cancelUrl,
            'queue_position' => $this->queuePosition,
            'error' => $this->error,
        ];
    }

    /**
     * Rehydrate a handle from a persisted {@see toArray()} row (the poll worker's
     * entry point). Unknown/absent keys degrade to null; `raw` is not round-tripped.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: (string) $data['provider'],
            jobId: (string) $data['job_id'],
            status: VideoJobStatus::from((string) $data['status']),
            model: $data['model'] ?? null,
            statusUrl: $data['status_url'] ?? null,
            responseUrl: $data['response_url'] ?? null,
            cancelUrl: $data['cancel_url'] ?? null,
            queuePosition: isset($data['queue_position']) ? (int) $data['queue_position'] : null,
            error: $data['error'] ?? null,
        );
    }
}
