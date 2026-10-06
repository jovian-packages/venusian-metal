<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Jovian\Engines\Metal\MetalFramebuffer;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;

it('is a GpuDevice named metal that presents into a Metal layer and needs no handles to lend against', function (): void {
    $device = new MetalDevice;

    expect($device)->toBeInstanceOf(Surface\Drawing\Gpu\GpuDevice::class)
        ->and($device->name())->toBe('metal')
        ->and($device->surfaces())->toBe([SurfaceKind::METAL_LAYER])
        ->and($device->handles())->toBe([])
        ->and($device->metal()->name())->toBe(MTLCreateSystemDefaultDevice()->name());
});

it('makes a transparent black RGBA8 target of the asked size', function (int $samples): void {
    $device = new MetalDevice;

    $target = $device->target(24, 8, $samples);

    expect($target)->toBeInstanceOf(MetalFramebuffer::class)
        ->and($target)->toBeInstanceOf(GLFramebuffer::class)
        ->and([$target->viewportWidth(), $target->viewportHeight()])->toBe([24, 8])
        ->and($target->hostFormat())->toEqual(FormatSpec::rgba8())
        ->and($target->toRgba8())->toBe(str_repeat("\x00\x00\x00\x00", 24 * 8))
        ->and($target->texture()->width())->toBe(24)
        ->and($target->texture()->sampleCount())->toBe(1);
})->with([1, 4]);

it('draws with one sample or four', function (): void {
    (new MetalDevice)->target(8, 8, 2);
})->throws(DrawingException::class, 'metal draws with 1 or 4 samples, got 2.');

it('refuses an empty target', function (): void {
    (new MetalDevice)->target(0, 8, 4);
})->throws(DrawingException::class, 'metal needs a target of at least 1 × 1, got 0 × 8.');

it('uploads a region and reads it back, tightly packed', function (int $samples): void {
    $target = (new MetalDevice)->target(16, 8, $samples);
    $block = str_repeat("\x10\x20\x30\xff", 6);

    $target->uploadRgba8($block, new Region(4, 2, 3, 2));

    expect($target->readRgba8(new Region(4, 2, 3, 2)))->toBe($block)
        ->and(strlen($target->readRgba8(new Region(0, 0, 16, 8))))->toBe(16 * 8 * 4)
        ->and(pixelOf($target->toRgba8(), 16, 3, 2))->toBe('00000000')
        ->and(pixelOf($target->toRgba8(), 16, 6, 3))->toBe('102030ff');
})->with([1, 4]);

it('serves the pixel calls of a Framebuffer through the GPU', function (): void {
    $target = (new MetalDevice)->target(16, 8, 4);

    $target->setPixel(2, 2, 0xFF0000FF)->fill(0x0000FFFF)->setSegment(0, 0, 4, 1, 0x00FF00FF);

    expect(pixelOf($target->toRgba8(), 16, 2, 2))->toBe('0000ffff')
        ->and(pixelOf($target->toRgba8(), 16, 1, 0))->toBe('00ff00ff')
        ->and($target->getPixel(1, 0))->toBe(0x00FF00FF)
        ->and($target->damage())->toEqual([new Region(0, 0, 16, 8)]);
});

it('keeps an old framebuffer readable after the target is re-made', function (): void {
    $device = new MetalDevice;
    $old = $device->target(8, 8, 4);
    $old->fill(0xFF0000FF);

    $new = $device->target(12, 6, 1);

    expect($new)->not->toBe($old)
        ->and([$new->viewportWidth(), $new->viewportHeight()])->toBe([12, 6])
        ->and(pixelOf($old->toRgba8(), 8, 3, 3))->toBe('ff0000ff')
        ->and(pixelOf($new->toRgba8(), 12, 3, 3))->toBe('00000000');
});
