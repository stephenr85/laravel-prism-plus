<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\ValueObjects;

use Prism\Prism\ValueObjects\Media\Media;

/**
 * A provider-portable video-generation request: describe the clip and (optionally)
 * seed it from a reference image (image-to-video). Normalized shape — each driver
 * maps these onto its own vendor body (fal.ai `prompt`/`image_url`/`duration`,
 * Sora `prompt`/`input_reference`/`seconds`); this class never leaks a vendor
 * spelling. Anything a specific model needs beyond this rides in `providerOptions`.
 */
final class VideoRequest
{
    /**
     * @param  string  $prompt  Text description of the clip.
     * @param  Media|null  $imageReference  A seed frame for image-to-video (reuse Prism's Media VO).
     * @param  int|null  $seconds  Requested duration; provider clamps to its supported set.
     * @param  string|null  $resolution  e.g. `720p`, `1080p` — mapped to the vendor's own axis.
     * @param  string|null  $webhookUrl  Push completion here instead of (or alongside) polling.
     * @param  string|null  $model  Override the provider's default video model.
     * @param  array<string, mixed>  $providerOptions  Verbatim extra body params for the target model.
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?Media $imageReference = null,
        public readonly ?int $seconds = null,
        public readonly ?string $resolution = null,
        public readonly ?string $webhookUrl = null,
        public readonly ?string $model = null,
        public readonly array $providerOptions = [],
    ) {}

    public function withModel(?string $model): self
    {
        return new self(
            prompt: $this->prompt,
            imageReference: $this->imageReference,
            seconds: $this->seconds,
            resolution: $this->resolution,
            webhookUrl: $this->webhookUrl,
            model: $model,
            providerOptions: $this->providerOptions,
        );
    }

    public function withWebhookUrl(?string $webhookUrl): self
    {
        return new self(
            prompt: $this->prompt,
            imageReference: $this->imageReference,
            seconds: $this->seconds,
            resolution: $this->resolution,
            webhookUrl: $webhookUrl,
            model: $this->model,
            providerOptions: $this->providerOptions,
        );
    }
}
