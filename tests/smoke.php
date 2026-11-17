<?php
/*
 * Checks the metal engine on this Mac's GPU against Velvet's pixels. Opens no window.
 *
 * Run inside an application with venusian-velvet installed, Velvet configured with Lustrous in PHP:
 *
 *   VELVET_FRAMEBUFFER=lustrous-php-full VELVET_RASTERIZER=lustrous-php VELVET_PAINTER=lustrous-php \
 *     zhp computer probe --execute 'require "/path/to/venusian-metal/tests/smoke.php";'
 *
 * Prints one line a check, then "ok N, failed M".
 *
 * Antialiased edges come from four samples a pixel, so a byte on an edge may be up to about a
 * quarter step (64) off Velvet's exact coverage, and only edges may differ.
 */
use Jovian\Engines\Metal\MetalDevice;
use Jovian\Engines\Metal\MetalSurface;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\CastsRays;
use Surface\Contracts\Graphics\DrawingContext;
use Surface\Contracts\Graphics\DrawingSurface;
use Surface\Contracts\Graphics\Feature;
use Surface\Contracts\Graphics\GraphicsEngine;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Graphics\Gfx;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\Rendering\Recording\DrawCommandQueue;
use Surface\Rendering\Recording\Queues\PHPLandQueue;

(require base_path('vendor/autoload.php'))->addPsr4('Jovian\\Engines\\Metal\\', dirname(__DIR__).'/src');

[$ok, $bad] = [0, 0];
$check = function (string $what, Closure $pass) use (&$ok, &$bad): void {
    try {
        [$passed, $note] = [(bool) $pass(), ''];
    } catch (Throwable $e) {
        [$passed, $note] = [false, ' ('.get_class($e).': '.$e->getMessage().')'];
    }
    $passed ? $ok++ : $bad++;
    echo $passed ? 'ok   ' : 'FAIL ', $what, $note, "\n";
};

$metal = new MetalDevice(Backend::GPU, recorder: fn () => new DrawCommandQueue(new PHPLandQueue()));
$velvet = Gfx::engine('velvet', 'cpu');

/** A surface's pixels and its painter, for checks that look inside it. */
$painterOf = fn (DrawingSurface $surface) => (fn () => $this->painter)->call($surface);

/** @return array{int, int} bytes that differ, and the worst difference */
$difference = function (string $a, string $b): array {
    [$differ, $worst] = [0, 0];
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $d = abs(ord($a[$i]) - ord($b[$i]));
        [$differ, $worst] = [$differ + ($d > 0 ? 1 : 0), max($worst, $d)];
    }

    return [$differ, $worst];
};

/** The pixels of one 100x100 frame drawn by each engine, compared. */
$painted = function (Closure $draw) use ($metal, $velvet, $difference): array {
    $out = [];
    foreach (['metal' => $metal, 'velvet' => $velvet] as $name => $engine) {
        $out[$name] = $engine->offscreen(100, 100)->render($draw)->read();
    }

    return $difference($out['metal'], $out['velvet']);
};

/** A pixel of a surface, as hex RGBA8. */
$pixel = fn (DrawingSurface $surface, int $x, int $y) => bin2hex(substr($surface->read(), ($y * $surface->width() + $x) * 4, 4));

$star = (new Path)->moveTo(50, 5)->lineTo(61, 38)->lineTo(95, 38)->lineTo(67, 58)->lineTo(78, 92)->lineTo(50, 71)->lineTo(22, 92)->lineTo(33, 58)->lineTo(5, 38)->lineTo(39, 38)->close();
$wave = new Path;
for ($x = 4.0; $x <= 96; $x += 4) {
    $y = 50 + 30 * sin($x / 9);
    $x === 4.0 ? $wave->moveTo($x, $y) : $wave->lineTo($x, $y);
}
$check('it is the GPU backend only, with every feature but images, and casts rays', fn () => $metal->name() === 'metal'
    && $metal->backend() === Backend::GPU && $metal instanceof CastsRays
    && array_values(array_filter(Feature::cases(), fn (Feature $f) => ! $metal->has($f))) === [Feature::IMAGES]);
