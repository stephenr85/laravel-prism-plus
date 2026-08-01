<?php

namespace Rushing\PrismPlus\Contracts;

use Rushing\PrismPlus\Data\VideoJob;
use Rushing\PrismPlus\Data\VideoJobStatus;
use Rushing\PrismPlus\Data\VideoRequest;
use Rushing\PrismPlus\Data\VideoResult;

/**
 * An async video-generation driver. Deliberately NOT a `Prism\Prism\Providers\Provider`
 * subclass: Prism's base class is a closed, fixed-slot contract with no video slot (and
 * video is fundamentally async — submit → poll/webhook → retrieve — which none of Prism's
 * synchronous modality slots model), so video lives in this parallel PrismPlus-owned
 * contract instead.
 *
 * `status()`/`retrieve()`/`cancel()` take the full {@see VideoJob} handle rather than a
 * bare id: async vendors encode the poll/result/cancel endpoints in the URLs returned at
 * submit time, so the serializable handle is the unit of continuation across a queued
 * poll worker.
 */
interface VideoProvider
{
    /**
     * Submit a generation job. MUST NOT block on completion — returns a handle
     * immediately (typically {@see VideoJobStatus::Queued}).
     */
    public function generate(VideoRequest $request): VideoJob;

    /**
     * Re-poll a job's lifecycle state, preserving its handle URLs.
     */
    public function status(VideoJob $job): VideoJob;

    /**
     * Fetch the finished result. Only valid once the job is
     * {@see VideoJobStatus::Completed}.
     */
    public function retrieve(VideoJob $job): VideoResult;

    /**
     * Request cancellation where the vendor supports it; a no-op otherwise.
     */
    public function cancel(VideoJob $job): void;
}
