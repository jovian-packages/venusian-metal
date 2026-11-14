// Filter steps, the glass pyramid and capture. Each pass draws one triangle over the viewport, the image's own size, and
// a fragment works out one pixel from its inputs. Images are RGBA8 premultiplied colour unless a note says straight;
// a value is read as its byte and written as one, so where Velvet's arithmetic is in integers (blurs, Kawase, tables,
// floods, the hash) this is too, step for step, and gives its bytes. PHPLandFilters and PHPLandGlass are the reference.

struct FOut {
    float4 position [[position]];
};

vertex FOut cover(uint vid [[vertex_id]])
{
    float2 p = float2((vid << 1) & 2, vid & 2);
    FOut o;
    o.position = float4(p.x * 2.0 - 1.0, 1.0 - p.y * 2.0, 0.0, 1.0);
    return o;
}

static int2 pixel(FOut in)
{
    return int2(floor(in.position.xy));
}

static uint4 byte4(float4 c)
{
    return uint4(round(c * 255.0));
}

static float4 unbyte(uint4 v)
{
    return float4(v) / 255.0;
}

// A pixel of an image of `size`, or past its edge: transparent (edge 0) or the nearest edge pixel (edge 1).
static uint4 fetch(texture2d<float> t, int2 p, int2 size, uint edge)
{
    if (p.x < 0 || p.y < 0 || p.x >= size.x || p.y >= size.y) {
        if (edge == 0) {
            return uint4(0);
        }
        p = clamp(p, int2(0), size - 1);
    }
    return byte4(t.read(uint2(p)));
}

// Velvet's unpremultiply of one channel: min(255, (c × 255 + a / 2) / a), 0 where a is 0.
static uint straight(uint c, uint a)
{
    return a == 0 ? 0 : min(255u, (c * 255 + a / 2) / a);
}

static uint premultiply(uint c, uint a)
{
    return (c * a + 127) / 255;
}

// PHPLandFilters::bilinear(): the four pixels around (u, v), weights in 1/256ths, rounded to nearest.
static uint4 bilinear(texture2d<float> t, int2 size, float u, float v, uint edge)
{
    float fx = clamp(u - 0.5, -1.0e9, 1.0e9);
    float fy = clamp(v - 0.5, -1.0e9, 1.0e9);
    int x0 = int(floor(fx));
    int y0 = int(floor(fy));
    uint tx = uint(floor((fx - float(x0)) * 256.0));
    uint ty = uint(floor((fy - float(y0)) * 256.0));
    uint4 sum = uint4(0);
    int2 taps[4] = { int2(x0, y0), int2(x0 + 1, y0), int2(x0, y0 + 1), int2(x0 + 1, y0 + 1) };
    uint weights[4] = { (256 - tx) * (256 - ty), tx * (256 - ty), (256 - tx) * ty, tx * ty };
    for (int i = 0; i < 4; i++) {
        if (weights[i] != 0) {
            sum += weights[i] * fetch(t, taps[i], size, edge);
        }
    }
    return (sum + 32768) >> 16;
}

// --- blur ---------------------------------------------------------------------------------------------------------

// One box blur along an axis: output i is the mean of i − left .. i − left + width − 1, rounded to nearest.
struct Box { int2 size; uint horizontal; uint width; int left; uint edge; uint2 pad; };

fragment float4 box_blur(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant Box &b [[buffer(0)]])
{
    int2 p = pixel(in);
    int2 step = b.horizontal != 0 ? int2(1, 0) : int2(0, 1);
    uint4 sum = uint4(0);
    for (int j = 0; j < int(b.width); j++) {
        sum += fetch(src, p + step * (j - b.left), b.size, b.edge);
    }
    return unbyte((sum + b.width / 2) / b.width);
}

// A small Gaussian along an axis: integer weights for −R..R that sum to 256, (sum + 128) >> 8.
struct Kernel { int2 size; uint horizontal; uint radius; uint edge; uint pad0; uint pad1; uint pad2; int weights[64]; };

fragment float4 kernel_blur(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant Kernel &k [[buffer(0)]])
{
    int2 p = pixel(in);
    int2 step = k.horizontal != 0 ? int2(1, 0) : int2(0, 1);
    uint4 sum = uint4(0);
    for (int i = 0; i <= int(2 * k.radius); i++) {
        int2 q = p + step * (i - int(k.radius));
        int at = k.horizontal != 0 ? q.x : q.y;
        int length = k.horizontal != 0 ? k.size.x : k.size.y;
        if (at < 0 || at >= length) {
            if (k.edge == 0) {
                continue;
            }
        }
        sum += uint(k.weights[i]) * fetch(src, q, k.size, 1);
    }
    return unbyte((sum + 128) >> 8);
}

