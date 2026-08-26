<?php

use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\PrismPlus\PrismPlusManager;

/**
 * The registry-kernel conformance seam for {@see PrismPlusManager} (registry-kernel ticket 38): the
 * capability map is a popcorn {@see Registry} rooted at `prism-plus.capabilities`, described into the
 * host's index at boot, and its historical two-argument `register()` still writes a PROVIDER one tier
 * down.
 */
it('shares ONE RegistryIndex across the container', function () {
    // The tripwire for ticket 27 D3's harness defect: without PopcornServiceProvider in
    // getPackageProviders(), the container auto-resolves a FRESH index per make() and every
    // assertion below would pass over a throwaway.
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('declares and is described under prism-plus.capabilities', function () {
    $manager = app(PrismPlusManager::class);

    expect($manager)->toBeInstanceOf(Registry::class)
        ->and($manager)->toBeInstanceOf(Gated::class);

    $index = app(RegistryIndex::class);

    expect($index->owner('prism-plus.capabilities'))->toBe($manager)
        ->and($index->declarationAt('prism-plus.capabilities')?->root)->toBe('prism-plus.capabilities');
});

it('holds the shipped capabilities as keys under that root', function () {
    $manager = app(PrismPlusManager::class);

    expect($manager->capabilities())->toBe(['rerank', 'video', 'models'])
        ->and($manager->has('rerank'))->toBeTrue()
        ->and($manager->has('nope'))->toBeFalse()
        ->and(array_map('strval', $manager->keys()))->toContain('prism-plus.capabilities.rerank');
});

it('round-trips a capability registry through the contract and the port vocabulary', function () {
    $manager = app(PrismPlusManager::class);

    $manager->register('search', new InvocableRegistry);

    expect($manager->resolve('search'))->toBeInstanceOf(InvocableRegistry::class)
        ->and($manager->capability('search'))->toBe($manager->resolve('search'))
        ->and($manager->capabilities())->toContain('search');
});

it('keeps the historical register($capability, $invocable) writing a provider one tier down', function () {
    $manager = app(PrismPlusManager::class);

    $manager->register('rerank', new LocalInvocable('fake', static fn (array $in): array => $in));

    expect($manager->capability('rerank')->has('fake'))->toBeTrue()
        ->and($manager->capability('rerank')->get('fake'))->toBeInstanceOf(Invocable::class)
        // …and the outer map still holds the REGISTRY at that key, not the invocable.
        ->and($manager->resolve('rerank'))->toBeInstanceOf(InvocableRegistry::class);

    $manager->forget('rerank', 'fake');

    expect($manager->capability('rerank')->has('fake'))->toBeFalse();
});

it('refuses a capability key with no entry rather than storing null', function () {
    expect(fn () => app(PrismPlusManager::class)->register('rerank'))
        ->toThrow(InvalidArgumentException::class);
});
