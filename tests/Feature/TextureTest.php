<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Bindings\Metal\Values\MTLRegion;
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

it('round-trips an RGBA8 texture through replaceRegion and getBytes', function () {
    $attachment = (new MetalEngine)->attach(new GPUHost(0, 32, 32, 1.0));
    $executor = $attachment->executor;

    $pixels = pack('C*', 255, 0, 0, 255, 0, 255, 0, 255, 0, 0, 255, 255, 255, 255, 255, 255);
    $handle = $executor->texture($pixels, 2, 2);
    $box = $executor->textureBox($handle);

    expect($handle->width)->toBe(2);
    expect($handle->height)->toBe(2);
    expect($box)->not->toBeNull();

    $bytes = $box->getBytesBytesPerRowFromRegionMipmapLevel(
        8,
        new MTLRegion(0, 0, 0, 2, 2, 1),
        0,
    );

    expect($bytes)->toBe($pixels);

    $executor->release();
});
