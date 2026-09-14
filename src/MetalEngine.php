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
        $width = max(1, (int) round($host->width * $host->scale));
        $height = max(1, (int) round($host->height * $host->scale));

        // A host that owns its layer (an SDL Metal view) lends it: adopt into
        // this extension's registry, configure it, and hand no layer back — the
        // host already shows it. Each side keeps its own retain.
        if ($host->layer > 0) {
            $layer = CAMetalLayer::box(Bridge::adopt('CAMetalLayer', $host->layer));
            if (is_null($layer)) {
                throw new MetalDrawingException('the host lent a pointer that is not a CAMetalLayer');
            }

            $this->configure($layer, $context, $width, $height);

            return new GPUAttachment(new MetalExecutor($context, $layer, $width, $height));
        }

        $layer = CAMetalLayer::init();
        if (is_null($layer)) {
            throw new MetalDrawingException('CAMetalLayer init failed');
        }

        $this->configure($layer, $context, $width, $height);

        return new GPUAttachment(
            new MetalExecutor($context, $layer, $width, $height),
            Bridge::pointerOf($layer->handle),
            'CAMetalLayer',
        );
    }

    private function configure(CAMetalLayer $layer, MetalContext $context, int $width, int $height): void
    {
        $layer->setDevice($context->device->handle);
        $layer->setPixelFormat(MTLPixelFormat::BGRA8_UNORM);
        $layer->setFramebufferOnly(false);
        $layer->setDrawableSize(new CGSize((float) $width, (float) $height));
    }
}
