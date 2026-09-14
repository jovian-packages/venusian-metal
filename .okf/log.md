# Update Log

## 2026-09-13
* **Update**: `MetalEngine::surfaceKind()` answers `SurfaceKind::LAYER` — slice 2 added the kind to `GPUEngineDriver` so a window engine knows whether to mint a layer host or a GL surface before `attach()`. No behaviour change on this side.

## 2026-09-13

Built as Phase B of the Surface GPU drawing program. `jovian/metal` is the
projection; this package is the composition.

* **Scaffold**: `jovian/venusian-metal`, namespace `Jovian\Venusian\Metal\`,
  PHP `^8.4|^8.5|^8.6`. Path repos for `jovian/metal` and `venusian/surface`.
  `VenusianMetalServiceProvider` binds `MetalEngine` as `gpu.metal`.
* **Context**: one `MTLDevice` / queue / compiled `painter.metal` library /
  `BGRA8_UNORM` pipeline (blending off) / LINEAR CLAMP_TO_EDGE sampler /
  two flag buffers / 1×1 white placeholder, cached on `MetalEngine`.
* **Executor**: frame lifecycle, buffer-staged draws, indexed uint16
  (instances ignored), RGBA8 textures, mid-frame blit `readPixels` with
  BGRA→RGBA swizzle and `LOAD` reopen. Capabilities: blending false, depth
  false, instancing true, readback true.
* **Seam**: `attach()` answers `Bridge::pointerOf($layer->handle)` and
  `'CAMetalLayer'`. No AppKit imports. Layer `framebufferOnly = false`.
* **Tests**: 9 Pest tests, 36 assertions. Pure suite (shader, capabilities,
  Transform pack, engine name, `surfaceKind() === LAYER`) runs without the
  extension. Ext-gated context / texture / attach / outside-frame
  `readPixels` green on a Mac with `ext-metal` loaded.
