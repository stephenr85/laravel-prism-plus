<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rerank
    |--------------------------------------------------------------------------
    |
    | Rerank is a genuinely-new modality Prism has no slot for. PrismPlus owns
    | it. Provider *credentials* are NOT configured here — they are read from
    | the same `config('prism.providers.*')` blocks Prism already uses, so an
    | operator configures a key once. This file only carries what's specific to
    | the new modality: which provider is the default, and each provider's
    | default rerank model.
    |
    */

    'rerank' => [

        'default_provider' => env('PRISM_PLUS_RERANK_PROVIDER', 'voyageai'),

        'providers' => [

            'voyageai' => [
                // Current generation is 2.5 (rerank-2 is the older family).
                'model' => env('VOYAGEAI_RERANK_MODEL', 'rerank-2.5'),
            ],

            'cohere' => [
                // rerank-v3.5 is deprecated (2026-07); v4.0 scores are not
                // comparable to v3.5 — retune any hard thresholds on upgrade.
                'model' => env('COHERE_RERANK_MODEL', 'rerank-v4.0-pro'),
            ],

        ],

    ],

];
