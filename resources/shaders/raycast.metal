// Raycasting: Velvet's grid walk (PHPLandMarcher, Amanatides and Woo), one ray a pixel of a hits pass, then a view
// drawn from those hits. Both passes use 'cover' (filters.metal): one triangle over the viewport.
//
// The GPU has no doubles, so a ray does not start from its absolute position: it starts from its cell (an integer)
// and where in that cell it is (a fraction), worked out by the CPU in double. A boundary's distance is worked out
// from how many boundaries were crossed (one rounding each), not added up step by step. So float32 only ever holds
// small numbers, and a hit's cell, wall and face are the walk's own integers.

// The eye's cell and where in it; the centre ray's direction and the camera plane (zero for one ray); how many rays;
// how many a pair of rows of the hits texture holds; bit 0: there is a reach, bit 1: the eye is inside the grid,
// bit 2: each ray's direction is its own, in the directions texture (ray i at i % per_row, i / per_row);
// the reach; the grid's size.
struct Cast { int2 cell; float2 frac; float2 dir; float2 plane; uint columns; uint per_row; uint flags; float reach; int2 grid; };

struct Walk { bool hit; float distance; float along; int2 cell; uint wall; uint face; };

static Walk march(texture2d<uint> grid, int2 size, int2 cell, float2 f, float2 d, bool reaches, float reach)
{
    Walk w;
    w.hit = false; w.distance = 0.0; w.along = 0.0; w.cell = int2(0); w.wall = 0; w.face = 0;

    bool move_x = d.x != 0.0;
    bool move_y = d.y != 0.0;
    if (!move_x && !move_y) {
        return w;
    }

    // how far along the ray one whole cell is, in x and in y
    float delta_x = move_x ? abs(1.0 / d.x) : 0.0;
    float delta_y = move_y ? abs(1.0 / d.y) : 0.0;
    int step_x = d.x < 0.0 ? -1 : 1;
    int step_y = d.y < 0.0 ? -1 : 1;

    // how far along the ray the first boundary is, in x and in y
    float first_x = move_x ? (d.x < 0.0 ? f.x : 1.0 - f.x) * delta_x : 0.0;
    float first_y = move_y ? (d.y < 0.0 ? f.y : 1.0 - f.y) * delta_y : 0.0;

    // a ray inside the grid crosses at most width + height boundaries before it leaves
    int crossed_x = 0;
    int crossed_y = 0;
    int limit = size.x + size.y + 2;
    for (int i = 0; i < limit; i++) {
        float next_x = fma(float(crossed_x), delta_x, first_x);
        float next_y = fma(float(crossed_y), delta_y, first_y);

        // a tie steps in y, as Velvet's does
        bool across_x = move_x && (!move_y || next_x < next_y);
        float distance;
        if (across_x) {
            distance = next_x;
            crossed_x++;
            cell.x += step_x;
        } else {
            distance = next_y;
            crossed_y++;
            cell.y += step_y;
        }

        if ((reaches && distance > reach) || cell.x < 0 || cell.y < 0 || cell.x >= size.x || cell.y >= size.y) {
            return w;
        }

        uint wall = grid.read(uint2(cell)).r;
        if (wall == 0) {
            continue;
        }

        // where the ray meets the face, from the eye's cell: its fraction is the same as from the grid's origin
        float where = across_x ? f.y + distance * d.y : f.x + distance * d.x;
        w.hit = true;
        w.distance = distance;
        w.along = where - floor(where);
        w.cell = cell;
        w.wall = wall;
        w.face = across_x ? (step_x > 0 ? 0u : 1u) : (step_y > 0 ? 2u : 3u);

        return w;
    }

    return w;
}

// One ray a pixel: ray i is at x = i % per_row, rows 2(i / per_row) and the next. The first row holds distance, along,
// cell x, cell y; the second wall, face. A miss is all zero (wall 0).
fragment float4 raycast_hits(FOut in [[stage_in]], texture2d<uint> grid [[texture(0)]], texture2d<float> directions [[texture(1)]],
                             constant Cast &c [[buffer(0)]])
{
    uint2 p = uint2(in.position.xy);
    uint index = (p.y >> 1) * c.per_row + p.x;
    if (index >= c.columns || (c.flags & 2u) == 0u) {
        return float4(0.0);
    }

    float2 d;
    if ((c.flags & 4u) != 0u) {
        d = directions.read(uint2(index % c.per_row, index / c.per_row)).xy;
    } else {
        float across = float(2u * index + 1u) / float(c.columns) - 1.0;
        d = c.dir + c.plane * across;
    }
    Walk w = march(grid, c.grid, c.cell, c.frac, d, (c.flags & 1u) != 0u, c.reach);
    if (!w.hit) {
        return float4(0.0);
    }

    return (p.y & 1u) == 0u
        ? float4(w.distance, w.along, float(w.cell.x), float(w.cell.y))
        : float4(float(w.wall), float(w.face), 0.0, 0.0);
}

