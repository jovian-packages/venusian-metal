<?php

namespace Jovian\Engines\Metal;

use CAMetalLayer;
use CAEDRMetadata;
use CAMetalDrawable;
use CGColorSpace;
use CGSize;
use NSApplication;
use NSDate;
use NSEventMask;
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
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\PresentTiming;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Drawing\Gpu\DrawList;
use Surface\Drawing\Gpu\GpuDevice;
use Surface\Drawing\Gpu\Op;
use Surface\Drawing\Gpu\QueuesFrames;
use Surface\Drawing\Gpu\ReportsPresents;
use Surface\Drawing\Gpu\SwitchesVsync;
use Surface\Drawing\Gpu\TargetsFormats;
use Surface\Framebuffers\HdrImage;

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
 *
 * Presenting: the drawable is the lent surface's size, and the target is
 * shown into its presentRect() by the show pass with the surface's filter,
 * the bars cleared black. Vsync is the layer's display sync: On syncs, Off
 * and Mailbox do not (a composited window shows the newest frame at each
 * refresh either way). A minimised or hidden window keeps taking drawables
 * under vsync, so presents never stall on it. At most setFramesInFlight()
 * presents are in flight (1 unless set); the layer keeps one drawable more,
 * three at most. A present's drawable reports when it reached the screen;
 * those times arrive through the application's event cycle, which
 * waitPresented() pumps as the AppKit session does.
 *
 * HDR: every HDR target (extended linear, HDR10) is an RGBA16Float texture
 * of linear light in sRGB primaries, SDR white at 1.0. The layer gets the
 * drawable format (RGBA16Float for extended linear, RGB10A2 for HDR10,
 * BGRA8 otherwise), EDR, and a CGColorSpace: extended linear sRGB for both
 * extended spaces (it carries P3 as values outside 0..1), ITU-R 2100 PQ for
 * HDR10 with HDR10 EDR metadata, Display P3 for an RGBA8 P3 target. The show
 * pass multiplies by the surface's SDR white level, or encodes PQ.
 */
