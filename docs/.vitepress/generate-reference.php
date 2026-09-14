<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

// The reference generator is intentionally dependency-light and does not boot
// the Composer/application runtime. Kernel 0.11.x made ModuleExtensionRegistry
// constants delegate to ModuleExtensionPoint, so preload that canonical type
// before the legacy standalone generator requires the registry.
require_once $repoRoot . '/app/Kernel/Module/ModuleExtensionPoint.php';

require __DIR__ . '/generate-reference-core.php';