$check('it refuses the CPU backend', function () {
    try {
        new MetalDevice(Backend::CPU);
    } catch (GraphicsException $e) {
        return str_contains($e->getMessage(), 'has no cpu backend');
    }

    return false;
});
$check('a new surface is transparent black', fn () => $metal->offscreen(3, 2)->read() === str_repeat("\0", 24));
// it holds premultiplied colour, so a half transparent clear keeps its alpha and its colour
$check('it reads back a cleared colour as its bytes', fn () => $pixel($metal->offscreen(4, 4)->render(fn (DrawingContext $g) => $g->clear(Color::rgba(10, 20, 30, 0.5))), 0, 0) === '0a141e80');
$check('it holds RGBA8 only', function () use ($metal) {
    try {
        $metal->offscreen(4, 4, FormatSpec::bgra8());
    } catch (Throwable) {
        return true;
    }

    return false;
});
$check('a surface of no size is refused', function () use ($metal) {
    try {
        $metal->offscreen(0, 4);
    } catch (GraphicsException) {
        return true;
    }

    return false;
});
$shapes = [
    'a translucent star, non-zero' => fn (DrawingContext $g) => $g->fill($star, Color::rgba(255, 107, 53, 0.8)),
    'a star, even-odd' => fn (DrawingContext $g) => $g->fill($star, Color::hex('#40c8ff'), FillRule::EVEN_ODD),
    'a stroked wave' => fn (DrawingContext $g) => $g->stroke($wave, Color::hex('#f7f052'), new StrokeStyle(4.0)),
    'a star cut by a clip' => fn (DrawingContext $g) => $g->clip(new Region(20, 20, 40, 40))->fill($star, Color::hex('#ff0000')),
];
foreach ($shapes as $name => $draw) {
    $check("{$name} is Velvet's but for its edges", function () use ($painted, $draw) {
        [$differ, $worst] = $painted(fn (DrawingContext $g) => $draw($g->clear(Color::hex('#101820'))));
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 2000 && $worst <= 72;
    });
}

