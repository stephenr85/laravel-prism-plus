<?php

declare(strict_types=1);

use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Rushing\PrismPlus\PrismPlus;

it('delegates every existing modality straight to Prism', function () {
    $prismPlus = app(PrismPlus::class);

    expect($prismPlus->text())->toBeInstanceOf(PendingTextRequest::class)
        ->and($prismPlus->structured())->toBeInstanceOf(PendingStructuredRequest::class)
        ->and($prismPlus->embeddings())->toBeInstanceOf(PendingEmbeddingRequest::class)
        ->and($prismPlus->image())->toBeInstanceOf(PendingImageRequest::class)
        ->and($prismPlus->audio())->toBeInstanceOf(PendingAudioRequest::class)
        ->and($prismPlus->moderation())->toBeInstanceOf(PendingModerationRequest::class);
});
