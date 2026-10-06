<?php

namespace Jovian\Engines\Metal;

use MTLTexture;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\GLFramebuffer;

/**
 * The metal engine's framebuffer: the single-sample RGBA8 texture its device
 * resolves each frame into (or draws into with hard edges). Reads wait for the
 * frame that last drew; writes go straight into the texture, and the device
 * restores its samples from it before the next frame.
 */
final class MetalFramebuffer extends GLFramebuffer
{
    public function __construct(private readonly MetalDevice $device, private readonly MTLTexture $texture)
    {
        parent::__construct();
    }

    public function texture(): MTLTexture
    {
        return $this->texture;
    }

    public function width(): int
    {
        return $this->texture->width();
    }

    public function height(): int
    {
        return $this->texture->height();
    }

    public function readRgba8(Region $region): string
    {
        return $this->device->read($this->texture, $region);
    }

    public function uploadRgba8(string $rgba8, Region $region): void
    {
        $this->device->write($this->texture, $rgba8, $region);
    }
}
