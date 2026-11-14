<?php

namespace Jovian\Engines\Metal;

use MTLCommandBuffer;
use MTLLoadAction;
use MTLOrigin;
use MTLPixelFormat;
use MTLPrimitiveType;
use MTLRegion;
use MTLRenderPassDescriptor;
use MTLSize;
use MTLStorageMode;
use MTLStoreAction;
use MTLTexture;
use MTLTextureUsage;
use MTLViewport;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\RaycastException;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Rendering\Raycasting\Raycaster;
use WeakMap;

/**
 * The metal engine's raycaster. Rendering checks every call and turns angles into vectors, in
 * double, as it does for every driver. Then each job goes where it is fastest:
 *
 *  - A view painted on a framebuffer stays on the GPU: one fragment walks a ray (raycast.metal),
 *    the view is drawn from the hits, and nothing comes back, so the CPU does not wait.
 *  - What comes back to the CPU (cast, casts, fan; a view as bytes) is one GPU pass and one read
 *    of its hits. A trip to the GPU and back costs about 0.26 ms before the first ray, so many
 *    rays go in one call (casts, fan), never one call a ray.
 *
 * A ray walks from its cell and where in that cell it is, so float32 holds small numbers only. The
 * cell, wall and face of a hit are the walk's own integers. Distances are float32: within a few
 * units in the last place of Velvet's doubles, and a wall's line is as tall as Velvet's or one
 * pixel off where that height falls on a whole number.
 *
 * The GPU holds textures up to 16384 a side, so the grid, the atlas of wall textures and a ray
 * list's rows are kept to that; a grid wider or taller is refused.
 */
class MetalCaster extends Raycaster
{
    /** The largest texture side the GPU takes. */
    public const int MAX_SIDE = 16384;

    /** The grid's cells, one byte each. */
    protected readonly MTLTexture $cells;

    /** @var array<int, true> the wall ids the grid holds */
    protected readonly array $ids;

    /** A ray list's hits, kept while ray lists are the same size. */
    protected ?MTLTexture $hits = null;

    /** Rays' own directions, kept while ray lists are the same size; and the 1x1 that stands in for none. */
    protected ?MTLTexture $directions = null;

    protected ?MTLTexture $noDirections = null;

    /** A view read out as bytes, kept while views are the same size. */
    protected ?MTLTexture $out = null;

    /** @var WeakMap<Scenery, array{MTLTexture, MTLTexture}> each scenery's atlas and wall table */
    protected WeakMap $atlases;

    /**
     * @throws MetalException When the grid is wider or taller than the GPU holds a texture.
     */
    public function __construct(
        protected readonly MetalDevice $device,
        Grid $grid,
    ) {
        parent::__construct($grid);

        if ($grid->width > self::MAX_SIDE || $grid->height > self::MAX_SIDE) {
            throw MetalException::because("a grid {$grid->width}x{$grid->height} is past the GPU's textures, ".self::MAX_SIDE.' a side.');
        }

        $this->cells = $device->texture(MTLPixelFormat::R8_UINT, $grid->width, $grid->height, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $this->cells->replaceRegionMipmapLevelWithBytesBytesPerRow(self::area(0, 0, $grid->width, $grid->height), 0, $grid->cells, $grid->width);

        $ids = [];
        foreach (str_split(count_chars($grid->cells, 3)) as $byte) {
            if ($byte !== "\0" && $byte !== '') {
                $ids[ord($byte)] = true;
            }
        }
        $this->ids = $ids;

        $this->atlases = new WeakMap;
    }

    public function driver(): string
    {
        return 'metal';
    }

    /** A view as RGBA8. The scenery's atlas is kept for the next call with it. */
    public function view(Camera $camera, int $width, int $height, Scenery $scenery, float $reach = INF): string
    {
        $arguments = self::pictureOf($camera, $width, $height, $scenery, $reach);

        return $this->viewBytes($arguments, $this->atlas($scenery));
    }

    /**
     * A view on a metal framebuffer at $where, drawn on the GPU from the GPU's hits.
     *
     * @internal
     *
     * @throws RaycastException When a ray hits a wall the scenery has no entry for: nothing is drawn.
     * @throws MetalException
     */
    public function paintOn(Camera $camera, Scenery $scenery, MetalFramebuffer $target, Region $where, float $reach): void
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = self::pictureOf($camera, $where->width, $where->height, $scenery, $reach);
        [$atlas, $walls] = $this->atlas($scenery);

        // rays and the view in one submission, and nothing comes back: the CPU does not wait for it
        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $commands = $this->checked($commands, $hits, $perRow, $width, $table);

        $target->cover($commands, $where, 'raycast_view', [$hits, $walls, $atlas], self::viewBytesOf($where->x, $where->y, $width, $height, $ceiling, $floor, $shade, $perRow, false));
        $this->device->submit($commands);
    }