$check('it scrolls a region on the GPU, leaving the strip behind as it was, and the next frame lands right', function () use ($metal) {
    $stripes = function (DrawingContext $g): void {
        $g->clear(Color::rgb(0, 0, 0));
        for ($x = 0; $x < 4; $x++) {
            $g->fillRect($x, 0, 1, 4, Color::rgb($x + 1, 0, 0));
        }
    };
    $surface = $metal->offscreen(4, 4)->render($stripes);
    $before = $surface->read();
    $surface->scroll(new Region(0, 0, 4, 1), 1, 0);
    $row = bin2hex(substr($surface->read(), 0, 16));

    return $row === '010000ff010000ff020000ff030000ff' && $surface->render($stripes)->read() === $before;
});
$check('a vertical scroll moves rows, top row first', function () use ($metal) {
    $surface = $metal->offscreen(1, 4)->render(function (DrawingContext $g): void {
        for ($y = 0; $y < 4; $y++) {
            $g->fillRect(0, $y, 1, 1, Color::rgb($y + 1, 0, 0));
        }
    });
    $surface->scroll(new Region(0, 0, 1, 4), 0, 1);

    return bin2hex($surface->read()) === '010000ff010000ff020000ff030000ff';
});
// the painter: every shape it carries out, against Velvet's painter
$scene = function (DrawingContext $g): void {
    $g->clear(Color::hex('#101820'));
    $g->shadow(10, 14, 60, 60, 10, 8, Color::rgba(0, 0, 0, 0.6));
    $g->roundRect(10, 10, 60, 60, [10, 10, 10, 10], Color::hex('#ff6b35'), Color::hex('#000000'), 3);
    $g->fillEllipse(70, 50, 24, 18, Color::rgba(64, 200, 255, 0.6));
    $g->strokeEllipse(70, 50, 30, 30, Color::hex('#ffffff'), 2);
    $g->fillRect(80, 4, 14, 14, Color::hex('#40ff80'));
    $g->line(4, 90, 96, 70, Color::hex('#ffffff'), 3);
    $g->push()->clip(new Region(0, 0, 50, 100))->fillPolygon([[20, 80], [45, 95], [70, 75]], Color::hex('#f7f052'))->pop();
};
$check("the painter's shapes are Velvet's but for their edges", function () use ($painted, $scene) {
    [$differ, $worst] = $painted($scene);
    echo "     {$differ} bytes differ, worst {$worst}\n";

    // four samples a pixel: where a curved edge crosses a pixel through its middle, Metal can count 0 or 1 of
    // 4 samples inside where Velvet measures half. A thin rounded border's inner corners show it most.
    return $differ < 3000 && $worst <= 128;
});
$check('the painter redraws only what changed, and reports it', function () use ($metal, $pixel, $painterOf) {
    $surface = $metal->offscreen(100, 100);
    $surface->render(fn (DrawingContext $g) => $g->clear(Color::hex('#000000'))->fillRect(10, 10, 10, 10, Color::hex('#ff0000')));
    $surface->render(fn (DrawingContext $g) => $g->clear(Color::hex('#000000'))->fillRect(50, 50, 10, 10, Color::hex('#ff0000')));
    $damage = $painterOf($surface)->damage();

    return $pixel($surface, 15, 15) === '000000ff' && $pixel($surface, 55, 55) === 'ff0000ff' && count($damage) >= 1;
});
// filters, backdrops and glass, through the painter, against Velvet's painter. A scene with something to blur under it.
use Surface\Contracts\Graphics\Effects\{Blur as FxBlur, BlurSampling as FxSampling, ColorMatrix as FxMatrix, FilterChain as FxChain, Glass as FxGlass};
$under = function (DrawingContext $g): DrawingContext {
    $g->clear(Color::hex('#203040'));
    for ($i = 0; $i < 10; $i++) {
        $g->fillRect($i * 10, 0, 5, 100, Color::rgb(($i * 70) % 256, ($i * 130 + 60) % 256, (255 - $i * 25) % 256));
    }

    return $g->fillEllipse(70, 70, 22, 18, Color::hex('#f7f052'))->fillRect(10, 60, 30, 30, Color::rgba(255, 255, 255, 0.5));
};
$blurry = FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 3.0, 3.0));
$scenes = [
    'a layer with a filter' => fn (DrawingContext $g) => $under($g)->layer(15, 15, 70, 60, fn (DrawingContext $g) => $g->fillRect(5, 5, 40, 30, Color::hex('#ff6b35'))->fillEllipse(45, 35, 15, 15, Color::hex('#40c8ff')), 0.9, null, FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 2.5, 2.5), FxMatrix::saturate(1.5))),
    'a layer with a backdrop' => fn (DrawingContext $g) => $under($g)->layer(15, 15, 70, 60, fn (DrawingContext $g) => $g->fillRect(0, 0, 70, 60, Color::rgba(255, 255, 255, 0.25)), 1.0, null, null, $blurry),
    'a rounded layer with a filter and a backdrop' => fn (DrawingContext $g) => $under($g)->layer(10, 20, 80, 50, fn (DrawingContext $g) => $g->fillEllipse(40, 25, 30, 18, Color::rgba(255, 107, 53, 0.8)), 0.8, [12, 12, 12, 12], FxChain::of(FxMatrix::hueRotate(90.0)), FxChain::of(new FxBlur(FxSampling::SPIRAL, 5.0, 5.0))),
    'a layer holding a layer with a backdrop' => fn (DrawingContext $g) => $under($g)->layer(5, 5, 90, 90, fn (DrawingContext $g) => $g->fillRect(0, 0, 45, 90, Color::hex('#80ff80'))->layer(20, 20, 50, 50, fn (DrawingContext $g) => $g->fillRect(0, 0, 50, 50, Color::rgba(0, 0, 0, 0.2)), 1.0, [8, 8, 8, 8], null, $blurry), 0.9),
    'a glass pane' => fn (DrawingContext $g) => $under($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(blur: 6.0)),
    'a glass pane, half frosted, bent, tinted, lit, grained and shadowed' => fn (DrawingContext $g) => $under($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(12.0, Color::rgba(40, 120, 255, 0.3), 1.4, 0.05, Color::rgba(255, 255, 255, 0.6), 6.0, 5.0, 0.5, 0.4, 6.0, Color::rgba(0, 0, 0, 0.5)), 0.9),
    'two glass panes that share a capture' => fn (DrawingContext $g) => $under($g)->glass(5, 5, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 4.0))->glass(55, 55, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 10.0)),
    'two glass panes with a shape between them' => fn (DrawingContext $g) => $under($g)->glass(5, 5, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0))->fillEllipse(50, 50, 20, 20, Color::hex('#ff0080'))->glass(40, 40, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0)),
    'a glass pane inside a layer' => fn (DrawingContext $g) => $under($g)->layer(5, 5, 90, 90, fn (DrawingContext $g) => $g->fillRect(0, 0, 90, 45, Color::hex('#ff6b35'))->glass(10, 20, 70, 50, [10, 10, 10, 10], new FxGlass(blur: 5.0)), 0.95),
];
// The same, with nothing antialiased: pixel-aligned rects only, layers without rounded corners. What differs then is
// the filter and glass arithmetic itself (float32 against PHP's doubles), not edge coverage.
$square = function (DrawingContext $g): DrawingContext {
    $g->clear(Color::hex('#203040'));
    for ($i = 0; $i < 10; $i++) {
        $g->fillRect($i * 10, 0, 5, 100, Color::rgb(($i * 70) % 256, ($i * 130 + 60) % 256, (255 - $i * 25) % 256));
    }

    return $g->fillRect(55, 55, 30, 25, Color::hex('#f7f052'))->fillRect(10, 60, 30, 30, Color::rgba(255, 255, 255, 0.5));
};
$exact = [
    'a layer with a filter' => fn (DrawingContext $g) => $square($g)->layer(15, 15, 70, 60, fn (DrawingContext $g) => $g->fillRect(5, 5, 40, 30, Color::hex('#ff6b35'))->fillRect(40, 30, 20, 20, Color::hex('#40c8ff')), 0.9, null, FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 2.5, 2.5), FxMatrix::saturate(1.5))),
    'a layer with a backdrop' => fn (DrawingContext $g) => $square($g)->layer(15, 15, 70, 60, fn (DrawingContext $g) => $g->fillRect(0, 0, 70, 60, Color::rgba(255, 255, 255, 0.25)), 1.0, null, null, $blurry),
    'a layer with a spiral backdrop and a filter' => fn (DrawingContext $g) => $square($g)->layer(10, 20, 80, 50, fn (DrawingContext $g) => $g->fillRect(10, 10, 50, 30, Color::rgba(255, 107, 53, 0.8)), 0.8, null, FxChain::of(FxMatrix::hueRotate(90.0)), FxChain::of(new FxBlur(FxSampling::SPIRAL, 5.0, 5.0))),
    'a layer holding a layer with a backdrop' => fn (DrawingContext $g) => $square($g)->layer(5, 5, 90, 90, fn (DrawingContext $g) => $g->fillRect(0, 0, 45, 90, Color::hex('#80ff80'))->layer(20, 20, 50, 50, fn (DrawingContext $g) => $g->fillRect(0, 0, 50, 50, Color::rgba(0, 0, 0, 0.2)), 1.0, null, null, $blurry), 0.9),
    'a glass pane' => fn (DrawingContext $g) => $square($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(blur: 6.0)),
    'a glass pane, half frosted, bent, tinted, lit and grained' => fn (DrawingContext $g) => $square($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(12.0, Color::rgba(40, 120, 255, 0.3), 1.4, 0.05, Color::rgba(255, 255, 255, 0.6), 6.0, 5.0, 0.5, 0.4), 0.9),
    'two glass panes that share a capture' => fn (DrawingContext $g) => $square($g)->glass(5, 5, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 4.0))->glass(55, 55, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 10.0)),
    'two glass panes with a shape between them' => fn (DrawingContext $g) => $square($g)->glass(5, 5, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0))->fillRect(40, 40, 25, 25, Color::hex('#ff0080'))->glass(40, 40, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0)),
    'a glass pane inside a layer' => fn (DrawingContext $g) => $square($g)->layer(5, 5, 90, 90, fn (DrawingContext $g) => $g->fillRect(0, 0, 90, 45, Color::hex('#ff6b35'))->glass(10, 20, 70, 50, [10, 10, 10, 10], new FxGlass(blur: 5.0)), 0.95),
];
foreach ($exact as $name => $draw) {
    $check("{$name}, nothing antialiased, is Velvet's within 2", function () use ($painted, $draw) {
        [$differ, $worst] = $painted($draw);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $worst <= 2;
    });
}
// A frame redrawn in many regions: the shared Painter hands the painter a copy of the filtered layer for each region,
// cut only at its last clip; Metal draws its contents once and takes them for the rest. Frame by frame against Velvet.
$check('a filtered layer in a frame redrawn in many regions is drawn once and is Velvet\'s', function () use ($metal, $velvet, $square, $painterOf) {
    $scene = fn (int $i) => fn (DrawingContext $g) => $g->clear(Color::hex('#203040'))
        // four small squares far apart change colour each frame: four regions, each reaching the layer
        ->fillRect(2, 2, 5, 5, Color::rgb(($i * 40) % 256, 0, 0))->fillRect(93, 2, 5, 5, Color::rgb(0, ($i * 40) % 256, 0))
        ->fillRect(2, 93, 5, 5, Color::rgb(0, 0, ($i * 40) % 256))->fillRect(93, 93, 5, 5, Color::rgb(($i * 40) % 256, ($i * 40) % 256, 0))
        ->layer(0, 0, 100, 100, fn (DrawingContext $g) => $g->fillRect(10, 10, 40, 40, Color::hex('#ff6b35'))->fillRect(30, 30, 40, 40, Color::rgba(64, 200, 255, 0.7)), 0.8, null, FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 2.5, 2.5)));
    $frames = [];
    foreach (['metal' => $metal, 'velvet' => $velvet] as $name => $engine) {
        $surface = $engine->offscreen(100, 100);
        for ($i = 0; $i < 6; $i++) {
            $frames[$name][$i] = $surface->render($scene($i))->read();
        }
        $last[$name] = $painterOf($surface);
    }
    $inner = $last['metal'];
    $worst = 0;
    foreach ($frames['metal'] as $i => $bytes) {
        for ($b = 0, $n = strlen($bytes); $b < $n; $b++) {
            $worst = max($worst, abs(ord($bytes[$b]) - ord($frames['velvet'][$i][$b])));
        }
    }
    echo "     last frame: ".count($last['metal']->damage())." regions, layer drawn {$inner->layerCounts['drawn']}, reused {$inner->layerCounts['reused']}; worst byte over 6 frames {$worst}\n";

    return $inner->layerCounts['drawn'] === 1 && $inner->layerCounts['reused'] > 0 && $worst <= 1;
});
foreach ($scenes as $name => $draw) {
    $check("{$name} is Velvet's, but for edges", function () use ($painted, $draw) {
        [$differ, $worst] = $painted($draw);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 3000 && $worst <= 128;
    });
}

