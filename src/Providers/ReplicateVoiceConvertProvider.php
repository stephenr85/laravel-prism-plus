<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Providers;

use Rushing\PrismPlus\Contracts\AudioTransformProvider;
use Rushing\PrismPlus\Data\AudioTransformResult;
use Rushing\PrismPlus\Replicate\ReplicateClient;

/**
 * Replicate-hosted singing VOICE CONVERSION (FreeVC by default) — the real voice-mimic path:
 * zero-shot, reference-based, no training. Feed a sung source vocal + a short reference of the
 * target voice, get the source re-sung in that timbre, naturally (unlike ElevenLabs STS, which
 * is speech-tuned and warbles on singing; and fal has no VC model at all).
 *
 * Implements the shared {@see AudioTransformProvider} contract so it drops into the same
 * voice-op seam as the fal driver: `transform($version, $input)` where `$version` is the
 * Replicate model version (empty → the FreeVC default) and `$input` is the model's opaque body
 * (e.g. FreeVC's `source_audio` + `reference_audio`). Async under the hood via {@see ReplicateClient}.
 */
final class ReplicateVoiceConvertProvider implements AudioTransformProvider
{
    /** FreeVC (jagilley/free-vc) — zero-shot voice conversion; source_audio + reference_audio → converted URL. */
    public const FREE_VC_VERSION = 'e4f2ff8a1d3779a2411e119dfad7d451d5f3314a8cd7003a88f88ce4c3b18d95';

    public function __construct(
        private readonly ReplicateClient $client,
        private readonly string $defaultVersion = self::FREE_VC_VERSION,
    ) {}

    /**
     * @param  string  $model  the Replicate model VERSION (empty → the FreeVC default).
     * @param  array<string, mixed>  $input  the model's input body (shaped by the host).
     */
    public function transform(string $model, array $input): AudioTransformResult
    {
        $url = $this->client->run($model !== '' ? $model : $this->defaultVersion, $input);

        return new AudioTransformResult(provider: 'replicate', url: $url);
    }
}
