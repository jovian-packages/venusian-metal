---
okf_version: "0.2"
---

# jovian/venusian-metal — knowledge bundle

Metal composition for Venusian Surface GPU drawing. `jovian/metal` projects
`ext-metal` one call at a time; this package owns the frame, the pipeline,
and the pointer seam. Surface talks to it only through `gpu.metal`.

Read this index first, then open only the concepts the task needs. Every
concept here is `status: draft` until a human verifies it.

# Concepts

* [executor.md](/executor.md) - frame lifecycle, buffer staging, mid-frame
  blit readback, and the boxes that outlive a frame
* [seam.md](/seam.md) - raw pointer bits are the only crossing; who mints
  the `CAMetalLayer`, who adopts it, who releases which retain

# Related bundles

* [jovian/metal](../../metal/.okf/index.md) - the typed projection this
  package composes
* [venusian/surface](../../../venusian/surface/.okf/index.md) - the
  engine-free contracts and Painter this package implements