// Points on a golden-angle spiral, each read bilinearly, averaged.
struct Spiral { int2 size; uint samples; uint edge; };

fragment float4 spiral_blur(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant Spiral &s [[buffer(0)]], constant float2 *offsets [[buffer(1)]])
{
    int2 p = pixel(in);
    uint4 sum = uint4(0);
    for (uint i = 0; i < s.samples; i++) {
        sum += bilinear(src, s.size, float(p.x) + 0.5 + offsets[i].x, float(p.y) + 0.5 + offsets[i].y, s.edge);
    }
    return unbyte((sum + s.samples / 2) / s.samples);
}

// --- colour -------------------------------------------------------------------------------------------------------

// The 4 × 5 matrix row by row, on the straight colour; premultiplied again by the new alpha.
fragment float4 color_matrix(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant float *m [[buffer(0)]])
{
    uint4 c = byte4(src.read(uint2(pixel(in))));
    float r = float(straight(c.r, c.a)) / 255.0;
    float g = float(straight(c.g, c.a)) / 255.0;
    float b = float(straight(c.b, c.a)) / 255.0;
    float a = float(c.a) / 255.0;
    float nr = clamp(m[0] * r + m[1] * g + m[2] * b + m[3] * a + m[4], 0.0, 1.0);
    float ng = clamp(m[5] * r + m[6] * g + m[7] * b + m[8] * a + m[9], 0.0, 1.0);
    float nb = clamp(m[10] * r + m[11] * g + m[12] * b + m[13] * a + m[14], 0.0, 1.0);
    float na = clamp(m[15] * r + m[16] * g + m[17] * b + m[18] * a + m[19], 0.0, 1.0);
    return unbyte(uint4(floor(nr * na * 255.0 + 0.5), floor(ng * na * 255.0 + 0.5), floor(nb * na * 255.0 + 0.5), floor(na * 255.0 + 0.5)));
}

fragment float4 flood(FOut in [[stage_in]], constant uint4 &pixel_ [[buffer(0)]])
{
    return unbyte(pixel_);
}

// Four lookup tables of 256 bytes, red, green, blue, alpha (an identity where the step left one out), on the
// straight colour; the alpha's table first, the colours premultiplied by the new alpha.
fragment float4 table(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant uchar *tables [[buffer(0)]])
{
    uint4 c = byte4(src.read(uint2(pixel(in))));
    uint na = tables[768 + c.a];
    return unbyte(uint4(premultiply(tables[straight(c.r, c.a)], na), premultiply(tables[256 + straight(c.g, c.a)], na), premultiply(tables[512 + straight(c.b, c.a)], na), na));
}

// A premultiplied pixel of 0..1 floats as bytes: clamped, the colours held to the alpha, rounded to nearest.
static float4 stored(float4 v)
{
    uint a = uint(floor(clamp(v.a, 0.0, 1.0) * 255.0 + 0.5));
    uint3 c = min(uint3(a), uint3(floor(clamp(v.rgb, 0.0, 1.0) * 255.0 + 0.5)));
    return unbyte(uint4(c, a));
}

// operator 0 over, 1 in, 2 out, 3 atop, 4 xor, 5 arithmetic (k1..k4)
struct Compose { uint op; uint pad0; uint pad1; uint pad2; float4 k; };

fragment float4 composite(FOut in [[stage_in]], texture2d<float> a [[texture(0)]], texture2d<float> b [[texture(1)]], constant Compose &c [[buffer(0)]])
{
    uint2 p = uint2(pixel(in));
    float4 x = float4(byte4(a.read(p))) / 255.0;
    float4 y = float4(byte4(b.read(p))) / 255.0;
    float aa = x.a;
    float ab = y.a;
    float4 v;
    switch (c.op) {
        case 0: v = x + y * (1.0 - aa); break;
        case 1: v = x * ab; break;
        case 2: v = x * (1.0 - ab); break;
        case 3: v = x * ab + y * (1.0 - aa); break;
        case 4: v = x * (1.0 - ab) + y * (1.0 - aa); break;
        default: v = c.k.x * x * y + c.k.y * x + c.k.z * y + c.k.w; break;
    }
    return stored(v);
}

