<?php

namespace Jovian\Engines\Metal;

use MTLCommandBuffer;
use MTLOrigin;
use MTLPixelFormat;
use MTLPrimitiveType;
use MTLRegion;
use MTLScissorRect;
use MTLSize;
use MTLStorageMode;
use MTLTexture;
use MTLTextureUsage;
use Surface\Contracts\Graphics\DrawingException;
use Surface\Contracts\Graphics\Effects\Glass;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Recording\Recorder;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Painters\GlassGroups;
use Surface\Rendering\Painters\Painter;
use Surface\Rendering\Rasterization\Stroker;

/**
 * The metal engine's painter. Recording, frame comparison and damage are the shared Painter's;
 * this class carries out a frame's commands on the GPU: every vertex in one buffer, every
 * command in one render pass, one submit, and a wait for the GPU before end() returns.
 *
 * Each command's clip is a scissor rect. Shapes with curved edges (ellipses, rings, rounded
 * boxes) are one rectangle each, with a shader that decides per sample whether a sample is
 * inside; paths and polylines are stencilled, then covered. Shadows are worked out per pixel.
 *
 * A gradient fill is a path's cover drawn through the gradient's 256-colour ramp, held as a
 * texture. An image is another of the engine's framebuffers, drawn as a rectangle that reads
 * its texture.
 *
 * A layer's contents are drawn first, into a texture of their own the size of the layer, and
 * filtered there when the layer has a filter; the layer is then one rectangle that reads that
 * texture, inside its rounded box, at its opacity. A layer's backdrop is what is under it, read
 * from the frame so far, filtered, and laid down inside the same box before the contents.
 *
 * A glass pane reads what is under it too: a group of panes nothing came between shares one
 * capture, taken down a Kawase pyramid as Velvet's is. The pyramid lives for the frame. (Velvet
 * keeps one from frame to frame while nothing under it changed; that only pays on a framebuffer
 * that is drawn whole every frame, and this one keeps its frame and redraws only what changed, so
 * a pane is only ever redrawn when something under it did.) Filters, captures and pyramids run in
 * MetalFilters.
 *
 * Where a command reads what is drawn so far (a backdrop, a pane's capture), the pass over that
 * frame ends there and a new one begins after it. The whole frame is one command buffer.
 */
class MetalPainter extends Painter
{
    /** A sheet's sides are multiples of this many pixels. */
    protected const int STEP = 64;

    /** How many gradient ramps are kept as textures between frames. */
    protected const int RAMPS = 32;

    /** @var array<string, MTLTexture> a gradient's ramp as a 256 x 1 texture, by its bytes; the oldest first */
    protected array $ramps = [];

    /** @var array<string, list<MetalFramebuffer>> what layers are drawn in, by their rounded size ("WxH"), kept from frame to frame */
    protected array $sheets = [];

    /** @var array<string, int> how many sheets of each size this frame has lent */
    protected array $lent = [];

    protected readonly MetalFilters $filters;

    /** How many glass pyramids the last frame built: for tests and measuring. */
    public int $pyramidsBuilt = 0;

    /** How many layers' contents the last frame drew and filtered, and how many it took from an earlier copy. */
    public array $layerCounts = ['drawn' => 0, 'reused' => 0];

    /**
     * The contents of the layers this frame has drawn, filter applied. A frame redrawn in several regions holds a copy
     * of each layer for each region it reaches, cut to it only at its last clip: its children, area and filter are the
     * same, so its contents are drawn once.
     *
     * @var list<array{array<int, mixed>, MTLTexture}>
     */
    protected array $layers = [];

    public function __construct(
        protected readonly MetalDevice $device,
        protected readonly MetalFramebuffer $target,
        protected readonly Edges $edges = Edges::ANTIALIASED,
        ?Recorder $recorder = null,
    ) {
        if (! is_null($recorder)) {
            $this->recordWith($recorder);
        }
        $this->filters = new MetalFilters($device);
    }

    public function name(): string
    {
        return 'metal';
    }

    public function framebuffer(): PaintTarget
    {
        return $this->target;
    }

