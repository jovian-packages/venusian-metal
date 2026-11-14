<?php

namespace Jovian\Engines\Metal;

use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GEPainter;

/** The metal engine's painter, as Graphics hands it out. */
class MetalGEPainter extends GEPainter
{
    protected function source(GEFramebuffer $framebuffer): PaintTarget
    {
        /** @var MetalFramebuffer $framebuffer the base checked it is this engine's */
        return $framebuffer;
    }
}
