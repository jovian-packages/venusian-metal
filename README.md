# jovian/venusian-metal

Metal composition for Venusian Surface GPU drawing.

`jovian/metal` projects `ext-metal` one call at a time and adds no behaviour.
This package is where a frame, a pipeline, and the pointer seam live. Surface
talks to it only through `gpu.metal`.

```
ext-metal  →  jovian/metal  →  venusian-metal  →  Surface
  1:1           typed            composition       cross-platform
```

It never imports AppKit. The only crossing is raw pointer bits: `attach()`
answers `Bridge::pointerOf($layer->handle)` and `'CAMetalLayer'`; the AppKit
window twin adopts that pointer on its side.

## Install

```bash
composer require jovian/venusian-metal
```

Requires macOS, PHP `^8.4|^8.5|^8.6`, `jovian/metal`, and the Surface drawing
contracts. Installing the package registers `VenusianMetalServiceProvider`,
which binds `MetalEngine` as the `gpu.metal` singleton.

## The shape of it

```php
$attachment = $engine->attach(new GPUHost($viewBits, 640, 480, 2.0));
$attachment->layer_class;     // 'CAMetalLayer'
$attachment->layer_pointer;   // raw bits for AppKit to adopt

$gpu = $attachment->executor; // Executor + Contracts\MetalDrawing
$gpu->metal()->device();      // bespoke — only on the concrete
```

A frame is `nextDrawable` → render encoder held open → draws staged through
`MTLBuffer`s → `endEncoding` / `presentDrawable` / `commit`. Every per-frame
box is dropped at `endFrame()` so `jovian/metal`'s destructors release them.

`setBytes` is unbound in ext-metal 0.8; every constant (the projection matrix,
the textured flag) goes through a buffer. Blending is a bare bool and there is
no depth attachment, so `capabilities()` answers `blending=false`,
`depth=false`, `instancing=true`, `readback=true`.

`readPixels()` is mid-frame: blit the drawable into a shared buffer, swizzle
BGRA→RGBA, then reopen the render encoder with load action `LOAD`.

## Ownership

PHP refcount owns Metal boxes. Never chain `->handle` off a temp. Hold the
`CAMetalLayer` for the executor's life; hold per-frame boxes on one `$frame`
object; hold textures until they are released.
