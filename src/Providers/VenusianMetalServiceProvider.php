<?php

namespace Jovian\Engines\Metal\Providers;

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Graphics\Backend;
use Surface\Graphics\Gfx;
use Surface\Rendering\Recorders;
use Voyager\NutsAndBolts\ServiceProvider;

/** Registers the 'metal' engine with Graphics, on the GPU backend. */
class VenusianMetalServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (extension_loaded('metal')) {
            Gfx::manager()->extend('metal', fn (Backend $backend) => new MetalDevice($backend, recorder: fn () => Recorders::driver()->recorder()), Backend::GPU);
        }
    }
}
