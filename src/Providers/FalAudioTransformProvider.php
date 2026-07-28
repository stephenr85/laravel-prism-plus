<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use RuntimeException;
use Rushing\PrismPlus\Contracts\AudioTransformProvider;
use Rushing\PrismPlus\Data\AudioTransformResult;
use Rushing\PrismPlus\Fal\FalQueue;

/**
 * fal.ai queue AUDIO-TRANSFORM driver — the home for fal voice ops (demucs vocal separation,
 * RVC / seed-vc voice conversion). It shares the {@see FalQueue} transport core with video +
 * music, and — because a transform is short and single-output — runs the whole submit → poll →
 * retrieve synchronously, returning the produced audio URL. Model-agnostic: the host adapter
 * shapes the body args; this driver owns only the queue mechanics + output-URL extraction
 * (isolated-vocal stem models expose it as `vocals`/`stems.vocals`; single-output models as the
 * generic `audio`/`url`).
 */
final class FalAudioTransformProvider implements AudioTransformProvider
{
    private readonly FalQueue $queue;

    public function __construct(
        string $apiKey,
        string $url = 'https://queue.fal.run',
        private readonly int $pollSeconds = 5,
        private readonly int $maxPolls = 60,
    ) {
        $this->queue = new FalQueue($apiKey, $url);
    }

    public function transform(string $model, array $input): AudioTransformResult
    {
        $submit = $this->queue->submit($model, $input);

        if ($submit->status() === 422) {
            throw new RuntimeException("fal audio-transform [{$model}] rejected the input (422): ".$submit->body());
        }
        $submit->throw();

        $job = (array) $submit->json();
        $jobId = (string) ($job['request_id'] ?? '');
        $statusUrl = (string) ($job['status_url'] ?? $this->queue->requestUrl($model, $jobId).'/status');
        $responseUrl = (string) ($job['response_url'] ?? $this->queue->requestUrl($model, $jobId));

        for ($i = 0; $i < $this->maxPolls; $i++) {
            $state = FalQueue::normalizeStatus((string) (((array) $this->queue->get($statusUrl, ['logs' => 1])->throw()->json())['status'] ?? 'processing'));
            if ($state === 'completed' || $state === 'failed') {
                break;
            }
            sleep($this->pollSeconds);
        }

        $response = $this->queue->get($responseUrl);
        $data = (array) $response->json();

        if ($response->failed() || isset($data['detail']) || isset($data['error'])) {
            $message = $data['detail'] ?? $data['error'] ?? "HTTP {$response->status()}";
            throw new RuntimeException("fal audio-transform [{$model}] job [{$jobId}] failed: ".(is_string($message) ? $message : (string) json_encode($message)));
        }

        return new AudioTransformResult(
            provider: 'fal',
            url: $this->outputUrl($data),
            raw: $data,
        );
    }

    /**
     * The produced audio URL — the isolated VOCAL stem when a stem model returns several
     * (`vocals`/`stems.vocals`), else the generic single-output shape (`audio`/`output`/`url`).
     *
     * @param  array<string, mixed>  $data
     */
    private function outputUrl(array $data): ?string
    {
        if (isset($data['vocals'])) {
            $vocals = $data['vocals'];
            if (is_array($vocals) && isset($vocals['url'])) {
                return (string) $vocals['url'];
            }
            if (is_string($vocals)) {
                return $vocals;
            }
        }

        if (isset($data['stems']['vocals'])) {
            $vocals = $data['stems']['vocals'];

            return is_array($vocals) ? ($vocals['url'] ?? null) : (string) $vocals;
        }

        foreach (['audio', 'audio_file', 'output'] as $key) {
            if (isset($data[$key]['url'])) {
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
}
