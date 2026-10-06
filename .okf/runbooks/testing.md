---
type: Runbook
title: Testing
description: Real-GPU Pest suite with Surface's GpuParity; temporary path repository; php84 and zhp.
resource: tests/
tags: [metal, pest]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-10-05T12:00:00Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Steps

* The suite runs the real GPU only. `tests/Pest.php` fails with a message on a Mac without ext-metal or a Metal device. Nothing is skipped.
* `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout (`dev/venusian/surface/tests`); `SURFACE_TESTS` overrides the directory.
* Surface's 0.10 splits are not on Packagist: add a path repository to `<surface>/src/Surface/*` for the run (`composer config repositories.surface '{"type":"path","url":"…/src/Surface/*","options":{"symlink":true}}'`), `composer update`.
* Run `php84 -d memory_limit=128M vendor/bin/pest`, then the same on ZTS (`zhp`). Both have ext-metal.
* After: remove `vendor/`, `composer.lock` and the repository entry; `git diff composer.json` is empty at commit time.
