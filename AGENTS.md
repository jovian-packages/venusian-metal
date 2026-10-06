# AGENTS.md

1. Bindings are ext-metal's, 1:1. This package holds the engine, never Metal calls of its own.
2. Every shader change keeps Velvet's arithmetic. See [.okf/architecture/engine.md](.okf/architecture/engine.md).
3. The parity suite is the gate: `tests/ParityTest.php` through Surface's `GpuParity`.
4. No toolkit code here. Lending lives in venusian-appkit / venusian-qt.
5. Tests run on the GPU, never skipped.
6. Publish prep is part of done: README examples run, `.okf` validated.
