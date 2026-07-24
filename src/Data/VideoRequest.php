<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Data;

use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A provider-portable video-generation request: describe the clip and (optionally)
 * seed it from a reference image (image-to-video). Normalized shape — each driver
 * maps these onto its own vendor body (fal.ai `prompt`/`image_url`/`duration`,
 * Sora `prompt`/`input_reference`/`seconds`); this class never leaks a vendor
 * spelling. Anything a specific model needs beyond this rides in `providerOptions`.
 */
#[TypeScript]
class VideoRequest extends Data
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
        public string $prompt,
        public ?Media $imageReference = null,
        public ?int $seconds = null,
        public ?string $resolution = null,
        public ?string $webhookUrl = null,
        public ?string $model = null,
        public array $providerOptions = [],
    ) {}

    public function withModel(?string $model): static
    {
        return new static(
            prompt: $this->prompt,
            imageReference: $this->imageReference,
            seconds: $this->seconds,
            resolution: $this->resolution,
            webhookUrl: $this->webhookUrl,
            model: $model,
            providerOptions: $this->providerOptions,
        );
    }

    public function withWebhookUrl(?string $webhookUrl): static
    {
        return new static(
            prompt: $this->prompt,
            imageReference: $this->imageReference,
            seconds: $this->seconds,
            resolution: $this->resolution,
            webhookUrl: $webhookUrl,
            model: $this->model,
            providerOptions: $this->providerOptions,
        );
    }

    /**
     * The wire shape for the registry's array boundary (overrides spatie's default projection). The
     * {@see Media} image seed can't cross an array/HTTP boundary as a binary object, so it is reduced
     * to an `image_url` — a hosted URL passes through; a local/base64 seed becomes a `data:` URI — the
     * same normalization the fal driver applies. {@see fromArray()} reconstructs a URL-backed Media.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'image_url' => $this->imageReferenceUrl(),
            'seconds' => $this->seconds,
            'resolution' => $this->resolution,
            'webhook_url' => $this->webhookUrl,
            'model' => $this->model,
            'provider_options' => $this->providerOptions,
        ];
    }

    /**
     * Rehydrate a request from its {@see toArray()} wire shape (the array-in half of the video
     * capability's invocable). An `image_url` becomes a URL-backed {@see Image} seed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $imageUrl = $data['image_url'] ?? null;

        return new static(
            prompt: (string) ($data['prompt'] ?? ''),
            imageReference: is_string($imageUrl) && $imageUrl !== '' ? Image::fromUrl($imageUrl) : null,
            seconds: isset($data['seconds']) ? (int) $data['seconds'] : null,
            resolution: $data['resolution'] ?? null,
            webhookUrl: $data['webhook_url'] ?? null,
            model: $data['model'] ?? null,
            providerOptions: (array) ($data['provider_options'] ?? []),
        );
    }

    /**
     * Reduce the {@see Media} image seed to a string fal/other drivers accept as `image_url`:
     * a hosted URL passes through; a local/base64 Media becomes a `data:` URI; null stays null.
     */
    private function imageReferenceUrl(): ?string
    {
        $media = $this->imageReference;

        if ($media === null) {
            return null;
        }

        if ($media->hasUrl()) {
            return $media->url();
        }

        $base64 = $media->base64();

        return $base64 !== null
            ? 'data:'.($media->mimeType() ?? 'image/png').';base64,'.$base64
            : null;
    }
}