    /**
     * @param list<array<int, mixed>> $commands
     *
     * @throws DrawingException When a command is one this painter does not carry out yet.
     * @throws MetalException When the GPU fails the frame.
     */
    protected function execute(array $commands): void
    {
        $this->lent = [];
        $this->pyramidsBuilt = 0;
        $this->layerCounts = ['drawn' => 0, 'reused' => 0];
        $this->layers = [];
        $buffer = $this->device->commands();
        try {
            $this->run($commands, $this->target, $buffer);
            $this->device->submit($buffer);
        } finally {
            $this->filters->release();
        }

        // a sheet no layer of this frame was drawn in goes
        foreach ($this->sheets as $size => $sheets) {
            $this->sheets[$size] = array_slice($sheets, 0, $this->lent[$size] ?? 0);
        }
        $this->sheets = array_filter($this->sheets);
    }

    /**
     * Encodes $commands as render passes over $onto, after the passes of the layers among them. A
     * pass ends where a command reads what is drawn so far, and the next begins after it.
     *
     * @param list<array<int, mixed>> $commands
     * @param int $ox where on the surface $onto starts: 0 for the framebuffer itself, a layer's corner for its sheet
     * @param bool $clear whether $onto starts from transparent black, as a layer's sheet does
     *
     * @throws DrawingException
     * @throws MetalException
     */
    protected function run(array $commands, MetalFramebuffer $onto, MTLCommandBuffer $buffer, int $ox = 0, int $oy = 0, bool $clear = false): void
    {
        $antialiased = $this->edges === Edges::ANTIALIASED;
        $samples = $antialiased ? MetalFramebuffer::SAMPLES : 1;
        $whole = new Region(0, 0, $this->target->width(), $this->target->height());

        // glass panes that can share a capture, and which group each pane is in
        $groups = GlassGroups::of($commands, $this->surface(), $this->commandBox(...));
        $groupOf = [];
        foreach ($groups as $g => $group) {
            foreach ($group['members'] as $member) {
                $groupOf[$member] = $g;
            }
        }
        $built = [];

        // the vertices and draws of the pass being gathered; flush() encodes it
        $floats = [];
        $draws = [];
        $vertices = 0;
        $first = true;
        $add = function (array $points) use (&$floats, &$vertices): array {
            array_push($floats, ...$points);
            $start = $vertices;
            $vertices += intdiv(count($points), 2);

            return [$start, $vertices - $start];
        };
        $box = fn (float $x0, float $y0, float $x1, float $y1): array => $add([$x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1]);
        $flush = function () use (&$floats, &$draws, &$vertices, &$first, $onto, $buffer, $antialiased, $samples, $ox, $oy, $clear): void {
            if ($draws === [] && ! ($first && $clear)) {
                return;
            }
            // made before the encoder: an encoder dropped without endEncoding() ends the process
            $vertexBuffer = $this->device->buffer(pack('g*', ...($floats ?: [0.0, 0.0])));
            $states = array_map(fn (array $draw): array => [$this->device->pipeline($draw[0], $samples, $draw[6]), $this->device->stencil($draw[1])], $draws);
            $encoder = $onto->begin($buffer, $antialiased, $vertexBuffer, $ox, $oy, $first && $clear);
            try {
                $bounds = new Region($ox, $oy, $onto->width(), $onto->height());
                foreach ($draws as $i => $draw) {
                    [, , $start, $count, $bytes, $clip] = $draw;
                    /** @var Region $clip in surface pixels: the scissor is in $onto's */
                    $clip = $clip->intersect($bounds);
                    if (is_null($clip) || $clip->isEmpty()) {
                        continue;
                    }
                    $encoder->setScissorRect(new MTLScissorRect($clip->x - $ox, $clip->y - $oy, $clip->width, $clip->height));
                    $encoder->setRenderPipelineState($states[$i][0]);
                    $encoder->setDepthStencilState($states[$i][1]);
                    if ($bytes !== '') {
                        $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
                    }
                    foreach ([7 => 0, 8 => 1] as $at => $index) {
                        if (isset($draw[$at])) {
                            $encoder->setFragmentTextureAtIndex($draw[$at], $index);
                        }
                    }
                    $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $start, $count);
                }
            } finally {
                $encoder->endEncoding();
            }
            $onto->drew($antialiased);
            [$floats, $draws, $vertices, $first] = [[], [], 0, false];
        };

