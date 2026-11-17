<?php

namespace Jovian\Engines\Metal;

use Surface\Contracts\Graphics\DrawingSurface;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\Raycaster;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\Hit;
use Surface\Contracts\Rendering\Raycasting\Hits;
use Surface\Contracts\Rendering\Raycasting\Scenery;

/**
 * The metal engine's raycaster: a view is walked and drawn on the GPU, on a metal surface,
 * without leaving it; rays whose hits come back to the caller are walked where that is fastest
 * (see MetalCaster).
 */
class MetalRaycaster implements Raycaster
{
    public function __construct(
        protected readonly MetalDevice $engine,
        protected readonly MetalCaster $caster,
    ) {}

    public function grid(): Grid
    {
        return $this->caster->grid();
    }

    public function cast(float $x, float $y, float $angle, float $reach = INF): ?Hit
    {
        return $this->caster->cast($x, $y, $angle, $reach);
    }

    public function fan(Camera $camera, int $columns, float $reach = INF): string
    {
        return $this->caster->fan($camera, $columns, $reach);
    }

    /** Every ray in one go. */
    public function casts(float $x, float $y, array $angles, float $reach = INF): array
    {
        $records = $this->caster->casts($x, $y, array_values($angles), $reach);

        return array_map(fn (int $i): ?Hit => Hits::at($records, $i), array_keys(array_values($angles)));
    }

    /** A view as RGBA8, $width x $height, top row first. */
    public function view(Camera $camera, int $width, int $height, Scenery $scenery, float $reach = INF): string
    {
        return $this->caster->view($camera, $width, $height, $scenery, $reach);
    }

    /**
     * What $camera sees, over $where on the surface, walked and drawn on the GPU.
     *
     * @throws GraphicsException When $onto is another engine's, or $where is not inside it.
     */
    public function paint(Camera $camera, Scenery $scenery, DrawingSurface $onto, ?Region $where = null, float $reach = INF): static
    {
        if (! $onto instanceof MetalSurface || $onto->engine() !== $this->engine) {
            throw GraphicsException::foreignResource('metal', $onto->engine()->name());
        }
        $whole = Region::wholeSurface($onto->width(), $onto->height());
        $where ??= $whole;
        if ($where->isEmpty() || $where->x < 0 || $where->y < 0 || $where->right() > $whole->width || $where->bottom() > $whole->height) {
            throw GraphicsException::outsideSurface($where, $whole->width, $whole->height);
        }
        $onto->frame(fn (MetalFramebuffer $framebuffer) => $this->caster->paintOn($camera, $scenery, $framebuffer, $where, $reach));

        return $this;
    }
}
