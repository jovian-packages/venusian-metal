<?php

/**
 * What a frame costs on metal against velvet, as an application has velvet set up: the kitchen
 * sink's three scenes, drawn the way the sink draws them, at its canvas's size on a Retina
 * display (1100x360, scale 2). No window: frames go to a framebuffer and are not shown.
 *
 * Nothing in a frame waits for the GPU, so each run ends by reading the frame back: that waits for every
 * frame of the run, and the time counts. It fails when a scene takes metal longer than it takes velvet. Correct pixels are the other
 * checks' business; this one is whether the GPU engine is worth choosing.
 *
 * Run inside an application with venusian-velvet and a Velvet raycasting module installed:
 *
 *   zhp computer probe --execute 'require "/path/to/venusian-metal/tests/cost.php";'
 */

use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\CastsRays;
use Surface\Contracts\Graphics\DrawingContext;
use Surface\Contracts\Graphics\Feature;
use Surface\Contracts\Graphics\GraphicsEngine;
use Surface\Rendering\Recorders;
use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Graphics\Gfx;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\NutsAndBolts\RadialGradient;

(require base_path('vendor/autoload.php'))->addPsr4('Jovian\\Engines\\Metal\\', dirname(__DIR__).'/src');

const WIDTH = 1100;
const HEIGHT = 360;
const SCALE = 2.0;
const FRAMES = 200;
const RUNS = 5;

