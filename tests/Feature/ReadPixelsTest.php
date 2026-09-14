<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Venusian\Metal\Exceptions\MetalDrawingException;
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

it('throws MetalDrawingException when readPixels is called outside a frame', function () {
    $executor = (new MetalEngine)->attach(new GPUHost(0, 64, 64, 1.0))->executor;

    expect(fn () => $executor->readPixels())
        ->toThrow(MetalDrawingException::class);

    $executor->release();
});