    /**
     * Rays from ($x, $y), one an angle: their Hits records, in order. Checked as cast() checks one ray, and each
     * angle becomes its vector in double, as cast() makes it. Walked in one GPU pass.
     *
     * @param list<float> $angles
     *
     * @throws RaycastException
     * @throws MetalException
     */
    public function casts(float $x, float $y, array $angles, float $reach = INF): string
    {
        foreach (['x' => $x, 'y' => $y] as $what => $value) {
            if (! is_finite($value)) {
                throw RaycastException::notFinite("A ray's {$what}");
            }
            if (abs($value) > self::LIMIT) {
                throw RaycastException::pastLimit("A ray's {$what}", $value);
            }
        }
        if (! ($reach > 0.0)) {
            throw RaycastException::reach($reach);
        }
        if ($angles === []) {
            return '';
        }

        foreach ($angles as $angle) {
            if (! is_finite($angle)) {
                throw RaycastException::notFinite("A ray's angle");
            }
        }

        $directions = '';
        foreach ($angles as $angle) {
            $directions .= pack('g2', cos($angle), sin($angle));
        }

        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, 0.0, 0.0, 0.0, 0.0, count($angles), $reach, $directions);
        $this->device->settle($commands);

        return $this->records($hits, $perRow, count($angles));
    }

    protected function ray(float $x, float $y, float $dx, float $dy, float $reach): string
    {
        return $this->hitRecords($x, $y, $dx, $dy, 0.0, 0.0, 1, $reach);
    }

    protected function rays(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        return $this->hitRecords($x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach);
    }

    protected function picture(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $width, int $height, array $walls, int $ceiling, int $floor, int $shade, float $reach): string
    {
        return $this->viewBytes([$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $walls, $ceiling, $floor, $shade, $reach], $this->pack($walls));
    }

    /**
     * $columns rays as Hits records.
     *
     * @throws MetalException
     */
    protected function hitRecords(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach);
        $this->device->settle($commands);

        return $this->records($hits, $perRow, $columns);
    }

    /**
     * A view's straight RGBA8 bytes, from an offscreen texture.
     *
     * @param array{float, float, float, float, float, float, int, int, array, int, int, int, float} $arguments pictureOf()'s
     * @param array{MTLTexture, MTLTexture} $atlas the atlas and the wall table
     *
     * @throws RaycastException When a ray hits a wall with no entry.
     * @throws MetalException
     */
    protected function viewBytes(array $arguments, array $atlas): string
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = $arguments;
        [$texture, $walls] = $atlas;

        $commands = $this->device->commands();
        [$hits, $perRow] = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $commands = $this->checked($commands, $hits, $perRow, $width, $table);

        if (is_null($this->out) || $this->out->width() !== $width || $this->out->height() !== $height) {
            $this->out = $this->device->texture(MTLPixelFormat::RGBA8_UNORM, $width, $height, 1, MTLStorageMode::SHARED, MTLTextureUsage::RENDER_TARGET->value);
        }
        $this->pass($commands, $this->out, 'raycast_view', MTLPixelFormat::RGBA8_UNORM, [$hits, $walls, $texture], self::viewBytesOf(0, 0, $width, $height, $ceiling, $floor, $shade, $perRow, true));
        $this->device->settle($commands);

        return $this->device->bytes($this->out, $width * 4, self::area(0, 0, $width, $height));
    }

    /**
     * Encodes the hits pass: $columns rays, one a pixel.
     *
     * @return array{MTLTexture, int} the hits texture and how many rays a pair of its rows holds
     *
     * @throws MetalException
     */
    protected function hitsPass(MTLCommandBuffer $commands, float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach, ?string $directions = null): array
    {
        $perRow = min($columns, self::MAX_SIDE);
        $rows = 2 * intdiv($columns + $perRow - 1, $perRow);
        if (is_null($this->hits) || $this->hits->width() !== $perRow || $this->hits->height() !== $rows) {
            $this->hits = $this->device->texture(MTLPixelFormat::RGBA32_FLOAT, $perRow, $rows, 1, MTLStorageMode::SHARED, MTLTextureUsage::RENDER_TARGET->value | MTLTextureUsage::SHADER_READ->value);
        }

        // Velvet walks nothing from outside the grid: every ray misses
        $inside = $x >= 0.0 && $y >= 0.0 && $x < $this->grid->width && $y < $this->grid->height;
        $cellX = $inside ? (int) floor($x) : 0;
        $cellY = $inside ? (int) floor($y) : 0;
        // past what float32 holds, a reach stops nothing a grid this size can show
        $reaches = is_finite($reach) && $reach < 3.0e38;
        $flags = ($reaches ? 1 : 0) | ($inside ? 2 : 0) | (is_null($directions) ? 0 : 4);

        $cast = pack('l2g6V3gl2', $cellX, $cellY, $inside ? $x - $cellX : 0.0, $inside ? $y - $cellY : 0.0, $dirX, $dirY, $planeX, $planeY, $columns, $perRow, $flags, $reaches ? $reach : 0.0, $this->grid->width, $this->grid->height);
        $this->pass($commands, $this->hits, 'raycast_hits', MTLPixelFormat::RGBA32_FLOAT, [$this->cells, $this->directions($directions ?? '', $perRow, intdiv($rows, 2))], $cast);

        return [$this->hits, $perRow];
    }

    /**
     * When the grid holds a wall the table has no entry for, the hits are read before a view is drawn: a ray
     * on such a wall is refused, as Velvet refuses it, and nothing is drawn.
     *
     * @return MTLCommandBuffer what to go on encoding into
     *
     * @throws RaycastException
     */
    protected function checked(MTLCommandBuffer $commands, MTLTexture $hits, int $perRow, int $columns, array $table): MTLCommandBuffer
    {
        if (array_diff_key($this->ids, $table) === []) {
            return $commands;
        }

        $this->device->settle($commands);
        $records = $this->records($hits, $perRow, $columns);
        for ($i = 0; $i < $columns; $i++) {
            $wall = ord($records[$i * 22 + 20]);
            if ($wall !== 0 && ! isset($table[$wall])) {
                throw RaycastException::noWall($wall);
            }
        }

        return $this->device->commands();
    }

    /** The hits texture's rays as Hits records: 22 bytes each, all zero for a miss. */
    protected function records(MTLTexture $hits, int $perRow, int $columns): string
    {
        $rows = $hits->height();
        $floats = unpack('g*', $this->device->bytes($hits, $perRow * 16, self::area(0, 0, $perRow, $rows)));

        $out = '';
        for ($i = 0; $i < $columns; $i++) {
            // unpack counts from 1
            $first = ((2 * intdiv($i, $perRow)) * $perRow + $i % $perRow) * 4 + 1;
            $second = $first + $perRow * 4;
            $wall = (int) $floats[$second];
            $out .= $wall === 0
                ? str_repeat("\0", 22)
                : pack('eevvCC', $floats[$first], $floats[$first + 1], (int) $floats[$first + 2], (int) $floats[$first + 3], $wall, (int) $floats[$second + 1]);
        }

        return $out;
    }

    /**
     * Each ray's direction (RG32Float, ray i at i % $perRow, i / $perRow), kept while it is the same size. With no
     * directions, a 1x1 stands in: the pass binds a texture either way.
     */
    protected function directions(string $directions, int $perRow, int $rows): MTLTexture
    {
        if ($directions === '') {
            return $this->noDirections ??= $this->device->texture(MTLPixelFormat::RG32_FLOAT, 1, 1, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        }
        if (is_null($this->directions) || $this->directions->width() !== $perRow || $this->directions->height() !== $rows) {
            $this->directions = $this->device->texture(MTLPixelFormat::RG32_FLOAT, $perRow, $rows, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        }
        $this->directions->replaceRegionMipmapLevelWithBytesBytesPerRow(self::area(0, 0, $perRow, $rows), 0, str_pad($directions, $perRow * $rows * 8, "\0"), $perRow * 8);

        return $this->directions;
    }

    /**
     * The wall table as a texture: per wall id its kind, colour, and where its texture is in the atlas. Written
     * once, when it is made.
     *
     * @param array<int, int|array{string, int, int}> $table
     * @param array<int, array{int, int}> $places
     *
     * @throws MetalException
     */
    protected function wallTable(array $table, array $places): MTLTexture
    {
        $texels = str_repeat("\0", 256 * 16);
        foreach ($table as $id => $wall) {
            $texel = is_int($wall)
                ? pack('V4', 1, $wall, 0, 0)
                : pack('V4', 2, 0, $places[$id][0] | ($places[$id][1] << 16), $wall[1] | ($wall[2] << 16));
            $texels = substr_replace($texels, $texel, $id * 16, 16);
        }
        $walls = $this->device->texture(MTLPixelFormat::RGBA32_UINT, 256, 1, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        $walls->replaceRegionMipmapLevelWithBytesBytesPerRow(self::area(0, 0, 256, 1), 0, $texels, 256 * 16);

        return $walls;
    }

    /**
     * The scenery on the GPU: its wall textures in one atlas and its wall table, made the first time it is drawn
     * and never written again, so a frame the GPU is still drawing can go on reading them.
     *
     * @return array{MTLTexture, MTLTexture} the atlas, the wall table
     */
    protected function atlas(Scenery $scenery): array
    {
        return $this->atlases[$scenery] ??= $this->pack($scenery->table());
    }

    /**
     * Wall textures packed in rows into one RGBA8Uint texture, tallest first.
     *
     * @param array<int, int|array{string, int, int}> $table
     * @return array{MTLTexture, MTLTexture} the atlas and the wall table that says where each wall's texture is in it
     *
     * @throws MetalException When a texture, or all of them, is past the GPU's textures.
     */
    protected function pack(array $table): array
    {
        $textures = array_filter($table, is_array(...));
        if ($textures === []) {
            return [$this->device->texture(MTLPixelFormat::RGBA8_UINT, 1, 1, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value), $this->wallTable($table, [])];
        }

        $area = 0;
        $widest = 0;
        foreach ($textures as $id => [, $width, $height]) {
            if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
                throw MetalException::because("wall {$id}'s texture {$width}x{$height} is past the GPU's textures, ".self::MAX_SIDE.' a side.');
            }
            $area += $width * $height;
            $widest = max($widest, $width);
        }
        $side = min(self::MAX_SIDE, max($widest, (int) ceil(sqrt($area))));

        uasort($textures, fn (array $a, array $b): int => $b[2] <=> $a[2]);
        [$x, $y, $shelf, $places] = [0, 0, 0, []];
        foreach ($textures as $id => [, $width, $height]) {
            if ($x + $width > $side) {
                [$x, $y, $shelf] = [0, $y + $shelf, 0];
            }
            $places[$id] = [$x, $y];
            $x += $width;
            $shelf = max($shelf, $height);
        }
        $tall = $y + $shelf;
        if ($tall > self::MAX_SIDE) {
            throw MetalException::because("the scenery's wall textures need an atlas {$side}x{$tall}: past the GPU's textures, ".self::MAX_SIDE.' a side.');
        }

        $atlas = $this->device->texture(MTLPixelFormat::RGBA8_UINT, $side, $tall, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        foreach ($textures as $id => [$bytes, $width, $height]) {
            $atlas->replaceRegionMipmapLevelWithBytesBytesPerRow(self::area($places[$id][0], $places[$id][1], $width, $height), 0, $bytes, $width * 4);
        }

        return [$atlas, $this->wallTable($table, $places)];
    }

    /**
     * Encodes one pass over all of $target with $fragment.
     *
     * @param list<MTLTexture> $textures
     *
     * @throws MetalException
     */
    protected function pass(MTLCommandBuffer $commands, MTLTexture $target, string $fragment, MTLPixelFormat $format, array $textures, string $bytes): void
    {
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($target);
        $color->setLoadAction(MTLLoadAction::DONT_CARE);
        $color->setStoreAction(MTLStoreAction::STORE);

        // everything that can fail is made before the encoder: an encoder dropped without endEncoding() ends the process
        $pipeline = $this->device->filterPipeline($fragment, $format);
        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw MetalException::because('no render encoder could be made.');
        try {
            $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $target->width(), (float) $target->height(), 0.0, 1.0));
            $encoder->setRenderPipelineState($pipeline);
            foreach ($textures as $i => $texture) {
                $encoder->setFragmentTextureAtIndex($texture, $i);
            }
            $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
            $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 3);
        } finally {
            $encoder->endEncoding();
        }
    }

    /** The View struct of raycast.metal. */
    protected static function viewBytesOf(int $x, int $y, int $width, int $height, int $ceiling, int $floor, int $shade, int $perRow, bool $straight): string
    {
        return pack('l4V8', $x, $y, $width, $height, $ceiling, $floor, $shade, $perRow, $straight ? 1 : 0, 0, 0, 0);
    }

    protected static function area(int $x, int $y, int $width, int $height): MTLRegion
    {
        return new MTLRegion(new MTLOrigin($x, $y, 0), new MTLSize($width, $height, 1));
    }
}
