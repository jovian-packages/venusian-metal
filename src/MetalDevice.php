<?php

namespace Jovian\Engines\Metal;

use CAMetalLayer;
use CGSize;
use MetalException;
use MTLBuffer;
use MTLClearColor;
use MTLColorWriteMask;
use MTLCommandBuffer;
use MTLCommandBufferStatus;
use MTLCommandQueue;
use MTLCompareFunction;
use MTLCullMode;
use MTLDepthStencilDescriptor;
use MTLDepthStencilState;
use MTLDevice;
use MTLFunction;
use MTLLibrary;
use MTLLoadAction;
use MTLOrigin;
use MTLPixelFormat;
use MTLPrimitiveType;
use MTLRegion;
use MTLRenderCommandEncoder;
use MTLRenderPassDescriptor;
use MTLRenderPipelineDescriptor;
use MTLRenderPipelineState;
use MTLResourceOptions;
use MTLScissorRect;
use MTLSize;
use MTLStencilDescriptor;
use MTLStencilOperation;
use MTLStorageMode;
use MTLStoreAction;
use MTLTexture;
use MTLTextureDescriptor;
use MTLTextureType;
use MTLTextureUsage;
use MTLViewport;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Drawing\Gpu\DrawList;
use Surface\Drawing\Gpu\GpuDevice;
use Surface\Drawing\Gpu\Op;

/**
 * The metal engine's device: one MTLDevice and queue, a persistent target, and
 * the pipelines that draw a DrawList into it.
 *
 * The target is a shared single-sample RGBA8 texture ($resolved), read back and
 * uploaded to by MetalFramebuffer and shown by present(). With four samples a
 * private multisampled texture is drawn into and resolved into it every frame;
 * an upload then leaves the samples behind, and the next draw restores them
 * from $resolved first. The stencil is an 8-bit texture at the same sample
 * count, cleared every pass: paths fill stencil-then-cover and leave it at zero.
 */
final class MetalDevice implements GpuDevice
{
    /** The one MSL source, compiled on the device at construction. */
    public const string SHADERS = __DIR__.'/../resources/shaders/venusian.metal';

    private MTLDevice $metal;

    private MTLCommandQueue $queue;

    private MTLLibrary $library;

    private ?MetalFramebuffer $framebuffer = null;

    /** Single-sample, shared: what is read back, uploaded to, resolved into and presented. */
    private ?MTLTexture $resolved = null;

    /** Four samples, private; null with one sample. */
    private ?MTLTexture $multisampled = null;

    private ?MTLTexture $stencil = null;

    private int $samples = 1;

    /** @var array<string, MTLRenderPipelineState> By fragment function, for the current sample count. */
    private array $pipelines = [];

    /** @var array<string, MTLDepthStencilState> */
    private array $stencils = [];

    /** The last command buffer committed: finish() waits for it. */
    private ?MTLCommandBuffer $last = null;

    /** The last present's command buffer: the next present waits for it to complete. */
    private ?MTLCommandBuffer $presented = null;

    /** $resolved was uploaded to since $multisampled was last drawn. */
    private bool $stale = false;

    private ?CAMetalLayer $layer = null;

    private ?MTLRenderPipelineState $show = null;

    /**
     * @param  MTLDevice|null  $metal  The system default device unless given.
     *
     * @throws DrawingException When the Mac has no Metal device, or the shaders do not compile on it.
     */
    public function __construct(?MTLDevice $metal = null)
    {
        $this->metal = $metal ?? MTLCreateSystemDefaultDevice() ?? throw new DrawingException('metal: this Mac has no Metal device.');
        $this->queue = $this->metal->newCommandQueue() ?? throw new DrawingException('metal: no command queue could be made.');
        try {
            $this->library = $this->metal->newLibraryWithSourceOptionsError(file_get_contents(self::SHADERS), null);
        } catch (MetalException $e) {
            throw new DrawingException("metal: the shaders did not compile on {$this->metal->name()}. The engine blends by reading the target in its fragment functions, which Apple silicon GPUs support. ".$e->getMessage(), 0, $e);
        }
    }

    public function name(): string
    {
        return 'metal';
    }

    public function metal(): MTLDevice
    {
        return $this->metal;
    }

    public function surfaces(): array
    {
        return [SurfaceKind::METAL_LAYER];
    }

    public function handles(): array
    {
        return [];
    }

