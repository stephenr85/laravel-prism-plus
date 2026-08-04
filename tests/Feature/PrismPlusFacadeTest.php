<?php

use Prism\Prism\Prism;
use Rushing\PrismPlus\Facades\PrismPlus;
use Rushing\PrismPlus\PrismPlus as PrismPlusService;
use Rushing\PrismPlus\PrismPlusManager;

it('resolves the PrismPlus singleton as its facade accessor', function () {
    expect(PrismPlus::getFacadeRoot())
        ->toBeInstanceOf(PrismPlusService::class)
        ->toBe(app(PrismPlusService::class));
});

it('is fakeable via a container swap', function () {
    $fake = new class(app(PrismPlusManager::class), new Prism) extends PrismPlusService
    {
        public function modelProviders(): array
        {
            return ['faked-provider'];
        }
    };

    PrismPlus::swap($fake);

    expect(PrismPlus::getFacadeRoot())->toBe($fake)
        ->and(PrismPlus::modelProviders())->toBe(['faked-provider']);
});
