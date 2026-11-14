<?php
/*
 * G5: what a filtered layer, a backdrop and a glass pane cost on Metal, at the kitchen sink's canvas size (1100 x 360
 * pixels). Each frame changes the content under the effect, so the effect is redrawn every frame. Times are the median
 * of 7 runs of 60 frames; memory is the graphics memory the process holds after the runs over what it held after one
 * plain frame, as macOS's footprint tool accounts it. Opens no window.
 *
 *   zhp tests/measure.php /path/to/venusian/topside
 */
use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Graphics\Effects\Blur;
use Surface\Contracts\Graphics\Effects\BlurSampling;
use Surface\Contracts\Graphics\Effects\ColorMatrix;
use Surface\Contracts\Graphics\Effects\FilterChain;
use Surface\Contracts\Graphics\Effects\Glass;
use Surface\Graphics\GEPainter;
use Surface\NutsAndBolts\Color;
use Surface\VelvetGE\Recording\CLandQueue;
use Surface\VelvetGE\Recording\VelvetRecorder;

$topside = $argv[1] ?? throw new RuntimeException('Give the path to venusian/topside.');
$loader = require "{$topside}/vendor/autoload.php";
$loader->addPsr4('Jovian\\Engines\\Metal\\', dirname(__DIR__).'/src');

function graphicsMemory(): float
{
    $mb = 0.0;
    foreach (explode("\n", shell_exec('footprint -p '.getmypid().' 2>/dev/null') ?? '') as $line) {
        if (preg_match('/^\s*([\d.]+)\s*(KB|MB|GB)\s.*\b(IOAccelerator|IOSurface)\b/', $line, $m)) {
            $mb += (float) $m[1] * ['KB' => 1 / 1024, 'MB' => 1, 'GB' => 1024][$m[2]];
        }
    }

    return $mb;
}

[$w, $h] = [1100, 360];
$device = new MetalDevice(recorder: fn () => new VelvetRecorder(new CLandQueue()));
$frame = $device->framebuffer($w, $h);
$painter = $device->painter($frame);
$under = function (GEPainter $g, int $i) use ($w, $h): GEPainter {
    $g->clear(Color::hex('#101820'));
    for ($s = 0; $s < 20; $s++) {
        $g->fillRect(($s * 60 + $i * 3) % $w, 0, 30, $h, Color::rgb(($s * 70) % 256, ($s * 130 + 60) % 256, 180));
    }

    return $g;
};
$painter->frame(fn (GEPainter $g) => $under($g, 0));
$base = graphicsMemory();

$effects = [
    'filters' => fn (GEPainter $g) => $g->layer(100, 40, 600, 280, fn (GEPainter $g) => $g->fillRect(20, 20, 400, 200, Color::hex('#ff6b35'))->fillEllipse(400, 140, 120, 90, Color::hex('#40c8ff')), 0.9, [24, 24, 24, 24], FilterChain::of(new Blur(BlurSampling::GAUSSIAN, 6.0, 6.0), ColorMatrix::saturate(1.4))),
    'backdrop' => fn (GEPainter $g) => $g->layer(100, 40, 600, 280, fn (GEPainter $g) => $g->fillRect(0, 0, 600, 280, Color::rgba(255, 255, 255, 0.2)), 1.0, [24, 24, 24, 24], null, FilterChain::of(new Blur(BlurSampling::GAUSSIAN, 8.0, 8.0))),
    'glass' => fn (GEPainter $g) => $g->glass(100, 40, 600, 280, [24, 24, 24, 24], new Glass(16.0, Color::rgba(255, 255, 255, 0.15), 1.2, 0.03, Color::rgba(255, 255, 255, 0.5), 6.0, 4.0, 0.3, 0.6)),
];
$results = [];
foreach ($effects as $name => $effect) {
    $runs = [];
    for ($run = 0; $run < 7; $run++) {
        $t = microtime(true);
        for ($i = 1; $i <= 60; $i++) {
            $painter->frame(fn (GEPainter $g) => $effect($under($g, $run * 60 + $i)));
        }
        $runs[] = (microtime(true) - $t) / 60 * 1000;
    }
    sort($runs);
    $results[$name] = $runs[3];
    printf("%-9s %.2f ms a frame (median of 7 runs of 60; fastest %.2f, slowest %.2f)\n", $name, $runs[3], $runs[0], $runs[6]);
}
$plain = [];
for ($run = 0; $run < 7; $run++) {
    $t = microtime(true);
    for ($i = 1; $i <= 60; $i++) {
        $painter->frame(fn (GEPainter $g) => $under($g, $run * 60 + $i));
    }
    $plain[] = (microtime(true) - $t) / 60 * 1000;
}
sort($plain);
printf("the same frames with no effect: %.2f ms a frame\n", $plain[3]);
$memory = graphicsMemory() - $base;
printf("graphics memory held for the effects, over a plain frame: %.1f MB\n", $memory);
printf("MEASURED filters=%.2fms backdrop=%.2fms glass=%.2fms memory=%.1fMB\n", $results['filters'], $results['backdrop'], $results['glass'], $memory);
