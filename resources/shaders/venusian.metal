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

// The light of the target: whether it is HDR (linear half floats, 1.0 SDR
// white, plain source-over), and whether the bound image source holds half
// floats already linear.
struct Light { uint hdr; uint source_float; uint2 pad; };

static float3 to_linear(float3 c)
{
    return select(pow((c + 0.055) / 1.055, 2.4), c / 12.92, c <= 0.04045);
}

// A Paint's sRGB bytes as linear light, SDR white at 1.0.
static float4 lit(uint4 rgba)
{
    return float4(to_linear(float3(rgba.rgb) / 255.0), float(rgba.a) / 255.0);
}

// Linear source-over at alpha a, straight colour in, straight colour out.
static float4 over_linear(float3 s, float a, float4 dst)
{
    float out_a = a + dst.a * (1.0 - a);
    float3 rgb = out_a > 0.0 ? (s * a + dst.rgb * dst.a * (1.0 - a)) / out_a : float3(0.0);
    return float4(rgb, out_a);
}

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
fragment float4 solid(VOut in [[stage_in]], constant Paint &paint [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    return light.hdr != 0 ? lit(paint.rgba) : float4(paint.rgba) / 255.0;
}

// STENCIL_FILL: nothing written to colour (the pipeline's write mask is none).
fragment float4 mark(VOut in [[stage_in]])
{
    return float4(0.0);
}

// COVER and RECTS: source-over at the colour's alpha.
fragment float4 fill(VOut in [[stage_in]], float4 dst [[color(0)]], constant Paint &paint [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    if (light.hdr != 0) {
        float4 s = lit(paint.rgba);
        return over_linear(s.rgb, s.a, dst);
    }
    return over(paint.rgba, paint.rgba.a, dst);
}

// ELLIPSE and RING: a sample is in when it lies inside the outer ellipse and,
// for a ring, outside the inner one. With one sample that is the pixel's centre.
fragment float4 oval(VOut in [[stage_in]], float4 dst [[color(0)]], constant Oval &oval [[buffer(0)]], constant Light &light [[buffer(1)]])
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
    if (light.hdr != 0) {
        float4 s = lit(oval.rgba);
        return over_linear(s.rgb, s.a, dst);
    }
    return over(oval.rgba, oval.rgba.w, dst);
}

// IMAGE: the source pixel under the inverse placement of the pixel's centre,
// nearest or Velvet's bilinear (weights in 256ths, colours weighed by alpha),
// blended at the source alpha times the opacity. On an HDR target the source
// is sampled in floats, decoded from sRGB unless it is half floats already.
fragment float4 image(VOut in [[stage_in]], float4 dst [[color(0)]], texture2d<float> source [[texture(0)]], constant Picture &p [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    float2 centre = floor(in.at) + 0.5;
    float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
    float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
    float w = p.efwh.z;
    float h = p.efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard_fragment();
    }

    if (light.hdr != 0) {
        float4 f;
        if (p.options.y == 0) {
            f = source.read(uint2(uint(floor(u)), uint(floor(v))));
        } else {
            float2 at = float2(u, v) - 0.5;
            float2 o = floor(at);
            float2 t = at - o;
            float2 last = float2(w, h) - 1.0;
            uint2 a = uint2(clamp(o, float2(0.0), last));
            uint2 b = uint2(clamp(o + 1.0, float2(0.0), last));
            float4 p00 = source.read(a);
            float4 p10 = source.read(uint2(b.x, a.y));
            float4 p01 = source.read(uint2(a.x, b.y));
            float4 p11 = source.read(b);
            float4 m = mix(mix(float4(p00.rgb * p00.a, p00.a), float4(p10.rgb * p10.a, p10.a), t.x),
                           mix(float4(p01.rgb * p01.a, p01.a), float4(p11.rgb * p11.a, p11.a), t.x), t.y);
            if (m.a <= 0.0) {
                discard_fragment();
            }
            f = float4(m.rgb / m.a, m.a);
        }
        float3 rgb = light.source_float != 0 ? f.rgb : to_linear(f.rgb);
        return over_linear(rgb, f.a * float(p.options.x) / 255.0, dst);
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

// The placed rect of a drawable, filled from the target: nearest, or a
// bilinear blend of the four texels around the point. Then encoded for the
// drawable: 0 as stored, 1 extended linear (× SDR white), 2 HDR10 (BT.2020
// primaries, ST 2084, a value of 1.0 at `nits`).
struct Show { float4 rect; float2 size; float white; float nits; uint linear; uint transform; uint2 pad; };

static float3 pq_encode(float3 nits)
{
    const float3x3 to2020 = float3x3(float3(0.6274040, 0.0690970, 0.0163916), float3(0.3292820, 0.9195400, 0.0880132), float3(0.0433136, 0.0113612, 0.8955950));
    float3 y = clamp(to2020 * nits / 10000.0, 0.0, 1.0);
    float3 p = pow(y, 0.1593017578125);
    return pow((0.8359375 + 18.8515625 * p) / (1.0 + 18.6875 * p), 78.84375);
}

fragment float4 show(VOut in [[stage_in]], texture2d<float> frame [[texture(0)]], constant Show &s [[buffer(0)]])
{
    float2 p = (in.position.xy - s.rect.xy) / s.rect.zw * s.size;
    float2 last = s.size - 1.0;
    float4 c;
    if (s.linear == 0) {
        c = frame.read(uint2(clamp(floor(p), float2(0.0), last)));
    } else {
        float2 f = p - 0.5;
        float2 o = floor(f);
        float2 t = f - o;
        uint2 a = uint2(clamp(o, float2(0.0), last));
        uint2 b = uint2(clamp(o + 1.0, float2(0.0), last));
        c = mix(mix(frame.read(a), frame.read(uint2(b.x, a.y)), t.x), mix(frame.read(uint2(a.x, b.y)), frame.read(b), t.x), t.y);
    }
    if (s.transform == 1) {
        c.rgb *= s.white;
    } else if (s.transform == 2) {
        c = float4(pq_encode(c.rgb * s.nits), 1.0);
    }
    return c;
}