// mode 0 normal, 1 multiply, 2 screen, 3 darken, 4 lighten; a over b
fragment float4 blend(FOut in [[stage_in]], texture2d<float> a [[texture(0)]], texture2d<float> b [[texture(1)]], constant uint &mode [[buffer(0)]])
{
    uint2 p = uint2(pixel(in));
    float4 x = float4(byte4(a.read(p))) / 255.0;
    float4 y = float4(byte4(b.read(p))) / 255.0;
    float qa = x.a;
    float qb = y.a;
    float3 ca = x.rgb;
    float3 cb = y.rgb;
    float3 v;
    switch (mode) {
        case 0: v = (1.0 - qa) * cb + ca; break;
        case 1: v = (1.0 - qa) * cb + (1.0 - qb) * ca + ca * cb; break;
        case 2: v = cb + ca - ca * cb; break;
        case 3: v = min((1.0 - qa) * cb + ca, (1.0 - qb) * ca + cb); break;
        default: v = max((1.0 - qa) * cb + ca, (1.0 - qb) * ca + cb); break;
    }
    return stored(float4(v, 1.0 - (1.0 - qa) * (1.0 - qb)));
}

// --- noise --------------------------------------------------------------------------------------------------------

static uint hash(int x, int y, int seed)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u + uint(seed) * 2246822519u;
    h = (h ^ (h >> 13)) * 1274126177u;
    return h ^ (h >> 16);
}

// type 0 turbulence, 1 fractal, 2 hash. stitch: width, height, wrapX, wrapY of the first octave; stitched: 1 or 0.
struct Noise { int2 origin; int seed; uint type; float2 frequency; uint octaves; uint stitched; int4 stitch; };

// gradients: 514 x 4, a row a channel, each texel a gradient (x, y)
static float noise2(constant ushort *selector, texture2d<float> gradients, uint channel, float x, float y, bool stitched, int4 stitch)
{
    float t = x + 4096.0;
    int bx0 = int(t) & 0xFF;
    int bx1 = (bx0 + 1) & 0xFF;
    float rx0 = t - float(int(t));
    float rx1 = rx0 - 1.0;
    t = y + 4096.0;
    int by0 = int(t) & 0xFF;
    int by1 = (by0 + 1) & 0xFF;
    float ry0 = t - float(int(t));
    float ry1 = ry0 - 1.0;
    if (stitched) {
        if (bx0 >= stitch.z) { bx0 -= stitch.x; }
        if (bx1 >= stitch.z) { bx1 -= stitch.x; }
        if (by0 >= stitch.w) { by0 -= stitch.y; }
        if (by1 >= stitch.w) { by1 -= stitch.y; }
    }
    bx0 &= 0xFF;
    bx1 &= 0xFF;
    by0 &= 0xFF;
    by1 &= 0xFF;
    int i = selector[bx0];
    int j = selector[bx1];
    int b00 = selector[i + by0];
    int b10 = selector[j + by0];
    int b01 = selector[i + by1];
    int b11 = selector[j + by1];
    float sx = rx0 * rx0 * (3.0 - 2.0 * rx0);
    float sy = ry0 * ry0 * (3.0 - 2.0 * ry0);
    float2 g00 = gradients.read(uint2(b00, channel)).xy;
    float2 g10 = gradients.read(uint2(b10, channel)).xy;
    float2 g01 = gradients.read(uint2(b01, channel)).xy;
    float2 g11 = gradients.read(uint2(b11, channel)).xy;
    float u = rx0 * g00.x + ry0 * g00.y;
    float v = rx1 * g10.x + ry0 * g10.y;
    float a = u + sx * (v - u);
    u = rx0 * g01.x + ry1 * g01.y;
    v = rx1 * g11.x + ry1 * g11.y;
    float b = u + sx * (v - u);
    return a + sy * (b - a);
}

