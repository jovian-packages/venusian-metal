<?php

namespace Jovian\Engines\Metal;

use MTLPrimitiveType;
use MTLScissorRect;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GERasterizer;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\Rendering\Rasterization\Stroker;

/**
 * The metal engine's rasterizer: a shape is filled on the GPU by stencil, then cover.
 *
 *  1. Each contour is drawn as a fan of triangles from its first point into the stencil only:
 *     front faces count up and back faces down (non-zero), or every face flips it (even-odd).
 *     What is left non-zero is inside the shape, whatever its crossings.
 *  2. One rectangle over the shape's bounds is drawn in the colour where the stencil is not
 *     zero, blending over what is there, and the stencil is zeroed behind it.
 *
 * Antialiased edges come from four samples a pixel, resolved into the texture: coverage is a
 * quarter step, not Velvet's exact area, so edges differ from Velvet's slightly. Hard edges
 * sample the pixel's centre, as Velvet's do. Colours are blended by the GPU on premultiplied
 * colour, which rounds its own way: a blended byte may be a step off Velvet's.
 */
class MetalRasterizer extends GERasterizer
{
    public function __construct(
        protected readonly MetalDevice $device,
        protected readonly Edges $edges,
    ) {
        parent::__construct($device);
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    protected function fillPath(GEFramebuffer $onto, Path $path, Color $color, FillRule $rule, Region $clip): void
    {
        $this->paint($onto, array_column($path->flatten(), 'points'), $color, $rule, $clip);
    }

    /** A stroke is filled as its outline, wound non-zero: the outline comes from the same Stroker as Velvet's. */
    protected function strokePath(GEFramebuffer $onto, Path $path, Color $color, StrokeStyle $style, Region $clip): void
    {
        $contours = [];
        foreach ($path->flatten() as ['points' => $points, 'closed' => $closed]) {
            $pieces = $style->dash === []
                ? [['points' => $points, 'closed' => $closed, 'direction' => null]]
                : Stroker::dashed($points, $closed, $style->dash, $style->dashOffset);
            foreach ($pieces as $piece) {
                foreach (Stroker::outline($piece['points'], $style->width, $piece['closed'], $style->cap, $style->join, $style->miterLimit, $piece['direction']) as $flat) {
                    $contours[] = array_chunk($flat, 2);
                }
            }
        }
        $this->paint($onto, $contours, $color, FillRule::NON_ZERO, $clip);
    }

    /**
     * @param list<list<array{float, float}>> $contours
     *
     * @throws MetalException
     */
    protected function paint(GEFramebuffer $onto, array $contours, Color $color, FillRule $rule, Region $clip): void
    {
        // the fans, then the covering rectangle, as one list of points
        $points = [];
        [$left, $top, $right, $bottom] = [INF, INF, -INF, -INF];
        foreach ($contours as $contour) {
            $count = count($contour);
            if ($count < 3) {
                continue;
            }
            for ($i = 1; $i < $count - 1; $i++) {
                array_push($points, ...$contour[0], ...$contour[$i], ...$contour[$i + 1]);
            }
            foreach ($contour as [$x, $y]) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }
        if ($points === []) {
            return;
        }
        $fan = intdiv(count($points), 2);
        array_push($points, $left, $top, $right, $top, $right, $bottom, $left, $top, $right, $bottom, $left, $bottom);

        /** @var MetalFramebuffer $onto the base checked it is this engine's */
        $antialiased = $this->edges === Edges::ANTIALIASED;
        $samples = $antialiased ? MetalFramebuffer::SAMPLES : 1;
        $commands = $this->device->commands();
        $encoder = $onto->begin($commands, $antialiased, $this->device->buffer(pack('g*', ...$points)));
        $encoder->setScissorRect(new MTLScissorRect($clip->x, $clip->y, $clip->width, $clip->height));

        $encoder->setRenderPipelineState($this->device->pipeline('mark', $samples));
        $encoder->setDepthStencilState($this->device->stencil($rule === FillRule::EVEN_ODD ? 'invert' : 'winding'));
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, 0, $fan);

        $rgba = pack('V4', (int) round($color->red * 255), (int) round($color->green * 255), (int) round($color->blue * 255), (int) round($color->alpha * 255));
        $encoder->setRenderPipelineState($this->device->pipeline('solid', $samples));
        $encoder->setDepthStencilState($this->device->stencil('cover'));
        $encoder->setFragmentBytesLengthAtIndex($rgba, strlen($rgba), 0);
        $encoder->drawPrimitivesVertexStartVertexCount(MTLPrimitiveType::TRIANGLE, $fan, 6);
        $encoder->endEncoding();

        $this->device->submit($commands);
        $onto->drew($antialiased);
    }
}
