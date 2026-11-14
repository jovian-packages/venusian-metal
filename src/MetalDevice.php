<?php

namespace Jovian\Engines\Metal;

use CFData;
use CFRange;
use Closure;
use MTLRegion;
use MTLTextureUsage;
use NSView;
use Throwable;
use WeakMap;
use Surface\Contracts\Graphics\ActorStage;
use MTLBlendFactor;
use MTLBlendOperation;
use MTLBuffer;
use MTLCommandBuffer;
use MTLCommandBufferStatus;
use MTLCommandQueue;
use MTLCompareFunction;
use MTLDepthStencilDescriptor;
use MTLDepthStencilState;
use MTLLibrary;
use MTLPixelFormat;
use MTLRenderPipelineDescriptor;
use MTLRenderPipelineState;
use MTLResourceOptions;
use MTLStencilDescriptor;
use MTLStencilOperation;
use MTLStorageMode;
use MTLTexture;
use MTLTextureDescriptor;
use MTLTextureType;
use MTLColorWriteMask;
use NSException;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\Resource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Recording\Recorder;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GEPainter;
use Surface\Graphics\GERasterizer;
use Surface\Graphics\GERaycaster;
use Surface\Graphics\GraphicsEngine;

/**
 * The metal engine: one Metal device (the Mac's GPU) and one command queue, and what they make.
 * Every framebuffer and rasterizer it hands out works on that device.
 *
 * It draws on the GPU only: the CPU backend is not offered. Its raycaster walks the grid on the
 * GPU too.
 */
class MetalDevice extends GraphicsEngine
{
    /** Compiled as one library, in this order. */
    protected const array SHADERS = [__DIR__.'/../resources/shaders/engine.metal', __DIR__.'/../resources/shaders/filters.metal', __DIR__.'/../resources/shaders/raycast.metal'];

    /** How many submissions the GPU may be working through before the CPU waits for the oldest. */
    protected const int IN_FLIGHT = 3;

    /** @var list<MTLCommandBuffer> submitted and not yet known to be finished, oldest first */
    protected array $inFlight = [];

    /** Memory Metal copies texture bytes into, for bytes(). */
    protected ?CFData $memory = null;

    protected readonly \MTLDevice $metal;

    protected readonly MTLCommandQueue $queue;

    protected readonly MTLLibrary $library;

    /** @var array<string, MTLRenderPipelineState> by fragment function and sample count */
    protected array $pipelines = [];

    /** @var array<string, MTLDepthStencilState> by kind */
    protected array $stencils = [];

    /** Whether the GPU makes memoryless textures; null until asked. */
    protected ?bool $memoryless = null;

    /** @var WeakMap<NSView, MetalLayer> what it added to each view a stage lent it */
    protected WeakMap $layers;

    /**
     * @param \MTLDevice|null $metal null: the Mac's default GPU
     * @param Closure(): Recorder|null $recorder makes the recorder each painter records with; null: Rendering's default
     *
     * @throws MetalException When the Mac has no Metal device, or the shaders do not compile on it.
     * @throws GraphicsException When asked for the CPU backend.
     */
    public function __construct(Backend $backend = Backend::GPU, ?\MTLDevice $metal = null, protected readonly ?Closure $recorder = null)
    {
        if ($backend !== Backend::GPU) {
            throw GraphicsException::backendNotOffered('metal', $backend, [Backend::GPU]);
        }
        parent::__construct($backend);
        $this->layers = new WeakMap();

        $this->metal = $metal ?? MTLCreateSystemDefaultDevice() ?? throw MetalException::because('this Mac has no Metal device.');
        $this->queue = $this->metal->newCommandQueue() ?? throw MetalException::because('no command queue could be made.');
        try {
            $this->library = $this->metal->newLibraryWithSourceOptionsError(implode("\n", array_map('file_get_contents', self::SHADERS)), null, $error)
                ?? throw MetalException::from("the shaders did not compile on {$this->metal->name()}", $error);
        } catch (NSException $e) {
            throw MetalException::from("the shaders did not compile on {$this->metal->name()}", $e);
        }
    }

