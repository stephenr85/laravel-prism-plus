<?php

namespace Rushing\PrismPlus\Contracts;

/**
 * A voice-clone + voice-changer driver — the home for ElevenLabs' voice ops, which fal's
 * queue does not offer (fal has no RVC/seed-vc slug; ace-step audio-to-audio detunes). Three
 * capabilities:
 *
 *  - **cloneVoice** — instant voice cloning: reference recordings → a reusable `voice_id`.
 *  - **isolate** — pull a clean vocal off a recording (an alternative to fal demucs).
 *  - **convert** — speech-to-speech: re-sing a source vocal in a cloned `voice_id`, keeping the
 *    source's melody/phrasing and swapping only the timbre (the true voice-mimic path).
 *
 * Unlike the fal queue drivers, this is a SYNC, multipart, bytes-in/bytes-out API — clone
 * returns an id; isolate/convert return the produced audio bytes for the host to persist.
 */
interface VoiceCloneProvider
{
    /**
     * Clone a voice from reference recordings; returns the vendor `voice_id`.
     *
     * @param  array<int, string>  $audioPaths  local paths to the reference clips.
     */
    public function cloneVoice(string $name, array $audioPaths): string;

    /** Isolate a clean vocal from a recording; returns the isolated audio bytes. */
    public function isolate(string $audioPath): string;

    /**
     * Speech-to-speech: re-voice the source audio in `$voiceId`; returns the produced audio bytes.
     *
     * @param  array<string, mixed>  $settings  voice_settings (stability / similarity_boost / style /
     *                                          use_speaker_boost) — timbre-fidelity vs expressiveness.
     */
    public function convert(string $voiceId, string $audioPath, array $settings = [], string $modelId = 'eleven_multilingual_sts_v2'): string;
}
