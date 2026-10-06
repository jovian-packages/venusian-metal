---
type: Module
title: Engine
description: MetalDevice - persistent target, one pass per DrawList, six pipelines, programmable Velvet blend, restore after upload, present into a layer.
resource: src/
tags: [metal, gpu, macos, drawing]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-10-05T12:00:00Z }
sources:
  - id: device
    resource: src/MetalDevice.php
    title: MetalDevice
  - id: shaders
    resource: resources/shaders/venusian.metal
    title: venusian.metal
---

# Overview

`MetalDevice implements Surface\Drawing\Gpu\GpuDevice`. `GpuRenderingEngine` lowers drawing calls to a `DrawList`; `draw()` encodes it. `MetalFramebuffer extends GLFramebuffer` over the shared texture.[^device]

# Textures

| Texture | Format | Samples | Storage | Role |
|---|---|---|---|---|
| resolved | RGBA8 | 1 | shared | read back, uploaded to, resolved into, presented |
| multisampled | RGBA8 | 4 | private | drawn into; absent with 1 sample |
| stencil | STENCIL8 | 1 or 4 | private | cleared every pass, left at zero by cover |

`target()` accepts 1 or 4 samples, minimum 1 × 1, and clears to transparent black.

# Pass

Colour: load, store (1 sample) or store-and-resolve into `resolved` (4). Stencil: clear, don't care. One pass per `DrawList`; a flat vertex buffer holds the list's vertices plus one whole-target quad (CLEAR, restore). Uploaded image sources become shared RGBA8 textures for the pass.

# Pipelines

Blending off in hardware; the fragment function reads `[[color(0)]]` and writes Velvet's integer source-over `(s·a + d·(255 − a) + 127) / 255`, alpha `(255·a + dₐ·(255 − a) + 127) / 255`. Interpolated position is `sample_no_perspective`, so shading runs per sample.[^shaders]

| Fragment | Used by |
|---|---|
| `solid` | CLEAR, SOLID (no blend) |
| `mark` | STENCIL_FILL (colour write mask none) |
| `fill` | COVER, RECTS |
| `oval` | ELLIPSE, RING |
| `image` | IMAGE |
| `copy` | restore into the multisampled texture |

`show` is the seventh function; its pipeline is BGRA8, one sample, for present only. Vertex function `place` maps target pixels to clip space.

Stencil states: `plain` (untouched), `winding` (front increment-wrap, back decrement-wrap; NON_ZERO), `invert` (EVEN_ODD), `cover` (not-equal to reference 0, pass op zero).

# Uniforms

| Struct | Bytes | `pack()` |
|---|---|---|
| `Frame { float2 size; }` | 8 | `g2` |
| `Paint { uint4 rgba; }` | 16 | `V4` |
| `Oval { uint4 rgba; float4 shape; float4 band; }` | 48 | `V4` + `g8` (cx, cy, rx, ry, stroke, hole, 0, 0) |
| `Picture { float4 abcd; float4 efwh; uint4 options; }` | 48 | `g8` (inverse a..f, w, h) + `V4` (opacity, linear, 0, 0) |

Vertex buffer 0 = float2 points; vertex buffer 1 = `Frame`; fragment buffer 0 = `Paint`, `Oval` or `Picture`; fragment texture 0 = image source or target.

# Restore

An upload into `resolved` with 4 samples sets `stale`. The next `draw()` first runs one pass that draws `resolved` into the multisampled texture through `copy`, so the samples carry the upload.

# Present and finish

`adopt()` sets the layer's device, BGRA8, framebuffer-only, display sync off. `present()` returns false when the surface is released, when the previous present's command buffer has not completed, or when no drawable comes; otherwise it draws `resolved` into the drawable through `show` and presents. It sets the layer's drawable size to the target's. `finish()` waits for the last command buffer and throws `DrawingException` when it failed on the GPU; readback and upload call it first. `release()` finishes, then drops every texture, pipeline and the layer.

[^device]: src/MetalDevice.php
[^shaders]: resources/shaders/venusian.metal
