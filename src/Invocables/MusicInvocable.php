<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Invocables;

use Closure;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\PrismPlus\Contracts\MusicProvider;
use Rushing\PrismPlus\Data\MusicJob;
use Rushing\PrismPlus\Data\MusicRequest;

/**
 * An async music-generation provider's **submit** step as a popcorn {@see Invocable} — strictly
 * array-in / array-out, the music sibling of {@see VideoInvocable}. Only `generate()` (a
 * request → job submit) crosses the registry; the follow-on `status()`/`retrieve()`/`cancel()`
 * take the full {@see MusicJob} handle and stay on the typed driver
 * reached via {@see driver()}.
 */
class MusicInvocable implements Invocable
{
    private Closure $driver;

    /**
     * @param  callable(): MusicProvider  $driver  resolves the typed music driver
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

    /** The resolved typed driver — for callers that need `status()`/`retrieve()`/`cancel()`. */
    public function driver(): MusicProvider
    {
        return ($this->driver)();
    }

    public function invoke(array $input): array
    {
        return ($this->driver)()->generate(MusicRequest::fromArray($input))->toArray();
    }
}
