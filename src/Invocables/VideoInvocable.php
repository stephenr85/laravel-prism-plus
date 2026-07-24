<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Invocables;

use Closure;
use Prism\Prism\ValueObjects\Media\Media;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Data\VideoRequest;

/**
 * An async video-generation provider's **submit** step as a popcorn {@see Invocable} — strictly
 * array-in / array-out. Only `generate()` (a request→response submit) crosses the registry; the
 * follow-on `status()`/`retrieve()`/`cancel()` take the full {@see VideoJob} handle, are not
 * request→response, and stay on the typed driver reached via `PrismPlus::videoProvider()`.
 *
 * The image-seed {@see Media} is carried across the array boundary as
 * an `image_url` by {@see VideoRequest::toArray()}/`fromArray()`, so the typed driver still receives a
 * usable seed frame without a binary object needing to cross the wire.
 */
class VideoInvocable implements Invocable
{
    private Closure $driver;

    /**
     * @param  callable(): VideoProvider  $driver  resolves the typed video driver
     */
    public function __construct(
        private string $name,
        callable $driver,
    ) {
        $this->driver = $driver(...);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * The resolved typed driver — the deprecated `PrismPlusManager::videoProvider()` shim hands this
     * back so callers can drive `status()`/`retrieve()`/`cancel()` off the same registered provider.
     */
    public function driver(): VideoProvider
    {
        return ($this->driver)();
    }

    public function invoke(array $input): array
    {
        return ($this->driver)()->generate(VideoRequest::fromArray($input))->toArray();
    }
}
