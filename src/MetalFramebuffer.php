<?php

namespace Jovian\Engines\Metal;

use MTLBuffer;
use MTLClearColor;
use MTLCommandBuffer;
use MTLCullMode;
use MTLLoadAction;
use MTLOrigin;
use MTLPixelFormat;
use MTLPrimitiveType;
use MTLRegion;
use MTLRenderCommandEncoder;
use MTLRenderPassDescriptor;
use MTLScissorRect;
use MTLSize;
use MTLStorageMode;
use MTLStoreAction;
use MTLTexture;
use MTLTextureUsage;
use MTLViewport;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Graphics\GEFramebuffer;
use Surface\NutsAndBolts\Color;

/**
 * The metal engine's framebuffer: an RGBA8 texture on the GPU holding premultiplied colour, and a
 * four-sample twin that antialiased shapes are drawn into and resolved from. read() and write()
 * take straight colour, as every GEFramebuffer does: the GPU converts on the way out and in.
 *
 * A write to the texture leaves the twin behind; it is filled from the texture again before the
 * next antialiased draw. Drawing is handed to the GPU and not waited for: the GPU runs it in order,
 * so whatever is drawn or shown next sees it. Only read() waits, for the bytes it gives back.
 *
 * Its stencils only live inside a pass, so they take no memory where the GPU has memoryless
 * textures. A transient framebuffer (a layer's sheet) is drawn from transparent black every
 * time, so its twin only lives inside a pass too: it holds its texture and nothing else.
 */
class MetalFramebuffer extends GEFramebuffer implements PaintTarget
{
    /** Samples a pixel when edges are antialiased. */
    public const int SAMPLES = 4;

    protected readonly MTLTexture $texture;

    protected readonly MTLTexture $samples;

    protected readonly MTLTexture $stencil;

    protected readonly MTLTexture $hardStencil;

    /** Straight colour on its way in or out: made the first time it is needed. */
    protected ?MTLTexture $staging = null;

    /** Two triangles over the whole surface: made the first time it is needed. */
    protected ?MTLBuffer $whole = null;

    /** Where present() shows it by the GPU; null: as pixels, through its stage. */
    protected ?MetalLayer $layer = null;

    /** Whether the four-sample twin is behind the texture: written to since it was last resolved. */
    protected bool $stale = false;

