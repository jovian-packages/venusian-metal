<?php

declare(strict_types=1);

use Surface\Contracts\Drawing\Transform;

it('packs an orthographic transform as 64 little-endian float bytes', function () {
    $packed = Transform::orthographic(640, 480)->toPacked();

    expect(strlen($packed))->toBe(64);

    $values = array_values(unpack('g16', $packed));

    expect($values[0])->toEqualWithDelta(2.0 / 640, 0.00001);
    expect($values[5])->toEqualWithDelta(-2.0 / 480, 0.00001);
    expect($values[10])->toEqualWithDelta(1.0, 0.00001);
    expect($values[12])->toEqualWithDelta(-1.0, 0.00001);
    expect($values[13])->toEqualWithDelta(1.0, 0.00001);
    expect($values[15])->toEqualWithDelta(1.0, 0.00001);
});
