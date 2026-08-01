<?php

use Illuminate\Support\Facades\Http;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismPlus\Cassette\ModelListingCassetteSubject;
use Rushing\PrismPlus\Data\ModelDescriptor;
use Rushing\PrismPlus\Data\ModelListing;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\Serializers\ModelListingSerializer;

/*
 * prism-cassette is a hard dependency of prism-plus, so every resolved `models` (listing) driver is
 * recording-wrapped (ADR-0129 §5 taping follow-up). The wrapping is transparent: an un-scoped call
 * runs the wrapped vendor driver LIVE via passthrough. These lock the serializer's key stability +
 * hydrate round-trip and the transparent passthrough, mirroring the rerank cassette tests.
 */

it('registers a models serializer and arms the capability', function () {
    expect(class_exists(CassetteManager::class))->toBeTrue();

    $manager = app(CassetteManager::class);

    // Armed capabilities are directly tape-able (non-Prism); the serializer is registered.
    expect($manager->isArmed('models'))->toBeTrue();
})->skip(fn () => ! method_exists(CassetteManager::class, 'isArmed'), 'CassetteManager::isArmed unavailable');

it('keys a models cassette on the provider — stable, and distinct per provider', function () {
    $serializer = new ModelListingSerializer;

    $a = $serializer->key(new ModelListingCassetteSubject('anthropic'));
    $aAgain = $serializer->key(new ModelListingCassetteSubject('anthropic'));
    $groq = $serializer->key(new ModelListingCassetteSubject('groq'));

    expect($a)->toBe($aAgain)          // deterministic
        ->and($a)->not->toBe($groq)    // a Groq recording can never replay for Anthropic
        ->and($serializer->provider(new ModelListingCassetteSubject('anthropic')))->toBe('anthropic')
        ->and($serializer->model(new ModelListingCassetteSubject('anthropic')))->toBe('');
});

it('round-trips a listed ModelListing through serialize → hydrate', function () {
    $serializer = new ModelListingSerializer;

    $listing = ModelListing::listed('anthropic', [
        new ModelDescriptor(
            id: 'claude-opus-5',
            provider: 'anthropic',
            displayName: 'Claude Opus 5',
            contextWindow: 1000000,
            maxOutput: 128000,
            accepts: ['image'],
            raw: ['id' => 'claude-opus-5'],
        ),
    ]);

    $frame = $serializer->serialize(new ModelListingCassetteSubject('anthropic'), $listing, '2026-07-27T00:00:00+00:00');
    $hydrated = $serializer->hydrate($frame);

    expect($hydrated)->toBeInstanceOf(ModelListing::class)
        ->and($hydrated->supported)->toBeTrue()
        ->and($hydrated->ids())->toBe(['claude-opus-5'])
        ->and($hydrated->models[0]->contextWindow)->toBe(1000000)
        ->and($hydrated->models[0]->accepts)->toBe(['image']);
});

it('round-trips an unsupported ModelListing through serialize → hydrate', function () {
    $serializer = new ModelListingSerializer;

    $listing = ModelListing::unsupported('voyageai', 'no listing endpoint');

    $frame = $serializer->serialize(new ModelListingCassetteSubject('voyageai'), $listing, '2026-07-27T00:00:00+00:00');
    $hydrated = $serializer->hydrate($frame);

    expect($hydrated->supported)->toBeFalse()
        ->and($hydrated->reason)->toBe('no listing endpoint')
        ->and($hydrated->models)->toBe([]);
});

it('runs a models listing live via passthrough when no cassette is armed', function () {
    config()->set('prism.providers.anthropic', [
        'api_key' => 'test-anthropic-key',
        'url' => 'https://api.anthropic.com/v1',
        'version' => '2023-06-01',
    ]);

    Http::fake([
        'api.anthropic.com/v1/models' => Http::response([
            'data' => [['type' => 'model', 'id' => 'claude-opus-5', 'max_input_tokens' => 1000000]],
            'has_more' => false,
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('anthropic');

    expect($listing->supported)->toBeTrue()
        ->and($listing->ids())->toBe(['claude-opus-5']);

    Http::assertSentCount(1);
});
