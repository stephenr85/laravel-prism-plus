<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The finished output of a music-generation job — the produced track's URL (fal media host,
 * short-lived — download promptly) plus its reported duration. The music counterpart to
 * {@see VideoResult}.
 */
#[TypeScript]
class MusicResult extends Data
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $jobId,
        public ?string $url = null,
        public ?string $mimeType = null,
        public ?float $seconds = null,
        public array $raw = [],
    ) {}
}