final class MetalDevice implements GpuDevice, SwitchesVsync, QueuesFrames, ReportsPresents, TargetsFormats
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


    /** $resolved was uploaded to since $multisampled was last drawn. */
    private bool $stale = false;

    private ?CAMetalLayer $layer = null;

    private TargetFormat $format = TargetFormat::Rgba8;

    private ColorSpace $space = ColorSpace::Srgb;

    /** Held while the layer uses it. */
    private ?CGColorSpace $colorspace = null;

    /** The surface adopted: its size, scaling and HDR state at each present. */
    private ?LentSurface $surface = null;

    /** Presents allowed in flight. */
    private int $inFlight = 1;

    /** @var list<MTLCommandBuffer> Presents not yet completed on the GPU. */
    private array $presents = [];

    /** @var array<int, MTLRenderPipelineState> Show pipelines by the drawable's pixel format. */
    private array $shows = [];

    /** Presents committed so far: the frame number of the newest. */
    private int $submitted = 0;

    /** @var list<array{int, CAMetalDrawable}> The last presents, oldest first. */
    private array $drawables = [];

    /** How many presents' drawables are kept to ask when they were shown. */
    private const int KEPT = 8;

    /** The newest present known to have reached the screen. */
    private ?PresentTiming $shown = null;

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
        return $this->make($width, $height, $samples, TargetFormat::Rgba8, ColorSpace::Srgb);
    }

    public function targetFormats(): array
    {
        if (! class_exists(CGColorSpace::class)) {
            return [];
        }

        return [
            [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb],
            [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3],
            [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq],
            [TargetFormat::Rgba8, ColorSpace::DisplayP3],
        ];
    }

    public function targetAs(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer
    {
        if (! in_array([$format, $space], $this->targetFormats(), true)) {
            throw new DrawingException("metal makes no {$format->value} {$space->value} target.");
        }

        return $this->make($width, $height, $samples, $format, $space);
    }

    private function make(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer
    {
        if ($samples !== 1 && $samples !== 4) {
            throw new DrawingException("metal draws with 1 or 4 samples, got {$samples}.");
        }
        if ($width < 1 || $height < 1) {
            throw new DrawingException("metal needs a target of at least 1 × 1, got {$width} × {$height}.");
        }

        $this->finish();
        $this->format = $format;
        $this->space = $space;
        $this->samples = $samples;
        $this->pipelines = [];
        $this->stale = false;
        $pixels = $this->pixelFormat();
        $this->resolved = $this->texture($pixels, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value);
        $this->multisampled = $samples === 4 ? $this->texture($pixels, $width, $height, 4, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value) : null;
        $this->stencil = $this->texture(MTLPixelFormat::STENCIL8, $width, $height, $samples, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value);
        $this->framebuffer = new MetalFramebuffer($this, $this->resolved, $space->isHdr());
        $this->configureLayer();

        // Defined contents from the start: transparent black, as a new framebuffer holds.
        $commands = $this->commands();
        $encoder = $commands->renderCommandEncoderWithDescriptor($this->pass(MTLLoadAction::CLEAR)) ?? throw new DrawingException('metal: no render encoder could be made.');
        $encoder->endEncoding();
        $commands->commit();
        $this->last = $commands;

        return $this->framebuffer;
    }

    /** The target's texture format: half floats for every HDR colour space. */
    private function pixelFormat(): MTLPixelFormat
    {
        return $this->space->isHdr() ? MTLPixelFormat::RGBA16_FLOAT : MTLPixelFormat::RGBA8_UNORM;
    }

    /** The adopted layer's drawable format, EDR, colour space and HDR10 metadata for the target. */
    private function configureLayer(): void
    {
        if (is_null($this->layer)) {
            return;
        }
        $this->layer->setPixelFormat(match ($this->format) {
            TargetFormat::Rgba8 => MTLPixelFormat::BGRA8_UNORM,
            TargetFormat::Rgba16Float => MTLPixelFormat::RGBA16_FLOAT,
            TargetFormat::Rgb10A2 => $this->space === ColorSpace::Hdr10Pq ? MTLPixelFormat::RGB10A2_UNORM : MTLPixelFormat::BGRA8_UNORM,
        });
        $this->layer->setWantsExtendedDynamicRangeContent($this->space->isHdr());
        $name = match ($this->space) {
            ColorSpace::Srgb => null,
            ColorSpace::DisplayP3 => 'kCGColorSpaceDisplayP3',
            ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3 => 'kCGColorSpaceExtendedLinearSRGB',
            ColorSpace::Hdr10Pq => 'kCGColorSpaceITUR_2100_PQ',
        };
        $this->colorspace = is_null($name) ? null : (CGColorSpace::createWithName($name) ?? throw new DrawingException("metal: macOS has no colour space named {$name}."));
        $this->layer->setColorspace($this->colorspace?->pointer());
        if ($this->space === ColorSpace::Hdr10Pq) {
            [, , $nits] = $this->transform();
            $headroom = $this->surface?->hdr()?->headroom ?? 1.0;
            $this->layer->setEDRMetadata(CAEDRMetadata::HDR10MetadataWithMinLuminanceMaxLuminanceOpticalOutputScale(0.005, $headroom > 1.0 ? $nits * $headroom : 1000.0, $nits));
        } else {
            $this->layer->setEDRMetadata(null);
        }
    }

    /** @internal MetalFramebuffer's readback: $region of $texture, $bytes a texel (4 RGBA8, 8 half floats), once the last frame has finished. */
    public function read(MTLTexture $texture, Region $region, int $bytes = 4): string
    {
        $this->finish();

        return $texture->getBytesBytesPerRowFromRegionMipmapLevel(null, $region->width * $bytes, self::region($region), 0)
            ?? throw new DrawingException('metal: the readback returned nothing.');
    }

    /** @internal MetalFramebuffer's upload: $texels over $region of $texture, $bytes a texel. The multisampled target is restored from it at the next draw. */
    public function write(MTLTexture $texture, string $texels, Region $region, int $bytes = 4): void
    {
        $this->finish();
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region($region), 0, $texels, $region->width * $bytes);
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
        $light = $this->light(false);
        $encoder->setFragmentBytesLengthAtIndex($light, strlen($light), 1);
        /** @var array<int, bool> $floats Whether each texture number holds half floats. */
        $floats = [];
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
                    [$textures[$operation[1]], $floats[$operation[1]]] = $this->upload($operation[2]);
                    break;

                case Op::IMAGE:
                    [, $first, $texture, $inverse, $opacity, $filter] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('image'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $encoder->setFragmentTextureAtIndex($textures[$texture], 0);
                    $bytes = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, (float) $textures[$texture]->width(), (float) $textures[$texture]->height())
                        .pack('V4', $opacity, $filter === Filter::LINEAR ? 1 : 0, 0, 0);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    if ($floats[$texture]) {
                        $floatLight = $this->light(true);
                        $encoder->setFragmentBytesLengthAtIndex($floatLight, strlen($floatLight), 1);
                    }
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    if ($floats[$texture]) {
                        $encoder->setFragmentBytesLengthAtIndex($light, strlen($light), 1);
                    }
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

    /** The Light block: HDR target, half-float source. */
    private function light(bool $floatSource): string
    {
        return pack('V4', $this->space->isHdr() ? 1 : 0, $floatSource ? 1 : 0, 0, 0);
    }

    /**
     * An image source as a shared texture, uploaded now: half floats for an
     * HdrImage on an HDR target, RGBA8 otherwise.
     *
     * @return array{MTLTexture, bool} The texture, and whether it holds half floats.
     */
    private function upload(Framebuffer $source): array
    {
        $width = $source->viewportWidth();
        $height = $source->viewportHeight();
        $whole = self::region(Region::wholeSurface($width, $height));
        if ($source instanceof HdrImage && $this->space->isHdr()) {
            $texture = $this->texture(MTLPixelFormat::RGBA16_FLOAT, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
            $texture->replaceRegionMipmapLevelWithBytesBytesPerRow($whole, 0, $source->rgba16f(), $width * 8);

            return [$texture, true];
        }
        $texture = $this->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow($whole, 0, $source->toRgba8(), $width * 4);

        return [$texture, false];
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
        $color->setPixelFormat($this->pixelFormat());
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
        $layer->setMaximumDrawableCount(max(2, min(3, $this->inFlight + 1)));
        $this->layer = $layer;
        $this->surface = $surface;
        $this->configureLayer();
    }

    public function vsyncModes(): array
    {
        return [VSync::On, VSync::Off, VSync::Mailbox];
    }

    public function applyVsync(VSync $vsync): void
    {
        $layer = $this->layer ?? throw new DrawingException('metal: adopt() a surface before applyVsync().');
        if ($vsync === VSync::Adaptive) {
            throw new DrawingException('metal presents with vsync on, off or mailbox, not adaptive.');
        }
        $layer->setDisplaySyncEnabled($vsync === VSync::On);
    }

    public function setFramesInFlight(int $frames): void
    {
        if ($frames < 1 || $frames > 3) {
            throw new DrawingException("metal keeps 1 to 3 frames in flight, got {$frames}.");
        }
        $this->inFlight = $frames;
        $this->layer?->setMaximumDrawableCount(max(2, min(3, $frames + 1)));
    }

    public function submitted(): int
    {
        return $this->submitted;
    }

    public function lastPresented(): ?PresentTiming
    {
        for ($i = count($this->drawables) - 1; $i >= 0; $i--) {
            [$frame, $drawable] = $this->drawables[$i];
            if (! is_null($this->shown) && $frame <= $this->shown->frame) {
                break;
            }
            $at = $drawable->presentedTime();
            if ($at > 0.0) {
                // CACurrentMediaTime's seconds and hrtime() count the same host clock.
                $this->shown = new PresentTiming($frame, (int) round($at * 1e9));
                break;
            }
        }

        return $this->shown;
    }

    public function waitPresented(int $frame, int $timeoutNs): bool
    {
        if ($frame > $this->submitted) {
            return false;
        }
        $until = hrtime(true) + $timeoutNs;
        while (true) {
            if (($this->lastPresented()?->frame ?? 0) >= $frame) {
                return true;
            }
            $left = $until - hrtime(true);
            if ($left <= 0) {
                return false;
            }
            $this->pumpPresentations(min($left, 2_000_000));
        }
    }

    /**
     * @internal The show pass's encoding for the current target: the
     * transform code (0 as stored, 1 extended linear, 2 PQ), the SDR white
     * level, and the nits an HDR10 value of 1.0 shows at.
     *
     * @return array{int, float, float}
     */
    public function transform(): array
    {
        $level = $this->surface?->hdr()?->sdrWhiteLevel ?? 1.0;
        $white = $level > 0.0 ? $level : 1.0;

        return [$this->transformCode(), $white, max(203.0, 80.0 * $white)];
    }

    /** The transform code of the current target: extended linear × white, HDR10 as PQ, SDR as stored. */
    private function transformCode(): int
    {
        return match ($this->space) {
            ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3 => 1,
            ColorSpace::Hdr10Pq => 2,
            default => 0,
        };
    }

    /** Presented times reach drawables through the application's event cycle: run it for up to $ns as the AppKit session runs it. */
    private function pumpPresentations(int $ns): void
    {
        if (! class_exists(NSApplication::class)) {
            usleep(max(1, intdiv($ns, 1000)));

            return;
        }
        $app = NSApplication::sharedApplication();
        $until = NSDate::dateWithTimeIntervalSinceNow($ns / 1e9);
        while ($event = $app->nextEventMatchingMaskUntilDateInModeDequeue(NSEventMask::ANY, $until, NSDefaultRunLoopMode, true)) {
            $app->sendEvent($event);
            $until = NSDate::distantPast();
        }
    }

    /**
     * Show the resolved texture in the layer's next drawable, sized to the
     * surface, inside its presentRect() with the surface's filter, the bars
     * black, encoded for the layer. False while setFramesInFlight() presents
     * are still on the GPU, and when the layer has no drawable free or the
     * surface no area.
     */
    public function present(LentSurface $surface): bool
    {
        $layer = $this->layer ?? throw new DrawingException('metal: adopt() a surface before present().');
        $target = $this->framebuffer ?? throw new DrawingException('metal: target() comes before present().');
        if ($surface->released()) {
            return false;
        }
        $this->presents = array_values(array_filter($this->presents, fn (MTLCommandBuffer $c): bool => ! in_array($c->status(), [MTLCommandBufferStatus::COMPLETED, MTLCommandBufferStatus::ERROR], true)));
        if (count($this->presents) >= $this->inFlight) {
            return false;
        }
        [$across, $down] = $surface->size();
        if ($across < 1 || $down < 1) {
            return false;
        }
        $size = $layer->drawableSize();
        if ((int) $size->width !== $across || (int) $size->height !== $down) {
            $layer->setDrawableSize(new CGSize((float) $across, (float) $down));
        }
        $drawable = $layer->nextDrawable();
        if (is_null($drawable)) {
            return false;
        }
        [$filter, $fit] = $surface->scaling();
        $width = $target->width();
        $height = $target->height();
        $placed = $fit->rect($across, $down, $width, $height);
        [$transform, $white, $nits] = $this->transform();

        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($drawable->texture());
        $color->setLoadAction(MTLLoadAction::CLEAR);
        $color->setClearColor(new MTLClearColor(0.0, 0.0, 0.0, 1.0));
        $color->setStoreAction(MTLStoreAction::STORE);

        $commands = $this->commands();
        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw new DrawingException('metal: no render encoder could be made.');
        $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $across, (float) $down, 0.0, 1.0));
        $encoder->setCullMode(MTLCullMode::NONE);
        $quad = self::quad((float) $placed->x, (float) $placed->y, (float) $placed->right(), (float) $placed->bottom());
        $encoder->setVertexBytesLengthAtIndex($quad, strlen($quad), 0);
        $frame = pack('g2', (float) $across, (float) $down);
        $encoder->setVertexBytesLengthAtIndex($frame, strlen($frame), 1);
        $encoder->setRenderPipelineState($this->showPipeline($layer->pixelFormat()));
        $encoder->setFragmentTextureAtIndex($this->resolved, 0);
        $show = pack('g4', (float) $placed->x, (float) $placed->y, (float) $placed->width, (float) $placed->height)
            .pack('g4', (float) $width, (float) $height, $white, $nits)
            .pack('V4', $filter === ScaleFilter::Linear ? 1 : 0, $transform, 0, 0);
        $encoder->setFragmentBytesLengthAtIndex($show, strlen($show), 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 6);
        $encoder->endEncoding();
        $commands->presentDrawable($drawable);
        $commands->commit();
        $this->presents[] = $commands;
        $this->last = $commands;
        $this->drawables[] = [++$this->submitted, $drawable];
        if (count($this->drawables) > self::KEPT) {
            array_shift($this->drawables);
        }

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
        $this->layer = null;
        $this->shows = [];
        $this->presents = [];
        $this->drawables = [];
        $this->surface = null;
        $this->colorspace = null;
        $this->format = TargetFormat::Rgba8;
        $this->space = ColorSpace::Srgb;
        $this->stale = false;
    }

    /** The pipeline that shows the target in a drawable of $format: one sample, no stencil. */
    private function showPipeline(MTLPixelFormat $format): MTLRenderPipelineState
    {
        if (isset($this->shows[$format->value])) {
            return $this->shows[$format->value];
        }

        $descriptor = MTLRenderPipelineDescriptor::new();
        $descriptor->setVertexFunction($this->function('place'));
        $descriptor->setFragmentFunction($this->function('show'));
        $descriptor->setRasterSampleCount(1);
        $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setPixelFormat($format);
        $color->setBlendingEnabled(false);

        try {
            return $this->shows[$format->value] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor);
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
