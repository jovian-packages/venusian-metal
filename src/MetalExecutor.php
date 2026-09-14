<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal;

use Jovian\Bindings\Metal\Enums\MTLIndexType;
use Jovian\Bindings\Metal\Enums\MTLLoadAction;
use Jovian\Bindings\Metal\Enums\MTLPixelFormat;
use Jovian\Bindings\Metal\Enums\MTLPrimitiveType;
use Jovian\Bindings\Metal\Enums\MTLResourceOptions;
use Jovian\Bindings\Metal\Enums\MTLStorageMode;
use Jovian\Bindings\Metal\Enums\MTLStoreAction;
use Jovian\Bindings\Metal\Enums\MTLTextureUsage;
use Jovian\Bindings\Metal\MTL\MTLBuffer;
use Jovian\Bindings\Metal\MTL\MTLCommandQueue;
use Jovian\Bindings\Metal\MTL\MTLDevice;
use Jovian\Bindings\Metal\MTL\MTLRenderCommandEncoder;
use Jovian\Bindings\Metal\MTL\MTLRenderPassDescriptor;
use Jovian\Bindings\Metal\MTL\MTLRenderPipelineState;
use Jovian\Bindings\Metal\MTL\MTLTexture;
use Jovian\Bindings\Metal\MTL\MTLTextureDescriptor;
use Jovian\Bindings\Metal\QuartzCore\CAMetalLayer;
use Jovian\Bindings\Metal\Values\CGSize;
use Jovian\Bindings\Metal\Values\MTLClearColor;
use Jovian\Bindings\Metal\Values\MTLOrigin;
use Jovian\Bindings\Metal\Values\MTLRegion;
use Jovian\Bindings\Metal\Values\MTLScissorRect;
use Jovian\Bindings\Metal\Values\MTLSize;
use Jovian\Bindings\Metal\Values\MTLViewport;
use Jovian\Venusian\Metal\Contracts\MetalDrawing;
use Jovian\Venusian\Metal\Exceptions\MetalDrawingException;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\ExecutorCapabilities;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

/**
 * One CAMetalLayer, one open render encoder per frame, every constant
 * staged through an MTLBuffer. Instances on drawIndexed are ignored —
 * ext-metal 0.8 has no indexed-instanced call.
 */
final class MetalExecutor implements Executor, MetalDrawing
{
    private ?CAMetalLayer $layer;

    private int $pixelWidth;

    private int $pixelHeight;

    private int $viewportX = 0;

    private int $viewportY = 0;

    private int $viewportWidth;

    private int $viewportHeight;

    private ?MTLScissorRect $scissor = null;

    private ?MetalFrame $frame = null;

    /** @var array<int, MTLTexture> */
    private array $textures = [];

    private int $nextTextureId = 1;

    private bool $released = false;

    public function __construct(
        private readonly MetalContext $context,
        CAMetalLayer $layer,
        int $pixelWidth,
        int $pixelHeight,
    ) {
        $this->layer = $layer;
        $this->pixelWidth = max(1, $pixelWidth);
        $this->pixelHeight = max(1, $pixelHeight);
        $this->viewportWidth = $this->pixelWidth;
        $this->viewportHeight = $this->pixelHeight;
    }

    public static function declaredCapabilities(): ExecutorCapabilities
    {
        return new ExecutorCapabilities(
            blending: true,
            depth: false,
            instancing: true,
            readback: true,
            max_texture_size: 16384,
        );
    }

    public function capabilities(): ExecutorCapabilities
    {
        return self::declaredCapabilities();
    }

    public function device(): MTLDevice
    {
        return $this->context->device;
    }

    public function queue(): MTLCommandQueue
    {
        return $this->context->queue;
    }

    public function layer(): CAMetalLayer
    {
        if (is_null($this->layer)) {
            throw new MetalDrawingException('executor has been released');
        }

        return $this->layer;
    }

    public function encoder(): ?MTLRenderCommandEncoder
    {
        return $this->frame?->encoder;
    }

    public function pipeline(): MTLRenderPipelineState
    {
        return $this->context->pipeline;
    }

    public function textureBox(TextureHandle $texture): ?MTLTexture
    {
        return $this->textures[$texture->id] ?? null;
    }

    public function resize(int $width, int $height): void
    {
        $this->assertAlive();
        $this->pixelWidth = max(1, $width);
        $this->pixelHeight = max(1, $height);
        $this->layer()->setDrawableSize(new CGSize((float) $this->pixelWidth, (float) $this->pixelHeight));
    }