$room = Grid::of([
    [1, 1, 1, 1, 1, 1, 1, 1, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 0, 2, 0, 0, 0, 3, 0, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 0, 3, 0, 0, 0, 2, 0, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 1, 1, 1, 1, 1, 1, 1, 1],
]);
$scenery = new Scenery([1 => 0x8A9BA8FF, 2 => 0xFF6B35FF, 3 => 0x40C8FFFF], 0x101820FF, 0x2B3A42FF, 150);
$floor = Grid::of([
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 0, 0, 3, 0, 0, 0, 1],
    [1, 0, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 0, 0, 0, 0, 0, 0, 2, 0, 0, 0, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 3, 3, 0, 0, 0, 2, 0, 0, 0, 0, 0, 0, 1],
    [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 1],
    [1, 0, 0, 0, 2, 0, 0, 0, 0, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
    [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
]);
[$blocks, $corners] = [[], []];
for ($y = 1; $y < $floor->height - 1; $y++) {
    for ($x = 1; $x < $floor->width - 1; $x++) {
        if (($kind = $floor->wall($x, $y)) !== 0) {
            $blocks[] = [$x, $y, $kind];
            foreach ([[$x, $y], [$x + 1, $y], [$x, $y + 1], [$x + 1, $y + 1]] as $corner) {
                $corners[implode(',', $corner)] = $corner;
            }
        }
    }
}
foreach ([[1, 1], [$floor->width - 1, 1], [1, $floor->height - 1], [$floor->width - 1, $floor->height - 1]] as $corner) {
    $corners[implode(',', $corner)] = $corner;
}

/** One scene's frame, as the kitchen sink draws it: a closure over the phase. */
$scenes = function (GraphicsEngine $engine) use ($room, $scenery, $floor, $blocks, $corners): array {
    $screen = $engine->offscreen(WIDTH, HEIGHT);
    $caster = $engine->raycaster($room);
    $lamp = $engine->raycaster($floor);
    [$width, $height] = [WIDTH / SCALE, HEIGHT / SCALE];

    // read() waits for every frame drawn before it: once a run, so its own cost is spread over FRAMES
    return [fn () => $screen->read(), [
        'shapes' => fn (float $phase) => $screen->render(function (DrawingContext $g) use ($width, $height, $phase): void {
            $g->clear(Color::hex('#101820'));
            $g->scale(SCALE);
            $g->roundRect(16, 16, 150, $height - 32, [14, 14, 14, 14], Color::hex('#ff6b35'));
            $g->fillEllipse(166, $height / 2, 52, 52, Color::rgba(64, 200, 255, 0.6));
            $wave = new Path;
            for ($x = 240.0; $x <= $width - 16; $x += 6.0) {
                $y = $height / 2 + 40 * sin($x / 28 - $phase);
                $x === 240.0 ? $wave->moveTo($x, $y) : $wave->lineTo($x, $y);
            }
            $g->stroke($wave, Color::hex('#f7f052'), new StrokeStyle(4.0));
        }),
        'room' => fn (float $phase) => $caster->paint(new Camera(4.5, 4.0, $phase * 0.25), $scenery, $screen),
        'light' => fn (float $phase) => $screen->render(function (DrawingContext $g) use ($width, $height, $phase, $floor, $lamp, $blocks, $corners): void {
            [$lx, $ly] = [12.0 + 8.0 * cos($phase * 0.4), 4.0 + 1.6 * sin($phase * 0.4)];
            $angles = [];
            foreach ($corners as [$cx, $cy]) {
                $toward = atan2($cy - $ly, $cx - $lx);
                array_push($angles, $toward - 0.0001, $toward + 0.0001);
            }
            sort($angles);
            $lit = new Path;
            foreach ($lamp->casts($lx, $ly, $angles) as $i => $hit) {
                [$x, $y] = [$lx + $hit->distance * cos($angles[$i]), $ly + $hit->distance * sin($angles[$i])];
                $i === 0 ? $lit->moveTo($x, $y) : $lit->lineTo($x, $y);
            }
            $lit->close();

            $cell = min($width / $floor->width, $height / $floor->height);
            $g->clear(Color::hex('#05070a'));
            $g->scale(SCALE);
            $g->translate(($width - $floor->width * $cell) / 2, ($height - $floor->height * $cell) / 2);
            $g->scale($cell);
            $g->fillRect(1, 1, $floor->width - 2, $floor->height - 2, Color::hex('#141b22'));
            $g->fill($lit, new RadialGradient($lx, $ly, 14.0, [[0.0, Color::rgba(255, 236, 170, 0.95)], [1.0, Color::rgba(255, 236, 170, 0.10)]]));
            $wall = Color::hex('#8a9ba8');
            $g->fillRect(0, 0, $floor->width, 1, $wall);
            $g->fillRect(0, $floor->height - 1, $floor->width, 1, $wall);
            $g->fillRect(0, 1, 1, $floor->height - 2, $wall);
            $g->fillRect($floor->width - 1, 1, 1, $floor->height - 2, $wall);
            foreach ($blocks as [$x, $y, $kind]) {
                $g->fillRect($x, $y, 1, 1, Color::hex($kind === 2 ? '#ff6b35' : '#40c8ff'));
            }
            $g->fillEllipse($lx, $ly, 0.22, 0.22, Color::hex('#fff7d6'));
        }),
    ]];
};

/** Milliseconds a frame: the median of RUNS runs of FRAMES frames, the phase moving as it does at 60 frames a second. */
$cost = function (Closure $frame, Closure $finish): float {
    $frame(0.0);
    $finish();
    $runs = [];
    for ($run = 0; $run < RUNS; $run++) {
        $start = hrtime(true);
        for ($i = 0; $i < FRAMES; $i++) {
            $frame(($run * FRAMES + $i) / 60 * 2.0);
        }
        $finish();
        $runs[] = (hrtime(true) - $start) / 1e6 / FRAMES;
    }
    sort($runs);

    return $runs[intdiv(RUNS, 2)];
};

$engines = ['metal' => new MetalDevice(Backend::GPU, recorder: fn () => Recorders::driver()->recorder()), 'velvet' => Gfx::engine('velvet')];
foreach ($engines as $name => $engine) {
    if (! $engine instanceof CastsRays || ! $engine->has(Feature::RAY_CASTING)) {
        throw new RuntimeException("The {$name} engine casts no rays here: Velvet needs a raycasting module (VELVET_RAYCASTER).");
    }
    echo $name, ': ', implode('  ·  ', array_map(fn ($part, $driver) => "{$part}: {$driver}", array_keys($engine->modules()), $engine->modules())), "\n";
}

$costs = array_map(function (GraphicsEngine $engine) use ($scenes, $cost): array {
    [$finish, $frames] = $scenes($engine);

    return array_map(fn (Closure $frame): float => $cost($frame, $finish), $frames);
}, $engines);
$slower = [];
foreach (array_keys($costs['metal']) as $scene) {
    [$metal, $velvet] = [$costs['metal'][$scene], $costs['velvet'][$scene]];
    printf("%-7s metal %.3f ms  velvet %.3f ms  (%s)\n", $scene, $metal, $velvet, $metal <= $velvet ? sprintf('metal %.1fx faster', $velvet / $metal) : sprintf('METAL %.1fx SLOWER', $metal / $velvet));
    if ($metal > $velvet) {
        $slower[] = $scene;
    }
}

echo $slower === [] ? "\nCOST-OK metal is at least as fast as velvet on every scene\n" : "\nCOST-FAILED metal is slower on: ".implode(', ', $slower)."\n";

