<?php

declare(strict_types=1);

/**
 * Embedded MSL for the Surface painter. Vertex layout is the Phase A
 * contract: nine floats `x y z r g b a u v` at vertex_id * 9. The
 * textured flag is a separate uint in fragment buffer(0), not a vertex
 * attribute. Transform is projection only; Metal clip space is y-up, so
 * no flip here.
 */
return <<<'MSL'
#include <metal_stdlib>
using namespace metal;

struct VertexOut {
    float4 position [[position]];
    float4 color;
    float2 uv;
};

vertex VertexOut painter_vertex(
    uint vid [[vertex_id]],
    const device float *vertices [[buffer(0)]],
    constant float4x4 &transform [[buffer(1)]]
) {
    uint base = vid * 9u;
    float x = vertices[base + 0u];
    float y = vertices[base + 1u];
    float r = vertices[base + 3u];
    float g = vertices[base + 4u];
    float b = vertices[base + 5u];
    float a = vertices[base + 6u];
    float u = vertices[base + 7u];
    float v = vertices[base + 8u];

    VertexOut out;
    out.position = transform * float4(x, y, 0.0, 1.0);
    out.color = float4(r, g, b, a);
    out.uv = float2(u, v);
    return out;
}

fragment float4 painter_fragment(
    VertexOut in [[stage_in]],
    texture2d<float> tex [[texture(0)]],
    sampler samp [[sampler(0)]],
    constant uint &textured [[buffer(0)]]
) {
    float4 sample = textured != 0u ? tex.sample(samp, in.uv) : float4(1.0, 1.0, 1.0, 1.0);
    return in.color * sample;
}
MSL;
