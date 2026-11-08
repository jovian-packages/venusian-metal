<?php

namespace Jovian\Engines\Metal;

use CAMetalLayer;
use CAEDRMetadata;
use CAMetalDrawable;
use CGColorSpace;
use NSApplication;
use NSDate;
use NSEventMask;
use FbBuffer;
use FbFormat;
use NSException;
use NSSize;
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
use Surface\Drawing\Gpu\FilterPlan;
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
use Surface\Drawing\Gpu\DrawsGlass;
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
final class MetalDevice implements GpuDevice, SwitchesVsync, QueuesFrames, ReportsPresents, TargetsFormats, DrawsGlass
{
    /** The one MSL source, compiled on the device at construction. */
    public const string SHADERS = __DIR__.'/../resources/shaders/venusian.metal';

    /** The filter passes' MSL source, compiled on the first filter chain. */
    public const string FILTER_SHADERS = __DIR__.'/../resources/shaders/filter.metal';

    private MTLDevice $metal;

    private MTLCommandQueue $queue;

    private MTLLibrary $library;

    private ?MetalFramebuffer $framebuffer = null;

    /** Single-sample, shared: what is read back, uploaded to, resolved into and presented. */
    private ?MTLTexture $resolved = null;

    /** Four samples, private; null with one sample. */
    private ?MTLTexture $multisampled = null;

    private ?MTLTexture $stencil = null;

    /** @var array<int, array{resolved: MTLTexture, multisampled: ?MTLTexture, stencil: MTLTexture}> Layer targets by number (1 up): made on first use at the target's size, kept until it changes. */
    private array $slots = [];

    private ?MTLLibrary $filterLibrary = null;

    /** @var array<string, MTLRenderPipelineState> The glass passes' pipelines (glass_capture, glass_down, glass_up). */
    private array $glassPipelines = [];

    /** @var array<int, array{panes: array{sharp: MTLTexture, samples: array<int, MTLTexture>}, images: list<array{MTLTexture, int, int}>}> The last frame's glass backdrops, by their place in the frame, kept while Lowering says so. */
    private array $glassKept = [];

    private ?MTLRenderPipelineState $filterPipeline = null;

    /** @var array<int, MTLTexture> Glyph atlases by number (ATLAS), kept across draws until remade or released. */
    private array $atlases = [];

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
    /** A filter pass over more pixels than this is committed and waited for before the next one. */
    private const int SPLIT_PIXELS = 262144;

    /** The most bytes of pass images kept (all sizes together) for the chains that follow; an older size goes first. */
    private const int POOL_BYTES = 134217728;

    /** @var array<string, list<MTLTexture>> Free pass images by "w x h", the size used last at the end. */
    private array $filterPool = [];

    /** Bytes of the images in $filterPool. */
    private int $filterBytes = 0;

    /** Source textures kept for each size, and the sizes kept. */
    private const int UPLOADS_PER_SIZE = 8;

    private const int UPLOAD_SIZES = 24;

    /** @var list<array{string, MTLTexture}> The source textures this frame took: [pool key, texture]; they go back to $uploads once it is committed. */
    private array $uploaded = [];

    /** @var array<string, list<array{MTLTexture, ?MTLCommandBuffer}>> Source textures by "w x h x bytes": kept after the frame that read them, free once its command buffer completed. */
    private array $uploads = [];

    /** A 1x1 transparent texture for the inputs a pass does not read. */
    private ?MTLTexture $blank = null;

    /** The command buffer draw() is encoding, and its open encoder: what abandon() closes when an exception ends the frame. */
    private ?MTLCommandBuffer $building = null;