    /** @return array{int, int} */
    public function drawableSize(): array
    {
        return [$this->pixelWidth, $this->pixelHeight];
    }

    public function beginFrame(Color $clear): bool
    {
        $this->assertAlive();
        if (! is_null($this->frame)) {
            $this->endFrame();
        }

        $drawable = $this->layer()->nextDrawable();
        if (is_null($drawable)) {
            return false;
        }

        $drawableTexture = $drawable->texture();
        if (is_null($drawableTexture)) {
            return false;
        }

        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        if (is_null($pass)) {
            throw new MetalDrawingException('render pass descriptor missing');
        }
        $passAttachments = $pass->colorAttachments();
        if (is_null($passAttachments)) {
            throw new MetalDrawingException('pass colorAttachments missing');
        }
        $colorAttachment = $passAttachments->objectAtIndexedSubscript(0);
        if (is_null($colorAttachment)) {
            throw new MetalDrawingException('pass colour attachment 0 missing');
        }
        $colorAttachment->setTexture($drawableTexture->handle);
        $colorAttachment->setLoadAction(MTLLoadAction::CLEAR);
        $colorAttachment->setStoreAction(MTLStoreAction::STORE);
        $colorAttachment->setClearColor(new MTLClearColor($clear->red, $clear->green, $clear->blue, $clear->alpha));

        $commandBuffer = $this->context->queue->commandBuffer();
        if (is_null($commandBuffer)) {
            throw new MetalDrawingException('command buffer creation failed');
        }
        $encoder = $commandBuffer->renderCommandEncoderWithDescriptor($pass->handle);
        if (is_null($encoder)) {
            throw new MetalDrawingException('render encoder creation failed');
        }

        $this->frame = new MetalFrame(
            $drawable,
            $drawableTexture,
            $pass,
            $passAttachments,
            $colorAttachment,
            $commandBuffer,
            $encoder,
        );

        $this->viewportX = 0;
        $this->viewportY = 0;
        $this->viewportWidth = $this->pixelWidth;
        $this->viewportHeight = $this->pixelHeight;
        $this->scissor = null;
        $this->bindPipelineAndViewport($encoder);

        return true;
    }

    public function viewport(int $x, int $y, int $width, int $height): void
    {
        $this->assertInFrame('viewport');
        $this->viewportX = $x;
        $this->viewportY = $y;
        $this->viewportWidth = max(1, $width);
        $this->viewportHeight = max(1, $height);
        $this->frame->encoder->setViewport($this->currentViewport());
    }

    public function scissor(int $x, int $y, int $width, int $height): void
    {
        $this->assertInFrame('scissor');
        $this->scissor = $this->clampedScissor($x, $y, $width, $height);
        $this->frame->encoder->setScissorRect($this->scissor);
    }

    public function unscissor(): void
    {
        $this->assertInFrame('unscissor');
        $this->scissor = $this->clampedScissor(0, 0, $this->pixelWidth, $this->pixelHeight);
        $this->frame->encoder->setScissorRect($this->scissor);
    }

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        $this->assertAlive();
        $width = max(1, $width);
        $height = max(1, $height);

        $descriptor = MTLTextureDescriptor::texture2DDescriptorWithPixelFormatWidthHeightMipmapped(
            MTLPixelFormat::RGBA8_UNORM,
            $width,
            $height,
            false,
        );
        if (is_null($descriptor)) {
            throw new MetalDrawingException('texture descriptor creation failed');
        }
        $descriptor->setUsage(MTLTextureUsage::SHADER_READ->value);
        $descriptor->setStorageMode(MTLStorageMode::SHARED);

        $texture = $this->context->device->newTextureWithDescriptor($descriptor->handle);
        if (is_null($texture)) {
            throw new MetalDrawingException('texture creation failed');
        }
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow(
            new MTLRegion(0, 0, 0, $width, $height, 1),
            0,
            $rgba8,
            $width * 4,
        );

        $id = $this->nextTextureId++;
        $this->textures[$id] = $texture;

