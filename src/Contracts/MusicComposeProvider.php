<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Contracts;

/**
 * A SYNCHRONOUS, structured music-composition driver — the home for vendors whose music API
 * returns the finished audio in one request/response instead of the fal queue's submit →
 * poll → retrieve lifecycle. It is to the async {@see MusicProvider} what {@see VoiceCloneProvider}
 * is to the fal audio drivers: same package, different transport shape.
 *
 * The driver is pure transport — the host shapes the already-vendor-correct request body
 * (e.g. ElevenLabs' `{composition_plan, model_id}` or `{prompt, music_length_ms}`) and this
 * contract POSTs it verbatim, never inventing or renaming a vendor field. Bytes-out (not a
 * URL): the produced audio comes back as raw bytes for the host to persist itself.
 */
interface MusicComposeProvider
{
    /**
     * Synchronously compose music from an already-shaped request body; returns the produced
     * audio bytes.
     *
     * @param  array<string, mixed>  $request  the vendor-correct body args, shaped by the host.
     * @param  string  $outputFormat  the vendor's `codec_samplerate_bitrate` output token.
     * @return string the produced audio bytes.
     */
    public function compose(array $request, string $outputFormat = 'mp3_44100_128'): string;
}