// layers: drawn apart and laid down at an opacity, against Velvet's
$shapes = fn (DrawingContext $g) => $g->fillRect(0, 0, 60, 40, Color::hex('#ff6b35'))->fillEllipse(40, 30, 25, 20, Color::rgba(64, 200, 255, 0.7));
$layers = [
    'at half opacity' => fn (DrawingContext $g) => $g->layer(10, 10, 70, 60, $shapes, 0.5),
    'with rounded corners' => fn (DrawingContext $g) => $g->layer(10, 10, 70, 60, $shapes, 1.0, [12, 12, 12, 12]),
    'moved, and partly off the surface' => fn (DrawingContext $g) => $g->translate(60, 70)->layer(0, 0, 70, 60, $shapes, 0.8),
    'cut by a clip' => fn (DrawingContext $g) => $g->clip(new Region(30, 20, 30, 60))->layer(10, 10, 70, 60, $shapes, 0.6),
    'holding a layer' => fn (DrawingContext $g) => $g->layer(5, 5, 90, 90, fn (DrawingContext $g) => $g->fillRect(0, 0, 90, 90, Color::rgba(255, 255, 255, 0.4))->layer(10, 10, 70, 60, $shapes, 0.5, [8, 8, 8, 8]), 0.7),
    'twice, the same size' => fn (DrawingContext $g) => $g->layer(0, 0, 50, 50, $shapes, 0.5)->layer(40, 40, 50, 50, $shapes, 0.9),
];
foreach ($layers as $name => $draw) {
    $check("a layer {$name} is Velvet's, but for its edges", function () use ($painted, $draw) {
        [$differ, $worst] = $painted(fn (DrawingContext $g) => $draw($g->clear(Color::hex('#203040'))));
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 3000 && $worst <= 128;
    });
}
$check('a layer that changes size a little keeps its sheet', function () use ($metal, $shapes, $painterOf) {
    $surface = $metal->offscreen(200, 100);
    $made = [];
    for ($i = 0; $i < 30; $i++) {
        $surface->render(fn (DrawingContext $g) => $g->clear(Color::hex('#000000'))->layer(0, 0, 70 + $i, 50, $shapes, 0.5));
        $made[spl_object_id((fn () => $this->sheets)->call($painterOf($surface))['128x64'][0])] = true;
    }

    return count($made) === 1;
});
$check('layers of a moving frame keep their sheets from frame to frame', function () use ($metal, $shapes, $pixel, $painterOf) {
    $surface = $metal->offscreen(100, 100);
    for ($i = 0; $i < 20; $i++) {
        $surface->render(fn (DrawingContext $g) => $g->clear(Color::hex('#000000'))->layer($i, 10, 50, 50, $shapes, 0.5));
    }

    $sheets = array_map('count', (fn () => $this->sheets)->call($painterOf($surface)));
    echo '     sheets held after 20 frames: '.json_encode($sheets)."\n";

    return $sheets === ['64x64' => 1] && $pixel($surface, 90, 90) === '000000ff' && $pixel($surface, 21, 12) !== '000000ff';
});

