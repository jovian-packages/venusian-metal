<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\FramebufferManager;
use Surface\Rasterize\RasterizeManager;

if (! extension_loaded('metal')) {
    throw new RuntimeException('venusian-metal tests need ext-metal loaded.');
}
if (is_null(MTLCreateSystemDefaultDevice())) {
    throw new RuntimeException('venusian-metal tests need a Mac with a Metal device.');
}

// The parity suite every engine runs lives in the Surface checkout (export-ignored from its package).
$parity = (getenv('SURFACE_TESTS') ?: dirname(__DIR__, 3).'/venusian/surface/tests').'/Support/GpuParity/GpuParity.php';
if (! is_file($parity)) {
    throw new RuntimeException("The GPU parity suite was not found at {$parity}: set SURFACE_TESTS to a Surface checkout's tests directory.");
}
require_once $parity;

/** A Metal engine with no output, its own device. */
function metalEngine(int $width = 64, int $height = 32, Edges $edges = Edges::ANTIALIASED): GpuRenderingEngine
{
    return new GpuRenderingEngine(new MetalDevice, $width, $height, $edges);
}

/** The RGBA8 pixel at ($x, $y) of a tightly packed frame, as hex. */
function pixelOf(string $rgba8, int $width, int $x, int $y): string
{
    return bin2hex(substr($rgba8, ($y * $width + $x) * 4, 4));
}

/** A config repository over an array, as Surface's own suite builds one. */
function metalConfig(array $items): object
{
    return new class($items)
    {
        public function __construct(private readonly array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    };
}

/** A drawing manager without a container: Velvet registered, nothing else. */
function metalDrawing(): DrawingManager
{
    $framebuffers = new class extends FramebufferManager
    {
        public function __construct()
        {
            $this->config = metalConfig([]);
        }
    };
    $rasterize = new class extends RasterizeManager
    {
        public function __construct()
        {
            $this->config = metalConfig([]);
        }
    };

    return new DrawingManager(metalConfig([]), $framebuffers, $rasterize);
}
