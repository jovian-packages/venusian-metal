#include <metal_stdlib>
using namespace metal;

// Every vertex is a point in target pixels, origin top-left. `at` is interpolated
// per sample, so a fragment runs once a sample and sees that sample's position
// and that sample's destination colour (color(0)): coverage comes from the
// samples, and the blend is Velvet's integer source-over, exact per channel.

struct Frame { float2 size; };
struct Paint { uint4 rgba; };
struct Oval { uint4 rgba; float4 shape; float4 band; };     // shape: cx, cy, rx, ry; band: stroke, hole (1 = ring), 0, 0
// the inverse placement a..f, the source size; opacity 0..255, linear (1) or nearest (0); the tint (bytes); the
// window u0 v0 u1 v1 (style.y = 1); the rounded box and its radii [tl, tr, br, bl] (style.x = 1)
struct Picture { float4 abcd; float4 efwh; uint4 options; uint4 tint; float4 win; float4 box; float4 radii; uint4 style; };

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

static float2 across(float4 box, float4 r, float y);
static bool inside(float2 ends, float x);

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
    // the window crops; the pixels beside it are still read by the smooth filter
    if (p.style.y != 0 && !(u >= p.win.x && u < p.win.z && v >= p.win.y && v < p.win.w)) {
        discard_fragment();
    }
    // rounded corners: a sample is in when it lies inside the rounded box, as ROUNDRECT decides it
    if (p.style.x != 0 && !inside(across(p.box, p.radii, in.at.y), in.at.x)) {
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
        // the tint multiplies the source's own values, as they are stored
        float4 tint = float4(p.tint) / 255.0;
        float3 tinted = f.rgb * tint.rgb;
        float3 rgb = light.source_float != 0 ? tinted : to_linear(tinted);
        return over_linear(rgb, f.a * tint.a * float(p.options.x) / 255.0, dst);
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
    // Velvet's order: the tint (alpha too), then the opacity, each (x × y + 127) / 255; the coverage is the samples'
    s = uint4((s.xyz * p.tint.xyz + 127) / 255, (s.w * p.tint.w + 127) / 255);
    uint a = (s.w * p.options.x + 127) / 255;
    return over(s, a, dst);
}

// COVER_GRADIENT: the ramp (a 256 x 1 source) at the position of the sample's pixel centre along the gradient, nearest,
// blended at the ramp colour's alpha like a fill. abcd and efwh (e, f) take the centre to the gradient's own space;
// options: kind (0 linear, 1 radial), spread (0 pad, 1 repeat, 2 reflect).
struct Gradient { float4 abcd; float4 efwh; uint4 options; };

