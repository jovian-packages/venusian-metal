<?php

declare(strict_types=1);

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

afterEach(function () {
    if (! metalExtensionLoaded()) {
        return;
    }

    Registry::reset();
    Lifetime::reset();
});

it('attaches a CAMetalLayer and answers pointer bits at host size times scale', function () {
    $attachment = (new MetalEngine)->attach(new GPUHost(0, 320, 240, 2.0));

    expect($attachment->layer_pointer)->toBeGreaterThan(0);
    expect($attachment->layer_class)->toBe('CAMetalLayer');
    expect($attachment->executor->drawableSize())->toBe([640, 480]);

    $attachment->executor->release();
});
