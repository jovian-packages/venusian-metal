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
use Surface\Contracts\Graphics\Effects\Blend;
use Surface\Contracts\Graphics\Effects\BlendMode;
use Surface\Contracts\Graphics\Effects\Blur;
use Surface\Contracts\Graphics\Effects\BlurSampling;
use Surface\Contracts\Graphics\Effects\Channel;
use Surface\Contracts\Graphics\Effects\ColorMatrix;
use Surface\Contracts\Graphics\Effects\Composite;
use Surface\Contracts\Graphics\Effects\CompositeOperator;
use Surface\Contracts\Graphics\Effects\Displace;
use Surface\Contracts\Graphics\Effects\EdgeMode;
use Surface\Contracts\Graphics\Effects\Field;
use Surface\Contracts\Graphics\Effects\FieldKind;
use Surface\Contracts\Graphics\Effects\FilterChain;
use Surface\Contracts\Graphics\Effects\FilterStep;
use Surface\Contracts\Graphics\Effects\Flood;
use Surface\Contracts\Graphics\Effects\Noise;
use Surface\Contracts\Graphics\Effects\NoiseType;
use Surface\Contracts\Graphics\Effects\Table;
use Surface\Contracts\Rendering\Framebuffers\Framebuffer;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandFilters;

/**
 * Filter chains, glass captures and the glass pyramid on the GPU. Every image is an RGBA8 texture; each step is one
 * pass that draws a triangle over the image and works out each pixel from its inputs (shaders/filters.metal).
 *
 * Images live for one frame: they come from a pool by size, rounded up to 64 pixels, and go back to it when the
 * frame ends (release()). A pool entry no frame used is let go then.
 */
class MetalFilters
{
    /** An image's sides are multiples of this many pixels in the pool. */
    protected const int STEP = 64;

    /** @var array<string, list<MTLTexture>> images by rounded size, kept from frame to frame */
    protected array $pool = [];

    /** @var array<string, int> how many of each size this frame has taken */
    protected array $taken = [];

    /** @var array<int, MTLTexture> each turbulence lattice's gradients as a texture, by seed */
    protected array $lattices = [];

    public function __construct(protected readonly MetalDevice $device) {}

    /**
     * Runs $chain over a $width x $height image, the chain's source. Every step works on premultiplied colour, as
     * PHPLandFilters::run() does; the result is premultiplied too.
     *
     * @param MTLTexture $source premultiplied colour, the image at its top left
     * @param int $originX where the image's top left lies on the surface: noise and fields are functions of position
     *
     * @throws MetalException
     */
    public function run(FilterChain $chain, MTLCommandBuffer $commands, MTLTexture $source, int $width, int $height, int $originX, int $originY): MTLTexture
    {
        $results = [FilterStep::SOURCE => $source];
        $previous = $source;
        foreach ($chain->steps as $step) {
            $input = fn (?string $name): MTLTexture => $name === null || $name === '' ? $previous : $results[$name];
            $made = match (true) {
                $step instanceof Blur => $this->blur($commands, $input($step->in), $width, $height, $step),
                $step instanceof ColorMatrix => $this->pass($commands, 'color_matrix', $width, $height, [$input($step->in)], pack('g20', ...$step->values)),
                $step instanceof Flood => $this->pass($commands, 'flood', $width, $height, [], pack('V4', ...self::floodPixel($step))),
                $step instanceof Composite => $this->pass($commands, 'composite', $width, $height, [$input($step->in), $input($step->in2)], pack('V4g4', self::operator($step->operator), 0, 0, 0, $step->k1, $step->k2, $step->k3, $step->k4)),
                $step instanceof Blend => $this->pass($commands, 'blend', $width, $height, [$input($step->in), $input($step->in2)], pack('V', self::mode($step->mode))),
                $step instanceof Noise => $this->noise($commands, $step, $width, $height, $originX, $originY),
                $step instanceof Displace => $this->displace($commands, $input($step->in), $width, $height, $step, is_string($step->map) ? $results[$step->map] : null),
                $step instanceof Field => $this->field($commands, $step, $width, $height, $originX, $originY),
                $step instanceof Table => $this->pass($commands, 'table', $width, $height, [$input($step->in)], self::tables($step)),
            };
            $previous = $made;
            if (($name = $step->named()) !== null) {
                $results[$name] = $made;
            }
        }

        return $previous;
    }

