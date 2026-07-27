<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Data\ModelListing;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\PrismPlusManager;

it('lists Anthropic models with the rich shape mapped to the normalized descriptor', function () {
    config()->set('prism.providers.anthropic', [
        'api_key' => 'test-anthropic-key',
        'url' => 'https://api.anthropic.com/v1',
        'version' => '2023-06-01',
    ]);

    Http::fake([
        'api.anthropic.com/v1/models' => Http::response([
            'data' => [
                [
                    'type' => 'model',
                    'id' => 'claude-opus-4-8',
                    'display_name' => 'Claude Opus 4.8',
                    'max_input_tokens' => 1000000,
                    'max_tokens' => 128000,
                    'capabilities' => ['image_input' => ['supported' => true]],
                ],
            ],
            'has_more' => false,
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('anthropic');

    expect($listing)->toBeInstanceOf(ModelListing::class)
        ->and($listing->supported)->toBeTrue()
        ->and($listing->ids())->toBe(['claude-opus-4-8']);

    expect($listing->models[0]->displayName)->toBe('Claude Opus 4.8')
        ->and($listing->models[0]->contextWindow)->toBe(1000000)
        ->and($listing->models[0]->maxOutput)->toBe(128000)
        ->and($listing->models[0]->accepts)->toBe(['image']);
});

it('lists an OpenAI-shape provider (groq) through the generic wheel adapter', function () {
    config()->set('prism.providers.groq', [
        'api_key' => 'test-groq-key',
        'url' => 'https://api.groq.com/openai/v1',
    ]);

    Http::fake([
        'api.groq.com/openai/v1/models' => Http::response([
            'data' => [
                ['id' => 'llama-3.3-70b-versatile', 'owned_by' => 'Meta', 'context_window' => 131072],
            ],
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('groq');

    expect($listing->supported)->toBeTrue()
        ->and($listing->ids())->toBe(['llama-3.3-70b-versatile'])
        ->and($listing->models[0]->provider)->toBe('groq')
        ->and($listing->models[0]->contextWindow)->toBe(131072);
});

it('returns an unsupported listing for a provider with no /models endpoint (voyageai)', function () {
    $listing = app(PrismPlus::class)->models('voyageai');

    expect($listing->supported)->toBeFalse()
        ->and($listing->models)->toBe([])
        ->and($listing->reason)->toContain('VoyageAI');
});

it('returns an unsupported listing for perplexity', function () {
    $listing = app(PrismPlus::class)->models('perplexity');

    expect($listing->supported)->toBeFalse()
        ->and($listing->reason)->toContain('Perplexity');
});

it('exposes the whole Prism OTB provider roster', function () {
    expect(app(PrismPlus::class)->modelProviders())
        ->toBe(PrismPlusManager::MODEL_PROVIDERS)
        ->toContain('openai', 'anthropic', 'gemini', 'ollama', 'elevenlabs', 'openrouter', 'voyageai', 'perplexity');
});
