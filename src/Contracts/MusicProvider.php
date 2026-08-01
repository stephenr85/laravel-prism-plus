<?php

namespace Rushing\PrismPlus\Contracts;

use Rushing\PrismPlus\Data\MusicJob;
use Rushing\PrismPlus\Data\MusicRequest;
use Rushing\PrismPlus\Data\MusicResult;

/**
 * An async music-generation driver — the music counterpart to {@see VideoProvider}. Like
 * video, music generation is fundamentally async (submit → poll/webhook → retrieve), which
 * Prism's synchronous modality slots don't model, so it lives in this parallel PrismPlus-owned
 * contract. `status()`/`retrieve()`/`cancel()` take the full serializable {@see MusicJob}
 * handle (the vendor encodes its poll/result/cancel endpoints in the submit-time URLs).
 */
interface MusicProvider
{
    /** Submit a generation job; MUST NOT block on completion — returns a handle immediately. */
    public function generate(MusicRequest $request): MusicJob;

    /** Re-poll a job's lifecycle state, preserving its handle URLs. */
    public function status(MusicJob $job): MusicJob;

    /** Fetch the finished result. Only valid once the job is Completed. */
    public function retrieve(MusicJob $job): MusicResult;

    /** Request cancellation where the vendor supports it; a no-op otherwise. */
    public function cancel(MusicJob $job): void;
}
