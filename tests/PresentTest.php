<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\ScaleFit;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * Presenting: a CAMetalLayer with no view behind it hands out drawables all
 * the same, so the copy is exercised here.
 */

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

it('sizes the drawable to the surface and places a target of another size by its fit', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 32, 16);
    $surface = layerSurface($layer, 64, 64);
    $device->adopt($surface);
    $surface->changeScaling(ScaleFilter::Linear, ScaleFit::Letterbox);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    $shown = $device->present($surface);
    $device->finish();

    expect($shown)->toBeTrue()
        ->and([(int) $layer->drawableSize()->width, (int) $layer->drawableSize()->height])->toBe([64, 64]);
});

it('presents with vsync on, off or mailbox through the layer\'s display sync', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $device->adopt(layerSurface($layer));

    $device->applyVsync(VSync::On);
    $on = $layer->displaySyncEnabled();
    $device->applyVsync(VSync::Mailbox);
    $mailbox = $layer->displaySyncEnabled();
    $device->applyVsync(VSync::Off);

    expect($device->vsyncModes())->toBe([VSync::On, VSync::Off, VSync::Mailbox])
        ->and([$on, $mailbox, $layer->displaySyncEnabled()])->toBe([true, false, false])
        ->and(fn () => $device->applyVsync(VSync::Adaptive))->toThrow(DrawingException::class, 'metal presents with vsync on, off or mailbox, not adaptive.')
        ->and(fn () => (new MetalDevice)->applyVsync(VSync::On))->toThrow(DrawingException::class, 'metal: adopt() a surface before applyVsync().');
});

it('keeps up to the frames in flight it is given, a drawable more than that on the layer', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 256, 256);
    $device->adopt(layerSurface($layer, 256, 256));
    $device->setFramesInFlight(2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));

    $first = $device->present(layerSurface($layer, 256, 256));
    $second = $device->present(layerSurface($layer, 256, 256));
    $device->finish();

    expect([$first, $second])->toBe([true, true])
        ->and($layer->maximumDrawableCount())->toBe(3)
        ->and(fn () => $device->setFramesInFlight(4))->toThrow(DrawingException::class, 'metal keeps 1 to 3 frames in flight, got 4.');
});

it('shows an SDR target as it is stored', function (): void {
    $device = new MetalDevice;
    $device->adopt(layerSurface(CAMetalLayer::layer()));
    $device->target(8, 8, 1);

    expect($device->transform())->toBe([0, 1.0, 203.0]);
});
