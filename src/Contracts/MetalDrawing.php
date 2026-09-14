<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal\Contracts;

use Jovian\Bindings\Metal\MTL\MTLCommandQueue;
use Jovian\Bindings\Metal\MTL\MTLDevice;
use Jovian\Bindings\Metal\MTL\MTLRenderCommandEncoder;
use Jovian\Bindings\Metal\MTL\MTLRenderPipelineState;
use Jovian\Bindings\Metal\QuartzCore\CAMetalLayer;

/**
 * The Metal-only half a sketch reaches beside the Painter.
 */
interface MetalDrawing
{
    public function device(): MTLDevice;

    public function queue(): MTLCommandQueue;

    public function layer(): CAMetalLayer;

    /** Non-null only inside a frame. */
    public function encoder(): ?MTLRenderCommandEncoder;

    public function pipeline(): MTLRenderPipelineState;
}
