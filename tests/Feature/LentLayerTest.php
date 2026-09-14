<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\QuartzCore\CAMetalLayer;
use Jovian\Bindings\Metal\Runtime\Bridge;
use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Venusian\Metal\MetalEngine;
use Surface\Contracts\Drawing\GPUHost;

beforeEach(function () {
    if (! metalExtensionLoaded()) {
        test()->markTestSkipped('ext-metal is not loaded');
    }

    Lifetime::reset();
    Registry::reset();
});

it('adopts a layer the host lends instead of minting one, and hands no layer back', function () {
    $lent = CAMetalLayer::init();
    $bits = Bridge::pointerOf($lent->handle);

    $attachment = (new MetalEngine)->attach(new GPUHost(0, 320, 240, 2.0, layer: $bits));

    expect($attachment->layer_pointer)->toBe(0)
        ->and($attachment->layer_class)->toBe('')
        ->and($attachment->executor->drawableSize())->toBe([640, 480])
        ->and($lent->drawableSize()->width)->toBe(640.0)
        ->and($lent->device())->not->toBeNull();

    $attachment->executor->release();
});
