<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Venusian\Metal\MetalContext;

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

it('builds a render pipeline from the embedded painter source', function () {
    $context = MetalContext::boot();

    expect($context->pipeline->handle)->toBeGreaterThan(0);
    expect($context->pipeline->isValid())->toBeTrue();
    expect($context->device->handle)->toBeGreaterThan(0);
    expect($context->queue->handle)->toBeGreaterThan(0);
    expect($context->sampler->handle)->toBeGreaterThan(0);
    expect($context->placeholder->width())->toBe(1);
    expect($context->placeholder->height())->toBe(1);
});
