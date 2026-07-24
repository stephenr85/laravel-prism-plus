<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Enums;

/**
 * A provider-portable video-job lifecycle state. Every async video vendor
 * (fal.ai, Sora, Veo, Runway…) has its own status vocabulary; each driver maps
 * its vendor states onto these four so the app-side poller never branches on a
 * vendor spelling. (fal.ai: IN_QUEUE→Queued, IN_PROGRESS→Processing,
 * COMPLETED→Completed; a webhook `status: ERROR` or an errored result →Failed.)
 */
enum VideoJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * A terminal state needs no further polling — the queued worker stops here.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Queued, self::Processing => false,
        };
    }
}
