<?php

declare(strict_types=1);

namespace Rushing\PrismPlus\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PrismPlus\PrismPlusServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            PrismPlusServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // PrismPlus reads credentials from Prism's own provider blocks.
        $app['config']->set('prism.providers.voyageai', [
            'api_key' => 'test-voyage-key',
            'url' => 'https://api.voyageai.com/v1',
        ]);

        $app['config']->set('prism.providers.cohere', [
            'api_key' => 'test-cohere-key',
            'url' => 'https://api.cohere.com/v2',
        ]);
    }
}