        return new TextureHandle($id, $width, $height);
    }

    public function releaseTexture(TextureHandle $texture): void
    {
        unset($this->textures[$texture->id]);
    }

    public function draw(
        Topology $topology,
        string $vertices,
        int $vertex_count,
        Transform $transform,
        ?TextureHandle $texture = null,
        int $instances = 1,
    ): void {
        $this->assertInFrame('draw');
        if ($vertex_count < 1) {
            return;
        }

        $this->bindDraw($vertices, $transform, $texture);
        $primitive = $this->primitive($topology);
        $encoder = $this->frame->encoder;

        if ($instances > 1) {
            $encoder->drawPrimitivesVertexStartVertexCountInstanceCount($primitive, 0, $vertex_count, $instances);

            return;
        }

        $encoder->drawPrimitivesVertexStartVertexCount($primitive, 0, $vertex_count);
    }

    public function drawIndexed(
        Topology $topology,
        string $vertices,
        int $vertex_count,
        string $indices,
        int $index_count,
        Transform $transform,
        ?TextureHandle $texture = null,
        int $instances = 1,
    ): void {
        $this->assertInFrame('drawIndexed');
        if ($index_count < 1) {
            return;
        }

        $this->bindDraw($vertices, $transform, $texture);
        $indexBuffer = $this->stageBuffer($indices);
        $this->frame->encoder->drawIndexedPrimitivesIndexCountIndexTypeIndexBufferIndexBufferOffset(
            $this->primitive($topology),
            $index_count,
            MTLIndexType::U_INT16,
            $indexBuffer->handle,
            0,
        );
    }

    public function readPixels(): string
    {
        $this->assertInFrame('readPixels');

        $frame = $this->frame;
        $frame->encoder->endEncoding();

        $width = $this->pixelWidth;
        $height = $this->pixelHeight;
        $bytesPerRow = $width * 4;
        $byteCount = $bytesPerRow * $height;
        $shared = MTLResourceOptions::MTL_RESOURCE_CPU_CACHE_MODE_DEFAULT_CACHE->value;
        $readback = $this->context->device->newBufferWithLengthOptions($byteCount, $shared);
        if (is_null($readback)) {
            throw new MetalDrawingException('readback buffer creation failed');
        }

        $origin = new MTLOrigin(0, 0, 0);
        $size = new MTLSize($width, $height, 1);
        $blit = $frame->commandBuffer->blitCommandEncoder();
        if (is_null($blit)) {
            throw new MetalDrawingException('blit encoder creation failed');
        }
        $blit->copyFromTextureSourceSliceSourceLevelSourceOriginSourceSizeToBufferDestinationOffsetDestinationBytesPerRowDestinationBytesPerImage(
            $frame->drawableTexture->handle,
            0,
            0,
            $origin,
            $size,
            $readback->handle,
            0,
            $bytesPerRow,
            $byteCount,
        );
        $blit->endEncoding();
        $frame->blit = $blit;
        $frame->readback = $readback;

        $frame->commandBuffer->commit();
        $frame->commandBuffer->waitUntilCompleted();

        $bgra = $readback->contentsBytes(0, $byteCount);
        $this->reopenEncoder(MTLLoadAction::LOAD);

        return $this->swizzleBgraToRgba($bgra);
    }

    public function endFrame(): void
    {
        if (is_null($this->frame)) {
            return;
        }

        $frame = $this->frame;
        $frame->encoder->endEncoding();
        $frame->commandBuffer->presentDrawable($frame->drawable->handle);
        $frame->commandBuffer->commit();
        $this->frame = null;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        if (! is_null($this->frame)) {
            $this->frame->encoder->endEncoding();
            $this->frame = null;
        }

        $this->textures = [];
        $this->layer = null;
        $this->released = true;
    }

    private function bindDraw(string $vertices, Transform $transform, ?TextureHandle $texture): void
    {
        $encoder = $this->frame->encoder;
        $vertexBuffer = $this->stageBuffer($vertices);
        $transformBuffer = $this->stageBuffer($transform->toPacked());
        $encoder->setVertexBufferOffsetAtIndex($vertexBuffer->handle, 0, 0);
        $encoder->setVertexBufferOffsetAtIndex($transformBuffer->handle, 0, 1);

        $flag = is_null($texture) ? $this->context->untexturedFlag : $this->context->texturedFlag;
        $encoder->setFragmentBufferOffsetAtIndex($flag->handle, 0, 0);

        $sampled = $this->context->placeholder;
        if (! is_null($texture)) {
            $held = $this->textures[$texture->id] ?? null;
            if (is_null($held)) {
                throw new MetalDrawingException('texture handle is not held by this executor');
            }
            $sampled = $held;
        }
        $encoder->setFragmentTextureAtIndex($sampled->handle, 0);
        $encoder->setFragmentSamplerStateAtIndex($this->context->sampler->handle, 0);
    }

    private function stageBuffer(string $bytes): MTLBuffer
    {
        $payload = $bytes === '' ? pack('V', 0) : $bytes;
        $buffer = $this->context->device->newBufferWithBytesLengthOptions(
            $payload,
            MTLResourceOptions::MTL_RESOURCE_CPU_CACHE_MODE_DEFAULT_CACHE->value,
        );
        if (is_null($buffer)) {
            throw new MetalDrawingException('buffer staging failed');
        }
        $this->frame->drawBuffers[] = $buffer;

        return $buffer;
    }

    private function reopenEncoder(MTLLoadAction $load): void
    {
        $previous = $this->frame;
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        if (is_null($pass)) {
            throw new MetalDrawingException('render pass descriptor missing');
        }
        $passAttachments = $pass->colorAttachments();
        if (is_null($passAttachments)) {
            throw new MetalDrawingException('pass colorAttachments missing');
        }
        $colorAttachment = $passAttachments->objectAtIndexedSubscript(0);
        if (is_null($colorAttachment)) {
            throw new MetalDrawingException('pass colour attachment 0 missing');
        }
        $colorAttachment->setTexture($previous->drawableTexture->handle);
        $colorAttachment->setLoadAction($load);
        $colorAttachment->setStoreAction(MTLStoreAction::STORE);

        $commandBuffer = $this->context->queue->commandBuffer();
        if (is_null($commandBuffer)) {
            throw new MetalDrawingException('command buffer creation failed');
        }
        $encoder = $commandBuffer->renderCommandEncoderWithDescriptor($pass->handle);
        if (is_null($encoder)) {
            throw new MetalDrawingException('render encoder creation failed');
        }

        $this->frame = new MetalFrame(
            $previous->drawable,
            $previous->drawableTexture,
            $pass,
            $passAttachments,
            $colorAttachment,
            $commandBuffer,
            $encoder,
        );
        $this->bindPipelineAndViewport($encoder);
        if (! is_null($this->scissor)) {
            $encoder->setScissorRect($this->scissor);
        }
    }

    private function bindPipelineAndViewport(MTLRenderCommandEncoder $encoder): void
    {
        $encoder->setRenderPipelineState($this->context->pipeline->handle);
        $encoder->setViewport($this->currentViewport());
    }

    private function currentViewport(): MTLViewport
    {
        return new MTLViewport(
            (float) $this->viewportX,
            (float) $this->viewportY,
            (float) $this->viewportWidth,
            (float) $this->viewportHeight,
            0.0,
            1.0,
        );
    }

    private function clampedScissor(int $x, int $y, int $width, int $height): MTLScissorRect
    {
        $x = max(0, min($x, $this->pixelWidth - 1));
        $y = max(0, min($y, $this->pixelHeight - 1));
        $width = max(1, min($width, $this->pixelWidth - $x));
        $height = max(1, min($height, $this->pixelHeight - $y));

        return new MTLScissorRect($x, $y, $width, $height);
    }

    private function primitive(Topology $topology): MTLPrimitiveType
    {
        return match ($topology) {
            Topology::POINTS => MTLPrimitiveType::POINT,
            Topology::LINES => MTLPrimitiveType::LINE,
            Topology::LINE_STRIP => MTLPrimitiveType::LINE_STRIP,
            Topology::TRIANGLES => MTLPrimitiveType::TRIANGLE,
            Topology::TRIANGLE_STRIP => MTLPrimitiveType::TRIANGLE_STRIP,
        };
    }

    private function swizzleBgraToRgba(string $bgra): string
    {
        $length = strlen($bgra);
        $rgba = $bgra;
        for ($i = 0; $i + 3 < $length; $i += 4) {
            $rgba[$i] = $bgra[$i + 2];
            $rgba[$i + 2] = $bgra[$i];
        }

        return $rgba;
    }

    private function assertAlive(): void
    {
        if ($this->released || is_null($this->layer)) {
            throw new MetalDrawingException('executor has been released');
        }
    }

    private function assertInFrame(string $operation): void
    {
        $this->assertAlive();
        if (is_null($this->frame)) {
            throw MetalDrawingException::outsideFrame($operation);
        }
    }
}
