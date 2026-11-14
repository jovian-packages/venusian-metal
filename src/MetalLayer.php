<?php

namespace Jovian\Engines\Metal;

use CALayer;
use CAMetalLayer;
use MTLPixelFormat;
use NSSize;
use NSView;

/**
 * What the metal engine adds to a view a stage lends it: a child view of its own, the size of the
 * stage's, hosting a CAMetalLayer that frames are shown in. The stage's own view is left as it
 * was; release() takes the child view away again.
 */
class MetalLayer
{
    protected readonly NSView $view;

    protected readonly CAMetalLayer $layer;

    /** @var array{int, int} the drawable size last set, in pixels */
    protected array $size = [0, 0];

    /** @throws MetalException */
    public function __construct(\MTLDevice $metal, protected readonly NSView $stage)
    {
        $this->layer = CAMetalLayer::layer() ?? throw MetalException::because('no Metal layer could be made.');
        $this->layer->setDevice($metal);
        $this->layer->setPixelFormat(MTLPixelFormat::BGRA8_UNORM);
        $this->layer->setFramebufferOnly(true);
        // a frame is shown as soon as it is drawn: the frame rate is the application's to set
        $this->layer->setDisplaySyncEnabled(false);

        // the layer is set before the view wants one, so the view hosts it rather than making its own
        $this->view = NSView::initWithFrame($stage->bounds());
        $this->view->setLayer(CALayer::fromPointer($this->layer->pointer()));
        $this->view->setWantsLayer(true);
        $stage->addSubview($this->view);
    }

    /**
     * The layer, sized to the stage's view and to $width x $height pixels at $scale.
     *
     * @internal
     */
    public function fitted(int $width, int $height, float $scale): CAMetalLayer
    {
        $this->view->setFrame($this->stage->bounds());
        if ($this->size !== [$width, $height]) {
            $this->layer->setContentsScale($scale);
            $this->layer->setDrawableSize(new NSSize((float) $width, (float) $height));
            $this->size = [$width, $height];
        }

        return $this->layer;
    }

    public function release(): void
    {
        $this->view->removeFromSuperview();
    }
}
