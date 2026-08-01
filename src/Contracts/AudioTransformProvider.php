<?php

namespace Rushing\PrismPlus\Contracts;

use Rushing\PrismPlus\Data\AudioTransformResult;

/**
 * An audio-in → audio-out transform driver over an async queue — the home for fal voice ops
 * like demucs vocal separation and singing voice conversion (RVC / seed-vc). Unlike a
 * generation provider, a transform's whole job is short and single-output, so the contract is
 * one synchronous {@see transform()} (submit → poll → retrieve internally) returning the
 * produced audio URL. The host's adapter shapes the opaque `$input` body (`audio_url`,
 * `reference_audio_url`, …); this driver only owns the queue mechanics.
 */
interface AudioTransformProvider
{
    /**
     * Run a model over the given (already vendor-shaped) input and return the produced audio.
     *
     * @param  array<string, mixed>  $input  the model-specific body args (shaped by the host adapter).
     */
    public function transform(string $model, array $input): AudioTransformResult;
}