    public function name(): string
    {
        return 'metal';
    }

    /** The GPU it draws on, by the name macOS gives it. */
    public function modules(): array
    {
        return ['gpu' => $this->metal->name()];
    }

    public function supports(Resource $resource): bool
    {
        return in_array($resource, [Resource::FRAMEBUFFER, Resource::RASTERIZER, Resource::RAYCASTER, Resource::PAINTER], true);
    }

    /**
     * An RGBA8 texture on the GPU, with a four-sample twin that antialiased shapes are drawn into.
     *
     * @throws GraphicsException When the size is below 1x1, or $format is not RGBA8: the only format it holds.
     */
    public function framebuffer(int $width, int $height, ?FormatSpec $format = null): GEFramebuffer
    {
        if ($width < 1 || $height < 1) {
            throw GraphicsException::badSize($width, $height);
        }
        if (! is_null($format) && ! $format->equals(FormatSpec::rgba8())) {
            throw MetalException::because('a framebuffer holds RGBA8 only.');
        }

        return new MetalFramebuffer($this, $width, $height);
    }

    /**
     * A framebuffer of the stage's size. When the stage lends an NSView, frames are shown on it by
     * the GPU, in a layer of the engine's own over the view; otherwise as pixels, through the stage.
     *
     * @throws GraphicsException When the stage has no size yet.
     */
    public function attach(ActorStage $stage, ?FormatSpec $format = null): GEFramebuffer
    {
        /** @var MetalFramebuffer $framebuffer */
        $framebuffer = parent::attach($stage, $format);
        $view = $stage->nativeSurface();
        if ($view instanceof NSView) {
            $framebuffer->showIn($this->layers[$view] ??= new MetalLayer($this->metal, $view));
        }

        return $framebuffer;
    }

    /** Takes away the layers it added to the views stages lent it. */
    public function release(): void
    {
        // what the GPU is still drawing is finished before anything it draws into is let go
        $this->sync();
        foreach ($this->layers as $layer) {
            $layer->release();
        }
        $this->layers = new WeakMap();
    }

    public function rasterizer(Edges $edges = Edges::ANTIALIASED): GERasterizer
    {
        return new MetalRasterizer($this, $edges);
    }

    /** @throws GraphicsException When $onto is another engine's. */
    /**
     * A raycaster over $grid: its cells are held on the GPU.
     *
     * @throws MetalException When the grid is wider or taller than the GPU holds a texture (16384).
     */
    public function raycaster(Grid $grid): GERaycaster
    {
        return new MetalRaycaster($this, new MetalCaster($this, $grid));
    }

    public function painter(GEFramebuffer $onto): GEPainter
    {
        if ($onto->engine() !== $this || ! $onto instanceof MetalFramebuffer) {
            throw GraphicsException::foreignResource('metal', $onto->engine()->name());
        }

        return new MetalGEPainter($this, $onto, new MetalPainter($this, $onto, recorder: is_null($this->recorder) ? null : ($this->recorder)()));
    }

    /** @internal */
    public function metal(): \MTLDevice
    {
        return $this->metal;
    }

