<?php

namespace Rushing\PrismPlus\Audio;

use BackedEnum;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\PendingRequest as PrismPendingAudioRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Audio;

/**
 * PrismPlus's audio decorator over Prism's `Audio\PendingRequest`. Prism already DRIVES audio —
 * OpenAI / ElevenLabs / Groq / Gemini each implement `textToSpeech()` (TTS, `asAudio()`) and
 * `speechToText()` (STT, `asText()`) — so this is a thin normalization layer, not a new provider
 * estate.
 *
 * Two provider warts, handled here so callers never branch on provider:
 *
 *  - **voice** is ALREADY normalized by Prism. Every provider reads `$request->voice()`; the
 *    OpenAI driver puts it in the request body, the ElevenLabs driver puts it in the URL path
 *    (`text-to-speech/{voice_id}`). So `withVoice()` passes straight through — the path-param wart
 *    lives in the ElevenLabs driver, not the interface.
 *  - **outputFormat** is NOT normalized. OpenAI/Groq take a simple `response_format` enum
 *    (`mp3|opus|aac|flac|wav|pcm`) in the body; ElevenLabs takes an `output_format` codec string
 *    (`mp3_44100_128`). {@see withOutputFormat()} accepts one normalized token and maps it to the
 *    right per-provider provider-option at dispatch.
 *
 * Everything not normalized here is reachable through {@see prism()} or {@see withProviderOptions()}.
 */
class PendingAudioRequest
{
    protected ?string $provider = null;

    protected ?string $outputFormat = null;

    public function __construct(protected PrismPendingAudioRequest $request) {}

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function using(string|Provider $provider, string|BackedEnum $model = '', array $providerConfig = []): self
    {
        // Capture the provider name so {@see applyOutputFormat()} can pick the right mapping — the
        // underlying Prism request resolves it into a driver we can't read back out.
        $this->provider = $provider instanceof BackedEnum ? (string) $provider->value : $provider;

        $this->request->using($provider, $model, $providerConfig);

        return $this;
    }

    public function withInput(string|Audio $input): self
    {
        $this->request->withInput($input);

        return $this;
    }

    public function withVoice(string $voice): self
    {
        $this->request->withVoice($voice);

        return $this;
    }

    /**
     * A normalized output-format token — `mp3`, `opus`, `aac`, `flac`, `wav`, `pcm` — mapped to the
     * provider's own option at dispatch (OpenAI/Groq `response_format` enum; ElevenLabs `output_format`
     * codec string). A full ElevenLabs codec string (e.g. `mp3_44100_128`) is accepted and passed
     * through untouched.
     */
    public function withOutputFormat(string $format): self
    {
        $this->outputFormat = strtolower($format);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withProviderOptions(array $options): self
    {
        $this->request->withProviderOptions($options);

        return $this;
    }

    /** Escape hatch: the wrapped Prism request, for anything this layer does not normalize. */
    public function prism(): PrismPendingAudioRequest
    {
        return $this->request;
    }

    /** Text-to-speech: normalizes the output format, then delegates to Prism. */
    public function asAudio(): AudioResponse
    {
        $this->applyOutputFormat();

        return $this->request->asAudio();
    }

    /** Speech-to-text: pure delegation (no output-format normalization on the transcription path). */
    public function asText(): TextResponse
    {
        return $this->request->asText();
    }

    /**
     * Merge the mapped output-format option into the request's provider options (merge, not replace,
     * so any caller-supplied provider options survive).
     */
    protected function applyOutputFormat(): void
    {
        if ($this->outputFormat === null) {
            return;
        }

        $this->request->withProviderOptions(array_merge(
            $this->request->providerOptions(),
            $this->mapOutputFormat($this->provider, $this->outputFormat),
        ));
    }

    /**
     * @return array<string, string> the single provider-option pair carrying the normalized format
     */
    protected function mapOutputFormat(?string $provider, string $format): array
    {
        return match ($provider) {
            'elevenlabs' => ['output_format' => $this->elevenLabsCodec($format)],
            // OpenAI, Groq (OpenAI-compatible `/audio/speech`), and any other provider take the
            // simple `response_format` enum.
            default => ['response_format' => $format],
        };
    }

    /**
     * ElevenLabs wants a `codec_samplerate[_bitrate]` string. Map the common normalized tokens to
     * sane defaults; a token that already looks like a full codec string (contains `_`) is trusted
     * and passed through.
     */
    protected function elevenLabsCodec(string $format): string
    {
        return match ($format) {
            'mp3' => 'mp3_44100_128',
            'opus' => 'opus_48000_128',
            'pcm' => 'pcm_44100',
            'ulaw' => 'ulaw_8000',
            'alaw' => 'alaw_8000',
            default => str_contains($format, '_') ? $format : 'mp3_44100_128',
        };
    }
}
