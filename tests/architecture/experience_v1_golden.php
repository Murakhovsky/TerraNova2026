<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'resources/experience/golden-set.yaml',
    'resources/experience/pages/growth/golden.yaml',
    'symfony/src/Web/Experience/Golden/GoldenExperienceReport.php',
    'symfony/src/Web/Experience/Golden/GoldenExperienceSet.php',
    'symfony/src/Command/GoldenExperienceCommand.php',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('EX-003 Golden Experience artifact missing: ' . $file);
    }
}

$manifest = (string) file_get_contents($root . '/resources/experience/golden-set.yaml');
foreach ([
    'required_count: 8',
    'core.executive.dashboard',
    'sales.dashboard',
    'sales.today',
    'sales.leads.collection',
    'sales.deal.workspace',
    'sales.pipeline',
    'growth.overview',
    'property.map',
    'target_quality: 4',
    'human_approval_required: true',
    'mass_migration_blocked_until_ready: true',
] as $marker) {
    if (!str_contains($manifest, $marker)) {
        throw new RuntimeException('Golden Experience manifest missing marker: ' . $marker);
    }
}

$inventory = (string) file_get_contents($root . '/resources/experience/pages/inventory.yaml');
if (str_contains($inventory, 'id: growth.overview')) {
    throw new RuntimeException('growth.overview must live in the Golden contract, not generic inventory.');
}

$command = (string) file_get_contents($root . '/symfony/src/Command/GoldenExperienceCommand.php');
foreach (['cos:experience:golden', "addOption('strict'", 'Mass migration', 'Golden ready'] as $marker) {
    if (!str_contains($command, $marker)) {
        throw new RuntimeException('Golden Experience command missing marker: ' . $marker);
    }
}

echo "EX-003 Golden Experience foundation OK\n";