// gradients: each kind and spread, against Velvet's
$star = (new Path)->moveTo(50, 5)->lineTo(61, 38)->lineTo(95, 38)->lineTo(67, 58)->lineTo(78, 92)->lineTo(50, 71)->lineTo(22, 92)->lineTo(33, 58)->lineTo(5, 38)->lineTo(39, 38)->close();
$stops = [[0.0, Color::hex('#ff6b35')], [0.5, Color::rgba(64, 200, 255, 0.5)], [1.0, Color::hex('#f7f052')]];
$gradients = [
    'a linear gradient' => new Surface\NutsAndBolts\LinearGradient(10, 10, 90, 60, $stops),
    'a radial gradient' => new Surface\NutsAndBolts\RadialGradient(50, 50, 40, $stops),
    'a repeating linear gradient' => new Surface\NutsAndBolts\LinearGradient(20, 0, 40, 0, $stops, Surface\NutsAndBolts\GradientSpread::REPEAT),
    'a reflected radial gradient' => new Surface\NutsAndBolts\RadialGradient(50, 50, 15, $stops, Surface\NutsAndBolts\GradientSpread::REFLECT),
];
foreach ($gradients as $name => $gradient) {
    $check("{$name} fills a path as Velvet's does, but for its edges", function () use ($painted, $star, $gradient) {
        [$differ, $worst] = $painted(fn (DrawingContext $g) => $g->clear(Color::hex('#101820'))->rotate(0.2)->fill($star, $gradient));
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 3000 && $worst <= 128;
    });
}

