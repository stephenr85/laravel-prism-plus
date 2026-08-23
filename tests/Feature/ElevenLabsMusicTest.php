<?php

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Providers\ElevenLabsMusicProvider;

/**
 * The synchronous ElevenLabs Music driver, exercised with a STUBBED API (no key, no spend).
 *
 * The header half is the point (voice-profile 27): the take's own id comes back ONLY as a
 * `song-id` response header, the body being raw audio, and this transport has no queue handle
 * either — so bytes-only is a take that was billed and can never be referred to again, for
 * conditioning or for inpainting.
 */
it('returns the produced bytes together with the response headers', function () {
    Http::fake(['*/music*' => Http::response('AUDIO_BYTES', 200, ['song-id' => 'sng_abc123'])]);

    $result = (new ElevenLabsMusicProvider('test-key'))->compose(['composition_plan' => ['chunks' => []]]);

    expect($result->bytes)->toBe('AUDIO_BYTES')
        ->and($result->header('song-id'))->toBe('sng_abc123')
        // Header case is not guaranteed by HTTP and this vendor has been seen to send both.
        ->and($result->header('Song-Id'))->toBe('sng_abc123')
        ->and($result->header('nothing-like-it'))->toBeNull();
});

it('posts the host-shaped body verbatim at the requested output format', function () {
    Http::fake(['*/music*' => Http::response('AUDIO_BYTES')]);

    (new ElevenLabsMusicProvider('test-key'))->compose(
        ['composition_plan' => ['chunks' => []], 'model_id' => 'music_v2', 'seed' => 7],
        'mp3_48000_192',
    );

    Http::assertSent(function ($request) {
        expect($request->data())->toBe(['composition_plan' => ['chunks' => []], 'model_id' => 'music_v2', 'seed' => 7]);

        return str_contains($request->url(), 'output_format=mp3_48000_192')
            && $request->hasHeader('xi-api-key', 'test-key');
    });
});
