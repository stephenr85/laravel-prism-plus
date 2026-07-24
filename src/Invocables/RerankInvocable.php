<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Invocables;

use Closure;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;

/**
 * A rerank provider as a popcorn {@see Invocable} — strictly array-in / array-out. This is the
 * load-bearing seam: because a provider answers rerank over the array boundary, the same capability
 * key can be served by this local PHP driver, an MCP tool, or a tenant webhook interchangeably. The
 * typed {@see RerankProvider} driver sits *behind* the boundary — {@see invoke()} hydrates the input
 * into a {@see RerankRequest}, calls the typed driver, and returns `$response->toArray()`.
 *
 * The driver is supplied as a factory (resolved per invoke) so provider credentials are read from
 * current config at call time and the cassette recording decorator can wrap the fresh live path.
 */
class RerankInvocable implements Invocable
{
    private Closure $driver;

    /**
     * @param  callable(): RerankProvider  $driver  resolves the typed rerank driver (recordable-wrapped)
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
     * The resolved typed driver — the deprecated `PrismPlusManager::rerankProvider()` shim hands this
     * back so callers holding the driver directly share the registry's single resolution path.
     */
    public function driver(): RerankProvider
    {
        return ($this->driver)();
    }

    public function invoke(array $input): array
    {
        return ($this->driver)()->rerank(RerankRequest::from($input))->toArray();
    }
}