    /** @throws MetalException */
    public function __construct(
        protected readonly MetalDevice $device,
        protected readonly int $width,
        protected readonly int $height,
        protected readonly bool $transient = false,
    ) {
        parent::__construct($device);
        $this->texture = $device->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value);
        $this->samples = $transient
            ? $device->transient(MTLPixelFormat::RGBA8_UNORM, $width, $height, self::SAMPLES)
            : $device->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, self::SAMPLES, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value);
        $this->stencil = $device->transient(MTLPixelFormat::STENCIL8, $width, $height, self::SAMPLES);
        $this->hardStencil = $device->transient(MTLPixelFormat::STENCIL8, $width, $height, 1);

        if (! $transient) {
            // defined from the start: transparent black, as every new framebuffer holds
            $this->clear(Color::rgba(0, 0, 0, 0.0));
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return FormatSpec::rgba8();
    }

    /** @throws MetalException */
    public function clear(Color $color): static
    {
        $commands = $this->device->commands();
        $pass = $this->pass(true, MTLLoadAction::CLEAR);
        // the colour as its bytes, then premultiplied, as the texture holds it
        $alpha = round($color->alpha * 255) / 255;
        $byte = fn (float $channel): float => round($channel * 255) / 255 * $alpha;
        $pass->colorAttachments()->objectAtIndexedSubscript(0)->setClearColor(new MTLClearColor($byte($color->red), $byte($color->green), $byte($color->blue), $alpha));
        ($commands->renderCommandEncoderWithDescriptor($pass) ?? throw MetalException::because('no render encoder could be made.'))->endEncoding();
        $this->device->submit($commands);
        $this->stale = false;

        return $this;
    }

    /**
     * The texture: premultiplied colour. What was drawn is in it once the GPU reaches it: for anything
     * submitted afterwards, and for the CPU after MetalDevice::sync().
     *
     * @internal
     */
    public function texture(): MTLTexture
    {
        return $this->texture;
    }

    /**
     * Shows it in $layer from now on.
     *
     * @internal
     */
    public function showIn(MetalLayer $layer): static
    {
        $this->layer = $layer;

        return $this;
    }

    /**
     * In a layer the stage lent, the GPU copies the texture to the layer's next drawable and shows
     * it: nothing comes back to the CPU, and the copy is not waited for. Otherwise as pixels,
     * through the stage. On a paced stage this runs once a refresh, when a drawable is free.
     *
     * @throws MetalException
     */
    protected function show(): void
    {
        if (is_null($this->layer)) {
            parent::show();

            return;
        }
        $drawable = $this->layer->fitted($this->width, $this->height, $this->stage()->pixelScale())->nextDrawable();
        if (is_null($drawable)) {
            // the system had no drawable to give in time: this frame is not shown
            return;
        }

        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($drawable->texture());
        $color->setLoadAction(MTLLoadAction::DONT_CARE);
        $color->setStoreAction(MTLStoreAction::STORE);
        $stencil = $pass->stencilAttachment();
        $stencil->setTexture($this->hardStencil);
        $stencil->setLoadAction(MTLLoadAction::DONT_CARE);
        $stencil->setStoreAction(MTLStoreAction::DONT_CARE);

        $commands = $this->device->commands();
        $encoder = $this->encoder($commands, $pass, $this->whole());
        $encoder->setRenderPipelineState($this->device->pipeline('copy', 1, blend: false, format: MTLPixelFormat::BGRA8_UNORM));
        $encoder->setDepthStencilState($this->device->stencil('plain'));
        $encoder->setFragmentTextureAtIndex($this->texture, 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 6);
        $encoder->endEncoding();
        $commands->presentDrawable($drawable);
        $this->device->submit($commands);
    }

    // PaintTarget: what a painter needs to know of what it draws on

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    /** The texture keeps what was drawn until it is drawn over. */
    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /**
     * Moves the pixels of $region by ($dx, $dy) on the GPU: what moves past the region's edge is
     * dropped, and the strip left behind keeps its old pixels.
     *
     * @throws MetalException
     */
    public function scroll(Region $region, int $dx, int $dy): void
    {
        $area = Region::wholeSurface($this->width, $this->height)->intersect($region);
        $from = $area?->intersect(new Region($area->x - $dx, $area->y - $dy, $area->width, $area->height));
        if (is_null($from) || $from->isEmpty() || ($dx === 0 && $dy === 0)) {
            return;
        }
        $copy = $this->device->texture(MTLPixelFormat::RGBA8_UNORM, $from->width, $from->height, 1, MTLStorageMode::PRIVATE, MTLTextureUsage::SHADER_READ->value);
        $commands = $this->device->commands();
        $blit = $commands->blitCommandEncoder() ?? throw MetalException::because('no blit encoder could be made.');
        $size = new MTLSize($from->width, $from->height, 1);
        $blit->copyFromTextureSourceSliceSourceLevelSourceOriginSourceSizeToTextureDestinationSliceDestinationLevelDestinationOrigin($this->texture, 0, 0, new MTLOrigin($from->x, $from->y, 0), $size, $copy, 0, 0, new MTLOrigin(0, 0, 0));
        $blit->copyFromTextureSourceSliceSourceLevelSourceOriginSourceSizeToTextureDestinationSliceDestinationLevelDestinationOrigin($copy, 0, 0, new MTLOrigin(0, 0, 0), $size, $this->texture, 0, 0, new MTLOrigin($from->x + $dx, $from->y + $dy, 0));
        $blit->endEncoding();
        $this->device->submit($commands);
        $this->stale = true;
    }

    /**
     * A render pass over the texture ($antialiased false) or the four-sample twin ($antialiased
     * true, resolved into the texture unless $resolve is false). Colour loaded, or as $load says;
     * the stencil cleared to 0.
     *
     * @internal
     */
    public function pass(bool $antialiased, MTLLoadAction $load = MTLLoadAction::LOAD, bool $resolve = true): MTLRenderPassDescriptor
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        if ($antialiased) {
            $color->setTexture($this->samples);
            if ($resolve) {
                $color->setResolveTexture($this->texture);
                // a transient's samples are resolved and dropped
                $color->setStoreAction($this->transient ? MTLStoreAction::MULTISAMPLE_RESOLVE : MTLStoreAction::STORE_AND_MULTISAMPLE_RESOLVE);
            } else {
                $color->setStoreAction(MTLStoreAction::STORE);
            }
        } else {
            $color->setTexture($this->texture);
            $color->setStoreAction(MTLStoreAction::STORE);
        }
        $color->setLoadAction($load);

        $stencil = $pass->stencilAttachment();
        $stencil->setTexture($antialiased ? $this->stencil : $this->hardStencil);
        $stencil->setLoadAction(MTLLoadAction::CLEAR);
        $stencil->setClearStencil(0);
        $stencil->setStoreAction(MTLStoreAction::DONT_CARE);

        return $pass;
    }

    /**
     * Opens a pass for drawing: the viewport, culling off, $vertices at buffer 0, and at buffer 1
     * the framebuffer's size and where on the surface it starts ($ox, $oy: vertices are in surface
     * pixels). With $clear it starts from transparent black; otherwise from what it holds, the
     * twin filled from the texture first when it is behind.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function begin(MTLCommandBuffer $commands, bool $antialiased, MTLBuffer $vertices, int $ox = 0, int $oy = 0, bool $clear = false): MTLRenderCommandEncoder
    {
        if ($clear) {
            $pass = $this->pass($antialiased, MTLLoadAction::CLEAR);
            $pass->colorAttachments()->objectAtIndexedSubscript(0)->setClearColor(new MTLClearColor(0.0, 0.0, 0.0, 0.0));
            $this->stale = $antialiased ? false : $this->stale;
        } else {
            if ($this->transient) {
                throw MetalException::because('a transient framebuffer is drawn from transparent black every time.');
            }
            if ($antialiased && $this->stale) {
                $this->restore($commands);
            }
            $pass = $this->pass($antialiased);
        }

        return $this->encoder($commands, $pass, $vertices, $ox, $oy);
    }

    /**
     * After a draw: a draw straight into the texture leaves the twin behind.
     *
     * @internal
     */
    public function drew(bool $antialiased): void
    {
        if (! $antialiased) {
            $this->stale = true;
        }
    }

    /**
     * Encodes one pass of $fragment (a 'cover' fragment: one triangle over the viewport) into the texture over $region,
     * replacing what is there: $textures at 0, 1, …, $bytes at buffer 0. The twin is left behind.
     *
     * @internal
     *
     * @param list<MTLTexture> $textures
     *
     * @throws MetalException
     */
    public function cover(MTLCommandBuffer $commands, Region $region, string $fragment, array $textures, string $bytes): void
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($this->texture);
        $color->setLoadAction(MTLLoadAction::LOAD);
        $color->setStoreAction(MTLStoreAction::STORE);

        // everything that can fail is made before the encoder: an encoder dropped without endEncoding() ends the process
        $pipeline = $this->device->filterPipeline($fragment);
        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw MetalException::because('no render encoder could be made.');
        try {
            $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0));
            $encoder->setScissorRect(new MTLScissorRect($region->x, $region->y, $region->width, $region->height));
            $encoder->setRenderPipelineState($pipeline);
            foreach ($textures as $i => $texture) {
                $encoder->setFragmentTextureAtIndex($texture, $i);
            }
            $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
            $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 3);
        } finally {
            $encoder->endEncoding();
        }
        $this->stale = true;
    }

    /** @throws MetalException */
    protected function readRgba8(Region $region): string
    {
        // the texture's premultiplied colour, straight, into the staging texture; then its rows out
        $commands = $this->device->commands();
        $this->convert($commands, 'unpremultiply', $this->texture, $this->staging(), $region);
        $this->device->settle($commands);

        return $this->device->bytes($this->staging, $region->width * 4, self::region($region));
    }

    /** @throws MetalException */
    protected function writeRgba8(string $rgba8, Region $region): void
    {
        // the staging texture is the CPU's to write once nothing submitted is still reading it
        $this->device->sync();
        $this->staging()->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region($region), 0, $rgba8, $region->width * 4);
        $commands = $this->device->commands();
        $this->convert($commands, 'premultiply', $this->staging, $this->texture, $region);
        $this->device->submit($commands);
        $this->stale = true;
    }

    /** One pass that draws the texture into every sample of the twin. */
    protected function restore(MTLCommandBuffer $commands): void
    {
        $encoder = $this->encoder($commands, $this->pass(true, MTLLoadAction::DONT_CARE, resolve: false), $this->whole());
        $encoder->setRenderPipelineState($this->device->pipeline('copy', self::SAMPLES, blend: false));
        $encoder->setDepthStencilState($this->device->stencil('plain'));
        $encoder->setFragmentTextureAtIndex($this->texture, 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 6);
        $encoder->endEncoding();
        $this->stale = false;
    }

    /** One pass that draws $from into $to over $region with $fragment, replacing what is there. */
    protected function convert(MTLCommandBuffer $commands, string $fragment, MTLTexture $from, MTLTexture $to, Region $region): void
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($to);
        $color->setLoadAction(MTLLoadAction::LOAD);
        $color->setStoreAction(MTLStoreAction::STORE);
        $stencil = $pass->stencilAttachment();
        $stencil->setTexture($this->hardStencil);
        $stencil->setLoadAction(MTLLoadAction::DONT_CARE);
        $stencil->setStoreAction(MTLStoreAction::DONT_CARE);

        $encoder = $this->encoder($commands, $pass, $this->whole());
        $encoder->setScissorRect(new MTLScissorRect($region->x, $region->y, $region->width, $region->height));
        $encoder->setRenderPipelineState($this->device->pipeline($fragment, 1, blend: false));
        $encoder->setDepthStencilState($this->device->stencil('plain'));
        $encoder->setFragmentTextureAtIndex($from, 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 6);
        $encoder->endEncoding();
    }

    /** @throws MetalException */
    protected function encoder(MTLCommandBuffer $commands, MTLRenderPassDescriptor $pass, MTLBuffer $vertices, int $ox = 0, int $oy = 0): MTLRenderCommandEncoder
    {
        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw MetalException::because('no render encoder could be made.');
        $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0));
        $encoder->setCullMode(MTLCullMode::NONE);
        $encoder->setVertexBufferOffsetAtIndex($vertices, 0, 0);
        $frame = pack('g4', (float) $this->width, (float) $this->height, (float) $ox, (float) $oy);
        $encoder->setVertexBytesLengthAtIndex($frame, strlen($frame), 1);

        return $encoder;
    }

    /** @throws MetalException */
    protected function staging(): MTLTexture
    {
        return $this->staging ??= $this->device->texture(MTLPixelFormat::RGBA8_UNORM, $this->width, $this->height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value);
    }

    /** @throws MetalException */
    protected function whole(): MTLBuffer
    {
        return $this->whole ??= $this->device->buffer(pack('g12', 0, 0, $this->width, 0, $this->width, $this->height, 0, 0, $this->width, $this->height, 0, $this->height));
    }

    protected static function region(Region $region): MTLRegion
    {
        return new MTLRegion(new MTLOrigin($region->x, $region->y, 0), new MTLSize($region->width, $region->height, 1));
    }
}
