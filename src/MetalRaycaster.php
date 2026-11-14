<?php

namespace Jovian\Engines\Metal;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\Hit;
use Surface\Contracts\Rendering\Raycasting\Hits;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GERaycaster;

/**
 * The metal engine's raycaster: a view is walked and drawn on the GPU, on a metal framebuffer,
 * without leaving it; rays whose hits come back to the caller are walked where that is fastest
 * (see MetalCaster).
 */
class MetalRaycaster extends GERaycaster
{
    public function __construct(
        MetalDevice $engine,
        protected readonly MetalCaster $caster,
    ) {
        parent::__construct($engine);
    }

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

    protected function paintView(Camera $camera, Scenery $scenery, GEFramebuffer $onto, Region $where, float $reach): void
    {
        /** @var MetalFramebuffer $onto mine() made sure */
        $this->caster->paintOn($camera, $scenery, $onto, $where, $reach);
    }
}
