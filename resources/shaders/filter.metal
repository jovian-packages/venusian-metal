#include <metal_stdlib>
using namespace metal;

// One filter pass over a layer target: the arithmetic of Surface's NativeFilters, one kind a pass (FilterPlan lists the
// kinds and what their numbers mean). Every texture is read exactly, as whole numbers 0..255, and a pixel's row is its
// row on the surface. The numbers a pass needs beyond its uniforms come in `data`, a float32's four bytes to an RGBA8
// texel, 512 a row.

struct FilterOut { float4 position [[position]]; };

struct Pass {
    float4 reg;   // the region: x0, y0, x1, y1 (x1, y1 past the last pixel)
    float4 kind;  // kind, 0, 0, 0
    float4 pa;    // the pass's first four numbers
    float4 pb;    // the next four
};

constant int PREMULTIPLY = 0;
constant int STRAIGHTEN = 1;
constant int BOX = 2;
constant int KERNEL = 3;
constant int SPIRAL = 4;
constant int MATRIX = 5;
constant int FLOOD = 6;
constant int COMPOSITE = 7;
constant int BLEND = 8;
constant int HASH = 9;
constant int TURBULENCE = 10;
constant int DISPLACE = 11;
constant int FIELD = 12;
constant int TABLE = 13;

// One triangle over the whole framebuffer: the fragment stage decides every pixel.
vertex FilterOut filter_place(uint vid [[vertex_id]])
{
    float2 p = float2(float((vid << 1) & 2u), float(vid & 2u));
    FilterOut out;
    out.position = float4(p * 2.0 - 1.0, 0.0, 1.0);
    return out;
}

// 2 to the integer power k, k in -127..127, by squaring: exact.
static float pow2(int k)
{
    float base = k < 0 ? 0.5 : 2.0;
    int n = k < 0 ? -k : k;
    float result = 1.0;
    for (int bit = 0; bit < 7; bit++) {
        if (((n >> bit) & 1) != 0) {
            result *= base;
        }
        base *= base;
    }
    return result;
}

// The i-th number: a float32 kept as its four bytes, little endian, in one RGBA8 texel.
static float num(texture2d<float> data, int i)
{
    int4 b = int4(floor(data.read(uint2(i & 511, i >> 9)) * 255.0 + 0.5));
    int e = ((b.w & 127) << 1) | (b.z >> 7);
    if (e == 0) {
        return 0.0;
    }
    float v = (1.0 + float(((b.z & 127) << 16) | (b.y << 8) | b.x) / 8388608.0) * pow2(e - 127);
    return (b.w >> 7) != 0 ? -v : v;
}

static int4 whole(texture2d<float> t, int2 p)
{
    return int4(floor(t.read(uint2(p)) * 255.0 + 0.5));
}

// A pixel of the region; one off it is nothing (edge 0) or the nearest the region has (edge 1).
static int4 edged(texture2d<float> t, constant Pass &pass, int2 p, int edge)
{
    int2 lo = int2(pass.reg.xy);
    int2 hi = int2(pass.reg.zw);
    if (p.x < lo.x || p.x >= hi.x || p.y < lo.y || p.y >= hi.y) {
        if (edge == 0) {
            return int4(0);
        }
        p = clamp(p, lo, hi - 1);
    }
    return whole(t, p);
}

// The four pixels around (u, v), weights in 1/256ths, rounded to nearest.
static int4 bilinear(texture2d<float> t, constant Pass &pass, float u, float v, int edge)
{
    float fx = clamp(u - 0.5, -1.0e9, 1.0e9);
    float fy = clamp(v - 0.5, -1.0e9, 1.0e9);
    int x0 = int(floor(fx));
    int y0 = int(floor(fy));
    int tx = int(floor((fx - float(x0)) * 256.0));
    int ty = int(floor((fy - float(y0)) * 256.0));
    int4 sum = int4(0);
    sum += (256 - tx) * (256 - ty) * edged(t, pass, int2(x0, y0), edge);
    sum += tx * (256 - ty) * edged(t, pass, int2(x0 + 1, y0), edge);
    sum += (256 - tx) * ty * edged(t, pass, int2(x0, y0 + 1), edge);
    sum += tx * ty * edged(t, pass, int2(x0 + 1, y0 + 1), edge);
    return (sum + 32768) >> 16;
}

