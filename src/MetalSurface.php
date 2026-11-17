<?php

namespace Jovian\Engines\Metal;

use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Graphics\PainterSurface;

/**
 * A metal surface: a framebuffer on the GPU and the painter that draws into it. On a stage that
 * lends an NSView it is shown by the GPU, in the engine's layer over the view; on any other stage
 * as pixels, through the stage.
 */
class MetalSurface extends PainterSurface
{
    public function __construct(
        MetalDevice $engine,
        protected readonly MetalFramebuffer $framebuffer,
        MetalPainter $painter,
        ?ActorStage $stage = null,
        protected readonly ?MetalLayer $layer = null,
    ) {
        parent::__construct($engine, $painter, $stage);
    }

    public function width(): int
    {
        return $this->framebuffer->width();
    }

    public function height(): int
    {
        return $this->framebuffer->height();
    }

    public function format(): FormatSpec
    {
        return $this->framebuffer->format();
    }

    /** @throws MetalException */
    public function read(): string
    {
        return $this->framebuffer->pixels(Region::wholeSurface($this->width(), $this->height()));
    }

    /** The framebuffer on the GPU. */
    public function paintTarget(): MetalFramebuffer
    {
        return $this->framebuffer;
    }

    /**
     * In the layer, by the GPU: nothing comes back to the CPU. On a paced stage this runs once a
     * refresh. Without a layer, as pixels through the stage.
     *
     * @throws MetalException
     */
    protected function show(): void
    {
        if (is_null($this->layer)) {
            parent::show();

            return;
        }
        $this->framebuffer->showOn($this->layer, $this->stage->pixelScale());
        $this->shown++;
    }
}