fragment float4 noise(FOut in [[stage_in]], texture2d<float> gradients [[texture(0)]], constant Noise &n [[buffer(0)]], constant ushort *selector [[buffer(1)]])
{
    int2 p = pixel(in) + n.origin;
    if (n.type == 2) {
        uint h = hash(p.x, p.y, n.seed) & 0xFF;
        return unbyte(uint4(h, h, h, 255));
    }
    uint4 s;
    for (uint channel = 0; channel < 4; channel++) {
        float sum = 0.0;
        float vx = float(p.x) * n.frequency.x;
        float vy = float(p.y) * n.frequency.y;
        float ratio = 1.0;
        int4 stitch = n.stitch;
        for (uint octave = 0; octave < n.octaves; octave++) {
            float v = noise2(selector, gradients, channel, vx, vy, n.stitched != 0, stitch);
            sum += (n.type == 1 ? v : abs(v)) / ratio;
            vx *= 2.0;
            vy *= 2.0;
            ratio *= 2.0;
            stitch = int4(stitch.x * 2, stitch.y * 2, 2 * stitch.z - 4096, 2 * stitch.w - 4096);
        }
        float value = n.type == 1 ? (sum * 255.0 + 255.0) / 2.0 : sum * 255.0;
        s[channel] = uint(clamp(floor(value + 0.5), 0.0, 255.0));
    }
    return unbyte(uint4(premultiply(s.r, s.a), premultiply(s.g, s.a), premultiply(s.b, s.a), s.a));
}

// --- fields -------------------------------------------------------------------------------------------------------

// kind 0 rounded box (distance, normal), 1 inside, 2 ramp
struct FieldStep { int2 origin; uint kind; uint pad; float4 box; float4 radii; float2 window; };

fragment float4 field(FOut in [[stage_in]], constant FieldStep &f [[buffer(0)]])
{
    float2 p = float2(pixel(in) + f.origin) + 0.5;
    float span = f.window.y - f.window.x;
    if (f.kind == 2) {
        uint v = uint(floor(clamp((p.y - f.window.x) / span, 0.0, 1.0) * 255.0 + 0.5));
        return unbyte(uint4(v, v, v, 255));
    }
    float hx = (f.box.z - f.box.x) / 2.0;
    float hy = (f.box.w - f.box.y) / 2.0;
    float2 q0 = p - float2((f.box.x + f.box.z) / 2.0, (f.box.y + f.box.w) / 2.0);
    float radius = q0.x < 0.0 ? (q0.y < 0.0 ? f.radii.x : f.radii.w) : (q0.y < 0.0 ? f.radii.y : f.radii.z);
    radius = min(radius, min(hx, hy));
    float sideX = q0.x < 0.0 ? -1.0 : 1.0;
    float sideY = q0.y < 0.0 ? -1.0 : 1.0;
    float qx = abs(q0.x) - hx + radius;
    float qy = abs(q0.y) - hy + radius;
    float corner = length(float2(max(qx, 0.0), max(qy, 0.0)));
    float nx;
    float ny;
    if (qx > 0.0 && qy > 0.0) {
        nx = sideX * (qx / max(corner, 1e-4));
        ny = sideY * (qy / max(corner, 1e-4));
    } else if (qx > qy) {
        nx = sideX;
        ny = 0.0;
    } else {
        nx = 0.0;
        ny = sideY;
    }
    float d = min(max(qx, qy), 0.0) + corner - radius;
    if (f.kind == 1) {
        uint v = d < 0.0 ? 255 : 0;
        return unbyte(uint4(v, v, v, 255));
    }
    return unbyte(uint4(uint(floor(clamp((d - f.window.x) / span, 0.0, 1.0) * 255.0 + 0.5)), uint(floor(clamp(0.5 + 0.5 * nx, 0.0, 1.0) * 255.0 + 0.5)), uint(floor(clamp(0.5 + 0.5 * ny, 0.0, 1.0) * 255.0 + 0.5)), 255));
}

// --- displacement -------------------------------------------------------------------------------------------------

// The map is straight (a framebuffer's) or premultiplied (a step's), read where the pixel is; past its edge, no shift.
struct Displacement { int2 size; int2 map_size; float scale; float spread; uint x_channel; uint y_channel; uint bands; uint map_premultiplied; uint2 pad; };