// Raven's sd_round_box_normal (ui.frag:42-57): x the distance to the rounded box (negative inside), yz the direction out of it.
static float3 box_distance(float2 pt, float4 box, float4 radii)
{
    float hx = (box.z - box.x) / 2.0;
    float hy = (box.w - box.y) / 2.0;
    float2 q0 = pt - float2((box.x + box.z) / 2.0, (box.y + box.w) / 2.0);
    float radius = q0.x < 0.0 ? (q0.y < 0.0 ? radii.x : radii.w) : (q0.y < 0.0 ? radii.y : radii.z);
    radius = min(min(radius, hx), hy);
    float sx = q0.x < 0.0 ? -1.0 : 1.0;
    float sy = q0.y < 0.0 ? -1.0 : 1.0;
    float qx = abs(q0.x) - hx + radius;
    float qy = abs(q0.y) - hy + radius;
    float mx = max(qx, 0.0);
    float my = max(qy, 0.0);
    float corner = sqrt(mx * mx + my * my);
    float nx = 0.0;
    float ny = 0.0;
    if (qx > 0.0 && qy > 0.0) {
        nx = sx * (qx / max(corner, 1.0e-4));
        ny = sy * (qy / max(corner, 1.0e-4));
    } else if (qx > qy) {
        nx = sx;
    } else {
        ny = sy;
    }
    return float3(min(max(qx, qy), 0.0) + corner - radius, nx, ny);
}

// A channel through its 256-entry table, when the mask has one: the tables lie one after another in `data`, red first.
static int table_pick(texture2d<float> data, int mask, int channel, int value)
{
    if (((mask >> channel) & 1) == 0) {
        return value;
    }
    int base = 0;
    for (int c = 0; c < channel; c++) {
        if (((mask >> c) & 1) != 0) {
            base += 256;
        }
    }
    return int(num(data, base + value) + 0.5);
}

static float unit(float v)
{
    return clamp(v, 0.0, 1.0);
}

static int byte(float v)
{
    return int(floor(unit(v) * 255.0 + 0.5));
}

// A premultiplied pixel of 0..1 floats: clamped, the colours held to the alpha, rounded to nearest.
static int4 stored(float4 p)
{
    int a = byte(p.w);
    return int4(min(byte(p.x), a), min(byte(p.y), a), min(byte(p.z), a), a);
}

static float2 lattice_grad(texture2d<float> data, int channel, int i)
{
    int at = 514 + (channel * 514 + i) * 2;
    return float2(num(data, at), num(data, at + 1));
}

static float noise2(texture2d<float> data, int channel, float x, float y)
{
    int ix = int(floor(x));
    int iy = int(floor(y));
    float rx0 = x - float(ix);
    float ry0 = y - float(iy);
    float rx1 = rx0 - 1.0;
    float ry1 = ry0 - 1.0;
    int bx0 = ix & 255;
    int bx1 = (bx0 + 1) & 255;
    int by0 = iy & 255;
    int by1 = (by0 + 1) & 255;
    int i = int(num(data, bx0));
    int j = int(num(data, bx1));
    int b00 = int(num(data, i + by0));
    int b10 = int(num(data, j + by0));
    int b01 = int(num(data, i + by1));
    int b11 = int(num(data, j + by1));
    float sx = rx0 * rx0 * (3.0 - 2.0 * rx0);
    float sy = ry0 * ry0 * (3.0 - 2.0 * ry0);
    float2 g00 = lattice_grad(data, channel, b00);
    float2 g10 = lattice_grad(data, channel, b10);
    float2 g01 = lattice_grad(data, channel, b01);
    float2 g11 = lattice_grad(data, channel, b11);
    float u = rx0 * g00.x + ry0 * g00.y;
    float v = rx1 * g10.x + ry0 * g10.y;
    float a = u + sx * (v - u);
    u = rx0 * g01.x + ry1 * g01.y;
    v = rx1 * g11.x + ry1 * g11.y;
    float b = u + sx * (v - u);
    return a + sy * (b - a);
}