// images: a picture drawn by each engine onto one of its own framebuffers, then drawn as an image
$images = function (Closure $draw) use ($metal, $velvet, $difference): array {
    $out = [];
    foreach (['metal' => $metal, 'velvet' => $velvet] as $name => $engine) {
        // a picture of single opaque pixels, every third left transparent. An opaque pixel-sized square is the
        // same bytes on every engine. A half-transparent one is not: an engine blends it over the transparent
        // pixel under it by its own rule, and nothing sets a pixel as it is (clear() takes no clip).
        $picture = $engine->offscreen(20, 12)->render(function (DrawingContext $g): void {
            for ($y = 0; $y < 12; $y++) {
                for ($x = 0; $x < 20; $x++) {
                    if (($x + $y) % 3 !== 0) {
                        $g->fillRect($x, $y, 1, 1, Color::rgb($x * 12, $y * 20, 200 - $x * 5));
                    }
                }
            }
        });
        // what the checks are worth: a picture of many colours, not one
        $colours = count(array_unique(str_split($picture->read(), 4)));
        if ($colours < 150) {
            throw new RuntimeException("{$name}'s picture has {$colours} colours, not a picture's");
        }
        $out[$name] = $engine->offscreen(100, 100)->render(fn (DrawingContext $g) => $draw($g->clear(Color::hex('#203040')), $picture))->read();
    }

    return $difference($out['metal'], $out['velvet']);
};
$pictures = [
    'at its own size' => [fn (DrawingContext $g, $p) => $g->image($p, 10, 10), 2],
    'four times the size, nearest' => [fn (DrawingContext $g, $p) => $g->image($p, 10, 10, 80, 48), 2],
    'four times the size, smooth' => [fn (DrawingContext $g, $p) => $g->image($p, 10, 10, 80, 48, filter: Surface\Contracts\Rendering\Framebuffers\Filter::LINEAR), 6],
    'half see-through and tinted' => [fn (DrawingContext $g, $p) => $g->image($p, 10, 10, 80, 48, opacity: 0.5, tint: Color::rgba(255, 128, 64, 0.8)), 4],
    'a part of it only' => [fn (DrawingContext $g, $p) => $g->image($p, 10, 10, 60, 60, sourceRect: [4, 2, 8, 8]), 2],
    'with rounded corners' => [fn (DrawingContext $g, $p) => $g->image($p, 10, 10, 80, 48, radii: [10, 10, 10, 10]), 128],
    'turned and cut by a clip' => [fn (DrawingContext $g, $p) => $g->clip(new Region(20, 20, 50, 50))->rotate(0.3)->image($p, 30, 0, 60, 36), 128],
];
foreach ($pictures as $name => [$draw, $allowed]) {
    $check("an image {$name} is Velvet's, within {$allowed}", function () use ($images, $draw, $allowed) {
        [$differ, $worst] = $images($draw);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $worst <= $allowed;
    });
}
$check("it draws an image from its own engine's surfaces only, and not a surface onto itself", function () use ($metal) {
    $surface = $metal->offscreen(8, 8);
    try {
        $surface->render(fn (DrawingContext $g) => $g->image($surface, 0, 0));
    } catch (Surface\Contracts\Graphics\DrawingException $e) {
        return str_contains($e->getMessage(), 'onto itself');
    }

    return false;
});
$check("it refuses another engine's surface as an image", function () use ($metal, $velvet) {
    $theirs = $velvet->offscreen(4, 4);
    try {
        $metal->offscreen(8, 8)->render(fn (DrawingContext $g) => $g->image($theirs, 0, 0));
    } catch (GraphicsException) {
        return true;
    }

    return false;
});

