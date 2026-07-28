<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Data\MusicJob;
use Rushing\PrismPlus\Data\MusicJobStatus;
use Rushing\PrismPlus\Data\MusicRequest;
use Rushing\PrismPlus\Providers\FalAudioTransformProvider;
use Rushing\PrismPlus\Providers\FalMusicProvider;

/**
 * The prism-plus MUSIC + audio-transform drivers over the shared fal-queue core (ticket 08):
 * generation (submit → poll → retrieve) and voice transforms (demucs / RVC) exercised with a
 * STUBBED fal queue (no key, no spend). Vendor body args are shaped by the host adapter and
 * ride through opaque, so these tests pin the transport + handle parsing, not the vendor schema.
 */
it('submits a music generation non-blocking and parses the job handle', function () {
    Http::fake([
        'queue.fal.run/*' => Http::response([
            'request_id' => 'req-9',
            'status_url' => 'https://queue.fal.run/fal-ai/ace-step/requests/req-9/status',
            'response_url' => 'https://queue.fal.run/fal-ai/ace-step/requests/req-9',
            'queue_position' => 1,
        ]),
    ]);

    $job = (new FalMusicProvider('test-key'))->generate(new MusicRequest(
        input: ['tags' => 'indie folk', 'lyrics' => '[verse]\nhello'],
        model: 'fal-ai/ace-step',
    ));

    expect($job)->toBeInstanceOf(MusicJob::class)
        ->and($job->provider)->toBe('fal')
        ->and($job->jobId)->toBe('req-9')
        ->and($job->status)->toBe(MusicJobStatus::Queued)
        ->and($job->status->isTerminal())->toBeFalse();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'fal-ai/ace-step')
        && $request['tags'] === 'indie folk'
        && $request->hasHeader('Authorization', 'Key test-key'));
});

it('surfaces a 422 schema rejection with the vendor body (no generation billed)', function () {
    Http::fake(['queue.fal.run/*' => Http::response('{"detail":"lyrics: field required"}', 422)]);

    expect(fn () => (new FalMusicProvider('test-key'))->generate(new MusicRequest(model: 'fal-ai/ace-step')))
        ->toThrow(RuntimeException::class, 'rejected the input (422)');
});

it('retrieves a completed music job into a result with the audio url + duration', function () {
    Http::fake([
        'queue.fal.run/*/status*' => Http::response(['status' => 'COMPLETED']),
        'queue.fal.run/*/requests/*' => Http::response(['audio' => ['url' => 'https://fal.media/song.wav', 'duration' => 128.5]]),
    ]);

    $provider = new FalMusicProvider('test-key');
    $job = new MusicJob(provider: 'fal', jobId: 'req-9', status: MusicJobStatus::Processing, model: 'fal-ai/ace-step',
        statusUrl: 'https://queue.fal.run/fal-ai/ace-step/requests/req-9/status',
        responseUrl: 'https://queue.fal.run/fal-ai/ace-step/requests/req-9');

    expect($provider->status($job)->status)->toBe(MusicJobStatus::Completed);

    $result = $provider->retrieve($job);
    expect($result->url)->toBe('https://fal.media/song.wav')
        ->and($result->seconds)->toBe(128.5);
});

it('runs an audio transform (demucs) synchronously and returns the isolated-vocal url', function () {
    Http::fake([
        'queue.fal.run/*/status*' => Http::response(['status' => 'COMPLETED']),
        'queue.fal.run/*/requests/*' => Http::response(['vocals' => ['url' => 'https://fal.media/vocals.wav']]),
        'queue.fal.run/fal-ai/*' => Http::response(['request_id' => 'r', 'status_url' => 'https://queue.fal.run/fal-ai/demucs/requests/r/status', 'response_url' => 'https://queue.fal.run/fal-ai/demucs/requests/r']),
    ]);

    $result = (new FalAudioTransformProvider('test-key'))->transform('fal-ai/demucs', ['audio_url' => 'https://media.test/raw.m4a']);

    expect($result->url)->toBe('https://fal.media/vocals.wav')
        ->and($result->provider)->toBe('fal');
});