fragment float4 filter_paint(FilterOut in [[stage_in]], constant Pass &pass [[buffer(0)]],
    texture2d<float> src [[texture(0)]], texture2d<float> src2 [[texture(1)]], texture2d<float> data [[texture(2)]], texture2d<float> map [[texture(3)]])
{
    int k = int(pass.kind.x);
    int2 p = int2(in.position.xy);
    int4 out_ = int4(0);

    if (k == PREMULTIPLY) {
        int4 c = whole(src, p);
        out_ = int4((c.xyz * c.w + 127) / 255, c.w);
    } else if (k == STRAIGHTEN) {
        int4 c = whole(src, p);
        out_ = c.w == 0 ? int4(0) : int4(min(int3(255), (c.xyz * 255 + c.w / 2) / c.w), c.w);
    } else if (k == BOX) {
        int axis = int(pass.pa.x);
        int size = clamp(int(pass.pa.y), 1, 128);
        int left = clamp(int(pass.pa.z), 0, 128);
        int edge = int(pass.pa.w);
        int4 sum = int4(0);
        for (int j = -left; j <= -left + size - 1; j++) {
            sum += edged(src, pass, axis == 0 ? int2(p.x + j, p.y) : int2(p.x, p.y + j), edge);
        }
        out_ = (sum + size / 2) / size;
    } else if (k == KERNEL) {
        int axis = int(pass.pa.x);
        int radius = clamp(int(pass.pa.y), 0, 32);
        int edge = int(pass.pa.z);
        int4 sum = int4(0);
        for (int n = 0; n < 2 * radius + 1; n++) {
            int j = n - radius;
            sum += int(num(data, n)) * edged(src, pass, axis == 0 ? int2(p.x + j, p.y) : int2(p.x, p.y + j), edge);
        }
        out_ = (sum + 128) >> 8;
    } else if (k == SPIRAL) {
        int samples = clamp(int(pass.pa.x), 1, 64);
        int edge = int(pass.pa.y);
        int4 sum = int4(0);
        for (int n = 0; n < samples; n++) {
            sum += bilinear(src, pass, float(p.x) + 0.5 + num(data, 2 * n), float(p.y) + 0.5 + num(data, 2 * n + 1), edge);
        }
        out_ = (sum + samples / 2) / samples;
    } else if (k == MATRIX) {
        int4 c = whole(src, p);
        float r = 0.0;
        float g = 0.0;
        float b = 0.0;
        if (c.w != 0) {
            r = float(min(255, (c.x * 255 + c.w / 2) / c.w)) / 255.0;
            g = float(min(255, (c.y * 255 + c.w / 2) / c.w)) / 255.0;
            b = float(min(255, (c.z * 255 + c.w / 2) / c.w)) / 255.0;
        }
        float alpha = float(c.w) / 255.0;
        float nr = unit(num(data, 0) * r + num(data, 1) * g + num(data, 2) * b + num(data, 3) * alpha + num(data, 4));
        float ng = unit(num(data, 5) * r + num(data, 6) * g + num(data, 7) * b + num(data, 8) * alpha + num(data, 9));
        float nb = unit(num(data, 10) * r + num(data, 11) * g + num(data, 12) * b + num(data, 13) * alpha + num(data, 14));
        float na = unit(num(data, 15) * r + num(data, 16) * g + num(data, 17) * b + num(data, 18) * alpha + num(data, 19));
        out_ = int4(int(floor(nr * na * 255.0 + 0.5)), int(floor(ng * na * 255.0 + 0.5)), int(floor(nb * na * 255.0 + 0.5)), int(floor(na * 255.0 + 0.5)));
    } else if (k == FLOOD) {
        int4 c = int4(pass.pa);
        out_ = int4((c.xyz * c.w + 127) / 255, c.w);
    } else if (k == COMPOSITE) {
        float4 a = float4(whole(src, p)) / 255.0;
        float4 b = float4(whole(src2, p)) / 255.0;
        int op = int(pass.pa.x);
        float4 r;
        if (op == 0) {
            r = a + b * (1.0 - a.w);
        } else if (op == 1) {
            r = a * b.w;
        } else if (op == 2) {
            r = a * (1.0 - b.w);
        } else if (op == 3) {
            r = a * b.w + b * (1.0 - a.w);
        } else if (op == 4) {
            r = a * (1.0 - b.w) + b * (1.0 - a.w);
        } else {
            r = pass.pa.y * a * b + pass.pa.z * a + pass.pa.w * b + pass.pb.x;
        }
        out_ = stored(r);
    } else if (k == BLEND) {
        float4 a = float4(whole(src, p)) / 255.0;
        float4 b = float4(whole(src2, p)) / 255.0;
        int mode = int(pass.pa.x);
        float3 r;
        if (mode == 0) {
            r = (1.0 - a.w) * b.xyz + a.xyz;
        } else if (mode == 1) {
            r = (1.0 - a.w) * b.xyz + (1.0 - b.w) * a.xyz + a.xyz * b.xyz;
        } else if (mode == 2) {
            r = b.xyz + a.xyz - a.xyz * b.xyz;
        } else if (mode == 3) {
            r = min((1.0 - a.w) * b.xyz + a.xyz, (1.0 - b.w) * a.xyz + b.xyz);
        } else {
            r = max((1.0 - a.w) * b.xyz + a.xyz, (1.0 - b.w) * a.xyz + b.xyz);
        }
        out_ = stored(float4(r, 1.0 - (1.0 - a.w) * (1.0 - b.w)));
    } else if (k == HASH) {
        uint seed = (uint(pass.pa.x) << 16) | uint(pass.pa.y);
        uint h = uint(p.x) * 374761393u + uint(p.y) * 668265263u + seed * 2246822519u;
        h = (h ^ (h >> 13)) * 1274126177u;
        int v = int((h ^ (h >> 16)) & 255u);
        out_ = int4(v, v, v, 255);
    } else if (k == TURBULENCE) {
        bool fractal = pass.pa.x > 0.5;
        int octaves = clamp(int(pass.pa.y), 1, 4);
        int4 straight = int4(0);
        for (int ch = 0; ch < 4; ch++) {
            float vx = float(p.x) * pass.pa.z;
            float vy = float(p.y) * pass.pa.w;
            float ratio = 1.0;
            float sum = 0.0;
            for (int o = 0; o < octaves; o++) {
                float n = noise2(data, ch, vx, vy);
                sum += (fractal ? n : abs(n)) / ratio;
                vx *= 2.0;
                vy *= 2.0;
                ratio *= 2.0;
            }
            float value = fractal ? (sum * 255.0 + 255.0) / 2.0 : sum * 255.0;
            straight[ch] = clamp(int(floor(value + 0.5)), 0, 255);
        }
        out_ = int4((straight.xyz * straight.w + 127) / 255, straight.w);
    } else if (k == FIELD) {
        int kd = int(pass.pa.x);
        float2 pt = float2(float(p.x) + 0.5, float(p.y) + 0.5);
        float from = num(data, 8);
        float span = num(data, 9) - from;
        span = abs(span) < 1.0e-30 ? 1.0e-30 : span;
        if (kd == 2) {
            int v = byte((pt.y - from) / span);
            out_ = int4(v, v, v, 255);
        } else {
            float3 f = box_distance(pt, float4(num(data, 0), num(data, 1), num(data, 2), num(data, 3)), float4(num(data, 4), num(data, 5), num(data, 6), num(data, 7)));
            if (kd == 1) {
                int v = f.x < 0.0 ? 255 : 0;
                out_ = int4(v, v, v, 255);
            } else {
                out_ = int4(byte((f.x - from) / span), byte(0.5 + 0.5 * f.y), byte(0.5 + 0.5 * f.z), 255);
            }
        }
    } else if (k == TABLE) {
        int4 c = whole(src, p);
        int a = c.w;
        int3 st = a == 0 ? int3(0) : min(int3(255), (c.xyz * 255 + a / 2) / a);
        int mask = int(pass.pa.x);
        int na = table_pick(data, mask, 3, a);
        int3 t = int3(table_pick(data, mask, 0, st.x), table_pick(data, mask, 1, st.y), table_pick(data, mask, 2, st.z));
        out_ = int4((t * na + 127) / 255, na);
    } else if (k == DISPLACE) {
        float scale = pass.pa.x;
        int xch = int(pass.pa.y);
        int ych = int(pass.pa.z);
        int bands = clamp(int(pass.pa.w), 1, 16);
        float spread = pass.pb.x;
        float dx = 0.0;
        float dy = 0.0;
        // the map lies on the region's top left corner and travels with it
        int2 size = int2(map.get_width(), map.get_height());
        int2 q = p - int2(pass.reg.xy);
        if (pass.pb.y > 0.5) {
            // the map is an earlier result, premultiplied: straight here
            int4 m = whole(src2, p);
            int4 sm = m.w == 0 ? int4(0) : int4(min(int3(255), (m.xyz * 255 + m.w / 2) / m.w), m.w);
            dx = scale * (float(sm[xch]) / 255.0 - 0.5);
            dy = scale * (float(sm[ych]) / 255.0 - 0.5);
        } else if (q.x < size.x && q.y < size.y) {
            int4 m = whole(map, q);
            dx = scale * (float(m[xch]) / 255.0 - 0.5);
            dy = scale * (float(m[ych]) / 255.0 - 0.5);
        }
        if (bands == 1) {
            out_ = bilinear(src, pass, float(p.x) + 0.5 + dx, float(p.y) + 0.5 + dy, 0);
        } else {
            float3 rgb = float3(0.0);
            for (int i = 0; i < bands; i++) {
                float t = float(i) / float(bands - 1) * 2.0 - 1.0;
                float f = 1.0 + spread * t;
                int4 s = bilinear(src, pass, float(p.x) + 0.5 + dx * f, float(p.y) + 0.5 + dy * f, 0);
                rgb += float3(s.xyz) * float3(num(data, i * 3), num(data, i * 3 + 1), num(data, i * 3 + 2));
            }
            int alpha = bilinear(src, pass, float(p.x) + 0.5 + dx, float(p.y) + 0.5 + dy, 0).w;
            out_ = int4(min(int3(floor(rgb + 0.5)), int3(alpha)), alpha);
        }
    }
    return float4(out_) / 255.0;
}

