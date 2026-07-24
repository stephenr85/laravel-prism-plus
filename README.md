# rushing/laravel-prism-plus

**Prism, plus the capabilities Prism has no slot for.**

[Prism](https://github.com/prism-php/prism) is the Laravel LLM toolkit — it owns text, structured
output, embeddings, images, audio, and moderation. But some capabilities don't fit Prism's fixed
modality slots: **rerank** (Voyage, Cohere) and **async video generation** (fal.ai) among them.

PrismPlus wraps Prism and **delegates every modality Prism already owns straight through untouched**,
then adds the missing capabilities as a small, uniform **registry of registries** — string-keyed, so
adding a provider is one `register()` call and adding a whole new capability is a new key. It reuses
Prism's own provider credentials (configure a key once), ships typed `spatie/laravel-data` value
objects, and bundles fixture recording so a real API call can be replayed forever, offline.

---

## Installation

```bash
composer require rushing/laravel-prism-plus
```

This pulls in Prism, [`rushing/laravel-popcorn`](https://github.com/stephenr85/laravel-popcorn) (the
registry kernel), [`rushing/prism-cassette`](https://github.com/stephenr85/prism-cassette) (record/
replay), and `spatie/laravel-data`. The service provider auto-registers.

Optionally publish the config:

```bash
php artisan vendor:publish --tag=prism-plus-config
```

### Credentials — configured once, for both Prism and PrismPlus

PrismPlus reads credentials from **Prism's own** `config('prism.providers.*')` blocks. A Voyage key
you already set for Prism embeddings is reused verbatim for PrismPlus rerank. fal.ai isn't a native
Prism provider, so just add a block for it under the same key:

```php
// config/prism.php
'providers' => [
    'voyageai' => ['api_key' => env('VOYAGEAI_API_KEY'), 'url' => 'https://api.voyageai.com/v1'],
    'cohere'   => ['api_key' => env('COHERE_API_KEY'),   'url' => 'https://api.cohere.com/v2'],
    'fal'      => ['api_key' => env('FAL_API_KEY'),      'url' => 'https://queue.fal.run'],
],
```

---

## Quickstart: rerank

Score a set of documents by relevance to a query and get them back best-first:

```php
use Rushing\PrismPlus\PrismPlus;
use Rushing\PrismPlus\Data\RerankRequest;

$response = app(PrismPlus::class)->rerank(new RerankRequest(
    query: 'What is the capital of France?',
    documents: [
        'Berlin is the capital of Germany.',
        'Paris is the capital of France.',
        'The Seine runs through Paris.',
    ],
    topK: 2,
));

$response->order();      // [1, 2] — original indices, best-first
$response->results[0];   // RerankResult { index: 1, score: 0.98, document: 'Paris is …' }
$response->provider;     // 'voyageai'
$response->model;        // 'rerank-2.5'
```

The provider defaults to `config('prism-plus.defaults.rerank')` (Voyage). Force a specific vendor by
passing its name — the second argument is the **only** override you need:

```php
app(PrismPlus::class)->rerank($request, 'cohere');
```

`rerank()` is the single, drift-safe boundary where the request and response types are paired: a
malformed payload fails loud through Data hydration — never a silent empty result.

---

## Quickstart: async video

Video generation is fundamentally async (submit → poll/webhook → retrieve). `video()` submits and
returns a **serializable handle immediately** — it never blocks on completion:

```php
use Rushing\PrismPlus\Data\VideoRequest;

$job = app(PrismPlus::class)->video(new VideoRequest(
    prompt: 'a red kite over a cliff at sunset',
    seconds: 8,
    resolution: '1080p',
    webhookUrl: 'https://app.test/webhooks/fal',   // optional — poll instead if omitted
));

$job->status;      // VideoJobStatus::Queued
$job->jobId;       // 'req-123'
$job->toArray();   // persist this to a `video_jobs` row — it's the unit of continuation
```

The handle carries the vendor's status/result/cancel URLs, so a queued worker rehydrates it and drives
the job to completion off the typed driver:

```php
use Rushing\PrismPlus\Data\VideoJob;

$job = VideoJob::fromArray($row->handle);
$driver = app(PrismPlus::class)->videoProvider('fal');

$job = $driver->status($job);                 // re-poll; VideoJobStatus::Processing → Completed
if ($job->status === VideoJobStatus::Completed) {
    $result = $driver->retrieve($job);        // VideoResult { url, mimeType, seconds, resolution }
    // download $result->url to your own servable disk…
}
$driver->cancel($job);                        // best-effort where the vendor supports it
```

---

## Delegated modalities

Everything Prism already owns is a **raw delegate** — same API, same objects, zero wrapping:

```php
$prism = app(PrismPlus::class);
$prism->text()->using('openai', 'gpt-4o-mini')->withPrompt('Hi')->asText();
$prism->structured();  $prism->embeddings();  $prism->image();  $prism->moderation();
```

The one exception is `audio()`, which returns a thin decorator that normalizes the provider-shaped
`voice` and `outputFormat` warts over Prism's audio drivers (`->withVoice()`, `->withOutputFormat()`).

**The rule that decides what's a capability vs a delegate:** PrismPlus capabilities are *array-boundary
invocables* (a single request → response over popcorn's `array in / array out` seam — which is what
lets a local driver, an MCP tool, or a webhook answer them interchangeably). Anything that needs a
**stream** (streaming text with tool-calls) stays a raw Prism delegate and never enters the registry.

---

## The mental model: a registry of registries

```
capability (string) ──► InvocableRegistry ──► provider (string) ──► Invocable
   "rerank"                                     "voyageai" / "cohere"
   "video"                                      "fal"
   "classify"  ◄── a new capability is just a new key; nothing is hand-copied
```

Two levels, both plain string-keyed maps. There is no reflection-string-to-method dispatch and no
per-capability copied estate. Each provider is a popcorn `Invocable` — deliberately array→array — with
the typed vendor driver sitting *behind* the boundary. The typed accessors (`rerank()`, `video()`) are
the ergonomic front door; the array boundary is what keeps a capability transport-agnostic.

### Add a provider — from any host's `boot()`, no package edit

```php
use Rushing\PrismPlus\PrismPlusManager;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Invocables\RemoteInvocable;
use Rushing\Popcorn\Binding;

$manager = app(PrismPlusManager::class);

// A local PHP driver…
$manager->register('rerank', new LocalInvocable('jina', fn (array $in) => /* … */ []));

// …or a tenant's own remote handler — an MCP tool or a webhook, no PHP driver shipped by you:
$manager->register('rerank', new RemoteInvocable('acme', Binding::Webhook, $httpTransport));
```

Re-registering under the same capability+provider key **overrides** it (swap a default for a
tenant-specific binding without callers changing). `forget('rerank', 'acme')` tears a tenant-scoped
registration down on tenant switch, so nothing bleeds across tenants on a shared worker.

### Add a capability

Registering the first provider under a brand-new key *is* adding a capability — no new manager
machinery. Pair it with its `Data` request/response VOs and one typed accessor and you're done.

---

## Configuration

```php
// config/prism-plus.php
return [
    // Default provider per capability — what the typed accessor picks when you name none.
    'defaults' => [
        'rerank' => env('PRISM_PLUS_RERANK_PROVIDER', 'voyageai'),
        'video'  => env('PRISM_PLUS_VIDEO_PROVIDER', 'fal'),
    ],

    'rerank' => [
        'providers' => [
            'voyageai' => ['model' => env('VOYAGEAI_RERANK_MODEL', 'rerank-2.5')],
            'cohere'   => ['model' => env('COHERE_RERANK_MODEL', 'rerank-v4.0-pro')],
        ],
    ],

    'video' => [
        'providers' => [
            'fal' => ['model' => env('FAL_VIDEO_MODEL', 'fal-ai/veo3')],
        ],
    ],
];
```

---

## Typed value objects (+ TypeScript)

The request/response VOs live in `Rushing\PrismPlus\Data` as `spatie/laravel-data` classes:
`RerankRequest`, `RerankResponse`, `RerankResult`, `VideoRequest`, `VideoJob`, `VideoResult`, and the
`VideoJobStatus` enum. They carry no `final`/`readonly` (extension-friendly) and are annotated
`#[TypeScript]`, so `spatie/laravel-typescript-transformer` emits frontend types for free (point its
scan at `vendor/rushing/laravel-prism-plus/src/Data`).

---

## Fixture recording (bundled)

`rushing/prism-cassette` is a hard dependency — fixture recording is a first-class feature, not an
add-on. Rerank calls tape automatically: make one real call in `record` mode, commit the cassette,
and every run after replays it offline with no key.

```php
use Rushing\PrismCassette\Facades\Cassette;

$response = Cassette::group('rerank')->record()->play(
    fn () => app(PrismPlus::class)->rerank($request),   // records the live call
);

$replayed = Cassette::group('rerank')->replay()->play(
    fn () => app(PrismPlus::class)->rerank($request),   // replays it; a miss fails loud
);
```

Recording works whether you call `rerank()` or hold the driver via `rerankProvider()` — no bypass.

---

## Conformance kit

Once anyone can register a provider, a shared behavioral contract proves it honors the capability's
*semantics* (not just its shape). Extend the shipped abstract test, point it at your provider, and it
asserts: at most `topK` results, strictly descending scores, in-range and unique indices, documents
echoed when requested — the same bar Voyage and Cohere are held to:

```php
use Rushing\PrismPlus\Testing\RerankProviderConformanceTest;

final class JinaRerankConformanceTest extends RerankProviderConformanceTest
{
    protected function providerName(): string { return 'jina'; }
    // arm the record leg (Http::fake for a token-free lane, or a live key for the keyed CI lane)
}
```

The default lane runs against cassette record/replay — token-free, replay-miss-fails-loud. See
`src/Testing/AssertsRerankConformance.php` for the reusable assertion trait.

---

## Backward compatibility

The pre-registry entry points (`rerankProvider()`, `videoProvider()`, `extend()`, `extendVideo()`)
survive as thin `@deprecated` shims that delegate into the registry — existing callers don't break on
upgrade. Prefer the typed `rerank()` / `video()` accessors and `register()` for new code.

---

## Licence

MIT
