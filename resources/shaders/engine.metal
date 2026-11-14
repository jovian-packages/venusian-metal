#include <metal_stdlib>
using namespace metal;

// The metal engine's shaders. Every vertex is a point in surface pixels, origin top-left. A texture that holds
// only part of the surface (a layer's) says where it starts: frame.origin.
// `at` is interpolated per sample, so a fragment runs once a sample: a curved edge is as fine
// as the samples. The framebuffer holds premultiplied colour, and drawing fragments return
// premultiplied colour for the pipeline to blend: source + destination × (1 − source alpha).

struct Frame { float2 size; float2 origin; };
struct Paint { uint4 rgba; };
// ELLIPSE and RING: the colour; cx, cy, rx, ry; stroke, ring (1), 0, 0
struct Oval { uint4 rgba; float4 shape; float4 band; };
// ROUNDRECT, ROUNDRING and SHADOW: the colour; the box x0 y0 x1 y1; radii tl tr br bl; inset, ring (1), blur, shadow radius
struct RoundBox { uint4 rgba; float4 box; float4 radii; float4 band; };

struct VOut {
    float4 position [[position]];
    float2 at [[sample_no_perspective]];
};

vertex VOut place(uint vid [[vertex_id]], const device float2 *points [[buffer(0)]], constant Frame &frame [[buffer(1)]])
{
    float2 p = points[vid];
    VOut out;
    float2 q = p - frame.origin;
    out.position = float4(q.x / frame.size.x * 2.0 - 1.0, 1.0 - q.y / frame.size.y * 2.0, 0.0, 1.0);
    out.at = p;
    return out;
}

// A colour's bytes, straight, as premultiplied colour at alpha × cover.
static float4 premultiplied(uint4 rgba, float cover)
{
    float a = float(rgba.a) / 255.0 * cover;
    return float4(float3(rgba.rgb) / 255.0 * a, a);
}

// The stencil pass: nothing is written to colour (the pipeline's write mask is none).
fragment float4 mark(VOut in [[stage_in]])
{
    return float4(0.0);
}

// The colour, where the stencil and the scissor let it through: a path's cover, spans, a clear.
fragment float4 solid(VOut in [[stage_in]], constant Paint &paint [[buffer(0)]])
{
    return premultiplied(paint.rgba, 1.0);
}

// A sample is in when it lies inside the outer ellipse and, for a ring, outside the inner one.
fragment float4 oval(VOut in [[stage_in]], constant Oval &oval [[buffer(0)]])
{
    float half_stroke = oval.band.x / 2.0;
    float2 q = (in.at - oval.shape.xy) / (oval.shape.zw + half_stroke);
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
    return premultiplied(oval.rgba, 1.0);
}

// Where line y crosses a rounded box: [ends.x, ends.y), empty when ends.y <= ends.x.
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

// A sample is in when it lies inside the rounded box and, for a ring, outside the box inset by band.x.
fragment float4 roundbox(VOut in [[stage_in]], constant RoundBox &b [[buffer(0)]])
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
    return premultiplied(b.rgba, 1.0);
}

// Once a pixel, at its centre: 1 − smoothstep(−blur, blur, d), d the signed distance to the rounded box.
fragment float4 shade(VOut in [[stage_in]], constant RoundBox &b [[buffer(0)]])
{
    float2 p = floor(in.at) + 0.5;
    float2 c = (b.box.xy + b.box.zw) / 2.0;
    float2 h = (b.box.zw - b.box.xy) / 2.0;
    float r = b.band.w;
    float blur = b.band.z;
    float2 q = abs(p - c) - h + r;
    float2 o = max(q, float2(0.0));
    float d = min(max(q.x, q.y), 0.0) + length(o) - r;
    float cover;
    if (blur > 0.0) {
        float t = clamp((d + blur) / (2.0 * blur), 0.0, 1.0);
        cover = 1.0 - t * t * (3.0 - 2.0 * t);
    } else {
        cover = d < 0.0 ? 1.0 : 0.0;
    }
    if (cover <= 0.0) {
        discard_fragment();
    }
    return premultiplied(b.rgba, cover);
}

// A path's cover in a gradient: the ramp (256 x 1, straight colour) at the position of the pixel's centre along
// the gradient, nearest. abcd and ef take the centre to the gradient's own space; options: kind (0 linear,
// 1 radial), spread (0 pad, 1 repeat, 2 reflect).
struct Gradient { float4 abcd; float4 ef; uint4 options; };