    /**
     * A texture on this device.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function texture(MTLPixelFormat $format, int $width, int $height, int $samples, MTLStorageMode $storage, int $usage): MTLTexture
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
            ?? throw MetalException::because("no {$width}x{$height} texture ({$format->name}, {$samples} samples) could be made.");
    }

    /**
     * A texture that only lives inside a render pass: it is cleared when the pass begins and not
     * kept when it ends (a stencil; a layer's four-sample colour, which is resolved and dropped).
     * On a GPU that has them (Apple's), it is memoryless and takes no memory at all.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function transient(MTLPixelFormat $format, int $width, int $height, int $samples): MTLTexture
    {
        $this->memoryless ??= $this->hasMemoryless();

        return $this->texture($format, $width, $height, $samples, $this->memoryless ? MTLStorageMode::MEMORYLESS : MTLStorageMode::PRIVATE, MTLTextureUsage::RENDER_TARGET->value);
    }

    /** Whether this GPU makes memoryless textures. */
    protected function hasMemoryless(): bool
    {
        try {
            $this->texture(MTLPixelFormat::STENCIL8, 1, 1, 1, MTLStorageMode::MEMORYLESS, MTLTextureUsage::RENDER_TARGET->value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A shared buffer holding $bytes.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function buffer(string $bytes): MTLBuffer
    {
        return $this->metal->newBufferWithBytesLengthOptions($bytes, strlen($bytes), MTLResourceOptions::STORAGE_MODE_SHARED)
            ?? throw MetalException::because('no vertex buffer could be made.');
    }

    /** @internal @throws MetalException */
    public function commands(): MTLCommandBuffer
    {
        return $this->queue->commandBuffer() ?? throw MetalException::because('no command buffer could be made.');
    }

    /**
     * Hands $commands to the GPU and returns: the CPU goes on to the next frame while the GPU draws this one.
     * The queue runs command buffers in the order they were submitted, so anything submitted later sees what
     * these drew. At most IN_FLIGHT are left running: past that, the oldest is waited for, which keeps the CPU
     * from queueing frames faster than the GPU finishes them.
     *
     * @internal
     *
     * @throws MetalException When an earlier submission failed on the GPU.
     */
    public function submit(MTLCommandBuffer $commands): void
    {
        $commands->commit();
        $this->inFlight[] = $commands;
        while (count($this->inFlight) > self::IN_FLIGHT) {
            self::finished(array_shift($this->inFlight));
        }
    }

    /**
     * Waits until the GPU has finished everything submitted. Called before the CPU reads a texture's bytes or
     * writes one a submission may still be using; nothing else waits.
     *
     * @internal
     *
     * @throws MetalException When a submission failed on the GPU.
     */
    public function sync(): void
    {
        while ($this->inFlight !== []) {
            self::finished(array_shift($this->inFlight));
        }
    }

    /**
     * $region of a shared $texture as bytes, $rowBytes a row. Metal copies a texture into memory at an
     * address: a mutable CFData is that memory, kept from call to call and grown when a read needs more.
     * Wait for what draws the texture first (settle).
     *
     * @internal
     */
    public function bytes(MTLTexture $texture, int $rowBytes, MTLRegion $region): string
    {
        $total = $rowBytes * $region->size->height;
        $this->memory ??= CFData::createMutable(null, 0);
        if ($this->memory->getLength() < $total) {
            $this->memory->setLength($total);
        }

        $texture->getBytesBytesPerRowFromRegionMipmapLevel($this->memory->getMutableBytePtr(), $rowBytes, $region, 0);
        $this->memory->getBytes(new CFRange(0, $total), $bytes);

        return $bytes;
    }

    /**
     * submit() then sync(): for commands whose result the CPU reads straight away.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function settle(MTLCommandBuffer $commands): void
    {
        $this->submit($commands);
        $this->sync();
    }

    /** @throws MetalException */
    protected static function finished(MTLCommandBuffer $commands): void
    {
        $commands->waitUntilCompleted();
        if ($commands->status() === MTLCommandBufferStatus::ERROR) {
            throw MetalException::because('the GPU failed a draw: '.($commands->error()?->localizedDescription() ?? 'no reason given').'.');
        }
    }

    /**
     * The pipeline for one fragment function at $samples: RGBA8 colour and an 8-bit stencil. With
     * $blend, the fragment's premultiplied colour goes over what is there (source + destination ×
     * (1 − source alpha)); without, it replaces it. Colour writes are off for 'mark'.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function pipeline(string $fragment, int $samples, bool $blend = true, MTLPixelFormat $format = MTLPixelFormat::RGBA8_UNORM): MTLRenderPipelineState
    {
        if (isset($this->pipelines["{$fragment}.{$samples}.".(int) $blend.".{$format->name}"])) {
            return $this->pipelines["{$fragment}.{$samples}.".(int) $blend.".{$format->name}"];
        }

        $descriptor = MTLRenderPipelineDescriptor::new();
        $descriptor->setVertexFunction($this->function('place'));
        $descriptor->setFragmentFunction($this->function($fragment));
        $descriptor->setRasterSampleCount($samples);
        $descriptor->setStencilAttachmentPixelFormat(MTLPixelFormat::STENCIL8);
        $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setPixelFormat($format);
        $color->setWriteMask($fragment === 'mark' ? MTLColorWriteMask::NONE : MTLColorWriteMask::ALL);
        $color->setBlendingEnabled($blend && $fragment !== 'mark');
        if ($blend) {
            $color->setRgbBlendOperation(MTLBlendOperation::ADD);
            $color->setAlphaBlendOperation(MTLBlendOperation::ADD);
            $color->setSourceRGBBlendFactor(MTLBlendFactor::ONE);
            $color->setSourceAlphaBlendFactor(MTLBlendFactor::ONE);
            $color->setDestinationRGBBlendFactor(MTLBlendFactor::ONE_MINUS_SOURCE_ALPHA);
            $color->setDestinationAlphaBlendFactor(MTLBlendFactor::ONE_MINUS_SOURCE_ALPHA);
        }

        try {
            return $this->pipelines["{$fragment}.{$samples}.".(int) $blend.".{$format->name}"] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor, $error)
                ?? throw MetalException::from("{$this->metal->name()} refused the '{$fragment}' pipeline", $error);
        } catch (NSException $e) {
            throw MetalException::from("{$this->metal->name()} refused the '{$fragment}' pipeline", $e);
        }
    }

    /**
     * The pipeline of a filter pass: the 'cover' vertex function (one triangle over the viewport), $fragment, one
     * sample, $format (RGBA8 unless said) written as it is, no stencil.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function filterPipeline(string $fragment, MTLPixelFormat $format = MTLPixelFormat::RGBA8_UNORM): MTLRenderPipelineState
    {
        $key = "filter.{$fragment}.{$format->name}";
        if (isset($this->pipelines[$key])) {
            return $this->pipelines[$key];
        }
        $descriptor = MTLRenderPipelineDescriptor::new();
        $descriptor->setVertexFunction($this->function('cover'));
        $descriptor->setFragmentFunction($this->function($fragment));
        $descriptor->setRasterSampleCount(1);
        $color = $descriptor->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setPixelFormat($format);
        $color->setWriteMask(MTLColorWriteMask::ALL);
        $color->setBlendingEnabled(false);

        try {
            return $this->pipelines[$key] = $this->metal->newRenderPipelineStateWithDescriptorError($descriptor, $error)
                ?? throw MetalException::from("{$this->metal->name()} refused the '{$fragment}' filter pipeline", $error);
        } catch (NSException $e) {
            throw MetalException::from("{$this->metal->name()} refused the '{$fragment}' filter pipeline", $e);
        }
    }

    /**
     * plain: the stencil untouched. winding: front faces count up, back faces down (non-zero).
     * invert: every face flips it (even-odd). cover: draw where it is not zero, and zero it.
     *
     * @internal
     *
     * @throws MetalException
     */
    public function stencil(string $kind): MTLDepthStencilState
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
            ?? throw MetalException::because("the '{$kind}' stencil state could not be made.");
    }

    /** @throws MetalException */
    protected function function(string $name): \MTLFunction
    {
        return $this->library->newFunctionWithName($name) ?? throw MetalException::because("the shaders have no function '{$name}'.");
    }
}
