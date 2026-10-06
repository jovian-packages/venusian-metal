<?php

namespace Jovian\Engines\Metal\Providers;

use Jovian\Engines\Metal\MetalDevice;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Registers the 'metal' engine with the drawing manager: each renderer() call
 * builds a new engine over a new MetalDevice from the shared arguments.
 */
class VenusianMetalServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        self::extend($this->app->get('drawing'));
    }

    /** The engine's creator, on any drawing manager. */
    public static function extend(DrawingManager $drawing): void
    {
        $drawing->extend('metal', fn (array $args, DrawingManager $drawing): GpuRenderingEngine => GpuRenderingEngine::from(new MetalDevice, $args, $drawing));
    }
}
