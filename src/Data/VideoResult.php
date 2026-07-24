<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A normalized, completed video-generation result. Every async vendor exposes the
 * finished clip as a (usually short-lived) hosted URL; the app is responsible for
 * downloading those bytes to its own servable disk (mirror `LocalImageGenerator`).
 * `bytes` is populated only when the driver itself fetched them.
 */
#[TypeScript]
class VideoResult extends Data
{
    /**
     * @param  string  $provider  The driver that produced this result.
     * @param  string  $jobId  The vendor job id this result belongs to.
     * @param  string|null  $url  The hosted video URL (often expiring — fetch promptly).
     * @param  string|null  $bytes  Raw video bytes, only if the driver downloaded them.
     * @param  string|null  $mimeType  e.g. `video/mp4`, where the vendor reports it.
     * @param  int|null  $seconds  Actual duration of the produced clip, where reported.
     * @param  string|null  $resolution  Actual resolution of the produced clip, where reported.
     * @param  array<string, mixed>  $raw  The raw vendor result payload, verbatim.
     */
    public function __construct(
        public string $provider,
        public string $jobId,
        public ?string $url = null,
        public ?string $bytes = null,
        public ?string $mimeType = null,
        public ?int $seconds = null,
        public ?string $resolution = null,
        public array $raw = [],
    ) {}
}
