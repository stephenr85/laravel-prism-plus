<?php

use Illuminate\Support\Facades\Http;
use Rushing\PrismPlus\Providers\ElevenLabsVoiceProvider;

/**
 * The ElevenLabs voice-clone + voice-changer driver (audiostud render-brief-and-voice / 10),
 * exercised with a STUBBED ElevenLabs HTTP API (no key, no spend). Pins the request shaping +
 * response handling — clone returns a voice_id; isolate/convert return the produced audio bytes.
 */
function tmpAudio(string $bytes = 'RIFFfakeWAV'): string
{
    $p = sys_get_temp_dir().'/el-'.bin2hex(random_bytes(4)).'.mp3';
    file_put_contents($p, $bytes);

    return $p;
}

it('clones a voice from reference recordings and returns the voice_id', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(['voice_id' => 'vid-123', 'requires_verification' => false])]);

    $id = (new ElevenLabsVoiceProvider('test-key'))->cloneVoice('Stephen', [tmpAudio(), tmpAudio()]);

    expect($id)->toBe('vid-123');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'voices/add') && $r->hasHeader('xi-api-key', 'test-key'));
});

it('isolates a vocal and returns the produced audio bytes', function () {
    Http::fake(['*/audio-isolation' => Http::response('ISOLATED_BYTES')]);

    $bytes = (new ElevenLabsVoiceProvider('test-key'))->isolate(tmpAudio());

    expect($bytes)->toBe('ISOLATED_BYTES');
});

it('speech-to-speech re-voices a source into a cloned voice with tuned settings', function () {
    Http::fake(['*/speech-to-speech/*' => Http::response('REVOICED_BYTES')]);

    $bytes = (new ElevenLabsVoiceProvider('test-key'))->convert(
        'vid-123',
        tmpAudio(),
        ['stability' => 0.35, 'similarity_boost' => 0.95, 'use_speaker_boost' => true],
    );

    expect($bytes)->toBe('REVOICED_BYTES');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'speech-to-speech/vid-123'));
});
