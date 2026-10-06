<?php

declare(strict_types=1);

use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Color;
use Venusian\Surface\Tests\Support\GpuParity\GpuParity;

/*
 * MetalDevice::draw(), through the engine: every operation of a DrawList on the
 * real GPU, held to Velvet's arithmetic pixel by pixel where the parity rule is
 * exact, and to its own promises elsewhere.
 */

/** Velvet's source-over for one channel. */
function overOf(int $s, int $d, int $a): int
{
    return intdiv($s * $a + $d * (255 - $a) + 127, 255);
}

function hexOf(int $r, int $g, int $b, int $a = 255): string
{
    return sprintf('%02x%02x%02x%02x', $r, $g, $b, $a);
}

it('clears to the colour, alpha included', function (int $samples): void {
    $engine = metalEngine(8, 4, $samples === 4 ? Edges::ANTIALIASED : Edges::HARD);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgba(16, 32, 48, 128 / 255)));

    expect($engine->framebuffer()->toRgba8())->toBe(str_repeat("\x10\x20\x30\x80", 32));
})->with([1, 4]);

it('blends as Velvet does, byte for byte', function (int $a, int $samples): void {
    $engine = metalEngine(8, 4, $samples === 4 ? Edges::ANTIALIASED : Edges::HARD);

    $engine->frame(function (RenderingEngine $g) use ($a): void {
        $g->clear(Color::rgb(30, 60, 90));
        $g->fillRect(2, 1, 4, 2, Color::rgba(200, 100, 50, $a / 255));
    });

    expect(pixelOf($engine->framebuffer()->toRgba8(), 8, 3, 1))
        ->toBe(hexOf(overOf(200, 30, $a), overOf(100, 60, $a), overOf(50, 90, $a), overOf(255, 255, $a)))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 8, 1, 1))->toBe(hexOf(30, 60, 90));
})->with([1, 127, 128, 254, 255])->with([1, 4]);

it('fills by the winding rule and by even-odd', function (): void {
    $overlapping = [[[2, 2], [12, 2], [12, 12], [2, 12]], [[6, 6], [16, 6], [16, 16], [6, 16]]];
    $winding = metalEngine(20, 20);
    $even_odd = metalEngine(20, 20);

    $winding->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillPath($overlapping, Color::rgb(255, 255, 255)));
    $even_odd->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillPath($overlapping, Color::rgb(255, 255, 255), FillRule::EVEN_ODD));

    expect(pixelOf($winding->framebuffer()->toRgba8(), 20, 9, 9))->toBe('ffffffff')
        ->and(pixelOf($winding->framebuffer()->toRgba8(), 20, 4, 4))->toBe('ffffffff')
        ->and(pixelOf($even_odd->framebuffer()->toRgba8(), 20, 9, 9))->toBe('000000ff')
        ->and(pixelOf($even_odd->framebuffer()->toRgba8(), 20, 4, 4))->toBe('ffffffff')
        ->and(pixelOf($even_odd->framebuffer()->toRgba8(), 20, 14, 14))->toBe('ffffffff');
});

it('leaves the stencil clean between paths', function (): void {
    $engine = metalEngine(20, 20);

    $engine->frame(function (RenderingEngine $g): void {
        $g->clear(Color::rgb(0, 0, 0));
        $g->fillRect(2, 2, 8, 8, Color::rgb(255, 0, 0));
        $g->fillRect(6, 6, 8, 8, Color::rgb(0, 0, 255));
        $g->fillTriangle(1, 18, 19, 18, 10, 10, Color::rgba(0, 255, 0, 0.5));
    });

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 20, 3, 3))->toBe('ff0000ff')
        ->and(pixelOf($frame, 20, 13, 7))->toBe('0000ffff')
        ->and(pixelOf($frame, 20, 8, 8))->toBe('0000ffff')
        ->and(pixelOf($frame, 20, 17, 2))->toBe('000000ff')
        ->and(pixelOf($frame, 20, 10, 16))->toBe(hexOf(0, overOf(255, 0, 128), 0));
});

it('keeps a pixel whose centre is inside with hard edges, and blends the edge with anti-aliased ones', function (): void {
    $hard = metalEngine(16, 16, Edges::HARD);
    $smooth = metalEngine(16, 16, Edges::ANTIALIASED);
    $draw = fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillEllipse(8.5, 8.5, 4.75, 4.75, Color::rgb(255, 255, 255));

    $hard->frame($draw);
    $smooth->frame($draw);

    // Centres: (8.5, 8.5) in; (12.5, 8.5) is 4.0 out of 4.75, in; (13.5, 8.5) is 5.0 out. The edge at x = 13.25 cuts pixel 13.
    expect(pixelOf($hard->framebuffer()->toRgba8(), 16, 8, 8))->toBe('ffffffff')
        ->and(pixelOf($hard->framebuffer()->toRgba8(), 16, 12, 8))->toBe('ffffffff')
        ->and(pixelOf($hard->framebuffer()->toRgba8(), 16, 13, 8))->toBe('000000ff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 8, 8))->toBe('ffffffff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 13, 8))->not->toBe('ffffffff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 13, 8))->not->toBe('000000ff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 0, 0))->toBe('000000ff');
});

it('strokes a ring and leaves its middle alone', function (): void {
    $engine = metalEngine(24, 24, Edges::HARD);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->strokeEllipse(12, 12, 8, 8, Color::rgb(255, 255, 0), 4));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 24, 12, 12))->toBe('000000ff')
        ->and(pixelOf($frame, 24, 20, 12))->toBe('ffff00ff')
        ->and(pixelOf($frame, 24, 16, 12))->toBe('000000ff')
        ->and(pixelOf($frame, 24, 23, 12))->toBe('000000ff');
});

