<?php

namespace Rushing\PrismPlus\Data;

use Rushing\PrismPlus\Fal\FalQueue;

/**
 * Normalized lifecycle state of an async music-generation job — the music counterpart to
 * {@see VideoJobStatus}. fal's queue spellings (`IN_QUEUE`/`IN_PROGRESS`/`COMPLETED`/…) map
 * onto these via {@see FalQueue::normalizeStatus()}.
 */
enum MusicJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public static function fromVendor(string $vendorStatus): self
    {
        return match (FalQueue::normalizeStatus($vendorStatus)) {
            'queued' => self::Queued,
            'completed' => self::Completed,
            'failed' => self::Failed,
            default => self::Processing,
        };
    }

    /** A finished state — no further polling. */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
