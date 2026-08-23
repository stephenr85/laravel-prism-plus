<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\ValueObjects\Media\Image;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\PrismPlus\Contracts\VideoProvider;
use Rushing\PrismPlus\Data\VideoJob;
use Rushing\PrismPlus\Data\VideoJobStatus;
use Rushing\PrismPlus\Data\VideoRequest;
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\Providers\FalVideoProvider;

it('resolves the default video provider (fal) from Prism credentials', function () {
    expect(app(PrismPlus::class)->videoProvider())
        ->toBeInstanceOf(FalVideoProvider::class)
        ->toBeInstanceOf(VideoProvider::class);
});

it('throws on an unknown video provider', function () {
    app(PrismPlus::class)->videoProvider('nope');
})->throws(RegistryMiss::class);

it('submits non-blocking: maps the body, opts into the webhook, parses the handle', function () {
    Http::fake([
        'queue.fal.run/*' => Http::response([
            'request_id' => 'req-123',
            'status_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123/status',
            'response_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123',
            'cancel_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123/cancel',
            'queue_position' => 2,
        ]),
    ]);

    $job = app(PrismPlus::class)->video(new VideoRequest(
        prompt: 'a red kite over a cliff',
        seconds: 8,
        resolution: '1080p',
        webhookUrl: 'https://app.test/webhooks/fal',
    ));

    expect($job)->toBeInstanceOf(VideoJob::class)
        ->and($job->provider)->toBe('fal')
        ->and($job->jobId)->toBe('req-123')
        ->and($job->status)->toBe(VideoJobStatus::Queued)
        ->and($job->status->isTerminal())->toBeFalse()
        ->and($job->queuePosition)->toBe(2)
        ->and($job->statusUrl)->toContain('/requests/req-123/status');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), 'fal-ai/veo3?fal_webhook=https%3A%2F%2Fapp.test%2Fwebhooks%2Ffal')
            && $request['prompt'] === 'a red kite over a cliff'
            && $request['duration'] === 8
            && $request['resolution'] === '1080p'
            && $request->hasHeader('Authorization', 'Key test-fal-key');
    });
});

it('threads an image reference (image-to-video) and a per-call model override', function () {
    Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-x'])]);

    app(PrismPlus::class)->video(new VideoRequest(
        prompt: 'animate this',
        imageReference: Image::fromUrl('https://cdn.test/seed.png'),
        model: 'fal-ai/kling-video',
    ));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'fal-ai/kling-video')
            && $request['image_url'] === 'https://cdn.test/seed.png';
    });
});

it('polls status, mapping the vendor state and preserving the handle URLs', function () {
    Http::fake([
        '*/requests/req-123/status*' => Http::response([
            'status' => 'IN_PROGRESS',
            'request_id' => 'req-123',
            'queue_position' => 0,
        ]),
    ]);

    // A rehydrated handle (as the app's poll worker would reconstruct from a row).
    $job = VideoJob::fromArray([
        'provider' => 'fal',
        'job_id' => 'req-123',
        'status' => 'queued',
        'model' => 'fal-ai/veo3',
        'status_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123/status',
        'response_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123',
        'cancel_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123/cancel',
    ]);

    $polled = app(PrismPlus::class)->videoProvider('fal')->status($job);

    expect($polled->status)->toBe(VideoJobStatus::Processing)
        // URLs survive the refresh so the next poll still targets the right endpoints.
        ->and($polled->responseUrl)->toBe($job->responseUrl)
        ->and($polled->cancelUrl)->toBe($job->cancelUrl);
});

it('retrieves the completed clip URL from the `video` object', function () {
    Http::fake([
        '*/requests/req-123' => Http::response([
            'video' => [
                'url' => 'https://v3.fal.media/files/panda/clip.mp4',
                'content_type' => 'video/mp4',
                'duration' => 8.0,
                'width' => 1920,
                'height' => 1080,
            ],
        ]),
    ]);

    $job = VideoJob::fromArray([
        'provider' => 'fal', 'job_id' => 'req-123', 'status' => 'completed',
        'model' => 'fal-ai/veo3',
        'response_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123',
    ]);

    $result = app(PrismPlus::class)->videoProvider('fal')->retrieve($job);

    expect($result->url)->toBe('https://v3.fal.media/files/panda/clip.mp4')
        ->and($result->mimeType)->toBe('video/mp4')
        ->and($result->seconds)->toBe(8)
        ->and($result->resolution)->toBe('1920x1080');
});

it('treats an error body on retrieve as a failure', function () {
    Http::fake(['*/requests/req-err' => Http::response(['detail' => 'content policy violation'])]);

    $job = VideoJob::fromArray([
        'provider' => 'fal', 'job_id' => 'req-err', 'status' => 'completed',
        'model' => 'fal-ai/veo3',
        'response_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-err',
    ]);

    app(PrismPlus::class)->videoProvider('fal')->retrieve($job);
})->throws(RuntimeException::class);

it('cancels best-effort without throwing on a non-2xx', function () {
    Http::fake(['*/cancel' => Http::response(['status' => 'ALREADY_COMPLETED'], 400)]);

    $job = VideoJob::fromArray([
        'provider' => 'fal', 'job_id' => 'req-123', 'status' => 'processing',
        'model' => 'fal-ai/veo3',
        'cancel_url' => 'https://queue.fal.run/fal-ai/veo3/requests/req-123/cancel',
    ]);

    app(PrismPlus::class)->videoProvider('fal')->cancel($job);

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/cancel'));
});

it('round-trips the job handle through toArray/fromArray for persistence', function () {
    $job = new VideoJob(
        provider: 'fal',
        jobId: 'req-9',
        status: VideoJobStatus::Processing,
        model: 'fal-ai/veo3',
        statusUrl: 'https://queue.fal.run/x/requests/req-9/status',
        responseUrl: 'https://queue.fal.run/x/requests/req-9',
        cancelUrl: 'https://queue.fal.run/x/requests/req-9/cancel',
        queuePosition: 1,
    );

    $rehydrated = VideoJob::fromArray($job->toArray());

    expect($rehydrated->jobId)->toBe('req-9')
        ->and($rehydrated->status)->toBe(VideoJobStatus::Processing)
        ->and($rehydrated->responseUrl)->toBe($job->responseUrl);
});
