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
    'hasSourceTree',
    'Production PHP image intentionally contains compiled runtime artifacts',
] as $marker) {
    if (!str_contains($scanner, $marker)) {
        throw new RuntimeException('EX-007 asset scanner contract missing: '.$marker);
    }
}

$frontend = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/frontend', FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $frontend[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
sort($frontend);
$expected = [
    'frontend/spatial/spatial-viewer.css',
    'frontend/spatial/spatial-viewer.js',
];
if ($frontend !== $expected) {
    throw new RuntimeException('EX-007 source boundary drift: frontend/ must contain only Spatial Vite island sources.');
}

$vite = (string) file_get_contents($root.'/vite.config.js');
if (!str_contains($vite, "'spatial-viewer':") || str_contains($vite, 'frontend/features/') || str_contains($vite, 'frontend/core/')) {
    throw new RuntimeException('EX-007 Vite source boundary is not Spatial-only.');
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperienceAssetDebtCommand.php');
if (!str_contains($command, "name: 'cos:experience:assets'") || !str_contains($command, "addOption('strict'")) {
    throw new RuntimeException('EX-007 asset debt command is incomplete.');
}

echo "EX-007 asset debt scanner/source boundary OK\n";
