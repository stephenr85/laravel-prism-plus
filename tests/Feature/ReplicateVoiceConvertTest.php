<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Providers\ReplicateVoiceConvertProvider;
use Rushing\PrismPlus\Replicate\ReplicateClient;

/**
 * The Replicate singing voice-conversion driver (audiostud render-brief-and-voice) — STUBBED
 * Replicate predictions API (no token, no spend). Pins the create → poll → output run + FreeVC
 * source/reference shaping (the id-based version, Bearer auth, output-url extraction).
 */

it('runs a prediction to completion and returns the converted audio url', function () {
    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response([
            'id' => 'p1', 'status' => 'processing',
            'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1'],
        ]),
        'api.replicate.com/v1/predictions/p1' => Http::response([
            'id' => 'p1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.wav',
        ]),
    ]);

    $result = (new ReplicateVoiceConvertProvider(new ReplicateClient('r8-key', pollSeconds: 0)))
        ->transform('', ['source_audio' => 'https://media.test/faith.mp3', 'reference_audio' => 'https://media.test/me.mp3']);

    expect($result->provider)->toBe('replicate')
        ->and($result->url)->toBe('https://replicate.delivery/out.wav');

    // FreeVC default version + Bearer auth + source/reference in the body.
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/predictions')
        && $r['version'] === ReplicateVoiceConvertProvider::FREE_VC_VERSION
        && $r['input']['source_audio'] === 'https://media.test/faith.mp3'
        && $r->hasHeader('Authorization', 'Bearer r8-key'));
});

it('surfaces a failed prediction', function () {
    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response(['id' => 'p2', 'status' => 'failed', 'error' => 'boom', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p2']]),
    ]);

    expect(fn () => (new ReplicateVoiceConvertProvider(new ReplicateClient('r8-key', pollSeconds: 0)))
        ->transform('somever', ['source_audio' => 'a', 'reference_audio' => 'b']))
        ->toThrow(RuntimeException::class, 'failed');
});

it('surfaces a 422 input-schema rejection', function () {
    Http::fake(['api.replicate.com/v1/predictions' => Http::response('{"detail":"input required"}', 422)]);

    expect(fn () => (new ReplicateClient('r8-key', pollSeconds: 0))->run('v', []))
        ->toThrow(RuntimeException::class, 'rejected the input (422)');
});
