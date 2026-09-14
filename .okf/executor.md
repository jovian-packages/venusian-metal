---
type: Component
title: MetalExecutor — frame, staging, readback
description: >-
  How a Metal frame is opened, how every constant is staged through an
  MTLBuffer, and how mid-frame readPixels blits the drawable then reopens
  the render encoder with LOAD.
resource: src/MetalExecutor.php
tags: [metal, executor, frame, readback, staging]
status: draft
generated:
  by: cursor-grok-4.6/cursor
  at: "2026-09-14T00:40:00Z"
sources:
  - id: spec
    resource: ../../venusian/surface/docs/superpowers/specs/2026-09-13-gpu-drawing-design.md
    title: GPU drawing slice 1 design
  - id: contracts
    resource: ../../venusian/surface/src/Surface/Contracts/Drawing
    title: Phase A drawing contracts
  - id: metal-runtime
    resource: ../metal/.okf/runtime.md
    title: jovian/metal runtime and pointer seam
---

# Overview

`MetalExecutor` implements `Surface\Contracts\Drawing\Executor` and
`Contracts\MetalDrawing`. One instance owns one `CAMetalLayer` for its
whole life. Per-frame boxes live on a `MetalFrame` dropped at
`endFrame()`.[^spec]

# Frame lifecycle

1. `beginFrame($clear)` — `nextDrawable()` nil answers false. Otherwise a
   render pass clears color0 (`BGRA8_UNORM`) to `$clear`, a command buffer
   and render encoder are held open, and the painter pipeline plus a
   full-drawable viewport are bound.
2. `draw` / `drawIndexed` / `scissor` / `viewport` / `readPixels` — legal
   only while that encoder is open. Outside a frame they throw
   `MetalDrawingException`.
3. `endFrame()` — `endEncoding`, `presentDrawable`, `commit`, drop
   `$frame`. The layer, the `MetalContext`, and held textures stay.

`release()` is terminal and idempotent: drop textures, the open frame
(ended, not presented), and the layer box.

# Staging

`setBytes` is unbound in ext-metal 0.8. Every constant is an `MTLBuffer`
made with `newBufferWithBytesLengthOptions(...,
MTLResourceOptions::MTL_RESOURCE_CPU_CACHE_MODE_DEFAULT_CACHE->value)` —
that 0 is also shared storage.[^metal-runtime]

- Vertex bytes → buffer 0
- `Transform::toPacked()` (64 bytes, column-major) → buffer 1
- Textured flag (`pack('V', 0|1)`) → fragment buffer 0
- Texture or the context's 1×1 white placeholder + LINEAR / CLAMP_TO_EDGE
  sampler → fragment texture/sampler 0

Vertex layout is the Phase A contract: `x y z r g b a u v`, nine floats,
`pack('g9')`. The shader reads `float4(x, y, 0, 1)` — z is padding for
this slice. The textured flag is **not** a vertex attribute.[^contracts]

`drawIndexed` uses `MTLIndexType::U_INT16`. Instances are ignored on the
indexed path; `capabilities()->instancing` is about the non-indexed call.

# Readback

`readPixels()` is mid-frame. The layer is `framebufferOnly = false`. Path:

1. `endEncoding` on the open render encoder
2. `MTLBlitCommandEncoder` copies the drawable texture into a shared
   `MTLBuffer`
3. `endEncoding`, `commit`, `waitUntilCompleted`
4. `contentsBytes`, then swizzle BGRA→RGBA
5. New command buffer, new pass on the same drawable with load action
   `LOAD`, pipeline and viewport rebound

Outside a frame → `MetalDrawingException`. `readback` capability is true.

# Capabilities

`blending=false`, `depth=false`, `instancing=true`, `readback=true`,
`max_texture_size=16384`. Pipeline `setBlendingEnabled(false)`. No depth
attachment. Transform is projection only; Metal clip space is y-up, so
this package does not flip.

# Ownership

PHP refcount owns Metal boxes. Never chain `->handle` off a temp. Hold
boxes in locals through every call that uses their handle. Per-frame boxes
(drawable, texture, pass, attachments, command buffer, encoder, per-draw
buffers, blit/readback) live on `$frame`.

[^spec]: GPU drawing slice 1 design
[^contracts]: Phase A drawing contracts
[^metal-runtime]: jovian/metal runtime and pointer seam
