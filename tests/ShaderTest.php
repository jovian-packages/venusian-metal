<?php

declare(strict_types=1);

use Jovian\Venusian\Metal\MetalContext;

it('embeds painter vertex and fragment functions on both buffer slots', function () {
    $source = MetalContext::shaderSource();

    expect($source)
        ->toContain('painter_vertex')
        ->toContain('painter_fragment')
        ->toContain('buffer(0)')
        ->toContain('buffer(1)')
        ->toContain('vid * 9u')
        ->toContain('float4(x, y, 0.0, 1.0)')
        ->toContain('constant uint &textured');
});
