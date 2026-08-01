<?php

namespace Rushing\PrismPlus\Providers;

use RuntimeException;
use Rushing\PrismPlus\Contracts\MusicProvider;
use Rushing\PrismPlus\Data\MusicJob;
use Rushing\PrismPlus\Data\MusicJobStatus;
use Rushing\PrismPlus\Data\MusicRequest;
use Rushing\PrismPlus\Data\MusicResult;
use Rushing\PrismPlus\Fal\FalQueue;

/**
 * fal.ai queue MUSIC driver — the produced-track tier over fal's async queue, the music
 * sibling of {@see FalVideoProvider}. One queue API fronts the whole fal music roster
 * (ACE-Step / MiniMax Music / Stable Audio / …), so a single integration reaches them all;
 * it shares the {@see FalQueue} transport core with video + voice.
 *
 * Model-agnostic: the host's vendor adapter shapes the body args ({@see MusicRequest::$input}
 * — tags/lyrics/quality knobs), and this driver only owns the queue orchestration + audio-URL
 * extraction. A 422 at submit is a synchronous schema rejection (no generation billed) whose
 * body names the offending fields.
 */
class FalMusicProvider implements MusicProvider
{
    private FalQueue $queue;

    public function __construct(
        string $apiKey,
        string $url = 'https://queue.fal.run',
        private string $defaultModel = 'fal-ai/ace-step',
    ) {
        $this->queue = new FalQueue($apiKey, $url);
    }

    public function generate(MusicRequest $request): MusicJob
    {
        $model = $request->model ?? $this->defaultModel;
        $query = $request->webhookUrl !== null ? ['fal_webhook' => $request->webhookUrl] : [];

        $response = $this->queue->submit($model, $request->input, $query);

        if ($response->status() === 422) {
            throw new RuntimeException("fal music [{$model}] rejected the input (422): ".$response->body());
        }
        $response->throw();

        $data = (array) $response->json();
        $jobId = (string) ($data['request_id'] ?? '');

        return new MusicJob(
            provider: 'fal',
            jobId: $jobId,
            status: MusicJobStatus::fromVendor((string) ($data['status'] ?? 'IN_QUEUE')),
            model: $model,
            statusUrl: $data['status_url'] ?? $this->queue->requestUrl($model, $jobId).'/status',
            responseUrl: $data['response_url'] ?? $this->queue->requestUrl($model, $jobId),
            cancelUrl: $data['cancel_url'] ?? $this->queue->requestUrl($model, $jobId).'/cancel',
            queuePosition: isset($data['queue_position']) ? (int) $data['queue_position'] : null,
            raw: $data,
        );
    }

    public function status(MusicJob $job): MusicJob
    {
        $statusUrl = $job->statusUrl ?? $this->queue->requestUrl($job->model ?? $this->defaultModel, $job->jobId).'/status';

        $data = (array) $this->queue->get($statusUrl, ['logs' => 1])->throw()->json();

        return $job->withStatus(
            status: MusicJobStatus::fromVendor((string) ($data['status'] ?? 'IN_PROGRESS')),
            queuePosition: isset($data['queue_position']) ? (int) $data['queue_position'] : null,
        );
    }

    public function retrieve(MusicJob $job): MusicResult
    {
        $responseUrl = $job->responseUrl ?? $this->queue->requestUrl($job->model ?? $this->defaultModel, $job->jobId);

        $response = $this->queue->get($responseUrl);
        $data = (array) $response->json();

        if ($response->failed() || isset($data['detail']) || isset($data['error'])) {
            $message = $data['detail'] ?? $data['error'] ?? "HTTP {$response->status()}";
            throw new RuntimeException("fal music job [{$job->jobId}] failed: ".(is_string($message) ? $message : (string) json_encode($message)));
        }

        return new MusicResult(
            provider: 'fal',
            jobId: $job->jobId,
            url: $this->audioUrl($data),
            mimeType: $this->audioField($data, 'content_type'),
            seconds: $this->durationSeconds($data),
            raw: $data,
        );
    }

    public function cancel(MusicJob $job): void
    {
        $cancelUrl = $job->cancelUrl ?? $this->queue->requestUrl($job->model ?? $this->defaultModel, $job->jobId).'/cancel';

        // Best-effort — never throw on a cancel.
        $this->queue->put($cancelUrl);
    }

    /**
     * The first audio URL in a fal music response — shapes vary
     * (`audio.url`, `audio_file.url`, `output.url`, `audio_url`, top-level `url`).
     *
     * @param  array<string, mixed>  $data
     */
    public function audioUrl(array $data): ?string
    {
        foreach (['audio', 'audio_file', 'output'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && isset($data[$key]['url'])) {
                return (string) $data[$key]['url'];
            }
        }
        foreach (['audio_url', 'url'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    /** The produced duration in seconds, if the model reports it (several nesting shapes). */
    public function durationSeconds(array $data): ?float
    {
        foreach ([['audio', 'duration'], ['audio_file', 'duration'], ['duration']] as $path) {
            $node = $data;
            foreach ($path as $seg) {
                $node = is_array($node) ? ($node[$seg] ?? null) : null;
            }
            if (is_numeric($node)) {
                return (float) $node;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function audioField(array $data, string $field): ?string
    {
        foreach (['audio', 'audio_file', 'output'] as $key) {
            if (isset($data[$key][$field]) && is_string($data[$key][$field])) {
                return $data[$key][$field];
            }
        }

        return null;
    }
}
