<?php

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

it('reads mistral\'s max_context_length context-window key', function () {
    config()->set('prism.providers.mistral', [
        'api_key' => 'test-mistral-key',
        'url' => 'https://api.mistral.ai/v1',
    ]);

    // Mistral's /v1/models spells the context window `max_context_length`, not `context_window`.
    Http::fake([
        'api.mistral.ai/v1/models' => Http::response([
            'data' => [
                ['id' => 'mistral-large-latest', 'max_context_length' => 131072, 'max_completion_tokens' => 8192],
            ],
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('mistral');

    expect($listing->models[0]->contextWindow)->toBe(131072)
        ->and($listing->models[0]->maxOutput)->toBe(8192);
});

it('resolves z (z.ai) through the OpenAI-compat wheel', function () {
    config()->set('prism.providers.z', [
        'api_key' => 'test-z-key',
        'url' => 'https://api.z.ai/api/paas/v4',
    ]);

    Http::fake([
        'api.z.ai/api/paas/v4/models' => Http::response([
            'data' => [
                ['id' => 'glm-4.6', 'owned_by' => 'zhipuai', 'context_length' => 200000],
            ],
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('z');

    expect($listing->supported)->toBeTrue()
        ->and($listing->ids())->toBe(['glm-4.6'])
        ->and($listing->models[0]->provider)->toBe('z')
        ->and($listing->models[0]->contextWindow)->toBe(200000);
});

it('lists OpenAI\'s own bare /models shape (no context window advertised)', function () {
    config()->set('prism.providers.openai', [
        'api_key' => 'test-openai-key',
        'url' => 'https://api.openai.com/v1',
    ]);

    // Live reconciliation (2026-07): OpenAI's own listing carries only id/object/created/owned_by —
    // no context-window key. The shape adapter's key-fallback is for the OTHER OpenAI-shape providers
    // (groq/mistral/z); for openai itself contextWindow is legitimately null.
    Http::fake([
        'api.openai.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'created' => 1715367049, 'owned_by' => 'system'],
            ],
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('openai');

    expect($listing->supported)->toBeTrue()
        ->and($listing->ids())->toBe(['gpt-4o'])
        ->and($listing->models[0]->contextWindow)->toBeNull()
        ->and($listing->models[0]->maxOutput)->toBeNull();
});

it('filters Gemini\'s collection to content-generating models by supportedGenerationMethods', function () {
    config()->set('prism.providers.gemini', [
        'api_key' => 'test-gemini-key',
        'url' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models' => Http::response([
            'models' => [
                // Kept: text/multimodal.
                ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'inputTokenLimit' => 1048576, 'outputTokenLimit' => 65536, 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
                // Kept: embeddings.
                ['name' => 'models/text-embedding-004', 'displayName' => 'Text Embedding 004', 'supportedGenerationMethods' => ['embedContent']],
                // Dropped: tuning/legacy — generates no content.
                ['name' => 'models/aqa', 'displayName' => 'Model that performs Attributed Question Answering', 'supportedGenerationMethods' => ['generateAnswer']],
                // Kept: unknown shape with no methods (fail-open).
                ['name' => 'models/gemini-future', 'displayName' => 'Future'],
            ],
        ]),
    ]);

    $listing = app(PrismPlus::class)->models('gemini');

    expect($listing->supported)->toBeTrue()
        ->and($listing->ids())->toBe(['gemini-2.5-flash', 'text-embedding-004', 'gemini-future'])
        ->and($listing->models[0]->contextWindow)->toBe(1048576);
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
