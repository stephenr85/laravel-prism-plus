<?php

namespace Rushing\PrismPlus\Data;

/**
 * What a synchronous music compose call returns: the produced audio bytes AND the vendor's
 * response headers.
 *
 * The headers are here because the take's own vendor id arrives NOWHERE ELSE. ElevenLabs
 * returns raw audio as the body and the id as a `song-id` response header; that id is the
 * input to conditioning (`conditioning_ref.song_id`) and to inpainting, and on the
 * synchronous path there is no queue handle either — so it is the ONLY identity the
 * recording host can carry for a take it has already been billed for. A bytes-only return
 * type drops it on the floor.
 *
 * Deliberately NOT a spatie Data with a TypeScript attribute: raw audio bytes are not a wire
 * shape, and this never crosses to a client.
 */
class MusicComposeResult
{
    /**
     * @param  string  $bytes  the produced audio.
     * @param  array<string, array<int, string>|string>  $headers  the vendor's response headers, verbatim.
     */
    public function __construct(
        public readonly string $bytes,
        public readonly array $headers = [],
    ) {}

    /**
     * One header value, matched case-insensitively — HTTP header case is not guaranteed and
     * ElevenLabs has been seen to send both `song-id` and `Song-Id`.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
