<?php

namespace Rushing\PrismPlus\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\MusicComposeProvider;

/**
 * The ElevenLabs Music driver — the synchronous, structured-conditioning counterpart to the
 * async fal music queue. Speaks the ElevenLabs REST API (`xi-api-key`, JSON, synchronous):
 *  - `POST music?output_format=…` → the produced track's audio bytes.
 *
 * The host shapes the request body (ElevenLabs' `{composition_plan, model_id}` or
 * `{prompt, music_length_ms, model_id}`) — this driver is pure transport and POSTs it verbatim,
 * never inventing or renaming a vendor field. Bytes-out (not a fal-queue URL), so the host
 * persists the produced audio itself. Mirrors {@see ElevenLabsVoiceProvider} exactly.
 */
class ElevenLabsMusicProvider implements MusicComposeProvider
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.elevenlabs.io/v1/',
    ) {}

    public function compose(array $request, string $outputFormat = 'mp3_44100_128'): string
    {
        return $this->client()
            ->withBody(
                (string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'application/json',
            )
            ->post('music?output_format='.$outputFormat)
            ->throw()
            ->body();
    }

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('prism.providers.elevenlabs.api_key', ''),
            baseUrl: (string) config('prism.providers.elevenlabs.url', 'https://api.elevenlabs.io/v1/'),
        );
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    private function client(): PendingRequest
    {
        // Music renders are long — allow well past the fal/voice default before giving up.
        return Http::withHeaders(['xi-api-key' => $this->apiKey])
            ->baseUrl($this->baseUrl)
            ->timeout(300);
    }
}
