<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal;

use Jovian\Bindings\Metal\Enums\MTLPixelFormat;
use Jovian\Bindings\Metal\QuartzCore\CAMetalLayer;
use Jovian\Bindings\Metal\Runtime\Bridge;
use Jovian\Bindings\Metal\Values\CGSize;
use Jovian\Venusian\Metal\Exceptions\MetalDrawingException;
use Surface\Contracts\Drawing\GPUAttachment;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;

/**
 * Surface's Metal driver. One context per engine instance; the provider
 * binds this class as a singleton behind gpu.metal.
 */
final class MetalEngine implements GPUEngineDriver
{
    private ?MetalContext $context = null;

    public function engine(): GPUEngine
    {
        return GPUEngine::METAL;
    }

    public function surfaceKind(): SurfaceKind
    {
        return SurfaceKind::LAYER;
    }

    public function context(): MetalContext
    {
        return $this->context ??= MetalContext::boot();
    }

    public function attach(GPUHost $host): GPUAttachment
    {
        $context = $this->context();
        $layer = CAMetalLayer::init();
        if (is_null($layer)) {
            throw new MetalDrawingException('CAMetalLayer init failed');
        }

        $layer->setDevice($context->device->handle);
        $layer->setPixelFormat(MTLPixelFormat::BGRA8_UNORM);
        $layer->setFramebufferOnly(false);

        $width = max(1, (int) round($host->width * $host->scale));
        $height = max(1, (int) round($host->height * $host->scale));
        $layer->setDrawableSize(new CGSize((float) $width, (float) $height));

        $pointer = Bridge::pointerOf($layer->handle);

        return new GPUAttachment(
            new MetalExecutor($context, $layer, $width, $height),
            $pointer,
            'CAMetalLayer',
        );
    }
}