    private ?MTLRenderCommandEncoder $encoding = null;

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
            $this->library = $this->metal->newLibraryWithSourceOptionsError(file_get_contents(self::SHADERS), null, $error)
                ?? throw MetalException::fromError($error);
        } catch (NSException $e) {
            throw new DrawingException("metal: the shaders did not compile on {$this->metal->name()}: ".$e->getMessage(), 0, MetalException::fromException($e));
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
        $this->slots = [];
        $this->filterPipeline = null;
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
        $this->layer->setColorspace($this->colorspace);
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

        // ext-metal writes into an address only: an ext-fb buffer of $bytes/4 RGBA8 cells per texel holds the rows.
        $rows = new FbBuffer(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), $region->width * intdiv($bytes, 4), $region->height);
        $texture->getBytesBytesPerRowFromRegionMipmapLevel($rows->pointer(), $region->width * $bytes, self::region($region), 0);

        return $rows->bytes();
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

    /** Nothing stays on the GPU when the device goes: the last command buffer is waited for (a failure then has nobody to tell). */
    public function __destruct()
    {
        try {
            $this->finish();
        } catch (\Throwable) {
        }
    }

    /** Ends the open encoder and commits the frame draw() was building, so no encoder or buffer is left open or dropped half made. */
    private function abandon(): void
    {
        try {
            $this->encoding?->endEncoding();
            if ($this->building?->status() === MTLCommandBufferStatus::NOT_ENQUEUED) {
                $this->building->commit();
                $this->last = $this->building;
            }
        } catch (\Throwable) {
        }
        $this->encoding = $this->building = null;
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
            $error = $this->last->error()?->localizedDescription() ?? 'no error given';
            $this->last = null;

            throw new DrawingException("metal: a frame failed on the GPU: {$error}.");
        }
        $this->last = null;
    }

    /** The operations draw() handles. */
    private const array OPS = [Op::CLEAR, Op::SCISSOR, Op::SOLID, Op::STENCIL_FILL, Op::COVER, Op::COVER_GRADIENT, Op::RECTS, Op::ELLIPSE, Op::RING, Op::UPLOAD, Op::IMAGE, Op::ROUNDRECT, Op::ROUNDRING, Op::SHADOW, Op::ATLAS, Op::MASK, Op::GLYPHS, Op::TARGET, Op::BACKDROP, Op::FILTER, Op::LAYER_SOURCE, Op::GLASS_BACKDROP, Op::GLASS];

    /**
     * Every operation of $list is one draw() handles: checked before any GPU work, so an unknown one fails the
     * frame loudly instead of being skipped.
     *
     * @throws DrawingException On the first operation it does not handle.
     */
    private static function known(DrawList $list): void
    {
        foreach ($list->operations as $operation) {
            if (! in_array($operation[0], self::OPS, true)) {
                $op = $operation[0] instanceof Op ? $operation[0]->name : get_debug_type($operation[0]);

                throw new DrawingException("metal: unknown draw op {$op}");
            }
        }
    }

    public function draw(DrawList $list): void
    {
        try {
            $this->encode($list);
        } catch (\Throwable $e) {
            $this->abandon();

            throw $e;
        }
    }

    private function encode(DrawList $list): void
    {
        $target = $this->framebuffer ?? throw new DrawingException('metal: target() comes before draw().');
        $width = $target->width();
        $height = $target->height();
        self::known($list);

        // The list's vertices, then one quad over the whole target for CLEAR and the restore.
        $whole = $list->vertexCount();
        $vertices = $list->vertices.self::quad(0.0, 0.0, (float) $width, (float) $height);
        $buffer = $this->metal->newBufferWithBytesLengthOptions($vertices, strlen($vertices), MTLResourceOptions::STORAGE_MODE_SHARED)
            ?? throw new DrawingException('metal: no vertex buffer could be made.');

        // both live in properties too (references), so abandon() can close them if an exception ends the frame
        $commands = &$this->building;
        $commands = $this->commands();
        $this->uploaded = [];
        if ($this->stale) {
            $this->restore($commands, $buffer, $whole, $width, $height);
        }

        $encoder = &$this->encoding;
        $encoder = $commands->renderCommandEncoderWithDescriptor($this->pass(MTLLoadAction::LOAD)) ?? throw new DrawingException('metal: no render encoder could be made.');
        $this->prepare($encoder, $buffer, $width, $height);
        $light = $this->light(false);
        $encoder->setFragmentBytesLengthAtIndex($light, strlen($light), 1);
        /** @var array<int, bool> $floats Whether each texture number holds half floats. */
        $floats = [];
        $scissor = new MTLScissorRect(0, 0, $width, $height);
        /** @var array<int, MTLTexture> $textures */
        $textures = [];
        /** @var array{sharp: MTLTexture, samples: array<int, MTLTexture>}|null $panes The backdrop of the panes drawn now. */
        $panes = null;
        /** @var list<array{MTLTexture, int, int}> $glassImages Glass levels no frame keeps any more, back to the pool once this one is committed. */
        $glassImages = [];
        /** @var array<int, true> $glassUsed The backdrops this frame drew, by place. */
        $glassUsed = [];

        $current = 0;
        /** Begins a pass over a target: its colour loaded or cleared, the per-pass state set again. */
        $begin = function (int $slot, MTLLoadAction $load) use (&$commands, $buffer, $width, $height, &$light): MTLRenderCommandEncoder {
            $encoder = $commands->renderCommandEncoderWithDescriptor($this->pass($load, $slot)) ?? throw new DrawingException('metal: no render encoder could be made.');
            try {
                $this->prepare($encoder, $buffer, $width, $height);
                $encoder->setFragmentBytesLengthAtIndex($light, strlen($light), 1);
            } catch (\Throwable $e) {
                $encoder->endEncoding();

                throw $e;
            }

            return $encoder;
        };

        foreach ($list->operations as $operation) {
            switch ($operation[0]) {
                case Op::TARGET:
                    // draws go to another target from here: a layer's own (cleared), or back to an enclosing one
                    [, $current, $cleared] = $operation;
                    $this->ensureSlot($current);
                    $encoder->endEncoding();
                    $encoder = null;
                    $encoder = $begin($current, is_null($cleared) ? MTLLoadAction::LOAD : MTLLoadAction::CLEAR);
                    $scissor = new MTLScissorRect(0, 0, $width, $height);
                    break;

                case Op::BACKDROP:
                    // [, $from, $into, $region]: the region of one target copied into another, no blending
                    [, $from, $into, $region] = $operation;
                    $this->ensureSlot($into);
                    $encoder->endEncoding();
                    $encoder = null;
                    $this->copy($commands, $this->slotTexture($from), $this->slotTexture($into), $region);
                    $encoder = $begin($current, MTLLoadAction::LOAD);
                    $encoder->setScissorRect($scissor);
                    break;

                case Op::FILTER:
                    [, $into, $region, $chain, $premultiplied, $maps, $tables] = $operation;
                    $this->ensureSlot($into);
                    $encoder->endEncoding();
                    $encoder = null;
                    $this->filter($commands, $into, $region, FilterPlan::of($chain, $premultiplied, $maps, $tables, $region->width, $region->height), $textures, $width, $height);
                    $encoder = $begin($current, MTLLoadAction::LOAD);
                    $encoder->setScissorRect($scissor);
                    break;

                case Op::GLASS_BACKDROP:
                    // [, $from, $region, $depths]: the region read once and taken down and up the pyramid, in passes of their own
                    [, $from, $region, $depths, $n, $keep] = $operation;
                    $glassUsed[$n] = true;
                    if ($keep && isset($this->glassKept[$n])) {
                        // nothing under the region changed since it was made: the pyramid as it is
                        $panes = $this->glassKept[$n]['panes'];
                        break;
                    }
                    $encoder->endEncoding();
                    $encoder = null;
                    $made = [];
                    $panes = $this->glassBackdrop($commands, $this->slotTexture($from), $region, $depths, $made);
                    array_push($glassImages, ...($this->glassKept[$n]['images'] ?? []));
                    $this->glassKept[$n] = ['panes' => $panes, 'images' => $made];
                    $encoder = $begin($current, MTLLoadAction::LOAD);
                    $encoder->setScissorRect($scissor);
                    break;

                case Op::GLASS:
                    [, $first, $geometry, $look, $depth, $region] = $operation;
                    if ($panes === null) {
                        break;
                    }
                    [$frost, $refraction, $bevel, $saturation, $glow, $grain, $tint, $rim, $alpha] = $look;
                    $encoder->setRenderPipelineState($this->pipeline('glass'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $encoder->setFragmentTextureAtIndex($panes['samples'][$depth], 0);
                    $encoder->setFragmentTextureAtIndex($panes['sharp'], 1);
                    $bytes = pack('g16', $geometry[0], $geometry[1], $geometry[2], $geometry[3], ...$geometry[4], ...[$frost, $refraction, $bevel, $saturation, $glow, $grain, (float) $region->x, (float) $region->y])
                        .pack('V12', ($tint >> 24) & 0xFF, ($tint >> 16) & 0xFF, ($tint >> 8) & 0xFF, $tint & 0xFF, ($rim >> 24) & 0xFF, ($rim >> 16) & 0xFF, ($rim >> 8) & 0xFF, $rim & 0xFF, $alpha, 2, $frost < 1.0 ? 1 : 0, 0);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    break;

                case Op::LAYER_SOURCE:
                    // the target's texture is the image source the IMAGE that follows reads
                    [, $texture, $slot] = $operation;
                    $this->ensureSlot($slot);
                    $textures[$texture] = $this->slotTexture($slot);
                    $floats[$texture] = false;
                    break;

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

                case Op::COVER_GRADIENT:
                    // As COVER, in the gradient: the ramp is the source texture, the shader reads its entry for each sample.
                    [, $first, $texture, $kind, $inverse, $spread] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('gradient'));
                    $encoder->setDepthStencilState($this->stencilState('cover'));
                    $encoder->setFragmentTextureAtIndex($textures[$texture], 0);
                    $bytes = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, 0.0, 0.0).pack('V4', $kind, $spread, 0, 0);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    $encoder->setStencilReferenceValue(0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
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

                case Op::ROUNDRECT:
                case Op::ROUNDRING:
                    $ring = $operation[0] === Op::ROUNDRING;
                    [, $first, $x0, $y0, $x1, $y1, $radii] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('roundbox'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $bytes = self::paintBytes($operation[$ring ? 8 : 7]).pack('g12', $x0, $y0, $x1, $y1, ...$radii, ...[$ring ? $operation[7] : 0.0, $ring ? 1.0 : 0.0, 0.0, 0.0]);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    break;

                case Op::SHADOW:
                    [, $first, $x0, $y0, $x1, $y1, $radius, $blur, $rgba] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('shade'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $bytes = self::paintBytes($rgba).pack('g12', $x0, $y0, $x1, $y1, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, $blur, $radius);
                    $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    // one sample keeps a pixel when its centre is in: hard edges (GpuRenderingEngine::samples())
                    $hard = pack('V', $this->samples === 1 ? 1 : 0);
                    $encoder->setFragmentBytesLengthAtIndex($hard, strlen($hard), 2);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, 6);
                    break;

                case Op::ATLAS:
                    // A new texture's bytes are undefined: zero it. A frame in flight keeps the old one alive.
                    [, $atlas, $atlasWidth, $atlasHeight] = $operation;
                    $this->atlases[$atlas] = $this->texture(MTLPixelFormat::R8_UNORM, $atlasWidth, $atlasHeight, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
                    $this->atlases[$atlas]->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region(Region::wholeSurface($atlasWidth, $atlasHeight)), 0, str_repeat("\0", $atlasWidth * $atlasHeight), $atlasWidth);
                    break;

                case Op::MASK:
                    // Only regions no earlier frame drew from: frames in flight read what they read before.
                    [, $atlas, $region, $bytes] = $operation;
                    $this->atlases[$atlas]->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region($region), 0, $bytes, $region->width);
                    break;

                case Op::GLYPHS:
                    [, $first, $count, $texels, $atlas, $rgba] = $operation;
                    $this->paint($encoder, 'glyph', 'plain', $rgba);
                    $encoder->setFragmentTextureAtIndex($this->atlases[$atlas], 0);
                    // vertex_id runs from $first, so texels[vid] must land on vertex $texels.
                    $encoder->setVertexBufferOffsetAtIndex($buffer, ($texels - $first) * 8, 2);
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $first, $count);
                    break;

                case Op::IMAGE:
                    [, $first, $texture, $inverse, $opacity, $filter, $tint, $window, $round] = $operation;
                    $encoder->setRenderPipelineState($this->pipeline('image'));
                    $encoder->setDepthStencilState($this->stencilState('plain'));
                    $encoder->setFragmentTextureAtIndex($textures[$texture], 0);
                    $bytes = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, (float) $textures[$texture]->width(), (float) $textures[$texture]->height())
                        .pack('V4', $opacity, $filter === Filter::LINEAR ? 1 : 0, 0, 0)
                        .pack('V4', ($tint >> 24) & 0xFF, ($tint >> 16) & 0xFF, ($tint >> 8) & 0xFF, $tint & 0xFF)
                        .pack('g4', ...($window ?? [0.0, 0.0, 0.0, 0.0]))
                        .pack('g8', ...($round === null ? [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0] : [$round[0], $round[1], $round[2], $round[3], ...$round[4]]))
                        .pack('V4', $round === null ? 0 : 1, $window === null ? 0 : 1, 0, 0);
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
        $encoder = null;
        $commands->commit();
        $this->last = $commands;
        $this->keepUploads($commands);
        $commands = null;
        // backdrops this frame no longer drew go too; the queue runs in order, so a later frame that takes these from the
        // pool writes them after this one has read them
        foreach (array_diff_key($this->glassKept, $glassUsed) as $n => $kept) {
            array_push($glassImages, ...$kept['images']);
            unset($this->glassKept[$n]);
        }
        foreach ($glassImages as [$image, $w, $h]) {
            $this->releaseImage($image, $w, $h);
        }
    }

    /**
     * The backdrop under a list's glass panes: $region of $target copied out once, taken down the dual-filter pyramid to
     * the deepest depth asked, and up to half size for each depth: small passes in the frame's command buffer, each over a
     * pooled level of its own size.
     * shortcut: rebuilt every frame the panes are drawn; upgrade by keeping it while nothing under the region changed.
     *
     * @param  list<int>  $depths
     * @param  list<array{MTLTexture, int, int}>  $images  The levels made, for the pool once the frame is committed.
     * @return array{sharp: MTLTexture, samples: array<int, MTLTexture>}
     */
    private function glassBackdrop(MTLCommandBuffer $commands, MTLTexture $target, Region $region, array $depths, array &$images): array
    {
        $pass = function (string $fragment, MTLTexture $source, int $w, int $h, array $ints) use ($commands, &$images): MTLTexture {
            $out = $this->image($w, $h);
            $images[] = [$out, $w, $h];
            $descriptor = MTLRenderPassDescriptor::renderPassDescriptor();
            $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
            $color->setTexture($out);
            $color->setLoadAction(MTLLoadAction::DONT_CARE);
            $color->setStoreAction(MTLStoreAction::STORE);
            $encoder = $commands->renderCommandEncoderWithDescriptor($descriptor) ?? throw new DrawingException('metal: no render encoder could be made.');
            try {
                $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $w, (float) $h, 0.0, 1.0));
                $encoder->setCullMode(MTLCullMode::NONE);
                $encoder->setRenderPipelineState($this->glassPipeline($fragment));
                $encoder->setFragmentTextureAtIndex($source, 0);
                $uniform = pack('l4', ...$ints);
                $encoder->setFragmentBytesLengthAtIndex($uniform, strlen($uniform), 0);
                $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 3);
            } finally {
                $encoder->endEncoding();
            }

            return $out;
        };

        $sharp = $pass('glass_capture', $target, $region->width, $region->height, [$region->x, $region->y, 0, 0]);
        $down = [[$sharp, $region->width, $region->height]];
        for ($k = 1, $deepest = max($depths); $k <= $deepest; $k++) {
            [$source, $w, $h] = $down[$k - 1];
            $down[$k] = [$pass('glass_down', $source, intdiv($w + 1, 2), intdiv($h + 1, 2), [$w, $h, 0, 0]), intdiv($w + 1, 2), intdiv($h + 1, 2)];
        }
        $samples = [];
        foreach ($depths as $depth) {
            [$up, $w, $h] = $down[$depth];
            for ($k = $depth - 1; $k >= 1; $k--) {
                $up = $pass('glass_up', $up, $down[$k][1], $down[$k][2], [$w, $h, 0, 0]);
                [$w, $h] = [$down[$k][1], $down[$k][2]];
            }
            $samples[$depth] = $up;
        }

        return ['sharp' => $sharp, 'samples' => $samples];
    }

    /** A glass pass's pipeline: the filter triangle and one of the glass fragment functions, one RGBA8 attachment. */
    private function glassPipeline(string $fragment): MTLRenderPipelineState
    {
        if (isset($this->glassPipelines[$fragment])) {
            return $this->glassPipelines[$fragment];
        }
        try {
            $this->filterLibrary ??= $this->metal->newLibraryWithSourceOptionsError(file_get_contents(self::FILTER_SHADERS), null, $error)
                ?? throw MetalException::fromError($error);
            $descriptor = MTLRenderPipelineDescriptor::new();
            $descriptor->setVertexFunction($this->filterLibrary->newFunctionWithName('filter_place') ?? throw new DrawingException("metal: the filter shaders have no function 'filter_place'."));
            $descriptor->setFragmentFunction($this->filterLibrary->newFunctionWithName($fragment) ?? throw new DrawingException("metal: the filter shaders have no function '{$fragment}'."));
            $descriptor->setRasterSampleCount(1);
            $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
            $color->setPixelFormat(MTLPixelFormat::RGBA8_UNORM);
            $color->setWriteMask(MTLColorWriteMask::ALL);
            $color->setBlendingEnabled(false);

            return $this->glassPipelines[$fragment] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor, $error)
                ?? throw MetalException::fromError($error);
        } catch (NSException $e) {
            throw new DrawingException("metal: the '{$fragment}' pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, MetalException::fromException($e));
        } catch (MetalException $e) {
            throw new DrawingException("metal: the '{$fragment}' pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, $e);
        }
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
        $float = $source instanceof HdrImage && $this->space->isHdr();
        $texture = $this->uploadTexture($float, $width, $height);
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow($whole, 0, $float ? $source->rgba16f() : $source->toRgba8(), $width * ($float ? 8 : 4));

        return [$texture, $float];
    }

    /** A source texture of this size: one a finished frame read, or a new one. It joins $uploaded and goes back to the pool when the frame commits. */
    private function uploadTexture(bool $float, int $width, int $height): MTLTexture
    {
        $key = "{$width}x{$height}x".($float ? 8 : 4);
        foreach ($this->uploads[$key] ?? [] as $i => [$texture, $commands]) {
            if (is_null($commands) || $commands->status() === MTLCommandBufferStatus::COMPLETED) {
                unset($this->uploads[$key][$i]);
                $this->uploaded[] = [$key, $texture];

                return $texture;
            }
        }
        $texture = $this->texture($float ? MTLPixelFormat::RGBA16_FLOAT : MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $this->uploaded[] = [$key, $texture];

        return $texture;
    }

    /** The frame is committed: the source textures it took are free for a frame that starts after it completes. */
    private function keepUploads(MTLCommandBuffer $commands): void
    {
        foreach ($this->uploaded as [$key, $texture]) {
            $list = $this->uploads[$key] ?? [];
            $list[] = [$texture, $commands];
            // oldest first out: a size seen at most UPLOADS_PER_SIZE times in flight
            $this->uploads[$key] = array_slice($list, -self::UPLOADS_PER_SIZE);
            // the size just used goes last, so the sizes used longest ago are let go first
            $kept = $this->uploads[$key];
            unset($this->uploads[$key]);
            $this->uploads[$key] = $kept;
        }
        $this->uploaded = [];
        while (count($this->uploads) > self::UPLOAD_SIZES) {
            unset($this->uploads[array_key_first($this->uploads)]);
        }
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
        $descriptor->setVertexFunction($this->function($fragment === 'glyph' ? 'placeGlyph' : 'place'));
        $descriptor->setFragmentFunction($this->function($fragment));
        $descriptor->setRasterSampleCount($this->samples);
        $descriptor->setStencilAttachmentPixelFormat(MTLPixelFormat::STENCIL8);
        $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setPixelFormat($this->pixelFormat());
        $color->setWriteMask($fragment === 'mark' ? MTLColorWriteMask::NONE : MTLColorWriteMask::ALL);
        $color->setBlendingEnabled(false);

        try {
            return $this->pipelines[$fragment] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor, $error)
                ?? throw MetalException::fromError($error);
        } catch (NSException $e) {
            throw new DrawingException("metal: the '{$fragment}' pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, MetalException::fromException($e));
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
        $layer = CAMetalLayer::fromPointer($surface->handle('layer')) ?? throw new DrawingException('metal: the surface lent no layer.');
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
            $layer->setDrawableSize(new NSSize((float) $across, (float) $down));
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
        $this->atlases = [];
        $this->filterPool = [];
        $this->filterBytes = 0;
        $this->uploads = [];
        $this->uploaded = [];
        $this->blank = null;
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
            return $this->shows[$format->value] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor, $error)
                ?? throw MetalException::fromError($error);
        } catch (NSException $e) {
            throw new DrawingException("metal: the 'show' pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, MetalException::fromException($e));
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
    private function pass(MTLLoadAction $load, int $slot = 0): MTLRenderPassDescriptor
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $multisampled = $slot === 0 ? $this->multisampled : $this->slots[$slot]['multisampled'];
        if (is_null($multisampled)) {
            $color->setTexture($this->slotTexture($slot));
            $color->setStoreAction(MTLStoreAction::STORE);
        } else {
            $color->setTexture($multisampled);
            $color->setResolveTexture($this->slotTexture($slot));
            $color->setStoreAction(MTLStoreAction::STORE_AND_MULTISAMPLE_RESOLVE);
        }
        $color->setLoadAction($load);
        $color->setClearColor(new MTLClearColor(0.0, 0.0, 0.0, 0.0));

        $stencil = $pass->stencilAttachment();
        $stencil->setTexture($slot === 0 ? $this->stencil : $this->slots[$slot]['stencil']);
        $stencil->setLoadAction(MTLLoadAction::CLEAR);
        $stencil->setClearStencil(0);
        $stencil->setStoreAction(MTLStoreAction::DONT_CARE);

        return $pass;
    }

    /** The resolved texture of a target: the frame's own for 0, a layer target's otherwise. */
    private function slotTexture(int $slot): MTLTexture
    {
        return $slot === 0 ? $this->resolved : $this->slots[$slot]['resolved'];
    }

    /**
     * A layer target, made on first use: colour at the frame's samples, resolved, a stencil.
     *
     * @throws DrawingException On a target in an HDR colour space.
     */
    private function ensureSlot(int $slot): void
    {
        if ($slot === 0 || isset($this->slots[$slot])) {
            return;
        }
        if ($this->space->isHdr()) {
            throw new DrawingException('metal: layers are not drawn on a target in an HDR colour space yet.');
        }
        $width = $this->resolved->width();
        $height = $this->resolved->height();
        $pixels = $this->pixelFormat();
        $this->slots[$slot] = [
            'resolved' => $this->texture($pixels, $width, $height, 1, MTLStorageMode::PRIVATE, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value),
            'multisampled' => $this->samples === 4 ? $this->texture($pixels, $width, $height, 4, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value) : null,
            'stencil' => $this->texture(MTLPixelFormat::STENCIL8, $width, $height, $this->samples, MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value),
        ];
    }

    /** $region of one texture copied into the same place in another, no blending, by a blit encoder. */
    private function copy(MTLCommandBuffer $commands, MTLTexture $from, MTLTexture $into, Region $region): void
    {
        $blit = $commands->blitCommandEncoder() ?? throw new DrawingException('metal: no blit encoder could be made.');
        $origin = new MTLOrigin($region->x, $region->y, 0);
        $blit->copyFromTextureSourceSliceSourceLevelSourceOriginSourceSizeToTextureDestinationSliceDestinationLevelDestinationOrigin(
            $from, 0, 0, $origin, new MTLSize($region->width, $region->height, 1), $into, 0, 0, $origin,
        );
        $blit->endEncoding();
    }

    /**
     * A filter chain over a region of a layer target: FilterPlan's passes, each a draw of the filter program over the region
     * into a texture of its own, the last pass's straight pixels copied back into the target.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  array<int, MTLTexture>  $textures  Source textures by the numbers the list gave them.
     */
    private function filter(MTLCommandBuffer &$commands, int $slot, Region $region, array $plan, array $textures, int $width, int $height): void
    {
        if ($plan === []) {
            return;
        }
        $pipeline = $this->filterPipeline();
        $results = [0 => $this->slotTexture($slot)];
        $blank = $this->blank ??= $this->texture(MTLPixelFormat::RGBA8_UNORM, 1, 1, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $lastRead = FilterPlan::lastReads($plan);
        foreach ($plan as $i => $pass) {
            $output = $this->image($width, $height);
            $data = $blank;
            if ($pass['data'] !== []) {
                $rows = intdiv(count($pass['data']) + 511, 512);
                $data = $this->texture(MTLPixelFormat::RGBA8_UNORM, 512, $rows, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
                $data->replaceRegionMipmapLevelWithBytesBytesPerRow(self::region(new Region(0, 0, 512, $rows)), 0, pack('g*', ...array_pad($pass['data'], $rows * 512, 0.0)), 512 * 4);
            }
            $descriptor = MTLRenderPassDescriptor::renderPassDescriptor();
            $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
            $color->setTexture($output);
            $color->setLoadAction(MTLLoadAction::DONT_CARE);
            $color->setStoreAction(MTLStoreAction::STORE);
            $encoder = $commands->renderCommandEncoderWithDescriptor($descriptor) ?? throw new DrawingException('metal: no render encoder could be made.');
            try {
                $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0));
                $encoder->setCullMode(MTLCullMode::NONE);
                $encoder->setRenderPipelineState($pipeline);
                $encoder->setScissorRect(new MTLScissorRect($region->x, $region->y, $region->width, $region->height));
                foreach ([0 => $results[$pass['in']], 1 => $results[$pass['in2']], 2 => $data, 3 => $pass['map'] < 0 ? $blank : $textures[$pass['map']]] as $index => $texture) {
                    $encoder->setFragmentTextureAtIndex($texture, $index);
                }
                $p = array_pad($pass['params'], 8, 0.0);
                $uniform = pack('g16', (float) $region->x, (float) $region->y, (float) $region->right(), (float) $region->bottom(), (float) $pass['kind'], 0.0, 0.0, 0.0, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]);
                $encoder->setFragmentBytesLengthAtIndex($uniform, strlen($uniform), 0);
                $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 3);
            } catch (\Throwable $e) {
                $encoder->endEncoding();

                throw $e;
            }
            $encoder->endEncoding();
            $results[$i + 1] = $output;
            // an image no later pass reads goes back to the pool at once: a chain holds the images alive at one time, not one for each
            // pass (the queue runs commands in order, so a later pass writes an image after this one read it)
            foreach (array_unique([$pass['in'], $pass['in2']]) as $read) {
                if ($read > 0 && $lastRead[$read] === $i) {
                    $this->releaseImage($results[$read], $width, $height);
                    unset($results[$read]);
                }
            }
            // a pass over a large region may take a while: it goes to the GPU and is waited for, so one command buffer never carries a
            // whole heavy chain (the display watchdog counts the time of a command buffer)
            if ($region->width * $region->height > self::SPLIT_PIXELS && $i < count($plan) - 1) {
                $this->settle($commands);
                $commands = $this->commands();
            }
        }
        $this->copy($commands, $results[count($plan)], $this->slotTexture($slot), $region);
        $this->releaseImage($results[count($plan)], $width, $height);
    }

    /** A pass image of the target's size: a free one from the pool, or a new one. */
    private function image(int $width, int $height): MTLTexture
    {
        $key = "{$width}x{$height}";
        if (! empty($this->filterPool[$key])) {
            $this->filterBytes -= $width * $height * 4;

            return array_pop($this->filterPool[$key]);
        }

        return $this->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::PRIVATE, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value);
    }

    /** A pass image no pass reads any more, kept for the next chain; over POOL_BYTES the sizes used longest ago are let go first. */
    private function releaseImage(MTLTexture $texture, int $width, int $height): void
    {
        $key = "{$width}x{$height}";
        $list = $this->filterPool[$key] ?? [];
        $list[] = $texture;
        unset($this->filterPool[$key]);
        $this->filterPool[$key] = $list;
        $this->filterBytes += $width * $height * 4;
        while ($this->filterBytes > self::POOL_BYTES) {
            $oldest = array_key_first($this->filterPool);
            [$w, $h] = array_map('intval', explode('x', $oldest));
            array_shift($this->filterPool[$oldest]);
            $this->filterBytes -= $w * $h * 4;
            if ($this->filterPool[$oldest] === []) {
                unset($this->filterPool[$oldest]);
            }
        }
    }

    /** The filter program: a triangle and the filter fragment function, one RGBA8 colour attachment and nothing else. */
    private function filterPipeline(): MTLRenderPipelineState
    {
        if (! is_null($this->filterPipeline)) {
            return $this->filterPipeline;
        }
        try {
            $this->filterLibrary ??= $this->metal->newLibraryWithSourceOptionsError(file_get_contents(self::FILTER_SHADERS), null, $error)
                ?? throw MetalException::fromError($error);
            $descriptor = MTLRenderPipelineDescriptor::new();
            $descriptor->setVertexFunction($this->filterLibrary->newFunctionWithName('filter_place') ?? throw new DrawingException("metal: the filter shaders have no function 'filter_place'."));
            $descriptor->setFragmentFunction($this->filterLibrary->newFunctionWithName('filter_paint') ?? throw new DrawingException("metal: the filter shaders have no function 'filter_paint'."));
            $descriptor->setRasterSampleCount(1);
            $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
            $color->setPixelFormat(MTLPixelFormat::RGBA8_UNORM);
            $color->setWriteMask(MTLColorWriteMask::ALL);
            $color->setBlendingEnabled(false);

            return $this->filterPipeline = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor, $error)
                ?? throw MetalException::fromError($error);
        } catch (NSException $e) {
            throw new DrawingException("metal: the filter pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, MetalException::fromException($e));
        } catch (MetalException $e) {
            throw new DrawingException("metal: the filter pipeline was refused by {$this->metal->name()}: ".$e->getMessage(), 0, $e);
        }
    }

    /** Commits a command buffer and waits for it; a failure on the GPU is an exception, not a hang later. */
    private function settle(MTLCommandBuffer $commands): void
    {
        $commands->commit();
        $commands->waitUntilCompleted();
        if ($commands->status() === MTLCommandBufferStatus::ERROR) {
            $error = $commands->error()?->localizedDescription() ?? 'no error given';

            throw new DrawingException("metal: a filter pass failed on the GPU: {$error}.");
        }
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