    /**
     * A $width x $height region of $source at ($x, $y) as an image of its own; past $source's own $sourceWidth x
     * $sourceHeight, transparent. With $straight, unpremultiplied, as Velvet's glass reads its backdrop.
     *
     * @throws MetalException
     */
    public function grab(MTLCommandBuffer $commands, MTLTexture $source, int $sourceWidth, int $sourceHeight, int $x, int $y, int $width, int $height, bool $straight): MTLTexture
    {
        return $this->pass($commands, 'grab', $width, $height, [$source], pack('l4V4', $x, $y, $sourceWidth, $sourceHeight, $straight ? 1 : 0, 0, 0, 0));
    }

    /**
     * One level down of a $width x $height region at the top left of $source: PHPLandGlass::down().
     *
     * @throws MetalException
     */
    public function kawaseDown(MTLCommandBuffer $commands, MTLTexture $source, int $width, int $height): MTLTexture
    {
        return $this->pass($commands, 'kawase_down', intdiv($width + 1, 2), intdiv($height + 1, 2), [$source], pack('l4', 0, 0, $width, $height));
    }

    /**
     * One level up of a $sourceWidth x $sourceHeight level, $width x $height: PHPLandGlass::up().
     *
     * @throws MetalException
     */
    public function kawaseUp(MTLCommandBuffer $commands, MTLTexture $source, int $sourceWidth, int $sourceHeight, int $width, int $height): MTLTexture
    {
        return $this->pass($commands, 'kawase_up', $width, $height, [$source], pack('l2', $sourceWidth, $sourceHeight));
    }

    /**
     * An image of the pool, for this frame only.
     *
     * @throws MetalException
     */
    public function take(int $width, int $height): MTLTexture
    {
        $size = self::round($width).'x'.self::round($height);
        $index = $this->taken[$size] ?? 0;
        $this->taken[$size] = $index + 1;

        return $this->pool[$size][$index] ??= $this->device->texture(MTLPixelFormat::RGBA8_UNORM, self::round($width), self::round($height), 1, MTLStorageMode::PRIVATE, MTLTextureUsage::SHADER_READ->value | MTLTextureUsage::RENDER_TARGET->value);
    }