        foreach ($commands as $index => $command) {
            $kind = $command[0];
            switch ($kind) {
                case 'clear':
                    $draws[] = ['solid', 'plain', ...$box(0, 0, $whole->width, $whole->height), self::paint($command[1]), $command[2] ?? $whole, false];
                    break;
                case 'path':
                    [, $contours, $rule, $rgba, $clip] = $command;
                    $this->stencilled($draws, $add, $contours, $rule, $rgba, $clip);
                    break;
                case 'gradpath':
                    [, $contours, $rule, $gradient, $clip] = $command;
                    $inverse = $gradient['inverse'];
                    $uniform = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, 0.0, 0.0)
                        .pack('V4', $gradient['kind'] === 'radial' ? 1 : 0, match ($gradient['spread']) {
                            GradientSpread::PAD => 0, GradientSpread::REPEAT => 1, GradientSpread::REFLECT => 2,
                        }, 0, 0);
                    $this->stencilled($draws, $add, $contours, $rule, 0, $clip, ['gradient', $uniform, $this->ramp($gradient['ramp'])]);
                    break;
                case 'image':
                    [, $source, $placement, $opacity, $filter, $style, $clip] = $command;
                    if (! $source instanceof MetalFramebuffer || $source->engine() !== $this->device) {
                        throw new DrawingException('The metal painter draws an image from one of its own engine\'s framebuffers, got '.get_debug_type($source).'.');
                    }
                    if ($source === $this->target) {
                        throw new DrawingException('The metal painter cannot draw a framebuffer onto itself.');
                    }
                    $inverse = $placement->inverse();
                    if (is_null($inverse)) {
                        break;
                    }
                    [$sw, $sh] = [(float) $source->width(), (float) $source->height()];
                    [$tint, $window, $round] = [$style['tint'] ?? 0xFFFFFFFF, $style['window'] ?? null, $style['round'] ?? null];
                    $uniform = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, $sw, $sh)
                        .pack('V4', $opacity, $filter === Filter::LINEAR ? 1 : 0, 0, 0)
                        .pack('V4', ...self::bytes($tint))
                        .pack('g4', ...($window ?? [0.0, 0.0, 0.0, 0.0]))
                        .pack('g8', ...(is_null($round) ? [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0] : [$round[0], $round[1], $round[2], $round[3], ...$round[4]]))
                        .pack('V4', is_null($round) ? 0 : 1, is_null($window) ? 0 : 1, 0, 0);
                    // the source's four corners where the placement puts them: two triangles
                    [$a, $b, $c, $d] = [$placement->apply(0.0, 0.0), $placement->apply($sw, 0.0), $placement->apply($sw, $sh), $placement->apply(0.0, $sh)];
                    $draws[] = ['image', 'plain', ...$add([...$a, ...$b, ...$c, ...$a, ...$c, ...$d]), $uniform, $clip, true, $source->texture()];
                    break;
                case 'layer':
                    [, $area, $geometry, $children, $alpha, $filter, $backdrop, $clip] = $command;
                    if ($area->isEmpty()) {
                        break;
                    }
                    [$x0, $y0, $x1, $y1, $radii] = $geometry;
                    if (! is_null($backdrop)) {
                        // what is under the layer so far, filtered, laid down inside its box
                        $flush();
                        $outer = self::grown($area, $backdrop->reach(), $this->surface());
                        $under = $this->filters->grab($buffer, $onto->texture(), $onto->width(), $onto->height(), $outer->x - $ox, $outer->y - $oy, $outer->width, $outer->height, false);
                        $under = $this->filters->run($backdrop, $buffer, $under, $outer->width, $outer->height, $outer->x, $outer->y);
                        $draws[] = ['sheet', 'plain', ...$box($x0, $y0, $x1, $y1), pack('g12', $x0, $y0, $x1, $y1, ...$radii, ...[(float) $outer->x, (float) $outer->y, 1.0, 0.0]), $clip, true, $under];
                    }
                    // its contents, in a sheet of their own: these passes come before this one in the buffer. A copy of a
                    // layer already drawn this frame (the same children, area and filter, another region) takes its contents.
                    // Contents that read what is under them (glass, a backdrop) depend on the region drawn first: never taken.
                    $reads = self::reads($children);
                    $same = [$area->x, $area->y, $area->width, $area->height, $children, $filter];
                    $contents = null;
                    if (! $reads) {
                        foreach ($this->layers as [$drawn, $texture]) {
                            if ($drawn === $same) {
                                $contents = $texture;
                                $this->layerCounts['reused']++;
                                break;
                            }
                        }
                    }
                    if (is_null($contents)) {
                        $sheet = $this->sheet($area->width, $area->height, transient: ! $reads);
                        $this->run($children, $sheet, $buffer, $area->x, $area->y, true);
                        $contents = $sheet->texture();
                        if (! is_null($filter)) {
                            $contents = $this->filters->run($filter, $buffer, $contents, $area->width, $area->height, $area->x, $area->y);
                        }
                        $this->layerCounts['drawn']++;
                        if (! $reads) {
                            $this->layers[] = [$same, $contents];
                        }
                    }
                    $draws[] = ['sheet', 'plain', ...$box($x0, $y0, $x1, $y1), pack('g12', $x0, $y0, $x1, $y1, ...$radii, ...[(float) $area->x, (float) $area->y, $alpha / 255, 0.0]), $clip, true, $contents];
                    break;
                case 'glass':
                    [, $area, $geometry, $look, $blur, $shadow, $clip] = $command;
                    $g = $groupOf[$index];
                    if (! isset($built[$g])) {
                        $flush();
                        $built[$g] = $this->pyramid($groups[$g], $onto, $buffer, $ox, $oy);
                    }
                    $panes = $built[$g];
                    if (! is_null($shadow)) {
                        // the shadow, after the backdrop was read and before the pane
                        [, $sx0, $sy0, $sx1, $sy1, $radius, $spread, $rgba, $shadowClip] = $shadow;
                        $shade = pack('V4g4g4g4', ...self::bytes($rgba), ...[$sx0, $sy0, $sx1, $sy1], ...[0.0, 0.0, 0.0, 0.0], ...[0.0, 0.0, $spread, $radius]);
                        $draws[] = ['shade', 'plain', ...$box($sx0 - $spread - 1, $sy0 - $spread - 1, $sx1 + $spread + 1, $sy1 + $spread + 1), $shade, $shadowClip, true];
                    }
                    [$x0, $y0, $x1, $y1, $radii] = $geometry;
                    [$frost, $refraction, $bevel, $saturation, $light, $grain, $tint, $rim, $paneAlpha] = $look;
                    [$level, $levelWidth, $levelHeight] = $panes['samples'][Glass::levels($blur)];
                    [$sharp, $sharpWidth, $sharpHeight] = $panes['sharp'];
                    $uniform = pack('g4', $x0, $y0, $x1, $y1).pack('g4', ...$radii)
                        .pack('g4', $frost, $refraction, $bevel, $saturation).pack('g4', $light, $grain, $paneAlpha, 2.0)
                        .pack('V4', ...self::bytes($tint)).pack('V4', ...self::bytes($rim))
                        .pack('g2', (float) $panes['region']->x, (float) $panes['region']->y).pack('V2', $frost < 1.0 ? 1 : 0, 0)
                        .pack('l4', $levelWidth, $levelHeight, $sharpWidth, $sharpHeight);
                    $draws[] = ['pane', 'plain', ...$box(floor($x0), floor($y0), ceil($x1), ceil($y1)), $uniform, $clip, true, $level, $sharp];
                    break;
                case 'polyline':
                    [, $points, $stroke, $closed, $rgba, $clip] = $command;
                    $outline = array_map(fn (array $flat): array => array_chunk($flat, 2), Stroker::outline($points, $stroke, $closed));
                    $this->stencilled($draws, $add, $outline, FillRule::NON_ZERO, $rgba, $clip);
                    break;
                case 'ellipse':
                case 'ring':
                    [$cx, $cy, $rx, $ry] = array_slice($command, 1, 4);
                    [$stroke, $rgba, $clip] = $kind === 'ring' ? array_slice($command, 5, 3) : [0.0, $command[5], $command[6]];
                    $reach = $stroke / 2 + 1;
                    $oval = pack('V4g4g4', ...self::bytes($rgba), ...[$cx, $cy, $rx, $ry, $stroke, $kind === 'ring' ? 1.0 : 0.0, 0.0, 0.0]);
                    $draws[] = ['oval', 'plain', ...$box($cx - $rx - $reach, $cy - $ry - $reach, $cx + $rx + $reach, $cy + $ry + $reach), $oval, $clip, true];
                    break;
                case 'roundrect':
                case 'roundring':
                    [$x0, $y0, $x1, $y1, $radii] = array_slice($command, 1, 5);
                    [$inset, $rgba, $clip] = $kind === 'roundring' ? array_slice($command, 6, 3) : [0.0, $command[6], $command[7]];
                    $round = pack('V4g4g4g4', ...self::bytes($rgba), ...[$x0, $y0, $x1, $y1], ...$radii, ...[$inset, $kind === 'roundring' ? 1.0 : 0.0, 0.0, 0.0]);
                    $draws[] = ['roundbox', 'plain', ...$box($x0, $y0, $x1, $y1), $round, $clip, true];
                    break;
                case 'shadow':
                    [, $x0, $y0, $x1, $y1, $radius, $blur, $rgba, $clip] = $command;
                    $shade = pack('V4g4g4g4', ...self::bytes($rgba), ...[$x0, $y0, $x1, $y1], ...[0.0, 0.0, 0.0, 0.0], ...[0.0, 0.0, $blur, $radius]);
                    $draws[] = ['shade', 'plain', ...$box($x0 - $blur - 1, $y0 - $blur - 1, $x1 + $blur + 1, $y1 + $blur + 1), $shade, $clip, true];
                    break;
                case 'spans':
                    // whole pixels: a rectangle a span
                    [, $bytes, $rgba, $clip] = $command;
                    [$first, $count] = [$vertices, 0];
                    for ($at = 0, $end = strlen($bytes); $at < $end; $at += 7) {
                        ['y' => $y, 'x' => $x, 'length' => $length] = unpack('vy/vx/vlength', $bytes, $at);
                        $count += $box($x, $y, $x + $length, $y + 1)[1];
                    }
                    if ($count > 0) {
                        $draws[] = ['solid', 'plain', $first, $count, self::paint($rgba), $clip, true];
                    }
                    break;
                default:
                    throw new DrawingException("The metal painter does not know the command '{$kind}'.");
            }
        }
        $flush();
    }

    /**
     * The backdrop of a group of glass panes: its region read from what $onto holds so far,
     * straight, and taken down a Kawase pyramid (1/2, 1/4, 1/8, 1/16 as the panes' blurs need),
     * then up to half size for each depth a pane uses, as Velvet's painter builds it. Its levels
     * come from the frame's pool.
     *
     * @param array{region: Region, depths: list<int>, members: list<int>} $group
     * @return array{region: Region, samples: array<int, array{MTLTexture, int, int}>, sharp: array{MTLTexture, int, int}}
     *
     * @throws MetalException
     */
    protected function pyramid(array $group, MetalFramebuffer $onto, MTLCommandBuffer $buffer, int $ox, int $oy): array
    {
        ['region' => $region, 'depths' => $depths] = $group;
        $this->pyramidsBuilt++;

        $sharp = $this->filters->grab($buffer, $onto->texture(), $onto->width(), $onto->height(), $region->x - $ox, $region->y - $oy, $region->width, $region->height, true);
        $down = [[$sharp, $region->width, $region->height]];
        for ($k = 1, $deepest = max($depths); $k <= $deepest; $k++) {
            [$source, $w, $h] = $down[$k - 1];
            $down[$k] = [$this->filters->kawaseDown($buffer, $source, $w, $h), intdiv($w + 1, 2), intdiv($h + 1, 2)];
        }
        $samples = [];
        foreach ($depths as $depth) {
            $up = $down[$depth];
            for ($k = $depth - 1; $k >= 1; $k--) {
                [, $w, $h] = $down[$k];
                $up = [$this->filters->kawaseUp($buffer, $up[0], $up[1], $up[2], $w, $h), $w, $h];
            }
            $samples[$depth] = $up;
        }

        return ['region' => $region, 'samples' => $samples, 'sharp' => $down[0]];
    }

    /** Whether any of $commands reads what is drawn under it: then the pass over their sheet has to end there. */
    protected static function reads(array $commands): bool
    {
        foreach ($commands as $command) {
            if ($command[0] === 'glass' || ($command[0] === 'layer' && ! is_null($command[6]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A sheet at least that size that no layer of this frame has yet: one kept from the last frame,
     * or a new one. Sizes are rounded up to a step, so a layer whose size changes a little from
     * frame to frame keeps its sheet; it is drawn in the sheet's top-left part. A sheet whose
     * contents read what is drawn under them (glass, a backdrop) is drawn in more than one pass, so
     * it keeps its samples between passes: it is not transient.
     */
    protected function sheet(int $width, int $height, bool $transient = true): MetalFramebuffer
    {
        $width = intdiv($width + self::STEP - 1, self::STEP) * self::STEP;
        $height = intdiv($height + self::STEP - 1, self::STEP) * self::STEP;
        $size = ($transient ? '' : 'kept:')."{$width}x{$height}";
        $index = $this->lent[$size] ?? 0;
        $this->lent[$size] = $index + 1;

        return $this->sheets[$size][$index] ??= new MetalFramebuffer($this->device, $width, $height, transient: $transient);
    }

    /**
     * A filled shape by stencil, then cover: the contours as triangle fans into the stencil, then
     * one rectangle over their bounds in the colour where the stencil is not zero.
     *
     * @param list<list<array{float, float}>> $contours
     * @param array{string, string, MTLTexture}|null $cover the fragment, its bytes and its texture, in place of the colour
     */
    protected function stencilled(array &$draws, \Closure $add, array $contours, FillRule $rule, int $rgba, Region $clip, ?array $cover = null): void
    {
        $fans = [];
        [$left, $top, $right, $bottom] = [INF, INF, -INF, -INF];
        foreach ($contours as $contour) {
            $count = count($contour);
            if ($count < 3) {
                continue;
            }
            for ($i = 1; $i < $count - 1; $i++) {
                array_push($fans, ...$contour[0], ...$contour[$i], ...$contour[$i + 1]);
            }
            foreach ($contour as [$x, $y]) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }
        if ($fans === []) {
            return;
        }
        $draws[] = ['mark', $rule === FillRule::EVEN_ODD ? 'invert' : 'winding', ...$add($fans), '', $clip, true];
        $bounds = $add([$left, $top, $right, $top, $right, $bottom, $left, $top, $right, $bottom, $left, $bottom]);
        $draws[] = is_null($cover)
            ? ['solid', 'cover', ...$bounds, self::paint($rgba), $clip, true]
            : [$cover[0], 'cover', ...$bounds, $cover[1], $clip, true, $cover[2]];
    }

    /**
     * A gradient's ramp as a texture: 256 x 1, straight colour as the recorder made it. Kept by its
     * bytes, so a gradient drawn every frame is uploaded once.
     *
     * @throws MetalException
     */
    protected function ramp(string $ramp): MTLTexture
    {
        if (isset($this->ramps[$ramp])) {
            return $this->ramps[$ramp];
        }
        $texture = $this->device->texture(MTLPixelFormat::RGBA8_UNORM, 256, 1, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow(new MTLRegion(new MTLOrigin(0, 0, 0), new MTLSize(256, 1, 1)), 0, $ramp, 1024);
        if (count($this->ramps) >= self::RAMPS) {
            array_shift($this->ramps);
        }

        return $this->ramps[$ramp] = $texture;
    }

    /** @return array{int, int, int, int} 0xRRGGBBAA as its four bytes */
    protected static function bytes(int $rgba): array
    {
        return [($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
    }

    /** A Paint: the colour's four bytes as uint4. */
    protected static function paint(int $rgba): string
    {
        return pack('V4', ...self::bytes($rgba));
    }
}
