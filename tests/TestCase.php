<?php

namespace Rushing\PrismPlus\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\PrismCassette\CassetteServiceProvider;
use Rushing\PrismPlus\PrismPlusServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelDataServiceProvider::class,
            // Testbench does not auto-discover, so requiring rushing/laravel-popcorn is NOT enough:
            // without this the container hands out a FRESH RegistryIndex per make(), every describe()
            // lands on a throwaway and the suite stays green over an empty index (registry-kernel
            // ticket 27 D3). RegistryIndexSharingTest is the tripwire for exactly that.
            PopcornServiceProvider::class,
            // prism-cassette is a hard dependency now; its provider binds the CassetteManager singleton
            // that PrismPlus's rerank serializer registers onto and RecordingRerankProvider tapes through.
            CassetteServiceProvider::class,
            PrismPlusServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Cassette forces 'replay' under runningUnitTests() unless the STORE sets its own mode; pin the
        // store to passthrough so an un-scoped rerank runs the driver live (Http::fake in the
        // normalization tests). The record/replay tests opt in with explicit group()->record()/replay()
        // scopes, whose frame overrides the store mode.
        $app['config']->set('cassette.stores.file.mode', 'passthrough');
        $app['config']->set('cassette.stores.file.path', sys_get_temp_dir().'/prism-plus-test-cassettes');

        // PrismPlus reads credentials from Prism's own provider blocks.
        $app['config']->set('prism.providers.voyageai', [
            'api_key' => 'test-voyage-key',
            'url' => 'https://api.voyageai.com/v1',
        ]);

        $app['config']->set('prism.providers.cohere', [
            'api_key' => 'test-cohere-key',
            'url' => 'https://api.cohere.com/v2',
        ]);

        // fal.ai is not a native Prism provider; its async video driver lives in
        // PrismPlus and reads this same credential block.
        $app['config']->set('prism.providers.fal', [
            'api_key' => 'test-fal-key',
            'url' => 'https://queue.fal.run',
        ]);
    }
}
