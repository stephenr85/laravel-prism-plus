<?php

declare(strict_types=1);

use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Rushing\PrismPlus\PrismPlus;

it('passes voice straight through — Prism already normalizes it per provider', function () {
    $fake = Prism::fake();

    app(PrismPlus::class)->audio()
        ->using('openai', 'tts-1')
        ->withInput('hello world')
        ->withVoice('nova')
        ->asAudio();

    $fake->assertRequest(function (array $recorded) {
        expect($recorded[0])->toBeInstanceOf(TextToSpeechRequest::class)
            ->and($recorded[0]->voice())->toBe('nova')
            ->and($recorded[0]->input())->toBe('hello world');
    });
});

it('maps a normalized outputFormat to OpenAI response_format enum', function () {
    $fake = Prism::fake();

    app(PrismPlus::class)->audio()
        ->using('openai', 'tts-1')
        ->withInput('hi')
        ->withVoice('alloy')
        ->withOutputFormat('opus')
        ->asAudio();

    $fake->assertRequest(function (array $recorded) {
        expect($recorded[0]->providerOptions('response_format'))->toBe('opus')
            ->and($recorded[0]->providerOptions('output_format'))->toBeNull();
    });
});

it('maps a normalized outputFormat to an ElevenLabs codec string', function () {
    $fake = Prism::fake();

    app(PrismPlus::class)->audio()
        ->using('elevenlabs', 'eleven_multilingual_v2')
        ->withInput('hi')
        ->withVoice('21m00Tcm4TlvDq8ikWAM')
        ->withOutputFormat('mp3')
        ->asAudio();

    $fake->assertRequest(function (array $recorded) {
        // ElevenLabs takes a codec string, not the simple enum.
        expect($recorded[0]->providerOptions('output_format'))->toBe('mp3_44100_128')
            ->and($recorded[0]->providerOptions('response_format'))->toBeNull()
            // voice_id still rides through withVoice() (the path-param wart lives in the driver).
            ->and($recorded[0]->voice())->toBe('21m00Tcm4TlvDq8ikWAM');
    });
});

it('trusts a full ElevenLabs codec string and passes it through', function () {
    $fake = Prism::fake();

    app(PrismPlus::class)->audio()
        ->using('elevenlabs', 'eleven_multilingual_v2')
        ->withInput('hi')
        ->withVoice('voice')
        ->withOutputFormat('mp3_22050_32')
        ->asAudio();

    $fake->assertRequest(fn (array $r) => expect($r[0]->providerOptions('output_format'))->toBe('mp3_22050_32'));
});

it('merges the mapped format over caller-supplied provider options', function () {
    $fake = Prism::fake();

    app(PrismPlus::class)->audio()
        ->using('openai', 'tts-1')
        ->withInput('hi')
        ->withVoice('alloy')
        ->withProviderOptions(['speed' => 1.25])
        ->withOutputFormat('flac')
        ->asAudio();

    $fake->assertRequest(function (array $recorded) {
        expect($recorded[0]->providerOptions('speed'))->toBe(1.25)
            ->and($recorded[0]->providerOptions('response_format'))->toBe('flac');
    });
});

it('transcribes audio input through the STT path (no output-format normalization)', function () {
    $fake = Prism::fake();

    $response = app(PrismPlus::class)->audio()
        ->using('openai', 'whisper-1')
        ->withInput(Audio::fromBase64(base64_encode('fake-bytes'), 'audio/mpeg'))
        ->asText();

    expect($response->text)->toBe('fake transcribed text');

    $fake->assertRequest(fn (array $r) => expect($r[0])->toBeInstanceOf(SpeechToTextRequest::class));
});