fragment float4 gradient(VOut in [[stage_in]], texture2d<float> ramp [[texture(0)]], constant Gradient &g [[buffer(0)]])
{
    float2 centre = floor(in.at) + 0.5;
    float u = g.abcd.x * centre.x + g.abcd.z * centre.y + g.ef.x;
    float v = g.abcd.y * centre.x + g.abcd.w * centre.y + g.ef.y;
    float t = g.options.x == 0 ? u : length(float2(u, v));
    if (g.options.y == 0) {
        t = clamp(t, 0.0, 1.0);
    } else if (g.options.y == 1) {
        t = t - floor(t);
    } else {
        float m = t - 2.0 * floor(t / 2.0);
        t = m > 1.0 ? 2.0 - m : m;
    }
    float4 c = ramp.read(uint2(uint(floor(t * 255.0 + 0.5)), 0));
    return float4(c.rgb * c.a, c.a);
}

// An image: another framebuffer's texture (premultiplied colour) under the inverse placement of the pixel's
// centre, nearest or a blend of the four texels around the point. abcd and efwh: the inverse placement and the
// source's size; options: opacity 0..255, linear (1) or nearest (0); the tint's bytes; the window u0 v0 u1 v1
// (style.y = 1); the rounded box and its radii tl tr br bl (style.x = 1).
struct Picture { float4 abcd; float4 efwh; uint4 options; uint4 tint; float4 win; float4 box; float4 radii; uint4 style; };

fragment float4 image(VOut in [[stage_in]], texture2d<float> source [[texture(0)]], constant Picture &p [[buffer(0)]])
{
    float2 centre = floor(in.at) + 0.5;
    float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
    float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
    float w = p.efwh.z;
    float h = p.efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard_fragment();
    }
    // the window crops; the texels beside it are still read by the smooth filter
    if (p.style.y != 0 && !(u >= p.win.x && u < p.win.z && v >= p.win.y && v < p.win.w)) {
        discard_fragment();
    }
    // rounded corners: a sample is in when it lies inside the rounded box
    if (p.style.x != 0 && !inside(across(p.box, p.radii, in.at.y), in.at.x)) {
        discard_fragment();
    }

    float4 c;
    if (p.options.y == 0) {
        c = source.read(uint2(uint(floor(u)), uint(floor(v))));
    } else {
        float2 at = float2(u, v) - 0.5;
        float2 o = floor(at);
        float2 t = at - o;
        float2 last = float2(w, h) - 1.0;
        uint2 a = uint2(clamp(o, float2(0.0), last));
        uint2 b = uint2(clamp(o + 1.0, float2(0.0), last));
        c = mix(mix(source.read(a), source.read(uint2(b.x, a.y)), t.x), mix(source.read(uint2(a.x, b.y)), source.read(b), t.x), t.y);
    }
    // the tint multiplies the straight colour and the alpha; then the opacity
    float4 tint = float4(p.tint) / 255.0;
    float k = tint.a * float(p.options.x) / 255.0;
    return float4(c.rgb * tint.rgb * k, c.a * k);
}

// A layer laid down: its own texture (premultiplied colour, starting at place.xy of the surface) at the pixel,
// inside the rounded box, at the layer's opacity (place.z, 0..1).
struct Sheet { float4 box; float4 radii; float4 place; };

fragment float4 sheet(VOut in [[stage_in]], texture2d<float> source [[texture(0)]], constant Sheet &s [[buffer(0)]])
{
    if (!inside(across(s.box, s.radii, in.at.y), in.at.x)) {
        discard_fragment();
    }
    float2 local = floor(in.at) - s.place.xy;
    if (local.x < 0.0 || local.y < 0.0 || local.x >= float(source.get_width()) || local.y >= float(source.get_height())) {
        discard_fragment();
    }
    return source.read(uint2(local)) * s.place.z;
}

// A glass pane, PHPLandGlass::pane() at the pixel's centre: the blurred backdrop (a pyramid level, straight colour,
// `scale` times smaller, starting at origin) bent near the edge, mixed with the sharp one by the frost, then saturation,
// tint, light, rim and grain; covered by 0.5 − d of the rounded box, at the pane's alpha. Straight in, premultiplied out.
// look: frost, refraction, bevel, saturation; look2: light, grain, alpha (0..255), scale
struct Pane { float4 box; float4 radii; float4 look; float4 look2; uint4 tint; uint4 rim; float2 origin; uint has_sharp; uint pad; int2 blur_size; int2 sharp_size; };

static float level_sample(texture2d<float> level, int2 size, float u, float v, uint k)
{
    float fu = floor(u);
    float fv = floor(v);
    float tx = u - fu;
    float ty = v - fv;
    int x0 = clamp(int(fu), 0, size.x - 1);
    int x1 = clamp(int(fu) + 1, 0, size.x - 1);
    int y0 = clamp(int(fv), 0, size.y - 1);
    int y1 = clamp(int(fv) + 1, 0, size.y - 1);
    float a = round(level.read(uint2(x0, y0))[k] * 255.0);
    float b = round(level.read(uint2(x1, y0))[k] * 255.0);
    float c = round(level.read(uint2(x0, y1))[k] * 255.0);
    float d = round(level.read(uint2(x1, y1))[k] * 255.0);
    float top = a + (b - a) * tx;
    float under = c + (d - c) * tx;
    return top + (under - top) * ty;
}