// ---- Glass (Surface's drawing-path spec, G4): the backdrop and its dual-filter (Kawase) pyramid -------------------
// Each pass is drawn over a level of its own size; every texel is read exactly, as whole numbers, so the levels are
// ext-fb's fb_kawase_down() and fb_kawase_up() byte for byte.

// The region under the panes, copied out of the target: reg is its x, y in the target.
fragment float4 glass_capture(FilterOut in [[stage_in]], texture2d<float> target [[texture(0)]], constant int4 &reg [[buffer(0)]])
{
    return target.read(uint2(int2(in.position.xy) + reg.xy));
}

static uint4 texel(texture2d<float> level, int x, int y, int2 size)
{
    return uint4(round(level.read(uint2(clamp(x, 0, size.x - 1), clamp(y, 0, size.y - 1))) * 255.0));
}

// One level down: (4 x the 2x2 centre + the 4x4 block + 16) >> 5, reads past the edge taking it. size is the source's.
fragment float4 glass_down(FilterOut in [[stage_in]], texture2d<float> source [[texture(0)]], constant int4 &size [[buffer(0)]])
{
    int2 o = int2(in.position.xy) * 2 - 1;
    uint4 all = uint4(0), centre = uint4(0);
    for (int b = 0; b < 4; b++) {
        for (int a = 0; a < 4; a++) {
            uint4 v = texel(source, o.x + a, o.y + b, size.xy);
            all += v;
            if ((a == 1 || a == 2) && (b == 1 || b == 2)) {
                centre += v;
            }
        }
    }
    return float4((4u * centre + all + 16u) >> 5) / 255.0;
}

