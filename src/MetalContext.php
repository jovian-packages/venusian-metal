<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal;

use Jovian\Bindings\Metal\Enums\MTLPixelFormat;
use Jovian\Bindings\Metal\Enums\MTLResourceOptions;
use Jovian\Bindings\Metal\Enums\MTLSamplerAddressMode;
use Jovian\Bindings\Metal\Enums\MTLSamplerMinMagFilter;
use Jovian\Bindings\Metal\Enums\MTLStorageMode;
use Jovian\Bindings\Metal\Enums\MTLTextureUsage;
use Jovian\Bindings\Metal\MTL\MTLBuffer;
use Jovian\Bindings\Metal\MTL\MTLCommandQueue;
use Jovian\Bindings\Metal\MTL\MTLDevice;
use Jovian\Bindings\Metal\MTL\MTLFunction;
use Jovian\Bindings\Metal\MTL\MTLLibrary;
use Jovian\Bindings\Metal\MTL\MTLRenderPipelineColorAttachmentDescriptor;
use Jovian\Bindings\Metal\MTL\MTLRenderPipelineColorAttachmentDescriptorArray;
use Jovian\Bindings\Metal\MTL\MTLRenderPipelineDescriptor;
use Jovian\Bindings\Metal\MTL\MTLRenderPipelineState;
use Jovian\Bindings\Metal\MTL\MTLSamplerDescriptor;
use Jovian\Bindings\Metal\MTL\MTLSamplerState;
use Jovian\Bindings\Metal\MTL\MTLTexture;
use Jovian\Bindings\Metal\MTL\MTLTextureDescriptor;
use Jovian\Bindings\Metal\Runtime\Bridge;
use Jovian\Bindings\Metal\Values\HandleError;
use Jovian\Bindings\Metal\Values\MTLRegion;
use Jovian\Venusian\Metal\Exceptions\MetalDrawingException;

/**
 * One device, queue, compiled library, pipeline, sampler, flag buffers, and
 * a 1×1 white placeholder texture. Cached on MetalEngine for the process.
 */
final class MetalContext
{
    public function __construct(
        public readonly MTLDevice $device,
        public readonly MTLCommandQueue $queue,
        public readonly MTLLibrary $library,
        public readonly MTLFunction $vertexFunction,
        public readonly MTLFunction $fragmentFunction,
        public readonly MTLRenderPipelineDescriptor $pipelineDescriptor,
        public readonly MTLRenderPipelineColorAttachmentDescriptorArray $pipelineAttachments,
        public readonly MTLRenderPipelineColorAttachmentDescriptor $colorAttachment,
        public readonly MTLRenderPipelineState $pipeline,
        public readonly MTLSamplerDescriptor $samplerDescriptor,
        public readonly MTLSamplerState $sampler,
        public readonly MTLBuffer $untexturedFlag,
        public readonly MTLBuffer $texturedFlag,
        public readonly MTLTextureDescriptor $placeholderDescriptor,
        public readonly MTLTexture $placeholder,
    ) {}

    public static function shaderSource(): string
    {
        /** @var string $source */
        $source = require __DIR__.'/Shaders/painter.metal.php';

        return $source;
    }

