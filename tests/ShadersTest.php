<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;

it('compiles on this GPU and names every function the device uses', function (): void {
    $library = MTLCreateSystemDefaultDevice()->newLibraryWithSourceOptionsError(file_get_contents(MetalDevice::SHADERS), null);

    foreach (['place', 'solid', 'mark', 'fill', 'oval', 'image', 'copy', 'show'] as $name) {
        expect($library->newFunctionWithName($name))->not->toBeNull($name);
    }
});