fragment float4 gradient(VOut in [[stage_in]], float4 dst [[color(0)]], texture2d<float> ramp [[texture(0)]], constant Gradient &g [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    float2 centre = floor(in.at) + 0.5;
    float u = g.abcd.x * centre.x + g.abcd.z * centre.y + g.efwh.x;
    float v = g.abcd.y * centre.x + g.abcd.w * centre.y + g.efwh.y;
    float t = g.options.x == 0 ? u : length(float2(u, v));
    if (g.options.y == 0) {
        t = clamp(t, 0.0, 1.0);
    } else if (g.options.y == 1) {
        t = t - floor(t);
    } else {
        float m = t - 2.0 * floor(t / 2.0);
        t = m > 1.0 ? 2.0 - m : m;
    }
    uint4 s = uint4(round(ramp.read(uint2(uint(floor(t * 255.0 + 0.5)), 0)) * 255.0));
    if (light.hdr != 0) {
        float4 c = lit(s);
        return over_linear(c.rgb, c.a, dst);
    }
    return over(s, s.a, dst);
}

// ROUNDRECT, ROUNDRING and SHADOW: the box, its radii [tl, tr, br, bl], and band: inset, ring (1), blur, shadow radius.
struct RoundBox { uint4 rgba; float4 box; float4 radii; float4 band; };

// Where line y crosses a rounded box, as Rasterize's roundAcross(): the interval [ends.x, ends.y), empty when
// ends.y <= ends.x. Above a corner's band the end is on the quarter circle r in from both sides.
static float corner(float cx, float cy, float r, float y, float side)
{
    float t = (y - cy) / r;
    float h = r * sqrt(max(0.0, 1.0 - t * t));
    return side < 0.0 ? cx - h : cx + h;
}

static float2 across(float4 box, float4 r, float y)
{
    if (y < box.y || y >= box.w) {
        return float2(0.0, 0.0);
    }
    float left = box.x;
    if (y < box.y + r.x) {
        left = corner(box.x + r.x, box.y + r.x, r.x, y, -1.0);
    } else if (y > box.w - r.w) {
        left = corner(box.x + r.w, box.w - r.w, r.w, y, -1.0);
    }
    float right = box.z;
    if (y < box.y + r.y) {
        right = corner(box.z - r.y, box.y + r.y, r.y, y, 1.0);
    } else if (y > box.w - r.z) {
        right = corner(box.z - r.z, box.w - r.z, r.z, y, 1.0);
    }
    return float2(left, right);
}

static bool inside(float2 ends, float x)
{
    return ends.y > ends.x && x >= ends.x && x < ends.y;
}

// ROUNDRECT and ROUNDRING: a sample is in when it lies inside the rounded box and, for a ring, outside the box
// inset by band.x with radii max(0, r − inset). With one sample that is the pixel's centre.
fragment float4 roundbox(VOut in [[stage_in]], float4 dst [[color(0)]], constant RoundBox &b [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    if (!inside(across(b.box, b.radii, in.at.y), in.at.x)) {
        discard_fragment();
    }
    if (b.band.y > 0.5) {
        float inset = b.band.x;
        float4 hole = float4(b.box.xy + inset, b.box.zw - inset);
        if (hole.z > hole.x && hole.w > hole.y && inside(across(hole, max(b.radii - inset, float4(0.0)), in.at.y), in.at.x)) {
            discard_fragment();
        }
    }
    if (light.hdr != 0) {
        float4 s = lit(b.rgba);
        return over_linear(s.rgb, s.a, dst);
    }
    return over(b.rgba, b.rgba.a, dst);
}

// SHADOW: once a pixel, at its centre: 1 − smoothstep(−blur, blur, d), d the signed distance to the rounded box
// (Rasterize's roundDistance and shade(), in the same order); band.z is the blur, band.w the radius. Coverage is
// rounded to 0..255, held at 128 for hard edges, and blended as paintSpans() blends a span of it.
fragment float4 shade(VOut in [[stage_in]], float4 dst [[color(0)]], constant RoundBox &b [[buffer(0)]], constant Light &light [[buffer(1)]], constant uint &hard [[buffer(2)]])
{
    float2 p = floor(in.at) + 0.5;
    float2 c = (b.box.xy + b.box.zw) / 2.0;
    float2 h = (b.box.zw - b.box.xy) / 2.0;
    float r = b.band.w;
    float blur = b.band.z;
    float2 q = abs(p - c) - h + r;
    float2 o = max(q, float2(0.0));
    float d = min(max(q.x, q.y), 0.0) + sqrt(o.x * o.x + o.y * o.y) - r;
    float cover;
    if (blur > 0.0) {
        float t = clamp((d + blur) / (2.0 * blur), 0.0, 1.0);
        cover = 1.0 - t * t * (3.0 - 2.0 * t);
    } else {
        cover = d < 0.0 ? 1.0 : 0.0;
    }
    uint v = uint(floor(cover * 255.0 + 0.5));
    if (hard != 0) {
        v = v >= 128 ? 255 : 0;
    }
    uint a = (b.rgba.a * v + 127) / 255;
    if (a == 0) {
        discard_fragment();
    }
    if (light.hdr != 0) {
        float4 s = lit(b.rgba);
        return over_linear(s.rgb, float(a) / 255.0, dst);
    }
    return over(b.rgba, a, dst);
}

// GLYPHS: as place, plus the vertex's atlas texel from the frame's vertices again (buffer 2, offset so that
// the same vertex_id reads it).
struct GlyphOut {
    float4 position [[position]];
    float2 at [[sample_no_perspective]];
    float2 texel [[sample_no_perspective]];
};

vertex GlyphOut placeGlyph(uint vid [[vertex_id]], const device float2 *points [[buffer(0)]], constant Frame &frame [[buffer(1)]], const device float2 *texels [[buffer(2)]])
{
    float2 p = points[vid];
    GlyphOut out;
    out.position = float4(p.x / frame.size.x * 2.0 - 1.0, 1.0 - p.y / frame.size.y * 2.0, 0.0, 1.0);
    out.at = p;
    out.texel = texels[vid];
    return out;
}

// GLYPHS: the atlas coverage under the sample, nearest, blended as paintSpans() blends a span of that
// coverage: a = (alpha · coverage + 127) / 255, then Velvet's source-over at a.
fragment float4 glyph(GlyphOut in [[stage_in]], float4 dst [[color(0)]], texture2d<float> atlas [[texture(0)]], constant Paint &paint [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    uint coverage = uint(round(atlas.read(uint2(floor(in.texel))).r * 255.0));
    uint a = (paint.rgba.a * coverage + 127) / 255;
    if (a == 0) {
        discard_fragment();
    }
    if (light.hdr != 0) {
        float4 s = lit(paint.rgba);
        return over_linear(s.rgb, float(a) / 255.0, dst);
    }
    return over(paint.rgba, a, dst);
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

// ---- GLASS: one pane over its box (Surface's drawing-path spec, G4; ext-fb's fb_paint_glass() step for step) -----
// box x0 y0 x1 y1; radii tl tr br bl; a: frost, refraction, bevel, saturation; b: light, grain, the backdrop's x, y;
// tint and rim as bytes; opts: alpha, the level's scale, whether a sharp capture is bound.
struct GlassPane { float4 box; float4 radii; float4 a; float4 b; uint4 tint; uint4 rim; uint4 opts; };

// The signed distance from p to the rounded box, and the box's outward normal there.
static float glass_distance(float2 p, constant GlassPane &g, thread float2 &n)
{
    float2 c = (g.box.xy + g.box.zw) * 0.5, h = (g.box.zw - g.box.xy) * 0.5;
    float r = p.x < c.x ? (p.y < c.y ? g.radii.x : g.radii.w) : (p.y < c.y ? g.radii.y : g.radii.z);
    float2 q = abs(p - c) - (h - r);
    float d;
    if (q.x > 0.0 && q.y > 0.0) {
        float len = length(q);
        d = len - r;
        n = q / len;
    } else if (q.x > q.y) {
        d = q.x - r;
        n = float2(1.0, 0.0);
    } else {
        d = q.y - r;
        n = float2(0.0, 1.0);
    }
    n *= float2(p.x < c.x ? -1.0 : 1.0, p.y < c.y ? -1.0 : 1.0);
    return d;
}

static float3 glass_sample(texture2d<float> level, float2 uv)
{
    int2 size = int2(level.get_width(), level.get_height());
    float2 f = floor(uv), t = uv - f;
    int x0 = clamp(int(f.x), 0, size.x - 1), x1 = clamp(int(f.x) + 1, 0, size.x - 1);
    int y0 = clamp(int(f.y), 0, size.y - 1), y1 = clamp(int(f.y) + 1, 0, size.y - 1);
    float3 a = round(level.read(uint2(x0, y0)).rgb * 255.0), b = round(level.read(uint2(x1, y0)).rgb * 255.0);
    float3 c = round(level.read(uint2(x0, y1)).rgb * 255.0), d = round(level.read(uint2(x1, y1)).rgb * 255.0);
    float3 top = a + (b - a) * t.x, under = c + (d - c) * t.x;
    return top + (under - top) * t.y;
}

static uint glass_grain(int x, int y)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u;
    h = (h ^ (h >> 13)) * 1274126177u;
    h ^= h >> 16;
    return h & 0xFFFFu;
}

fragment float4 glass(VOut in [[stage_in]], float4 dst [[color(0)]], texture2d<float> blur [[texture(0)]], texture2d<float> sharp [[texture(1)]],
    constant GlassPane &g [[buffer(0)]], constant Light &light [[buffer(1)]])
{
    // the samples decide the edge; the colour is the pixel centre's, as Velvet's
    float2 n;
    if (glass_distance(in.at, g, n) >= 0.0) {
        discard_fragment();
    }
    float2 pixel = floor(in.at);
    float2 p = pixel + 0.5;
    float d = glass_distance(p, g, n);
    float band = g.a.z > 0.0 ? g.a.z : (g.a.y > 0.0 ? g.a.y : 1.0);
    float e = clamp(1.0 + d / band, 0.0, 1.0);
    float fe = e * e;
    float2 s = p - n * g.a.y * fe;
    float3 c = glass_sample(blur, (s - g.b.zw) / float(g.opts.y) - 0.5);
    if (g.opts.z != 0 && g.a.x < 1.0) {
        float3 crisp = glass_sample(sharp, s - g.b.zw - 0.5);
        c = crisp + (c - crisp) * g.a.x;
    }
    float luma = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
    c = luma + (c - luma) * g.a.w;
    c = c + (float3(g.tint.rgb) - c) * (float(g.tint.a) / 255.0);
    float lit_rim = 0.5 + 0.5 * dot(n, float2(-0.6, -0.8));
    float grain = g.b.y * 0.04 * (float(glass_grain(int(pixel.x), int(pixel.y))) / 65536.0 - 0.5) * 255.0;
    c = c + g.b.x * 255.0 + float3(g.rim.rgb) * (float(g.rim.a) / 255.0) * fe * lit_rim + grain;
    uint3 rgb = uint3(clamp(floor(c + 0.5), 0.0, 255.0));
    if (light.hdr != 0) {
        return over_linear(to_linear(float3(rgb) / 255.0), float(g.opts.x) / 255.0, dst);
    }
    return over(uint4(rgb, 255u), g.opts.x, dst);
}