// a stage that lends an NSView: a bare one, in no window, so nothing is shown on screen
$stage = new class implements Surface\Contracts\Graphics\ActorStage {
    public NSView $view;

    public int $shown = 0;

    public function __construct()
    {
        $this->view = NSView::initWithFrame(new NSRect(0.0, 0.0, 120.0, 60.0));
    }

    public function pixelSize(): array
    {
        return [240, 120];
    }

    public function pixelScale(): float
    {
        return 2.0;
    }

    public function display(string $rgba8, int $width, int $height): void
    {
        $this->shown++;
    }

    public function nativeSurface(): ?object
    {
        return $this->view;
    }
};
$check('on a stage that lends an NSView, frames go to a layer of its own over the view, not through display()', function () use ($metal, $stage, $pixel) {
    $surface = $metal->surface($stage);
    for ($i = 0; $i < 5; $i++) {
        $surface->render(fn (DrawingContext $g) => $g->clear(Color::hex('#101820'))->fillEllipse(60 + $i, 30, 20, 20, Color::hex('#ff6b35')))->present();
    }
    $again = $metal->surface($stage);
    $again->present();
    $added = count($stage->view->subviews());
    $metal->release();

    $read = [$pixel($surface, 4, 4), $pixel($surface, 64, 30)];
    echo "     display() calls {$stage->shown}, frames shown {$surface->shown()}, views added {$added}, left after release ".count($stage->view->subviews()).', pixels '.implode(' ', $read)."\n";

    return $stage->shown === 0 && $surface->shown() === 5 && $added === 1 && count($stage->view->subviews()) === 0 && $read === ['101820ff', 'ff6b35ff'];
});
echo "ok {$ok}, failed {$bad}\n";