static uint grain_of(int x, int y)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u;
    h = (h ^ (h >> 13)) * 1274126177u;
    h ^= h >> 16;
    return h & 0xFFFF;
}

fragment float4 pane(VOut in [[stage_in]], texture2d<float> blur [[texture(0)]], texture2d<float> sharp [[texture(1)]], constant Pane &g [[buffer(0)]])
{
    int2 at = int2(floor(in.at));
    float px = float(at.x) + 0.5;
    float py = float(at.y) + 0.5;
    float cx = (g.box.x + g.box.z) / 2.0;
    float cy = (g.box.y + g.box.w) / 2.0;
    float hx = (g.box.z - g.box.x) / 2.0;
    float hy = (g.box.w - g.box.y) / 2.0;
    float frost = g.look.x;
    float refraction = g.look.y;
    float bevel = g.look.z;
    float saturation = g.look.w;
    float light = g.look2.x;
    float grain_amount = g.look2.y;
    float alpha = g.look2.z;
    float scale = g.look2.w;
    float band = bevel > 0.0 ? bevel : (refraction > 0.0 ? refraction : 1.0);
    float r = px < cx ? (py < cy ? g.radii.x : g.radii.w) : (py < cy ? g.radii.y : g.radii.z);
    float dx = abs(px - cx) - (hx - r);
    float dy = abs(py - cy) - (hy - r);
    float d;
    float nx;
    float ny;
    if (dx > 0.0 && dy > 0.0) {
        float len = sqrt(dx * dx + dy * dy);
        d = len - r;
        nx = dx / len;
        ny = dy / len;
    } else if (dx > dy) {
        d = dx - r;
        nx = 1.0;
        ny = 0.0;
    } else {
        d = dy - r;
        nx = 0.0;
        ny = 1.0;
    }
    if (d >= 0.5) {
        discard_fragment();
    }
    if (px < cx) { nx = -nx; }
    if (py < cy) { ny = -ny; }
    float e = clamp(1.0 + d / band, 0.0, 1.0);
    float fe = e * e;
    float sx = px - nx * refraction * fe;
    float sy = py - ny * refraction * fe;
    float u = (sx - g.origin.x) / scale - 0.5;
    float v = (sy - g.origin.y) / scale - 0.5;
    float3 c = float3(level_sample(blur, g.blur_size, u, v, 0), level_sample(blur, g.blur_size, u, v, 1), level_sample(blur, g.blur_size, u, v, 2));
    if (g.has_sharp != 0 && frost < 1.0) {
        float su = sx - g.origin.x - 0.5;
        float sv = sy - g.origin.y - 0.5;
        for (uint k = 0; k < 3; k++) {
            float s = level_sample(sharp, g.sharp_size, su, sv, k);
            c[k] = s + (c[k] - s) * frost;
        }
    }
    float luma = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
    float lit = 0.5 + 0.5 * (nx * -0.6 + ny * -0.8);
    float grain = grain_amount * 0.04 * (float(grain_of(at.x, at.y)) / 65536.0 - 0.5) * 255.0;
    float3 tint = float3(g.tint.rgb);
    float tint_a = float(g.tint.a) / 255.0;
    float3 rim = float3(g.rim.rgb);
    float rim_a = float(g.rim.a) / 255.0;
    float3 o;
    for (uint k = 0; k < 3; k++) {
        float ck = luma + (c[k] - luma) * saturation;
        ck = ck + (tint[k] - ck) * tint_a;
        ck = ck + light * 255.0 + rim[k] * rim_a * fe * lit + grain;
        o[k] = clamp(floor(ck + 0.5), 0.0, 255.0);
    }
    float cover = min(0.5 - d, 1.0);
    float a = floor(cover * alpha + 0.5);
    if (a <= 0.0) {
        discard_fragment();
    }
    float k = a / 255.0;
    return float4(o / 255.0 * k, k);
}

// Every sample of the four-sample texture from the single-sample one, after it was written to.
fragment float4 copy(VOut in [[stage_in]], texture2d<float> frame [[texture(0)]])
{
    return frame.read(uint2(in.position.xy));
}

// Reading: premultiplied colour as straight colour.
fragment float4 unpremultiply(VOut in [[stage_in]], texture2d<float> frame [[texture(0)]])
{
    float4 c = frame.read(uint2(in.position.xy));
    return c.a > 0.0 ? float4(c.rgb / c.a, c.a) : float4(0.0);
}

// Writing: straight colour as premultiplied colour.
fragment float4 premultiply(VOut in [[stage_in]], texture2d<float> frame [[texture(0)]])
{
    float4 c = frame.read(uint2(in.position.xy));
    return float4(c.rgb * c.a, c.a);
}
