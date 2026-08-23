<?php

use Illuminate\Support\Facades\Cache;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Invocables\RemoteInvocable;
use Rushing\Popcorn\Laravel\Invocables\CachedInvocable;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\PrismPlusManager;

/**
 * The host-facing payoff of the registry (ticket 05): a capability can be answered by any binding,
 * swapped per tenant, memoized, and a brand-new capability stood up — all without editing package
 * source. Everything here drives the public `register()`/`forget()`/`capability()` seam a host would
 * call from its own service-provider `boot()`.
 */
function conformingRerankOutput(string $provider): array
{
    return [
        'provider' => $provider,
        'model' => 'test',
        'results' => [['index' => 0, 'score' => 0.5, 'document' => null]],
        'usage' => [],
    ];
}

it('registers a LocalInvocable provider from outside — no package-source edit', function () {
    app(PrismPlusManager::class)->register('rerank', new LocalInvocable(
        'in-process',
        static fn (array $input): array => conformingRerankOutput('in-process'),
    ));

    $response = app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'in-process');

    expect($response->provider)->toBe('in-process');
});

it('registers an MCP RemoteInvocable a tenant answers out of process', function () {
    $calls = [];
    app(PrismPlusManager::class)->register('rerank', new RemoteInvocable(
        'tenant-mcp',
        Binding::Mcp,
        function (string $name, array $input) use (&$calls): array {
            $calls[] = $name;

            return conformingRerankOutput('tenant-mcp');
        },
    ));

    $response = app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'tenant-mcp');

    expect($response->provider)->toBe('tenant-mcp')
        ->and($calls)->toBe(['tenant-mcp']);
});

it('registers a webhook RemoteInvocable with no PHP driver shipped by the maintainer', function () {
    app(PrismPlusManager::class)->register('rerank', new RemoteInvocable(
        'tenant-webhook',
        Binding::Webhook,
        static fn (string $name, array $input): array => conformingRerankOutput('tenant-webhook'),
    ));

    $registry = app(PrismPlusManager::class)->capability('rerank');

    expect($registry->get('tenant-webhook')->binding())->toBe(Binding::Webhook);
});

it('re-registering under the same key overrides the prior binding; callers are unchanged', function () {
    $manager = app(PrismPlusManager::class);

    $manager->register('rerank', new LocalInvocable('swap', static fn (array $i): array => conformingRerankOutput('first')));
    expect(app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'swap')->provider)->toBe('first');

    // Same capability+provider key, different binding → overrides.
    $manager->register('rerank', new RemoteInvocable('swap', Binding::Webhook, static fn (string $n, array $i): array => conformingRerankOutput('second')));
    expect(app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'swap')->provider)->toBe('second');
});

it('forget() tears a tenant-scoped registration down with no cross-tenant bleed', function () {
    $manager = app(PrismPlusManager::class);

    // A shared worker projects a tenant-scoped provider on tenant switch...
    $manager->register('rerank', new LocalInvocable('tenant-x', static fn (array $i): array => conformingRerankOutput('tenant-x')));
    expect($manager->capability('rerank')->has('tenant-x'))->toBeTrue();

    // ...and forgets it on revert, so the next tenant on the same worker can't reach it.
    $manager->forget('rerank', 'tenant-x');
    expect($manager->capability('rerank')->has('tenant-x'))->toBeFalse();

    app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'tenant-x');
})->throws(RegistryMiss::class);

it('a CachedInvocable memoizes identical inputs transparently, regardless of binding', function () {
    $calls = 0;
    $inner = new LocalInvocable('counting', function (array $input) use (&$calls): array {
        $calls++;

        return conformingRerankOutput('counting');
    });

    app(PrismPlusManager::class)->register('rerank', new CachedInvocable(
        $inner,
        Cache::store('array'),
        static fn (array $input): string => md5(json_encode($input, JSON_THROW_ON_ERROR)),
    ));

    $request = new RerankRequest(query: 'same', documents: ['a', 'b']);

    app(PrismPlus::class)->rerank($request, provider: 'counting');
    app(PrismPlus::class)->rerank($request, provider: 'counting');

    // Two identical calls, one underlying invocation.
    expect($calls)->toBe(1);
});

it('adding a capability is registering its first provider under a new key — no new machinery', function () {
    // A throwaway capability the package ships no built-ins for: `capability('classify')` seeds an
    // empty registry, and the first register() stands the capability up.
    app(PrismPlusManager::class)->register('classify', new LocalInvocable(
        'acme',
        static fn (array $input): array => ['label' => 'positive', 'score' => 0.9],
    ));

    $output = app(PrismPlusManager::class)->capability('classify')->invoke('acme', ['text' => 'great!']);

    expect($output)->toBe(['label' => 'positive', 'score' => 0.9]);
});
