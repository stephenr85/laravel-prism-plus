<?php

declare(strict_types=1);

use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\PrismPlus\Contracts\RerankProvider;
use Rushing\PrismPlus\Data\RerankRequest;
use Rushing\PrismPlus\Data\RerankResponse;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\PrismPlusManager;
use Rushing\PrismPlus\Providers\VoyageRerankProvider;

/**
 * The registry-of-registries behavior seam (tickets 03/04): drive the typed
 * `PrismPlus::rerank()` accessor over provider invocables registered in the `rerank`
 * capability registry, never asserting on the internal map shape. A `LocalInvocable` fake stands in
 * for a driver so the seam is exercised without any HTTP.
 */

/**
 * A fake rerank provider as a plain array→array LocalInvocable — the shape a host registers.
 */
function fakeRerankInvocable(string $name, string $provider): LocalInvocable
{
    return new LocalInvocable($name, static fn (array $input): array => [
        'provider' => $provider,
        'model' => 'fake-model',
        'results' => [
            ['index' => 1, 'score' => 0.90, 'document' => null],
            ['index' => 0, 'score' => 0.10, 'document' => null],
        ],
        'usage' => ['units' => 1],
    ]);
}

it('resolves a registered LocalInvocable fake through the typed accessor', function () {
    app(PrismPlusManager::class)->register('rerank', fakeRerankInvocable('fake', 'fake'));

    $response = app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'q', documents: ['a', 'b']),
        provider: 'fake',
    );

    expect($response)->toBeInstanceOf(RerankResponse::class)
        ->and($response->provider)->toBe('fake')
        ->and($response->model)->toBe('fake-model')
        ->and($response->order())->toBe([1, 0])
        ->and($response->usage)->toBe(['units' => 1]);
});

it('picks the default provider from config when none is named', function () {
    app(PrismPlusManager::class)->register('rerank', fakeRerankInvocable('default-fake', 'default-fake'));
    config()->set('prism-plus.defaults.rerank', 'default-fake');

    $response = app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']));

    expect($response->provider)->toBe('default-fake');
});

it('an explicit provider argument overrides the config default', function () {
    config()->set('prism-plus.defaults.rerank', 'voyageai');
    app(PrismPlusManager::class)->register('rerank', fakeRerankInvocable('override', 'override'));

    $response = app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'q', documents: ['a']),
        provider: 'override',
    );

    expect($response->provider)->toBe('override');
});

it('fails loud through Data hydration when a provider returns a malformed result', function () {
    // A conforming shape is `{provider, model, results, usage}`; this fake omits the required fields.
    app(PrismPlusManager::class)->register('rerank', new LocalInvocable(
        'broken',
        static fn (array $input): array => ['garbage' => true],
    ));

    app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'broken');
})->throws(Exception::class);

it('fails loud on an unknown provider rather than a silent empty result', function () {
    app(PrismPlus::class)->rerank(new RerankRequest(query: 'q', documents: ['a']), provider: 'nope');
})->throws(InvalidArgumentException::class);

it('the deprecated rerankProvider() shim resolves the built-in driver through the registry', function () {
    // Migration safety: the create*/method_exists dispatch is deleted; the shim must still hand back
    // a typed driver resolved from the registry (covers the removed public surface).
    expect(app(PrismPlusManager::class)->rerankProvider('voyageai'))
        ->toBeInstanceOf(VoyageRerankProvider::class)
        ->toBeInstanceOf(RerankProvider::class);
});

it('the deprecated rerankProvider() shim adapts a host-registered invocable back to a typed driver', function () {
    app(PrismPlusManager::class)->register('rerank', fakeRerankInvocable('adapted', 'adapted'));

    $driver = app(PrismPlusManager::class)->rerankProvider('adapted');

    expect($driver)->toBeInstanceOf(RerankProvider::class);

    $response = $driver->rerank(new RerankRequest(query: 'q', documents: ['a', 'b']));

    expect($response->provider)->toBe('adapted')
        ->and($response->order())->toBe([1, 0]);
});

it('extend() registers a custom provider into the registry, reachable via the typed accessor', function () {
    app(PrismPlusManager::class)->extend('acme', fn ($app, $config): RerankProvider => new class implements RerankProvider
    {
        public function rerank(RerankRequest $request): RerankResponse
        {
            return new RerankResponse(provider: 'acme', model: 'acme-1', results: [], usage: []);
        }
    });

    $response = app(PrismPlus::class)->rerank(
        new RerankRequest(query: 'q', documents: ['a']),
        provider: 'acme',
    );

    expect($response->provider)->toBe('acme')
        ->and($response->model)->toBe('acme-1');
});
