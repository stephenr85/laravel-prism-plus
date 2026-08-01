<?php

namespace Rushing\PrismPlus\Invocables;

use Closure;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\PrismPlus\Contracts\ModelListProvider;

/**
 * A model-listing provider as a popcorn {@see Invocable} — array-in / array-out, so the same
 * `models` capability key can be served by this local PHP driver, an MCP tool, or a tenant webhook
 * interchangeably. Listing takes no request payload, so {@see invoke()} ignores its input (the slot
 * is reserved for future filters) and returns `$listing->toArray()`.
 *
 * The driver is a factory resolved per invoke, so provider credentials are read from current config
 * at call time.
 */
class ModelListInvocable implements Invocable
{
    private Closure $driver;

    /**
     * @param  callable(): ModelListProvider  $driver
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

    public function driver(): ModelListProvider
    {
        return ($this->driver)();
    }

    /**
     * @param  array<string, mixed>  $input  ignored — listing takes no payload (reserved for filters)
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        return ($this->driver)()->listModels()->toArray();
    }
}
