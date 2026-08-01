<?php

namespace Rushing\PrismPlus\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A provider-portable music-generation request. Unlike video (which normalizes prompt/
 * image/duration here), music generation's vendor fields — tags/lyrics/quality knobs — are
 * MODEL-specific and are shaped by the consuming host's own vendor adapter (kernel §6
 * quarantine), so this request carries the already-shaped opaque `input` args verbatim plus
 * the `model` slug and an optional `webhookUrl`. The prism-plus driver is pure transport; it
 * never invents or renames a vendor field.
 */
#[TypeScript]
class MusicRequest extends Data
{
    /**
     * @param  array<string, mixed>  $input  the model-specific body args (shaped by the host adapter).
     * @param  string|null  $model  the fal model slug (overrides the driver default).
     * @param  string|null  $webhookUrl  push completion here instead of (or alongside) polling.
     */
    public function __construct(
        public array $input = [],
        public ?string $model = null,
        public ?string $webhookUrl = null,
    ) {}

    /** @return array<string, mixed> the wire shape for the registry array boundary. */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'model' => $this->model,
            'webhook_url' => $this->webhookUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            input: (array) ($data['input'] ?? []),
            model: $data['model'] ?? null,
            webhookUrl: $data['webhook_url'] ?? null,
        );
    }
}
