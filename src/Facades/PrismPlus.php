<?php

namespace Rushing\PrismPlus\Facades;

use Illuminate\Support\Facades\Facade;
use Rushing\PrismPlus\PrismPlus as PrismPlusService;

/**
 * Ergonomic front door to the prism-plus invocation surface.
 *
 * @method static \Prism\Prism\Text\PendingRequest text()
 * @method static \Prism\Prism\Structured\PendingRequest structured()
 * @method static \Prism\Prism\Embeddings\PendingRequest embeddings()
 * @method static \Prism\Prism\Images\PendingRequest image()
 * @method static \Rushing\PrismPlus\Audio\PendingAudioRequest audio()
 * @method static \Prism\Prism\Moderation\PendingRequest moderation()
 * @method static \Rushing\PrismPlus\Data\RerankResponse rerank(\Rushing\PrismPlus\Data\RerankRequest $request, ?string $provider = null)
 * @method static \Rushing\PrismPlus\Contracts\RerankProvider rerankProvider(?string $provider = null)
 * @method static \Rushing\PrismPlus\Data\VideoJob video(\Rushing\PrismPlus\Data\VideoRequest $request, ?string $provider = null)
 * @method static \Rushing\PrismPlus\Contracts\VideoProvider videoProvider(?string $provider = null, array $providerConfig = [])
 * @method static \Rushing\PrismPlus\Data\ModelListing models(string $provider)
 * @method static array modelProviders()
 *
 * @see PrismPlusService
 */
class PrismPlus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PrismPlusService::class;
    }
}