    public function target(int $width, int $height, int $samples): GLFramebuffer
    {
        if ($samples !== 1 && $samples !== 4) {
            throw new DrawingException("metal draws with 1 or 4 samples, got {$samples}.");
        }
        if ($width < 1 || $height < 1) {
            throw new DrawingException("metal needs a target of at least 1 × 1, got {$width} × {$height}.");
        }

        $this->finish();
        $this->samples = $samples;
        $this->pipelines = [];
        $this->stale = false;
        $this->resolved = $this->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value);
        $this->multisampled = $samples === 4 ? $this->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 4, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value) : null;
        $this->stencil = $this->texture(MTLPixelFormat::STENCIL8, $width, $height, $samples, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value);
        $this->framebuffer = new MetalFramebuffer($this, $this->resolved);

        // Defined contents from the start: transparent black, as a new framebuffer holds.
        $commands = $this->commands();
        $encoder = $commands->renderCommandEncoderWithDescriptor($this->pass(MTLLoadAction::CLEAR)) ?? throw new DrawingException('metal: no render encoder could be made.');
        $encoder->endEncoding();
        $commands->commit();
        $this->last = $commands;

        return $this->framebuffer;
    }

    /** @internal MetalFramebuffer's readback: $region of $texture as RGBA8, once the last frame has finished. */
    public function read(MTLTexture $texture, Region $region): string
    {
        $this->finish();

        return $texture->getBytesBytesPerRowFromRegionMipmapLevel(null, $region->width * 4, self::region($region), 0)
            ?? throw new DrawingException('metal: the readback returned nothing.');
    }

    /** @internal MetalFramebuffer's upload: $rgba8 over $region of $texture. The multisampled target is restored from it at the next draw. */
    public function write(MTLTexture $texture, string $rgba8, Region $region): void
    {
        $this->finish();
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region($region), 0, $rgba8, $region->width * 4);
        if ($texture === $this->resolved && ! is_null($this->multisampled)) {
            $this->stale = true;
        }
    }

    /**
     * Wait for the last command buffer committed.
     *
     * @throws DrawingException When it failed on the GPU.
     */
    public function finish(): void
    {
        if (is_null($this->last)) {
            return;
        }
        $this->last->waitUntilCompleted();
        if ($this->last->status() === MTLCommandBufferStatus::ERROR) {
            $error = $this->last->error() ?? 'no error given';
            $this->last = null;

            throw new DrawingException("metal: a frame failed on the GPU: {$error}.");
        }
        $this->last = null;
    }

    public function draw(DrawList $list): void
    {
        $target = $this->framebuffer ?? throw new DrawingException('metal: target() comes before draw().');
        $width = $target->width();
        $height = $target->height();

        // The list's vertices, then one quad over the whole target for CLEAR and the restore.
        $whole = $list->vertexCount();
        $vertices = $list->vertices.self::quad(0.0, 0.0, (float) $width, (float) $height);
        $buffer = $this->metal->newBufferWithBytesLengthOptions($vertices, strlen($vertices), MTLResourceOptions::STORAGE_MODE_SHARED)
            ?? throw new DrawingException('metal: no vertex buffer could be made.');

        $commands = $this->commands();
        if ($this->stale) {
            $this->restore($commands, $buffer, $whole, $width, $height);
        }

        $encoder = $commands->renderCommandEncoderWithDescriptor($this->pass(MTLLoadAction::LOAD)) ?? throw new DrawingException('metal: no render encoder could be made.');
        $this->prepare($encoder, $buffer, $width, $height);
        $scissor = new MTLScissorRect(0, 0, $width, $height);
        /** @var array<int, MTLTexture> $textures */
        $textures = [];

        foreach ($list->operations as $operation) {
            switch ($operation[0]) {
                case Op::CLEAR:
                    $encoder->setScissorRect(new MTLScissorRect(0, 0, $width, $height));
                    $this->paint($encoder, 'solid', 'plain', $operation[1]);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $whole, 6);
                    $encoder->setScissorRect($scissor);
                    break;

                case Op::SCISSOR:
                    $scissor = new MTLScissorRect($operation[1]->x, $operation[1]->y, $operation[1]->width, $operation[1]->height);
                    $encoder->setScissorRect($scissor);
                    break;

                case Op::SOLID:
                    $this->paint($encoder, 'solid', 'plain', $operation[2]);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $operation[1], 6);
                    break;

                case Op::STENCIL_FILL:
                    $encoder->setRenderPipelineState($this->pipeline('mark'));
                    $encoder->setDepthStencilState($this->stencilState($operation[3] === FillRule::NON_ZERO ? 'winding' : 'invert'));
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $operation[1], $operation[2]);
                    break;

                case Op::COVER:
                    $this->paint($encoder, 'fill', 'cover', $operation[2]);
                    $encoder->setStencilReferenceValue(0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $operation[1], 6);
                    break;

                case Op::RECTS:
                    $this->paint($encoder, 'fill', 'plain', $operation[3]);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $operation[1], $operation[2]);
                    break;

                case Op::ELLIPSE:
                    [, $first, $cx, $cy, $rx, $ry, $rgba] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('oval'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $bytes = self::paintBytes($rgba).pack('g8', $cx, $cy, $rx, $ry, 0.0, 0.0, 0.0, 0.0);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    break;

                case Op::RING:
                    [, $first, $cx, $cy, $rx, $ry, $stroke, $rgba] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('oval'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $bytes = self::paintBytes($rgba).pack('g8', $cx, $cy, $rx, $ry, $stroke, 1.0, 0.0, 0.0);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    break;

                case Op::UPLOAD:
                    $textures[$operation[1]] = $this->upload($operation[2]);
                    break;

                case Op::IMAGE:
                    [, $first, $texture, $inverse, $opacity, $filter] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('image'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $encoder->setFragmentTextureAtIndex($textures[$texture], 0);
                    $bytes = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, (float) $textures[$texture]->width(), (float) $textures[$texture]->height())
                        .pack('V4', $opacity, $filter === Filter::LINEAR ? 1 : 0, 0, 0);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    break;
            }
        }

        $encoder->endEncoding();
        $commands->commit();
        $this->last = $commands;
    }

    /** Viewport, winding, the vertex buffer and the target size every pass over the target starts with. */
    private function prepare(MTLRenderCommandEncoder $encoder, MTLBuffer $buffer, int $width, int $height): void
    {
        $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0));
        $encoder->setCullMode(MTLCullMode::NONE);
        $encoder->setVertexBufferOffsetAtIndex($buffer, 0, 0);
        $size = pack('g2', (float) $width, (float) $height);
        $encoder->setVertexBytesLengthAtIndex($size, strlen($size), 1);
    }

    /** One pass that draws the resolved texture back into the multisampled one, after an upload. */
    private function restore(MTLCommandBuffer $commands, MTLBuffer $buffer, int $whole, int $width, int $height): void
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($this->multisampled);
        $color->setLoadAction(MTLLoadAction::DONT_CARE);
        $color->setStoreAction(MTLStoreAction::STORE);
        $stencil = $pass->stencilAttachment();
        $stencil->setTexture($this->stencil);
        $stencil->setLoadAction(MTLLoadAction::CLEAR);
        $stencil->setStoreAction(MTLStoreAction::DONT_CARE);

        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw new DrawingException('metal: no render encoder could be made.');
        $this->prepare($encoder, $buffer, $width, $height);
        $encoder->setRenderPipelineState($this->pipeline('copy'));
        $encoder->setDepthStencilState($this->stencilState('plain'));
        $encoder->setFragmentTextureAtIndex($this->resolved, 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $whole, 6);
        $encoder->endEncoding();
        $this->stale = false;
    }

    /** A flat-colour draw: the pipeline, the stencil state and the Paint. */
    private function paint(MTLRenderCommandEncoder $encoder, string $fragment, string $stencil, int $rgba): void
    {
        $encoder->setRenderPipelineState($this->pipeline($fragment));
        $encoder->setDepthStencilState($this->stencilState($stencil));
        $bytes = self::paintBytes($rgba);
        $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
    }

    /** An image source as a shared RGBA8 texture, uploaded now. */
    private function upload(Framebuffer $source): MTLTexture
    {
        $width = $source->viewportWidth();
        $height = $source->viewportHeight();
        $texture = $this->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region(Region::wholeSurface($width, $height)), 0, $source->toRgba8(), $width * 4);

        return $texture;
    }

    /**
     * The pipeline for one fragment function at the current sample count:
     * RGBA8 colour, 8-bit stencil, blending off (the fragment blends), colour
     * writes off for `mark`.
     */
    private function pipeline(string $fragment): MTLRenderPipelineState
    {
        if (isset($this->pipelines[$fragment])) {
            return $this->pipelines[$fragment];
        }

        $descriptor = MTLRenderPipelineDescriptor::new();
        $descriptor->setVertexFunction($this->function('place'));
        $descriptor->setFragmentFunction($this->function($fragment));
        $descriptor->setRasterSampleCount($this->samples);
        $descriptor->setStencilAttachmentPixelFormat(MTLPixelFormat::STENCIL8);
        $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setPixelFormat(MTLPixelFormat::RGBA8_UNORM);
        $color->setWriteMask($fragment === 'mark' ? MTLColorWriteMask::NONE : MTLColorWriteMask::ALL);
        $color->setBlendingEnabled(false);

        try {
            return $this->pipelines[$fragment] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor);
        } catch (MetalException $e) {
            throw new DrawingException("metal: the '{$fragment}' pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, $e);
        }
    }

    private function function(string $name): MTLFunction
    {
        return $this->library->newFunctionWithName($name) ?? throw new DrawingException("metal: the shaders have no function '{$name}'.");
    }

    /**
     * plain: the stencil untouched. winding: front faces count up, back faces
     * down (NON_ZERO). invert: every face flips it (EVEN_ODD). cover: draw
     * where it is not zero, and zero it.
     */
    private function stencilState(string $kind): MTLDepthStencilState
    {
        if (isset($this->stencils[$kind])) {
            return $this->stencils[$kind];
        }

        $front = MTLStencilDescriptor::new();
        $back = MTLStencilDescriptor::new();
        switch ($kind) {
            case 'winding':
                $front->setDepthStencilPassOperation(MTLStencilOperation::INCREMENT_WRAP);
                $back->setDepthStencilPassOperation(MTLStencilOperation::DECREMENT_WRAP);
                break;
            case 'invert':
                $front->setDepthStencilPassOperation(MTLStencilOperation::INVERT);
                $back->setDepthStencilPassOperation(MTLStencilOperation::INVERT);
                break;
            case 'cover':
                foreach ([$front, $back] as $face) {
                    $face->setStencilCompareFunction(MTLCompareFunction::NOT_EQUAL);
                    $face->setDepthStencilPassOperation(MTLStencilOperation::ZERO);
                }
                break;
        }
        $descriptor = MTLDepthStencilDescriptor::new();
        $descriptor->setFrontFaceStencil($front);
        $descriptor->setBackFaceStencil($back);

        return $this->stencils[$kind] = $this->metal->newDepthStencilStateWithDescriptor($descriptor)
            ?? throw new DrawingException("metal: the '{$kind}' stencil state could not be made.");
    }

    /** A Paint: the colour's four channels as uint4. */
    private static function paintBytes(int $rgba): string
    {
        return pack('V4', ($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF);
    }

    /** Six vertices: x0,y0 x1,y0 x1,y1 x0,y0 x1,y1 x0,y1, as Lowering lays a quad. */
    private static function quad(float $x0, float $y0, float $x1, float $y1): string
    {
        return pack('g12', $x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1);
    }

    public function adopt(LentSurface $surface): void
    {
        $layer = CAMetalLayer::fromPointer($surface->handle('layer'));
        $layer->setDevice($this->metal);
        $layer->setPixelFormat(MTLPixelFormat::BGRA8_UNORM);
        $layer->setFramebufferOnly(true);
        $layer->setDisplaySyncEnabled(false);
        $this->layer = $layer;
    }

    /**
     * Draw the resolved texture into the layer's next drawable and present it.
     * No wait on the display: while the last copy's command buffer has not
     * completed, the layer may have no free drawable, and nothing is asked.
     */
    public function present(LentSurface $surface): bool
    {
        $layer = $this->layer ?? throw new DrawingException('metal: adopt() a surface before present().');
        $target = $this->framebuffer ?? throw new DrawingException('metal: target() comes before present().');
        if ($surface->released()) {
            return false;
        }
        if (! is_null($this->presented) && ! in_array($this->presented->status(), [MTLCommandBufferStatus::COMPLETED, MTLCommandBufferStatus::ERROR], true)) {
            return false;
        }

        $width = $target->width();
        $height = $target->height();
        $size = $layer->drawableSize();
        if ((int) $size->width !== $width || (int) $size->height !== $height) {
            $layer->setDrawableSize(new CGSize((float) $width, (float) $height));
        }
        $drawable = $layer->nextDrawable();
        if (is_null($drawable)) {
            return false;
        }

        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($drawable->texture());
        $color->setLoadAction(MTLLoadAction::DONT_CARE);
        $color->setStoreAction(MTLStoreAction::STORE);

        $commands = $this->commands();
        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw new DrawingException('metal: no render encoder could be made.');
        $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0));
        $encoder->setCullMode(MTLCullMode::NONE);
        $quad = self::quad(0.0, 0.0, (float) $width, (float) $height);
        $encoder->setVertexBytesLengthAtIndex($quad, strlen($quad), 0);
        $frame = pack('g2', (float) $width, (float) $height);
        $encoder->setVertexBytesLengthAtIndex($frame, strlen($frame), 1);
        $encoder->setRenderPipelineState($this->showPipeline());
        $encoder->setFragmentTextureAtIndex($this->resolved, 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 6);
        $encoder->endEncoding();
        $commands->presentDrawable($drawable);
        $commands->commit();
        $this->presented = $commands;
        $this->last = $commands;

        return true;
    }

    public function release(): void
    {
        $this->finish();
        $this->framebuffer = null;
        $this->resolved = null;
        $this->multisampled = null;
        $this->stencil = null;
        $this->pipelines = [];
        $this->stencils = [];
        $this->show = null;
        $this->layer = null;
        $this->presented = null;
        $this->stale = false;
    }

    /** The pipeline that shows the target in a BGRA8 drawable: one sample, no stencil. */
    private function showPipeline(): MTLRenderPipelineState
    {
        if (! is_null($this->show)) {
            return $this->show;
        }

        $descriptor = MTLRenderPipelineDescriptor::new();
        $descriptor->setVertexFunction($this->function('place'));
        $descriptor->setFragmentFunction($this->function('show'));
        $descriptor->setRasterSampleCount(1);
        $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setPixelFormat(MTLPixelFormat::BGRA8_UNORM);
        $color->setBlendingEnabled(false);

        try {
            return $this->show = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor);
        } catch (MetalException $e) {
            throw new DrawingException("metal: the 'show' pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, $e);
        }
    }

    private function texture(MTLPixelFormat $format, int $width, int $height, int $samples, MTLStorageMode $storage, int $usage): MTLTexture
    {
        $descriptor = MTLTextureDescriptor::new();
        $descriptor->setTextureType($samples > 1 ? MTLTextureType::TYPE_2D_MULTISAMPLE : MTLTextureType::TYPE_2D);
        $descriptor->setPixelFormat($format);
        $descriptor->setWidth($width);
        $descriptor->setHeight($height);
        $descriptor->setSampleCount($samples);
        $descriptor->setUsage($usage);
        $descriptor->setStorageMode($storage);

        return $this->metal->newTextureWithDescriptor($descriptor)
            ?? throw new DrawingException("metal: no {$width} × {$height} texture ({$format->name}, {$samples} samples) could be made.");
    }

    /** A pass over the target: colour loaded (or cleared to transparent black) and stored, resolved with four samples; the stencil cleared and dropped. */
    private function pass(MTLLoadAction $load): MTLRenderPassDescriptor
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        if (is_null($this->multisampled)) {
            $color->setTexture($this->resolved);
            $color->setStoreAction(MTLStoreAction::STORE);
        } else {
            $color->setTexture($this->multisampled);
            $color->setResolveTexture($this->resolved);
            $color->setStoreAction(MTLStoreAction::STORE_AND_MULTISAMPLE_RESOLVE);
        }
        $color->setLoadAction($load);
        $color->setClearColor(new MTLClearColor(0.0, 0.0, 0.0, 0.0));

        $stencil = $pass->stencilAttachment();
        $stencil->setTexture($this->stencil);
        $stencil->setLoadAction(MTLLoadAction::CLEAR);
        $stencil->setClearStencil(0);
        $stencil->setStoreAction(MTLStoreAction::DONT_CARE);

        return $pass;
    }

    private function commands(): MTLCommandBuffer
    {
        return $this->queue->commandBuffer() ?? throw new DrawingException('metal: no command buffer could be made.');
    }

    private static function region(Region $region): MTLRegion
    {
        return new MTLRegion(new MTLOrigin($region->x, $region->y, 0), new MTLSize($region->width, $region->height, 1));
    }
}
