# Agent guidelines — jovian/venusian-metal

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/)
(excluded from the Composer dist via `.gitattributes` `export-ignore`).
Before changing code or advising on this package: read
[`.okf/index.md`](.okf/index.md) first, open only the concepts the task
needs, prefer `status: stable` over `draft`. When you learn something
durable, update the affected concept(s) and append [`.okf/log.md`](.okf/log.md);
new or changed concepts stay `status: draft` until a human verifies them.

Do **not** create `.okf` folders under `src/` — knowledge for this package
lives at the package root only.

## Where this package sits

`ext-metal` (1:1 binding, zero opinion) → `jovian/metal` (enums + typed
projection) → **`jovian/venusian-metal`** (composition) → `venusian/surface`
(cross-platform abstraction).

**This is the layer where opinion is allowed.** `jovian/metal` may only
project one extension call per method, and Surface may not know Metal
exists, so everything that bundles Metal calls into a frame, a pipeline,
or a pointer seam belongs here.

Never import `Jovian\Bindings\AppKit\*` or `Jovian\Venusian\AppKit\*`.
Never depend on `jovian/appkit` or `jovian/venusian-appkit`. The only
crossing is raw pointer bits: this package answers
`Bridge::pointerOf($layer->handle)`; the AppKit side adopts.

Never build a cross-platform abstraction here — that is Surface's job.

## Package rules (quick) — 0.8.x

- Composer: `jovian/venusian-metal` **0.8.0**. PHP `^8.4|^8.5|^8.6`. macOS
  only. Requires `jovian/metal`, `surface/contracts`, `surface/drawing`,
  `venusian-voyager/contracts`, `venusian-voyager/nuts-and-bolts`.
- Namespace root is `Jovian\Venusian\Metal\` at `src/`.
- **The provider binds `gpu.metal`.** That container alias is the entire
  seam to Surface; installing this package is the whole of what makes the
  Metal engine available. Do not rename it.
- **Implement Surface's drawing contracts, do not re-declare policy.**
  `Executor` and `GPUEngineDriver` own the intersection. `Contracts\MetalDrawing`
  is the bespoke half a sketch reaches beside the Painter.
- **Exceptions subclass `Surface\Contracts\Drawing\DrawingException`** so a
  sketch catches one type without naming Metal.
- **Object parameters into `jovian/metal` are `int` handles.** Pass
  `$obj->handle`; only returns come back boxed.
- **Never chain `->handle` off a temp.** PHP frees a method-call temp the
  moment `->handle` is read — the box's destructor releases the registry
  entry, and the ext resolves nil. Hold the box in a local through every
  call that uses its handle (and through any call using pointer bits
  derived from it).
- **Per-frame boxes live on one `$frame` object** dropped at `endFrame()`.
  The `CAMetalLayer` box lives for the executor's life. Textures live until
  `releaseTexture()` / `release()`.
- **`setBytes` is unbound.** Every constant is staged through an `MTLBuffer`
  made with `newBufferWithBytesLengthOptions`.
- **`NS_OPTIONS` values stay `int`** because PHP enums cannot be OR'd. Build
  them from `SomeEnum::CASE->value | ...`.
- Enums are int- or string-backed with FULLY UPPERCASE cases. **No class
  constants anywhere.** Prefer `is_null($var)` over `$var === null`.

## Verification

Needs a Mac with `ext-metal` loaded. Pure-logic code should be covered by
Pest with no extension present; anything that touches Metal is proven by
running it, not by a skipped test reporting success.

```bash
vendor/bin/pest
php -l src/MetalEngine.php
composer validate
```

If PHP cannot see the extension, export the Herd scan dir first:

```bash
export HERD_PHP_84_INI_SCAN_DIR=$(zsh -ic 'echo $HERD_PHP_84_INI_SCAN_DIR')
```
