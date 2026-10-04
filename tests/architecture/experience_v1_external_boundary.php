<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/External/ExternalExperienceReport.php',
    'symfony/src/Web/Experience/External/ExternalExperiencePlanner.php',
    'symfony/src/Command/ExternalExperienceCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-006 external experience artifact missing: '.$file);
    }
}

$planner = (string) file_get_contents($root.'/symfony/src/Web/Experience/External/ExternalExperiencePlanner.php');
foreach ([
    "'public' => ['public_catalog', 'public_detail_marketing', 'form_editor']",
    "'portal' => ['portal']",
    'External Experience boundary violation',
] as $marker) {
    if (!str_contains($planner, $marker)) {
        throw new RuntimeException('EX-006 external UX boundary missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExternalExperienceCommand.php');
foreach ([
    "name: 'cos:experience:external'",
    'Design System',
    'separate from internal Workspace UX',
] as $marker) {
    if (!str_contains($command, $marker)) {
        throw new RuntimeException('EX-006 report contract missing: '.$marker);
    }
}

echo "EX-006 Portal/Public Experience boundary contract OK\n";