// Where the view is on the surface and its size; the ceiling and floor 0xRRGGBBAA; the shade; rays a pair of rows of
// the hits texture; 1 to write straight colour (a view read out as bytes), 0 premultiplied (painted on a framebuffer).
struct View { int2 origin; int2 size; uint ceiling; uint floor; uint shade; uint per_row; uint straight; uint pad0; uint pad1; uint pad2; };

// Velvet's tallest wall line, before it is cut to the view.
constant float MAX_LINE = 16777216.0;

static uint dimmed(uint rgba, uint dim)
{
    uint r = ((rgba >> 24) & 0xFFu) * dim / 255u;
    uint g = ((rgba >> 16) & 0xFFu) * dim / 255u;
    uint b = ((rgba >> 8) & 0xFFu) * dim / 255u;

    return (r << 24) | (g << 16) | (b << 8) | (rgba & 0xFFu);
}

// What a column's hit shows at a pixel, as PHPLandMarcher::picture() works it out, in integers where it is. Walls:
// one texel a wall id, x the kind (0 none, 1 a colour, 2 a texture), y the colour, z where its texture is in the
// atlas (x | y << 16), w its size (width | height << 16). The atlas holds textures as their straight bytes.
fragment float4 raycast_view(FOut in [[stage_in]], texture2d<float> hits [[texture(0)]], texture2d<uint> walls [[texture(1)]],
                             texture2d<uint> atlas [[texture(2)]], constant View &v [[buffer(0)]])
{
    int2 p = int2(in.position.xy) - v.origin;
    int column = p.x;
    int row = p.y;
    int height = v.size.y;
    int horizon = height / 2;

    uint rgba = row < horizon ? v.ceiling : v.floor;

    uint2 at = uint2(uint(column) % v.per_row, (uint(column) / v.per_row) * 2u);
    float4 first = hits.read(at);
    float4 second = hits.read(at + uint2(0, 1));
    uint wall = uint(second.x);

    if (wall != 0u) {
        float distance = first.x;
        float tall = distance > 0.0 ? float(height) / distance : MAX_LINE;
        if (!(tall < MAX_LINE)) {
            tall = MAX_LINE;
        }
        int line = int(tall);
        int start = horizon - line / 2;
        int from = max(start, 0);
        int to = min(start + line, height);

        if (row >= from && row < to) {
            uint face = uint(second.y);
            uint dim = face >= 2u ? v.shade : 255u;
            uint4 entry = walls.read(uint2(wall, 0));

            if (entry.x == 1u) {
                rgba = dimmed(entry.y, dim);
            } else if (entry.x == 2u) {
                int texture_width = int(entry.w & 0xFFFFu);
                int texture_height = int(entry.w >> 16);
                int texel_x = int(first.y * float(texture_width));
                if (texel_x > texture_width - 1) {
                    texel_x = texture_width - 1;
                }
                if (face == 1u || face == 2u) {
                    texel_x = texture_width - 1 - texel_x;
                }
                // in 64 bits: (row - start) runs to the wall's whole line, and a texture is up to 65535 tall
                int texel_y = int((long(row - start) * long(texture_height)) / long(line));
                uint4 texel = atlas.read(uint2(entry.z & 0xFFFFu, entry.z >> 16) + uint2(texel_x, texel_y));
                rgba = dimmed((texel.r << 24) | (texel.g << 16) | (texel.b << 8) | texel.a, dim);
            }
        }
    }

    float4 c = float4(float((rgba >> 24) & 0xFFu), float((rgba >> 16) & 0xFFu), float((rgba >> 8) & 0xFFu), float(rgba & 0xFFu)) / 255.0;

    return v.straight != 0u ? c : float4(c.rgb * c.a, c.a);
}
