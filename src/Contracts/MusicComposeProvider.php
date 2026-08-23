<?php

namespace Rushing\PrismPlus\Contracts;

use Rushing\PrismPlus\Data\MusicComposeResult;

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
 *
 * Bytes-out means bytes AND the response headers ({@see MusicComposeResult}), because on this
 * transport the take's vendor id is a header and nothing else: no queue handle exists on a
 * synchronous call, so a bytes-only return leaves a billed take with no identity at all. That
 * is why this is the return type rather than a fuller sibling method — a lossy default keeping
 * the short name is how the next caller reaches for the wrong one.
 */
interface MusicComposeProvider
{
    /**
     * Synchronously compose music from an already-shaped request body; returns the produced
     * audio bytes plus the vendor's response headers.
     *
     * @param  array<string, mixed>  $request  the vendor-correct body args, shaped by the host.
     * @param  string  $outputFormat  the vendor's `codec_samplerate_bitrate` output token.
     */
    public function compose(array $request, string $outputFormat = 'mp3_44100_128'): MusicComposeResult;
}
