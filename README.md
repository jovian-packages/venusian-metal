# jovian/venusian-metal

The `metal` rendering engine for Venusian Surface. Metal through ext-metal.

## Where it sits

```
DrawingManager → GpuRenderingEngine (Surface) → MetalDevice (this package) → ext-metal → Metal
```

The engine's framebuffer is a `MetalFramebuffer`, a Surface `GLFramebuffer`.

## Requirements

- macOS on Apple silicon. Engine blends by reading the target in its fragment functions; an Intel GPU refuses the pipelines with a `DrawingException` naming it.
- PHP 8.4, ext-metal ^0.10, `venusian-surface/drawing` ^0.10.

## Install

```
composer require jovian/venusian-metal
```

Provider discovered through `extra.venusian.providers`. It registers `metal` on `app('drawing')`.

## Usage

Offscreen. Run as a script; printed pixels are its output.

```php
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\NutsAndBolts\Color;

$engine = app('drawing')->renderer('metal', ['width' => 320, 'height' => 240]);
$engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 60, 40, Color::rgb(255, 128, 0)));
$rgba = $engine->framebuffer()->flush(FormatSpec::rgba8());

echo bin2hex(substr($rgba, (120 * 320 + 160) * 4, 4)), "\n";   // ff8000ff  ellipse centre
echo bin2hex(substr($rgba, 0, 4)), "\n";                       // 101820ff  corner
```

On a display. The display sends what changed, in its own format.

```php
$display = app('displays')->panel('st7796');
$engine = app('drawing')->renderer('metal', ['output' => $display]);
```

On a canvas.

```php
$engine = app('drawing')->renderer('metal', ['output' => $canvas]);
```

Needs a toolkit that lends a Metal layer (venusian-appkit, slice 8b). Until then the refusal reads `This window cannot host 'metal': it lends … It can host: …`.

## What it draws

| Operation | Draws |
|---|---|
| CLEAR | the colour over the whole target, no blend |
| SCISSOR | clip rectangle for what follows |
| SOLID | flat-colour triangles, no blend |
| STENCIL_FILL + COVER | a path: triangle lists into the stencil (winding or even-odd), then a cover quad blended where the stencil is non-zero and zeroed |
| ELLIPSE, RING | per-sample inside tests of the outer (and inner) ellipse |
| UPLOAD + IMAGE | a framebuffer as a texture; sampled at the inverse placement of the pixel centre, nearest or Velvet's 256ths bilinear |
| RECTS | rectangles blended at the colour's alpha |

Edges: four samples resolved (anti-aliased) or one (hard). Blending: Velvet's integer source-over per sample, so interior pixels match Velvet byte for byte (the parity suite's rule).

## Reference

- `MetalDevice`: `__construct(?MTLDevice)`, `metal()`, `target()`, `draw()`, `surfaces()`, `handles()`, `adopt()`, `present()`, `release()`, `read()`, `write()`, `finish()`.
- `MetalFramebuffer`: `texture()`, the `GLFramebuffer` methods.
- `VenusianMetalServiceProvider::extend(DrawingManager)`.

## Behaviour

- Readback waits for the frame.
- A pixel call between frames costs a readback and an upload and, with anti-aliased edges, a restore pass.
- `present()` skips the copy while the previous one is in flight, answering false.
- The engine's own framebuffer as an image source shows the frame before the current one.
- A re-made target is a new `MetalFramebuffer`; the old one keeps its own texture.
- A layer with no view hands out drawables; the copy is proven there, the window cells are slice 8b.

## Measured

Apple M1 Pro, 320 × 240, 100 ellipses and a line of text, anti-aliased: whole redraw 1.25 ms a frame, partial frames 4.57 ms, peak memory 4.0 MB. (Velvet, both extensions, same scene shape: 6.8 ms.)

## Testing

Real GPU only; nothing skipped. `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout; set `SURFACE_TESTS` to a Surface checkout's `tests` directory to move it. Install with a temporary path repository to `<surface>/src/Surface/*`, run `php -d memory_limit=128M vendor/bin/pest` on `php84` and ZTS. Runbook: [.okf/runbooks/testing.md](.okf/runbooks/testing.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
