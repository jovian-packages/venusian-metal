<?php

declare(strict_types=1);

/*
| Pest bootstrap for jovian/venusian-metal.
|
| Extension-dependent suites skip when ext-metal is absent. A skipped test
| is not evidence the GPU path works — run those on a Mac with the
| extension loaded (export HERD_PHP_84_INI_SCAN_DIR first).
*/

function metalExtensionLoaded(): bool
{
    return extension_loaded('metal');
}