    /**
     * $rgba8 ($width x $height, rows from the top) as an image for this frame. It is one the CPU can write: a
     * shared texture, from a pool of its own.
     *
     * @throws MetalException
     */
    public function upload(string $rgba8, int $width, int $height): MTLTexture
    {
        $size = 'shared:'.self::round($width).'x'.self::round($height);
        $index = $this->taken[$size] ?? 0;
        $this->taken[$size] = $index + 1;
        $texture = $this->pool[$size][$index] ??= $this->device->texture(MTLPixelFormat::RGBA8_UNORM, self::round($width), self::round($height), 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
        // a pooled texture may still be read by an earlier frame the GPU has not finished
        $this->device->sync();
        $texture->replaceRegionMipmapLevelWithBytesBytesPerRow(new MTLRegion(new MTLOrigin(0, 0, 0), new MTLSize($width, $height, 1)), 0, $rgba8, $width * 4);

        return $texture;
    }

    /** The frame is over: every image goes back to the pool, and one this frame did not use is let go. */
    public function release(): void
    {
        foreach ($this->pool as $size => $images) {
            $this->pool[$size] = array_slice($images, 0, $this->taken[$size] ?? 0);
        }
        $this->pool = array_filter($this->pool);
        $this->taken = [];
    }

    /** @throws MetalException */
    protected function blur(MTLCommandBuffer $commands, MTLTexture $image, int $width, int $height, Blur $blur): MTLTexture
    {
        $edge = $blur->edge === EdgeMode::DUPLICATE ? 1 : 0;
        if ($blur->sampling === BlurSampling::SPIRAL) {
            if ($blur->x <= 0.0) {
                return $image;
            }
            $offsets = '';
            foreach (PHPLandFilters::spiralOffsets($blur->x, $blur->samples) as [$dx, $dy]) {
                $offsets .= pack('g2', $dx, $dy);
            }

            return $this->pass($commands, 'spiral_blur', $width, $height, [$image], pack('l2V2', $width, $height, $blur->samples, $edge), [$offsets]);
        }
        foreach ([[true, $blur->x], [false, $blur->y]] as [$horizontal, $sigma]) {
            if ($sigma <= 0.0) {
                continue;
            }
            if ($sigma < 2.0) {
                $weights = PHPLandFilters::kernelWeights($sigma);
                $radius = intdiv(count($weights) - 1, 2);
                $bytes = pack('l2V3V3', $width, $height, $horizontal ? 1 : 0, $radius, $edge, 0, 0, 0).pack('l64', ...array_pad($weights, 64, 0));
                $image = $this->pass($commands, 'kernel_blur', $width, $height, [$image], $bytes);

                continue;
            }
            foreach (PHPLandFilters::boxes($sigma) as [$size, $left]) {
                $image = $this->pass($commands, 'box_blur', $width, $height, [$image], pack('l2V2lV3', $width, $height, $horizontal ? 1 : 0, $size, $left, $edge, 0, 0));
            }
        }

        return $image;
    }

    /** @throws MetalException */
    protected function noise(MTLCommandBuffer $commands, Noise $noise, int $width, int $height, int $originX, int $originY): MTLTexture
    {
        $type = match ($noise->type) {
            NoiseType::TURBULENCE => 0,
            NoiseType::FRACTAL => 1,
            NoiseType::HASH => 2,
        };
        [$frequencyX, $frequencyY] = PHPLandFilters::stitched($noise, $width, $height);
        $stitch = [0, 0, 0, 0];
        if ($noise->stitch) {
            $w = (int) ($width * $frequencyX + 0.5);
            $h = (int) ($height * $frequencyY + 0.5);
            $stitch = [$w, $h, (int) ($originX * $frequencyX + 4096 + $w), (int) ($originY * $frequencyY + 4096 + $h)];
        }
        $bytes = pack('l2lV', $originX, $originY, $noise->seed, $type).pack('g2', $frequencyX, $frequencyY).pack('V2', $noise->octaves, $noise->stitch ? 1 : 0).pack('l4', ...$stitch);
        // the lattice: 514 selector entries (uint16) as bytes, then 4 x 514 gradients (float2) as a texture, a row a channel
        $tables = PHPLandFilters::noiseTables($noise->seed);
        // a seed's lattice never changes: written once, when its texture is made
        if (! isset($this->lattices[$noise->seed])) {
            $this->lattices[$noise->seed] = $this->device->texture(MTLPixelFormat::RG32_FLOAT, 514, 4, 1, MTLStorageMode::SHARED, MTLTextureUsage::SHADER_READ->value);
            $this->lattices[$noise->seed]->replaceRegionMipmapLevelWithBytesBytesPerRow(new MTLRegion(new MTLOrigin(0, 0, 0), new MTLSize(514, 4, 1)), 0, substr($tables, 1028), 514 * 8);
        }
        $gradients = $this->lattices[$noise->seed];

        return $this->pass($commands, 'noise', $width, $height, [$gradients], $bytes, [substr($tables, 0, 1028)]);
    }

    /** @throws MetalException */
    protected function field(MTLCommandBuffer $commands, Field $field, int $width, int $height, int $originX, int $originY): MTLTexture
    {
        $kind = match ($field->kind) {
            FieldKind::ROUNDED_BOX => 0,
            FieldKind::INSIDE => 1,
            FieldKind::RAMP => 2,
        };
        $bytes = pack('l2V2', $originX, $originY, $kind, 0).pack('g4', ...($field->box ?? [0.0, 0.0, 0.0, 0.0])).pack('g4', ...$field->radii).pack('g2', $field->from, $field->to).pack('g2', 0.0, 0.0);

        return $this->pass($commands, 'field', $width, $height, [], $bytes);
    }

    /**
     * @param MTLTexture|null $named the map an earlier step made, premultiplied; null: the step's own framebuffer
     *
     * @throws MetalException
     */
    protected function displace(MTLCommandBuffer $commands, MTLTexture $image, int $width, int $height, Displace $step, ?MTLTexture $named): MTLTexture
    {
        if (is_null($named)) {
            /** @var Framebuffer $framebuffer */
            $framebuffer = $step->map;
            [$mapWidth, $mapHeight] = [$framebuffer->viewportWidth(), $framebuffer->viewportHeight()];
            $map = $this->upload($framebuffer->toRgba8(), $mapWidth, $mapHeight);
        } else {
            [$map, $mapWidth, $mapHeight] = [$named, $width, $height];
        }
        $channel = fn (Channel $c): int => match ($c) {
            Channel::R => 0,
            Channel::G => 1,
            Channel::B => 2,
            Channel::A => 3,
        };
        $weights = '';
        foreach ($step->weights ?? PHPLandFilters::bandWeights($step->bands) as [$r, $g, $b]) {
            $weights .= pack('g4', $r, $g, $b, 0.0);
        }
        $bytes = pack('l4', $width, $height, $mapWidth, $mapHeight).pack('g2', $step->scale, $step->spread).pack('V4', $channel($step->xChannel), $channel($step->yChannel), $step->bands, is_null($named) ? 0 : 1).pack('V2', 0, 0);

        return $this->pass($commands, 'displace', $width, $height, [$image, $map], $bytes, [$weights]);
    }

    /**
     * One pass: $fragment over a $width x $height image, into an image of the pool.
     *
     * @param list<MTLTexture> $textures at indexes 0, 1, …
     * @param string $bytes the fragment's buffer 0
     * @param list<string> $buffers the fragment's buffers 1, 2, …: each under 4 KB, as Metal takes bytes set on an encoder
     *
     * @throws MetalException
     */
    protected function pass(MTLCommandBuffer $commands, string $fragment, int $width, int $height, array $textures, string $bytes = '', array $buffers = []): MTLTexture
    {
        foreach ([$bytes, ...$buffers] as $data) {
            if (strlen($data) >= 4096) {
                throw MetalException::because("the '{$fragment}' pass was given ".strlen($data).' bytes in one buffer: Metal takes under 4096 set on an encoder.');
            }
        }
        $out = $this->take($width, $height);
        $pass = MTLRenderPassDescriptor::renderPassDescriptor();
        $color = $pass->colorAttachments()->objectAtIndexedSubscript(0);
        $color->setTexture($out);
        $color->setLoadAction(MTLLoadAction::DONT_CARE);
        $color->setStoreAction(MTLStoreAction::STORE);

        // everything that can fail is made before the encoder: an encoder dropped without endEncoding() ends the process
        $pipeline = $this->device->filterPipeline($fragment);
        $encoder = $commands->renderCommandEncoderWithDescriptor($pass) ?? throw MetalException::because('no render encoder could be made.');
        try {
            $encoder->setViewport(new MTLViewport(0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0));
            $encoder->setRenderPipelineState($pipeline);
            foreach ($textures as $i => $texture) {
                $encoder->setFragmentTextureAtIndex($texture, $i);
            }
            if ($bytes !== '') {
                $encoder->setFragmentBytesLengthAtIndex($bytes, strlen($bytes), 0);
            }
            foreach ($buffers as $i => $data) {
                $encoder->setFragmentBytesLengthAtIndex($data, strlen($data), $i + 1);
            }
            $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, 3);
        } finally {
            $encoder->endEncoding();
        }

        return $out;
    }

