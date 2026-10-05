<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'resources/experience/golden-structure.yaml',
    'symfony/src/Web/Experience/Golden/GoldenStructureAuditReport.php',
    'symfony/src/Web/Experience/Golden/GoldenStructureAuditService.php',
    'symfony/src/Command/GoldenStructureAuditCommand.php',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Golden structural audit artifact missing: ' . $file);
    }
}

$manifest = (string) file_get_contents($root . '/resources/experience/golden-structure.yaml');
if (substr_count($manifest, 'template: ') !== 8) {
    throw new RuntimeException('Golden structural audit must describe exactly eight templates.');
}

foreach ([
    'core.executive.dashboard:',
    'sales.dashboard:',
    'sales.today:',
    'sales.leads.collection:',
    'sales.deal.workspace:',
    'sales.pipeline:',
    'growth.overview:',
    'property.map:',
] as $page) {
    if (!str_contains($manifest, $page)) {
        throw new RuntimeException('Golden structural audit missing page: ' . $page);
    }
}

echo "Golden structural audit contract OK\n";
