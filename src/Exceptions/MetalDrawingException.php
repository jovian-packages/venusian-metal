<?php

declare(strict_types=1);

namespace Jovian\Venusian\Metal\Exceptions;

use Surface\Contracts\Drawing\DrawingException;

class MetalDrawingException extends DrawingException
{
    public static function outsideFrame(string $operation): self
    {
        return new self("{$operation} is only legal between beginFrame() and endFrame().");
    }
}
