<?php

declare(strict_types=1);

use Jovian\Venusian\Metal\MetalEngine;
use Surface\Contracts\Drawing\GPUEngine;

it('names the metal engine', function () {
    expect((new MetalEngine)->engine())->toBe(GPUEngine::METAL);
});

it('mints through a layer, not a GL context', function () {
    expect((new MetalEngine)->surfaceKind())->toBe(\Surface\Contracts\Drawing\SurfaceKind::LAYER);
});

it('declares slice-1 Metal capabilities honestly', function () {
    $capabilities = \Jovian\Venusian\Metal\MetalExecutor::declaredCapabilities();

    expect($capabilities->blending)->toBeTrue();
    expect($capabilities->depth)->toBeFalse();
    expect($capabilities->instancing)->toBeTrue();
    expect($capabilities->readback)->toBeTrue();
    expect($capabilities->max_texture_size)->toBeGreaterThan(0);
});
