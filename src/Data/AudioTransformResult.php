<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The produced output of an audio transform (an isolated vocal, a converted vocal, …): the
 * result audio URL plus the raw vendor payload. A stem model may expose several outputs; the
 * primary one is surfaced as {@see $url} and the rest stay in {@see $raw}.
 */
#[TypeScript]
class AudioTransformResult extends Data
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public ?string $url = null,
        public array $raw = [],
    ) {}
}
