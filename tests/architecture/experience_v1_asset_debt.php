<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Release/ExperienceAssetDebtReport.php',
    'symfony/src/Web/Experience/Release/ExperienceAssetDebtScanner.php',
    'symfony/src/Command/ExperienceAssetDebtCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-007 asset debt artifact missing: '.$file);
    }
}

$scanner = (string) file_get_contents($root.'/symfony/src/Web/Experience/Release/ExperienceAssetDebtScanner.php');
foreach ([
    'frontend/spatial/spatial-viewer.js',
    'frontend/spatial/spatial-viewer.css',
    'symfony/assets/styles/domains/growth.css',
    'tests/frontend/api_client.mjs',
    'viteBoundaryValid',
] as $marker) {
    if (!str_contains($scanner, $marker)) {
        throw new RuntimeException('EX-007 asset scanner contract missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperienceAssetDebtCommand.php');
if (!str_contains($command, "name: 'cos:experience:assets'") || !str_contains($command, "addOption('strict'")) {
    throw new RuntimeException('EX-007 asset debt command is incomplete.');
}

echo "EX-007 asset debt scanner contract OK\n";
