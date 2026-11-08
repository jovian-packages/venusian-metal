# Log

## 2026-11-08

* The vertex buffer is made for each frame (ext-metal's `MTLBuffer` has no write method, so a ring of reused buffers has no way to be filled); the pooled source textures stay.

## 2026-11-08

* G3 reuse (Surface drawing-path spec): image/ramp source textures are pooled by size and freed to the next frame once their command buffer completed. Parity: all 63 Surface scenes pass; `tests/MetalDeviceTest.php` draws six frames through the pool, with a growth between. Data tab, scale 2: the device's draw() 3.0 → 2.9 ms a frame, Lists 1.1–1.6 → 0.9 ms; of a frame of 20–45 ms CPU, nearly all of it is Silk and Lowering.

## 2026-11-07

* Glass (Surface drawing-path spec, G4): `MetalDevice` is `DrawsGlass`. `GLASS_BACKDROP` copies the region under a list's panes out of the target (`glass_capture`) and takes it down and up the dual-filter pyramid (`glass_down`, `glass_up` in `filter.metal`: integer texel reads, the bytes of ext-fb's levels) in small passes of their own size, pooled pass images, in the frame's command buffer; `GLASS` is one draw a pane in the normal pass (`glass` in `venusian.metal`: Velvet's per-pixel steps at the pixel centre, edges by the samples). Against Velvet+ext on a glass scene: the same but at anti-aliased edges (0.47 % of channels > 2). Glass tab, scale 2: 4.4 ms a frame (CPU per frame, as the bench counts). shortcut: the pyramid is rebuilt each frame the panes are drawn.
* Filter passes FIELD (an image from the pixel position: a rounded box's windowed distance and direction, its inside mask, a ramp) and TABLE (a 256-entry lookup per straight channel), and a displacement whose map is an earlier pass's result (`params[5]`). Parity: the new scenes `layer fields`, `layer tables` and `layer displacement by a made map` pass on this engine (`venusian/surface/scripts/gpu-parity.php`).

* Layers: the TARGET, BACKDROP, FILTER and LAYER_SOURCE operations. A layer target is a private resolved texture, a four-sample one at four samples and a stencil, made on first use and kept until the target changes; a layer's drawing is a render encoder of its own on it (cleared on entry; an enclosing target's encoder is begun again with its colour loaded), and BACKDROP and the end of a FILTER copy a region with a blit encoder between encoders. A filter chain is Surface's `FilterPlan`: one render encoder per plan pass with `filter_place` and `filter_paint` of `resources/shaders/filter.metal` (compiled on the first chain, a library of its own), over the region, into a texture of its own, four textures (inputs, the pass's numbers as an RGBA8 texture of float32 bytes, a displacement map) and 64 bytes of fragment bytes. All 57 parity tests pass on Homebrew PHP 8.4 (Mac): the layer, filter, backdrop, noise and displacement scenes are byte for byte Velvet's; the rounded-layer scene differs only at the rounded edge. `PacingTest` "reports the newest present the display showed" fails with or without this change (it needs a display). Layers on an HDR target throw.

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `dev-main` → `0.11.x-dev`, its extensions, `venusian-surface/*`, `venusian-voyager/*` and the jovian siblings at `^0.11.0`, PHP `~8.4.27||^8.5.12`, homepage `0.11.x`. No API change. 83 passed on Homebrew PHP 8.4 NTS and ZTS. macOS only.

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-05

* venusian-metal 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).

## 2026-11-07 — MetalDevice: panic-suspect review

- Pass images were full-target size and one per pass; a 25-pass glass chain held ~230 MB at 1800x1280 until its command buffer ended (several glass layers a frame: GB). Now images are recycled inside a chain once no later pass reads them (FilterPlan::lastReads), the free pool is capped by bytes (128 MiB, the size used longest ago goes first).
- draw() is exception-safe: the open encoder is ended and the half-built command buffer committed (abandon()) before the exception leaves; filter()/begin() end a pass encoder they opened if a call between throws.
- __destruct() drains the last command buffer.
- The 1x1 blank texture is made once.
