<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infrastructure\Web\Assets\ViteAssetResolver;

$assets = ViteAssetResolver::resolve(['public-surface']);
if (($assets['scripts'] ?? []) === []) {
    throw new RuntimeException('Framework-neutral view asset resolver returned no scripts.');
}
foreach ($assets['scripts'] as $script) {
    if (!is_string($script) || !str_starts_with($script, '/build/')) {
        throw new RuntimeException('Unexpected view asset URL: ' . var_export($script, true));
    }
}

echo "Framework-neutral PHTML asset resolver passed.\n";
