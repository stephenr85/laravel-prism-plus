<?php

declare(strict_types=1);

use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Rushing\PrismPlus\Audio\PendingAudioRequest;
use Rushing\PrismPlus\PrismPlus;

it('delegates every existing raw modality straight to Prism', function () {
    $prismPlus = app(PrismPlus::class);

    expect($prismPlus->text())->toBeInstanceOf(PendingTextRequest::class)
        ->and($prismPlus->structured())->toBeInstanceOf(PendingStructuredRequest::class)
        ->and($prismPlus->embeddings())->toBeInstanceOf(PendingEmbeddingRequest::class)
        ->and($prismPlus->image())->toBeInstanceOf(PendingImageRequest::class)
        ->and($prismPlus->moderation())->toBeInstanceOf(PendingModerationRequest::class);
});

it('wraps audio in the PrismPlus normalization decorator, not a raw Prism request', function () {
    // Audio is the one existing modality PrismPlus does not raw-delegate: it normalizes voice +
    // outputFormat over Prism's drivers, so it returns the decorator.
    expect(app(PrismPlus::class)->audio())->toBeInstanceOf(PendingAudioRequest::class);
});