    /** @return array{int, int, int, int} a flood's colour, premultiplied as PHPLandFilters::flood() does */
    protected static function floodPixel(Flood $flood): array
    {
        $a = (int) round($flood->color->alpha * 255);
        $p = fn (float $c): int => intdiv((int) round($c * 255) * $a + 127, 255);

        return [$p($flood->color->red), $p($flood->color->green), $p($flood->color->blue), $a];
    }

    /** Four tables of 256 bytes, red, green, blue, alpha; an identity for one the step left out. */
    protected static function tables(Table $table): string
    {
        $bytes = '';
        foreach ([$table->red, $table->green, $table->blue, $table->alpha] as $entries) {
            $bytes .= pack('C256', ...($entries ?? range(0, 255)));
        }

        return $bytes;
    }

    protected static function operator(CompositeOperator $operator): int
    {
        return match ($operator) {
            CompositeOperator::OVER => 0,
            CompositeOperator::IN => 1,
            CompositeOperator::OUT => 2,
            CompositeOperator::ATOP => 3,
            CompositeOperator::XOR => 4,
            CompositeOperator::ARITHMETIC => 5,
        };
    }

    protected static function mode(BlendMode $mode): int
    {
        return match ($mode) {
            BlendMode::NORMAL => 0,
            BlendMode::MULTIPLY => 1,
            BlendMode::SCREEN => 2,
            BlendMode::DARKEN => 3,
            BlendMode::LIGHTEN => 4,
        };
    }

    protected static function round(int $side): int
    {
        return max(self::STEP, intdiv($side + self::STEP - 1, self::STEP) * self::STEP);
    }
}