    public static function boot(): self
    {
        $device = MTLDevice::createSystemDefault();
        if (is_null($device)) {
            throw new MetalDrawingException('no system default Metal device');
        }

        $queue = $device->newCommandQueue();
        if (is_null($queue)) {
            throw new MetalDrawingException('failed to create a Metal command queue');
        }

        $library = MTLLibrary::box(self::unwrap(
            $device->newLibraryWithSourceOptionsError(self::shaderSource(), 0),
            'shader compile',
        ));
        if (is_null($library)) {
            throw new MetalDrawingException('compiled library did not box');
        }

        $vertexFunction = $library->newFunctionWithName('painter_vertex');
        $fragmentFunction = $library->newFunctionWithName('painter_fragment');
        if (is_null($vertexFunction) || is_null($fragmentFunction)) {
            throw new MetalDrawingException('painter shader functions missing from the compiled library');
        }

        $pipelineDescriptor = MTLRenderPipelineDescriptor::init();
        if (is_null($pipelineDescriptor)) {
            throw new MetalDrawingException('pipeline descriptor init failed');
        }
        $pipelineDescriptor->setLabel('venusian painter');
        $pipelineDescriptor->setVertexFunction($vertexFunction->handle);
        $pipelineDescriptor->setFragmentFunction($fragmentFunction->handle);

        $pipelineAttachments = $pipelineDescriptor->colorAttachments();
        if (is_null($pipelineAttachments)) {
            throw new MetalDrawingException('pipeline colorAttachments missing');
        }
        $colorAttachment = $pipelineAttachments->objectAtIndexedSubscript(0);
        if (is_null($colorAttachment)) {
            throw new MetalDrawingException('pipeline colour attachment 0 missing');
        }
        $colorAttachment->setPixelFormat(MTLPixelFormat::BGRA8_UNORM);
        $colorAttachment->setBlendingEnabled(false);

        $pipeline = MTLRenderPipelineState::box(self::unwrap(
            $device->newRenderPipelineStateWithDescriptorError($pipelineDescriptor->handle),
            'pipeline creation',
        ));
        if (is_null($pipeline)) {
            throw new MetalDrawingException('pipeline state did not box');
        }

        $samplerDescriptor = MTLSamplerDescriptor::init();
        if (is_null($samplerDescriptor)) {
            throw new MetalDrawingException('sampler descriptor init failed');
        }
        $samplerDescriptor->setMinFilter(MTLSamplerMinMagFilter::LINEAR);
        $samplerDescriptor->setMagFilter(MTLSamplerMinMagFilter::LINEAR);
        $samplerDescriptor->setSAddressMode(MTLSamplerAddressMode::CLAMP_TO_EDGE);
        $samplerDescriptor->setTAddressMode(MTLSamplerAddressMode::CLAMP_TO_EDGE);

        $sampler = $device->newSamplerStateWithDescriptor($samplerDescriptor->handle);
        if (is_null($sampler)) {
            throw new MetalDrawingException('sampler state creation failed');
        }

        $shared = MTLResourceOptions::MTL_RESOURCE_CPU_CACHE_MODE_DEFAULT_CACHE->value;
        $untexturedFlag = $device->newBufferWithBytesLengthOptions(pack('V', 0), $shared);
        $texturedFlag = $device->newBufferWithBytesLengthOptions(pack('V', 1), $shared);
        if (is_null($untexturedFlag) || is_null($texturedFlag)) {
            throw new MetalDrawingException('flag buffer creation failed');
        }

        $placeholderDescriptor = MTLTextureDescriptor::texture2DDescriptorWithPixelFormatWidthHeightMipmapped(
            MTLPixelFormat::RGBA8_UNORM,
            1,
            1,
            false,
        );
        if (is_null($placeholderDescriptor)) {
            throw new MetalDrawingException('placeholder descriptor creation failed');
        }
        $placeholderDescriptor->setUsage(MTLTextureUsage::SHADER_READ->value);
        $placeholderDescriptor->setStorageMode(MTLStorageMode::SHARED);

        $placeholder = $device->newTextureWithDescriptor($placeholderDescriptor->handle);
        if (is_null($placeholder)) {
            throw new MetalDrawingException('placeholder texture creation failed');
        }
        $placeholder->replaceRegionMipmapLevelWithBytesBytesPerRow(
            new MTLRegion(0, 0, 0, 1, 1, 1),
            0,
            pack('C4', 255, 255, 255, 255),
            4,
        );

        return new self(
            $device,
            $queue,
            $library,
            $vertexFunction,
            $fragmentFunction,
            $pipelineDescriptor,
            $pipelineAttachments,
            $colorAttachment,
            $pipeline,
            $samplerDescriptor,
            $sampler,
            $untexturedFlag,
            $texturedFlag,
            $placeholderDescriptor,
            $placeholder,
        );
    }

    public static function unwrap(HandleError $result, string $what): int
    {
        if ($result->handle > 0) {
            return $result->handle;
        }

        $detail = $result->error > 0
            ? (Bridge::errorDescription($result->error) ?? 'unreadable NSError')
            : 'no error object';

        throw new MetalDrawingException("{$what} failed: {$detail}");
    }
}
