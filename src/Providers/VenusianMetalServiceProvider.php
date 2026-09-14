<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal\Providers;

use Jovian\Venusian\Metal\MetalEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the Metal GPU engine under the alias Surface looks for.
 */
class VenusianMetalServiceProvider extends ServiceProvider
{
    /**
     * Bind the engine as a singleton behind 'gpu.metal'.
     *
     * Surface's GPUEngineManager resolves that string and nothing else, so
     * installing this package is the whole of what makes Metal drawing available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(MetalEngine::class);
        $this->app->alias(MetalEngine::class, 'gpu.metal');
    }

    /**
     * Nothing to boot. The engine compiles Metal when it is first asked to attach.
     * @return void
     */
    public function boot(): void {}
}
