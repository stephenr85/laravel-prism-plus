<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A provider-portable handle to an in-flight (or finished) music-generation job — the music
 * counterpart to {@see VideoJob}. Fully serializable ({@see toArray()}/{@see fromArray()}) so
 * the host can persist it and rehydrate it inside a queued poll worker: the async vendor
 * encodes the poll/result/cancel endpoints in the URLs returned at submit, so the handle —
 * not a bare id — is the unit of continuation.
 */
#[TypeScript]
class MusicJob extends Data
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $jobId,
        public MusicJobStatus $status,
        public ?string $model = null,
        public ?string $statusUrl = null,
        public ?string $responseUrl = null,
        public ?string $cancelUrl = null,
        public ?int $queuePosition = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    public function withStatus(MusicJobStatus $status, ?int $queuePosition = null, ?string $error = null): static
    {
        return new static(
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

    public function isComplete(): bool
    {
        return $this->status === MusicJobStatus::Completed;
    }

    /** @return array<string, mixed> */
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
            'raw' => $this->raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            provider: (string) ($data['provider'] ?? 'fal'),
            jobId: (string) ($data['job_id'] ?? ''),
            status: MusicJobStatus::from((string) ($data['status'] ?? 'processing')),
            model: $data['model'] ?? null,
            statusUrl: $data['status_url'] ?? null,
            responseUrl: $data['response_url'] ?? null,
            cancelUrl: $data['cancel_url'] ?? null,
            queuePosition: isset($data['queue_position']) ? (int) $data['queue_position'] : null,
            error: $data['error'] ?? null,
            raw: (array) ($data['raw'] ?? []),
        );
    }
}