it('draws a nearest image on whole pixels exactly, at full and half opacity', function (): void {
    $engine = metalEngine(32, 16);
    $tile = GpuParity::tile();

    $engine->frame(function (RenderingEngine $g) use ($tile): void {
        $g->clear(Color::rgb(0, 0, 0));
        $g->image($tile, 2, 2);
        $g->image($tile, 16, 4, opacity: 128 / 255);
    });

    $frame = $engine->framebuffer()->toRgba8();
    $want = $tile->toRgba8();
    expect($engine->framebuffer()->readRgba8(new Region(2, 2, 8, 8)))->toBe($want)
        ->and(pixelOf($frame, 32, 1, 1))->toBe('000000ff')
        ->and(pixelOf($frame, 32, 10, 2))->toBe('000000ff');
    [$r, $g, $b] = [ord($want[(3 * 8 + 5) * 4]), ord($want[(3 * 8 + 5) * 4 + 1]), ord($want[(3 * 8 + 5) * 4 + 2])];
    expect(pixelOf($frame, 32, 21, 7))->toBe(hexOf(overOf($r, 0, 128), overOf($g, 0, 128), overOf($b, 0, 128)));
});

it('draws a linear image as Velvet does on its flat interior', function (): void {
    $engine = metalEngine(32, 16);
    $velvet = GpuParity::velvet(32, 16, Edges::ANTIALIASED);
    $flat = new NativeFullFramebuffer(FormatSpec::rgba8(), 4, 4);
    $flat->fill(0x3060C0FF);
    $draw = fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($flat, 3.5, 2.5, 16, 8, 0.75, Filter::LINEAR);

    $engine->frame($draw);
    $velvet->frame($draw);

    expect(pixelOf($engine->framebuffer()->toRgba8(), 32, 10, 6))->toBe(pixelOf($velvet->framebuffer()->toRgba8(), 32, 10, 6))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 32, 10, 6))->not->toBe('000000ff');
});

it('clips to the scissor', function (): void {
    $engine = metalEngine(16, 8);

    $engine->frame(function (RenderingEngine $g): void {
        $g->clear(Color::rgb(0, 0, 0));
        $g->clip(new Region(4, 2, 4, 4));
        $g->fillRect(0, 0, 16, 8, Color::rgb(255, 255, 255));
    });

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 16, 5, 3))->toBe('ffffffff')
        ->and(pixelOf($frame, 16, 3, 3))->toBe('000000ff')
        ->and(pixelOf($frame, 16, 8, 3))->toBe('000000ff');
});

it('draws a partial frame over the kept target', function (): void {
    $engine = metalEngine(32, 16);
    $draw = fn (int $x) => fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect($x, 4, 4, 4, Color::rgb(255, 255, 255))->fillRect(24, 4, 4, 4, Color::rgb(255, 0, 0));

    $engine->frame($draw(2));
    $engine->frame($draw(10));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 32, 3, 5))->toBe('000000ff')
        ->and(pixelOf($frame, 32, 11, 5))->toBe('ffffffff')
        ->and(pixelOf($frame, 32, 25, 5))->toBe('ff0000ff')
        ->and($engine->damage())->not->toEqual([new Region(0, 0, 32, 16)]);
});

it('keeps an upload through the next partial frame', function (): void {
    $engine = metalEngine(32, 16, Edges::ANTIALIASED);
    $draw = fn (int $x) => fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect($x, 4, 4, 4, Color::rgb(255, 255, 255));
    $engine->frame($draw(2));

    $engine->framebuffer()->setPixel(30, 14, 0xFF0000FF);
    $engine->frame($draw(10));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 32, 30, 14))->toBe('ff0000ff')
        ->and(pixelOf($frame, 32, 11, 5))->toBe('ffffffff')
        ->and(pixelOf($frame, 32, 3, 5))->toBe('000000ff');
});

it('takes another engine\'s framebuffer as an image source', function (): void {
    $source = metalEngine(8, 8);
    $source->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));
    $engine = metalEngine(16, 16);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($source->framebuffer(), 4, 4));

    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 6, 6))->toBe('0080ffff')
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 16, 2, 2))->toBe('000000ff');
});

it('re-makes its target at another size and sample count', function (): void {
    $engine = metalEngine(16, 8, Edges::ANTIALIASED);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));
    $device = $engine->device();

    $target = $device->target(8, 4, 1);
    $engine2 = new Surface\Drawing\Gpu\GpuRenderingEngine($device, 8, 4, Edges::HARD);
    $engine2->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));

    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 1, 1))->toBe('ff0000ff')
        ->and(pixelOf($engine2->framebuffer()->toRgba8(), 8, 1, 1))->toBe('0000ffff')
        ->and($engine2->framebuffer())->not->toBe($target);
});

it('draws many frames without waiting between them', function (): void {
    $engine = metalEngine(64, 32);
    $started = hrtime(true);

    foreach (range(0, 59) as $i) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillEllipse(8 + $i % 48, 16, 6, 6, Color::rgb(255, 255, 255)));
    }
    $frame = $engine->framebuffer()->toRgba8();

    expect(pixelOf($frame, 64, 8 + 59 % 48, 16))->toBe('ffffffff')
        ->and((hrtime(true) - $started) / 1e6)->toBeLessThan(2000.0);
});
