<?php

namespace Jovian\Engines\Metal;

use MTLTexture;
use Surface\Contracts\Framebuffers\HdrReadback;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\HdrImage;
use Surface\Framebuffers\GLFramebuffer;

/**
 * The metal engine's framebuffer: the single-sample texture its device
 * resolves each frame into (or draws into with hard edges). Reads wait for the
 * frame that last drew; writes go straight into the texture, and the device
 * restores its samples from it before the next frame. An HDR target holds
 * linear half floats, SDR white at 1.0: read as RGBA8 it is sRGB, clamped at
 * SDR white; read as half floats it is as stored.
 */
final class MetalFramebuffer extends GLFramebuffer implements HdrReadback
{
    public function __construct(private readonly MetalDevice $device, private readonly MTLTexture $texture, private readonly bool $hdr = false)
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
        if (! $this->hdr) {
            return $this->device->read($this->texture, $region);
        }

        return HdrImage::fromRgba16f($this->readRgba16f($region), $region->width, $region->height)->toRgba8();
    }

    public function uploadRgba8(string $rgba8, Region $region): void
    {
        if (! $this->hdr) {
            $this->device->write($this->texture, $rgba8, $region);

            return;
        }
        $this->device->write($this->texture, LinearHalves::fromRgba8($rgba8, $region->width, $region->height), $region, 8);
    }

    public function readRgba16f(Region $region): string
    {
        if (! $this->hdr) {
            return LinearHalves::fromRgba8($this->device->read($this->texture, $region), $region->width, $region->height);
        }

        return $this->device->read($this->texture, $region, 8);
    }
}
