<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Venusian\Metal\MetalEngine;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Drawing\Painter;

beforeEach(function () {
    if (! metalExtensionLoaded()) {
        test()->markTestSkipped('ext-metal is not loaded');
    }

    Lifetime::reset();
    Registry::reset();
});

it('blends a half-transparent white over black to mid grey', function () {
    $executor = (new MetalEngine)->attach(new GPUHost(0, 8, 8, 1.0))->executor;
    $painter = new Painter($executor);

    expect($executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))->toBeTrue();
    $painter->begin(8, 8, 1.0);
    $painter->fillRect(0.0, 0.0, 8.0, 8.0, new Color(1.0, 1.0, 1.0, 0.5));
    $painter->flush();
    $pixels = $executor->readPixels();
    $painter->reset();
    $executor->endFrame();

    $centre = array_values(unpack('C4', substr($pixels, (4 * 8 + 4) * 4, 4)));
    foreach ([0, 1, 2] as $channel) {
        expect(abs($centre[$channel] - 128))->toBeLessThanOrEqual(2);
    }
    expect($centre[3])->toBe(255);

    $executor->release();
});
