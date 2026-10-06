#include <metal_stdlib>
using namespace metal;

// Every vertex is a point in target pixels, origin top-left. `at` is interpolated
// per sample, so a fragment runs once a sample and sees that sample's position
// and that sample's destination colour (color(0)): coverage comes from the
// samples, and the blend is Velvet's integer source-over, exact per channel.

struct Frame { float2 size; };
struct Paint { uint4 rgba; };
struct Oval { uint4 rgba; float4 shape; float4 band; };     // shape: cx, cy, rx, ry; band: stroke, hole (1 = ring), 0, 0
struct Picture { float4 abcd; float4 efwh; uint4 options; }; // the inverse placement a..f, the source size; opacity 0..255, linear (1) or nearest (0)

struct VOut {
    float4 position [[position]];
    float2 at [[sample_no_perspective]];
};

vertex VOut place(uint vid [[vertex_id]], const device float2 *points [[buffer(0)]], constant Frame &frame [[buffer(1)]])
{
    float2 p = points[vid];
    VOut out;
    out.position = float4(p.x / frame.size.x * 2.0 - 1.0, 1.0 - p.y / frame.size.y * 2.0, 0.0, 1.0);
    out.at = p;
    return out;
}

// Velvet's source-over: (s·a + d·(255 − a) + 127) / 255, alpha (255·a + dₐ·(255 − a) + 127) / 255.
static float4 over(uint4 s, uint a, float4 dst)
{
    uint4 d = uint4(round(dst * 255.0));
    uint3 rgb = (s.rgb * a + d.rgb * (255 - a) + 127) / 255;
    uint alpha = (255 * a + d.a * (255 - a) + 127) / 255;
    return float4(float3(rgb), float(alpha)) / 255.0;
}

// CLEAR and SOLID: the colour as it is, no blending.
fragment float4 solid(VOut in [[stage_in]], constant Paint &paint [[buffer(0)]])
{
    return float4(paint.rgba) / 255.0;
}

// STENCIL_FILL: nothing written to colour (the pipeline's write mask is none).
fragment float4 mark(VOut in [[stage_in]])
{
    return float4(0.0);
}

// COVER and RECTS: source-over at the colour's alpha.
fragment float4 fill(VOut in [[stage_in]], float4 dst [[color(0)]], constant Paint &paint [[buffer(0)]])
{
    return over(paint.rgba, paint.rgba.a, dst);
}

// ELLIPSE and RING: a sample is in when it lies inside the outer ellipse and,
// for a ring, outside the inner one. With one sample that is the pixel's centre.
fragment float4 oval(VOut in [[stage_in]], float4 dst [[color(0)]], constant Oval &oval [[buffer(0)]])
{
    float half_stroke = oval.band.x / 2.0;
    float2 outer = oval.shape.zw + half_stroke;
    float2 q = (in.at - oval.shape.xy) / outer;
    if (dot(q, q) > 1.0) {
        discard_fragment();
    }
    if (oval.band.y > 0.5) {
        float2 inner = oval.shape.zw - half_stroke;
        if (inner.x > 0.0 && inner.y > 0.0) {
            float2 r = (in.at - oval.shape.xy) / inner;
            if (dot(r, r) <= 1.0) {
                discard_fragment();
            }
        }
    }
    return over(oval.rgba, oval.rgba.w, dst);
}

// IMAGE: the source pixel under the inverse placement of the pixel's centre,
// nearest or Velvet's bilinear (weights in 256ths, colours weighed by alpha),
// blended at the source alpha times the opacity.
fragment float4 image(VOut in [[stage_in]], float4 dst [[color(0)]], texture2d<float> source [[texture(0)]], constant Picture &p [[buffer(0)]])
{
    float2 centre = floor(in.at) + 0.5;
    float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
    float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
    float w = p.efwh.z;
    float h = p.efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard_fragment();
    }

    uint4 s;
    if (p.options.y == 0) {
        s = uint4(round(source.read(uint2(uint(floor(u)), uint(floor(v)))) * 255.0));
    } else {
        float fx = u - 0.5;
        float x0 = floor(fx);
        uint tx = uint(floor((fx - x0) * 256.0));
        float fy = v - 0.5;
        float y0 = floor(fy);
        uint ty = uint(floor((fy - y0) * 256.0));
        int last_x = int(w) - 1;
        int last_y = int(h) - 1;
        uint xa = uint(clamp(int(x0), 0, last_x));
        uint xb = uint(clamp(int(x0) + 1, 0, last_x));
        uint ya = uint(clamp(int(y0), 0, last_y));
        uint yb = uint(clamp(int(y0) + 1, 0, last_y));
        uint weights[4] = { (256 - tx) * (256 - ty), tx * (256 - ty), (256 - tx) * ty, tx * ty };
        uint2 taps[4] = { uint2(xa, ya), uint2(xb, ya), uint2(xa, yb), uint2(xb, yb) };
        uint sum = 0;
        uint3 rgb = uint3(0);
        for (int i = 0; i < 4; i++) {
            uint4 t = uint4(round(source.read(taps[i]) * 255.0));
            uint weighed = weights[i] * t.w;
            sum += weighed;
            rgb += weighed * t.xyz;
        }
        if (sum == 0) {
            discard_fragment();
        }
        uint half_sum = sum / 2;
        s = uint4((rgb + half_sum) / sum, (sum + 32768) >> 16);
    }
    uint a = (s.w * p.options.x + 127) / 255;
    return over(s, a, dst);
}

// The target's pixel under this one, as it is: restoring the samples from an
// upload (copy, into the multisampled texture) and presenting (show, into a drawable).
fragment float4 copy(VOut in [[stage_in]], texture2d<float> frame [[texture(0)]])
{
    return frame.read(uint2(in.position.xy));
}

fragment float4 show(VOut in [[stage_in]], texture2d<float> frame [[texture(0)]])
{
    return frame.read(uint2(in.position.xy));
}