// 16 x the bilinear value at (x4, y4), in quarter pixels from the source's pixel centres.
static uint4 tap16(texture2d<float> source, int x4, int y4, int2 size)
{
    int fx = x4 & 3, fy = y4 & 3;
    int ix = (x4 - fx) / 4, iy = (y4 - fy) / 4;
    uint ux = uint(fx), uy = uint(fy);
    return (4u - uy) * ((4u - ux) * texel(source, ix, iy, size) + ux * texel(source, ix + 1, iy, size))
        + uy * ((4u - ux) * texel(source, ix, iy + 1, size) + ux * texel(source, ix + 1, iy + 1, size));
}

// One level up: the 8-tap tent, edge midpoints at +/-1 source pixel (weight 1), diagonals at +/-1/2 (weight 2), / 192.
fragment float4 glass_up(FilterOut in [[stage_in]], texture2d<float> source [[texture(0)]], constant int4 &size [[buffer(0)]])
{
    int2 q = int2(in.position.xy) * 2 - 1;
    uint4 sum = tap16(source, q.x - 4, q.y, size.xy) + tap16(source, q.x + 4, q.y, size.xy)
        + tap16(source, q.x, q.y - 4, size.xy) + tap16(source, q.x, q.y + 4, size.xy)
        + 2u * (tap16(source, q.x - 2, q.y - 2, size.xy) + tap16(source, q.x + 2, q.y - 2, size.xy)
            + tap16(source, q.x - 2, q.y + 2, size.xy) + tap16(source, q.x + 2, q.y + 2, size.xy));
    return float4((sum + 96u) / 192u) / 255.0;
}
