---
type: Decision
title: The Metal pointer seam
description: >-
  venusian-metal mints the CAMetalLayer and answers raw pointer bits;
  venusian-appkit adopts them. Neither side imports the other. Each side
  releases its own retain.
resource: src/MetalEngine.php
tags: [metal, appkit, pointer-seam, layer, adopt]
status: draft
generated:
  by: cursor-grok-4.6/cursor
  at: "2026-09-14T02:00:00Z"
sources:
  - id: runtime
    resource: ../metal/.okf/runtime.md
    title: jovian/metal runtime — pointerOf / adopt
  - id: spec
    resource: ../../venusian/surface/docs/superpowers/specs/2026-09-13-gpu-drawing-design.md
    title: GPU drawing slice 1 design
---

# Overview

Raw pointer bits are the only currency between Metal and AppKit. Registry
handles from one extension are meaningless in the other.[^runtime]

# Who does what

| Side | Mints | Hands over | Releases |
|---|---|---|---|
| `jovian/venusian-metal` | `CAMetalLayer::init()`, device, `BGRA8_UNORM`, `framebufferOnly=false`, `drawableSize = host × scale` | `Bridge::pointerOf($layer->handle)` and `'CAMetalLayer'` on `GPUAttachment` | The layer box on `MetalExecutor::release()` |
| `jovian/venusian-appkit` | The host `NSView` | Nothing Metal-ward except the `GPUHost` size/scale | The adopted box on `destroyNative()` |

This package never imports `Jovian\Bindings\AppKit\*` or
`Jovian\Venusian\AppKit\*`. No `class_exists`, no `method_exists`. AppKit
adopts; Metal does not call `adopt`.[^spec]

# Hold the box

```php
$layer = CAMetalLayer::init();
$layer->setDevice($device->handle);
$pointer = Bridge::pointerOf($layer->handle);   // layer still live
```

`CAMetalLayer::init()->handle` is already released by the time
`pointerOf` would run. The executor holds the layer for its whole life so
the bits stay valid while AppKit's twin holds the adopted retain.

Each side owns one retain. Neither may skip its own on the assumption the
other did it.

# What attach answers

`GPUAttachment(executor, layer_pointer > 0, layer_class === 'CAMetalLayer')`.
`drawableSize()` is pixels: host points × backing scale. A 320×240 host at
scale 2 is `[640, 480]`.

`MetalEngine::surfaceKind()` answers `SurfaceKind::LAYER`. Slice 2 added
the kind so a window engine mints a layer host or a GL surface *before*
`attach()`. This package still mints the layer itself; the host's
`GPUHost->gl` stays null.

[^runtime]: jovian/metal runtime — pointerOf / adopt
[^spec]: GPU drawing slice 1 design
