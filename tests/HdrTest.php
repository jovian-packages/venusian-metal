<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Contracts\Windows\Hdr;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\HdrImage;
use Surface\NutsAndBolts\Color;

/*
 * HDR targets: linear half floats where 1.0 is SDR white, SDR colours decoded
 * from sRGB, HdrImage sources past 1.0; the layer told its pixel format,
 * colour space and EDR; the show pass applying the white level and PQ.
 */

function hdrEngine(int $width, int $height, ColorSpace $space = ColorSpace::ExtendedLinearSrgb): GpuRenderingEngine
{
    return new GpuRenderingEngine(new MetalDevice, $width, $height, Edges::HARD, format: $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float, colorspace: $space);
}

it('makes HDR and Display P3 targets, with colour spaces from ext-appkit', function (): void {
    expect((new MetalDevice)->targetFormats())->toBe([
        [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb],
        [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3],
        [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq],
        [TargetFormat::Rgba8, ColorSpace::DisplayP3],
    ])->and(fn () => (new MetalDevice)->targetAs(8, 8, 1, TargetFormat::Rgba8, ColorSpace::Hdr10Pq))
        ->toThrow(DrawingException::class, 'metal makes no rgba8 hdr10-pq target.');
});

it('draws SDR colours as linear light with SDR white at 1.0, and reads them back as they were', function (ColorSpace $space): void {
    $engine = hdrEngine(4, 4, $space);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    $white = $engine->hdrSnapshot()->rgba16f();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(128, 64, 0)));

    expect($white)->toBe(str_repeat(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00), 16))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 4, 2, 2))->toBe('804000ff');
})->with([ColorSpace::ExtendedLinearSrgb, ColorSpace::Hdr10Pq]);

it('draws an HdrImage brighter than SDR white', function (): void {
    $engine = hdrEngine(2, 1);
    $hdr = HdrImage::fromFloats([4.0, 2.0, 1.0, 1.0, 0.5, 0.5, 0.5, 1.0], 2, 1);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($hdr, 0, 0));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v8', 0x4400, 0x4000, 0x3C00, 0x3C00, 0x3800, 0x3800, 0x3800, 0x3C00));
});

it('writes RGBA8 into an HDR target as linear light', function (): void {
    $engine = hdrEngine(1, 1);
    $engine->framebuffer()->uploadRgba8("\xFF\xFF\xFF\xFF", new Region(0, 0, 1, 1));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00));
});

it('keeps the target at 1.0 for SDR white and leaves the white level to the show pass', function (ColorSpace $space, int $code, float $nits): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $device->adopt(new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [2, 2], hdr: fn (): Hdr => new Hdr(true, 3.0, 4.0)));
    $engine = new GpuRenderingEngine($device, 2, 2, Edges::HARD, format: $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float, colorspace: $space);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));

    expect($device->read($engine->framebuffer()->texture(), new Region(0, 0, 1, 1), 8))->toBe(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00))
        ->and($device->transform())->toBe([$code, 3.0, $nits]);
})->with([
    'extended linear' => [ColorSpace::ExtendedLinearSrgb, 1, 240.0],
    'HDR10' => [ColorSpace::Hdr10Pq, 2, 240.0],
]);

it('sets the layer up for the target\'s colour space and presents', function (TargetFormat $format, ColorSpace $space, MTLPixelFormat $pixels, bool $edr): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $surface = layerSurface($layer, 8, 8);
    $device->adopt($surface);
    $device->targetAs(8, 8, 1, $format, $space);

    $shown = $device->present($surface);
    $device->finish();

    expect([$layer->pixelFormat(), $layer->wantsExtendedDynamicRangeContent(), is_int($layer->colorspace()), $shown])->toBe([$pixels, $edr, true, true]);
})->with([
    'extended linear sRGB' => [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb, MTLPixelFormat::RGBA16_FLOAT, true],
    'extended linear Display P3' => [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3, MTLPixelFormat::RGBA16_FLOAT, true],
    'HDR10' => [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq, MTLPixelFormat::RGB10A2_UNORM, true],
    'Display P3' => [TargetFormat::Rgba8, ColorSpace::DisplayP3, MTLPixelFormat::BGRA8_UNORM, false],
]);

it('puts the layer back to sRGB for an RGBA8 sRGB target', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $device->adopt(layerSurface($layer, 8, 8));
    $device->targetAs(8, 8, 1, TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb);
    $device->target(8, 8, 1);

    expect([$layer->pixelFormat(), $layer->wantsExtendedDynamicRangeContent(), $layer->colorspace(), $device->transform()[0]])->toBe([MTLPixelFormat::BGRA8_UNORM, false, null, 0]);
});
