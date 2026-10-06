<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Jovian\Engines\Metal\Providers\VenusianMetalServiceProvider;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

it('registers the metal engine on the drawing manager', function (): void {
    $drawing = metalDrawing();

    VenusianMetalServiceProvider::extend($drawing);
    $engine = $drawing->renderer('metal', ['width' => 16, 'height' => 8, 'edges' => 'hard']);

    expect($drawing->engines())->toBe(['velvet', 'metal'])
        ->and($engine)->toBeInstanceOf(GpuRenderingEngine::class)
        ->and($engine->name())->toBe('metal')
        ->and($engine->device())->toBeInstanceOf(MetalDevice::class)
        ->and([$engine->width(), $engine->height()])->toBe([16, 8]);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 0, 0))->toBe('ffffffff');
});

it('refuses the Velvet-only arguments by name', function (): void {
    $drawing = metalDrawing();
    VenusianMetalServiceProvider::extend($drawing);

    $drawing->renderer('metal', ['width' => 16, 'height' => 8, 'mode' => 'ring']);
})->throws(DrawingException::class, "metal does not take 'mode'. It takes: output, width, height, edges.");
