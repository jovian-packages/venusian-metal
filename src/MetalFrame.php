<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal;

use Jovian\Bindings\Metal\MTL\MTLBlitCommandEncoder;
use Jovian\Bindings\Metal\MTL\MTLBuffer;
use Jovian\Bindings\Metal\MTL\MTLCommandBuffer;
use Jovian\Bindings\Metal\MTL\MTLRenderCommandEncoder;
use Jovian\Bindings\Metal\MTL\MTLRenderPassColorAttachmentDescriptor;
use Jovian\Bindings\Metal\MTL\MTLRenderPassColorAttachmentDescriptorArray;
use Jovian\Bindings\Metal\MTL\MTLRenderPassDescriptor;
use Jovian\Bindings\Metal\MTL\MTLTexture;
use Jovian\Bindings\Metal\QuartzCore\CAMetalDrawable;

/**
 * Every per-frame Metal box. Dropped at endFrame() so jovian/metal
 * destructors release the handles.
 */
final class MetalFrame
{
    /** @var list<MTLBuffer> */
    public array $drawBuffers = [];

    public ?MTLBlitCommandEncoder $blit = null;

    public ?MTLBuffer $readback = null;

    public function __construct(
        public CAMetalDrawable $drawable,
        public MTLTexture $drawableTexture,
        public MTLRenderPassDescriptor $pass,
        public MTLRenderPassColorAttachmentDescriptorArray $passAttachments,
        public MTLRenderPassColorAttachmentDescriptor $colorAttachment,
        public MTLCommandBuffer $commandBuffer,
        public MTLRenderCommandEncoder $encoder,
    ) {}
}