fragment float4 displace(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], texture2d<float> map [[texture(1)]], constant Displacement &d [[buffer(0)]], constant float4 *weights [[buffer(1)]])
{
    int2 p = pixel(in);
    float dx = 0.0;
    float dy = 0.0;
    if (p.x < d.map_size.x && p.y < d.map_size.y) {
        uint4 m = byte4(map.read(uint2(p)));
        if (d.map_premultiplied != 0) {
            m = uint4(straight(m.r, m.a), straight(m.g, m.a), straight(m.b, m.a), m.a);
        }
        dx = d.scale * (float(m[d.x_channel]) / 255.0 - 0.5);
        dy = d.scale * (float(m[d.y_channel]) / 255.0 - 0.5);
    }
    float u = float(p.x) + 0.5;
    float v = float(p.y) + 0.5;
    uint4 middle = bilinear(src, d.size, u + dx, v + dy, 0);
    if (d.bands == 1) {
        return unbyte(middle);
    }
    float3 rgb = float3(0.0);
    for (uint i = 0; i < d.bands; i++) {
        float k = float(i) / float(d.bands - 1) * 2.0 - 1.0;
        float f = 1.0 + d.spread * k;
        uint4 t = bilinear(src, d.size, u + dx * f, v + dy * f, 0);
        rgb += float3(t.rgb) * weights[i].xyz;
    }
    uint3 c = min(uint3(middle.a), uint3(floor(rgb + 0.5)));
    return unbyte(uint4(c, middle.a));
}

// --- glass --------------------------------------------------------------------------------------------------------

// A region of the target, unpremultiplied: what Velvet's glass and backdrop read (straight RGBA8). Past the source, transparent.
struct Grab { int2 offset; int2 size; uint straight_out; uint pad0; uint pad1; uint pad2; };

fragment float4 grab(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant Grab &g [[buffer(0)]])
{
    int2 p = pixel(in) + g.offset;
    if (p.x < 0 || p.y < 0 || p.x >= g.size.x || p.y >= g.size.y) {
        return float4(0.0);
    }
    uint4 c = byte4(src.read(uint2(p)));
    if (g.straight_out != 0) {
        c = uint4(straight(c.r, c.a), straight(c.g, c.a), straight(c.b, c.a), c.a);
    }
    return unbyte(c);
}

// PHPLandGlass::down(): (4 × the 2 × 2 centre + the 4 × 4 block + 16) >> 5, rows and columns held to the region.
struct Down { int4 region; };

fragment float4 kawase_down(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant Down &d [[buffer(0)]])
{
    int2 p = pixel(in);
    int right = d.region.x + d.region.z - 1;
    int bottom = d.region.y + d.region.w - 1;
    uint4 all = uint4(0);
    uint4 centre = uint4(0);
    for (int b = 0; b < 4; b++) {
        int y = clamp(d.region.y + 2 * p.y - 1 + b, d.region.y, bottom);
        for (int a = 0; a < 4; a++) {
            int x = clamp(d.region.x + 2 * p.x - 1 + a, d.region.x, right);
            uint4 v = byte4(src.read(uint2(x, y)));
            all += v;
            if ((a == 1 || a == 2) && (b == 1 || b == 2)) {
                centre += v;
            }
        }
    }
    return unbyte((4 * centre + all + 16) >> 5);
}

// PHPLandGlass::up(): the 8-tap tent in quarter pixels, (sum + 96) / 192, edges held.
static uint4 tap(texture2d<float> src, int2 size, int x4, int y4)
{
    int ix = x4 >> 2;
    int iy = y4 >> 2;
    int fx = x4 & 3;
    int fy = y4 & 3;
    int x0 = clamp(ix, 0, size.x - 1);
    int x1 = clamp(ix + 1, 0, size.x - 1);
    int y0 = clamp(iy, 0, size.y - 1);
    int y1 = clamp(iy + 1, 0, size.y - 1);
    uint4 a = byte4(src.read(uint2(x0, y0)));
    uint4 b = byte4(src.read(uint2(x1, y0)));
    uint4 c = byte4(src.read(uint2(x0, y1)));
    uint4 d = byte4(src.read(uint2(x1, y1)));
    return uint(4 - fy) * (uint(4 - fx) * a + uint(fx) * b) + uint(fy) * (uint(4 - fx) * c + uint(fx) * d);
}

fragment float4 kawase_up(FOut in [[stage_in]], texture2d<float> src [[texture(0)]], constant int2 &size [[buffer(0)]])
{
    int2 p = pixel(in);
    int x4 = 2 * p.x - 1;
    int y4 = 2 * p.y - 1;
    uint4 sum = tap(src, size, x4 - 4, y4) + tap(src, size, x4 + 4, y4) + tap(src, size, x4, y4 - 4) + tap(src, size, x4, y4 + 4)
        + 2 * (tap(src, size, x4 - 2, y4 - 2) + tap(src, size, x4 + 2, y4 - 2) + tap(src, size, x4 - 2, y4 + 2) + tap(src, size, x4 + 2, y4 + 2));
    return unbyte((sum + 96) / 192);
}
