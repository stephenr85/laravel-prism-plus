<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Contracts\VoiceCloneProvider;

/**
 * The ElevenLabs voice-clone + voice-changer driver (spike-confirmed live 2026-07). Speaks the
 * ElevenLabs REST API (`xi-api-key`, multipart, synchronous):
 *  - `POST voices/add` (instant voice cloning) → `{voice_id}` (needs a paid plan).
 *  - `POST audio-isolation` → isolated-vocal audio bytes.
 *  - `POST speech-to-speech/{voice_id}` → re-voiced audio bytes (melody kept, timbre swapped).
 *
 * Bytes-out (not a URL like the fal queue), so the host persists the produced audio itself. The
 * `voice_settings`/`model_id` knobs ride through to tune timbre-fidelity vs expressiveness.
 */
final class ElevenLabsVoiceProvider implements VoiceCloneProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.elevenlabs.io/v1/',
    ) {}

    public function cloneVoice(string $name, array $audioPaths): string
    {
        $request = $this->client();
        foreach ($audioPaths as $path) {
            $request = $request->attach('files', (string) file_get_contents($path), basename($path));
        }

        $response = $request->post('voices/add', [
            'name' => $name,
            'remove_background_noise' => 'true',
        ])->throw();

        return (string) $response->json('voice_id');
    }

    public function isolate(string $audioPath): string
    {
        return $this->client()
            ->attach('audio', (string) file_get_contents($audioPath), basename($audioPath))
            ->post('audio-isolation')
            ->throw()
            ->body();
    }

    public function convert(string $voiceId, string $audioPath, array $settings = [], string $modelId = 'eleven_multilingual_sts_v2'): string
    {
        $data = ['model_id' => $modelId];
        if ($settings !== []) {
            $data['voice_settings'] = (string) json_encode($settings);
        }

        return $this->client()
            ->attach('audio', (string) file_get_contents($audioPath), basename($audioPath))
            ->post('speech-to-speech/'.$voiceId, $data)
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
        return Http::withHeaders(['xi-api-key' => $this->apiKey])
            ->baseUrl($this->baseUrl)
            ->timeout(180);
    }
}
