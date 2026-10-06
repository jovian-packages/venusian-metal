<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * Presenting: a CAMetalLayer with no view behind it hands out drawables all
 * the same, so the copy is exercised here; slice 8b puts one in a window.
 */

function layerSurface(CAMetalLayer $layer, int $width = 64, int $height = 32): LentSurface
{
    return new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [$width, $height]);
}

it('configures the layer it adopts for this device', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;

    $device->adopt(layerSurface($layer));

    expect($layer->device()?->name())->toBe($device->metal()->name())
        ->and($layer->pixelFormat())->toBe(MTLPixelFormat::BGRA8_UNORM)
        ->and($layer->framebufferOnly())->toBeTrue()
        ->and($layer->displaySyncEnabled())->toBeFalse();
});

it('refuses to present before adopting a surface', function (): void {
    $device = new MetalDevice;
    $device->target(8, 8, 1);

    $device->present(layerSurface(CAMetalLayer::layer()));
})->throws(DrawingException::class, 'metal: adopt() a surface before present().');

it('copies the target into a drawable and presents it', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 64, 32);
    $device->adopt(layerSurface($layer));
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    $shown = $device->present(layerSurface($layer));
    $device->finish();

    expect($shown)->toBeTrue()
        ->and([(int) $layer->drawableSize()->width, (int) $layer->drawableSize()->height])->toBe([64, 32]);
});

it('answers false while the last copy is still in flight, and copies again once it landed', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 256, 256);
    $device->adopt(layerSurface($layer, 256, 256));
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));

    $first = $device->present(layerSurface($layer, 256, 256));
    $while_in_flight = $device->present(layerSurface($layer, 256, 256));
    $device->finish();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 255, 0)));
    $after = $device->present(layerSurface($layer, 256, 256));

    // The second present answers false while the first is in flight; a GPU that
    // has already finished it copies again. Both are right; the third must land.
    expect($first)->toBeTrue()
        ->and($after)->toBeTrue()
        ->and($while_in_flight)->toBeIn([true, false]);
});

it('answers false for a released surface', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $device->target(8, 8, 1);
    $surface = layerSurface($layer, 8, 8);
    $device->adopt($surface);
    $surface->release();

    expect($device->present($surface))->toBeFalse();
});

it('lets go of everything on release()', function (): void {
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    $engine->release();

    expect(fn () => $device->draw(Surface\Drawing\Gpu\Lowering::lower([['clear', 0xFF0000FF]], 8, 8)))
        ->toThrow(DrawingException::class, 'metal: target() comes before draw().');
});
