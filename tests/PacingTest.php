<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * Present timing: a layer in a shown window, presented frame by frame, its
 * drawables saying when each reached the screen. A fresh layer's first
 * drawable is never shown.
 */

it('reports the newest present the display showed, and waits on one', function (): void {
    [$window, $layer] = shownLayerWindow(160, 120);
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 160, 120);
    $surface = new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [160, 120]);
    $device->adopt($surface);

    $waited = [];
    for ($frame = 1; $frame <= 6; $frame++) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(40 * $frame, 0, 0)));
        while (! $device->present($surface)) {
            $device->finish();
        }
        $waited[] = $device->waitPresented($frame, 500_000_000);
    }
    $last = $device->lastPresented();
    $beyond = $device->waitPresented(7, 1_000_000);
    $window->close();

    expect($device->submitted())->toBe(6)
        ->and(array_slice($waited, 1))->toBe([true, true, true, true, true])
        ->and($last?->frame)->toBe(6)
        ->and($last?->presentedAt)->toBeGreaterThan(0)
        ->and($beyond)->toBeFalse();
});

it('keeps presenting under vsync while the window is minimised', function (): void {
    [$window, $layer] = shownLayerWindow(160, 120);
    $device = new MetalDevice;
    $engine = new GpuRenderingEngine($device, 160, 120);
    $surface = new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [160, 120]);
    $device->adopt($surface);
    $device->applyVsync(VSync::On);
    $window->miniaturize(null);
    pumpApp(0.5);

    $started = hrtime(true);
    for ($frame = 0; $frame < 20; $frame++) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 8 * $frame)));
        $device->present($surface);
        pumpApp(0.0);
    }
    $elapsed = (hrtime(true) - $started) / 1e9;
    $window->deminiaturize(null);
    $window->close();

    expect($elapsed)->toBeLessThan(2.0);
});

it('reports nothing before a present was shown', function (): void {
    $device = new MetalDevice;

    expect([$device->submitted(), $device->lastPresented()])->toBe([0, null])
        ->and($device->waitPresented(1, 1_000_000))->toBeFalse();
});
