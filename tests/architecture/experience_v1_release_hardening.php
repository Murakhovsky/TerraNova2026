<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach ([
    'symfony/src/Web/Experience/Release/ExperienceReleaseReport.php',
    'symfony/src/Web/Experience/Release/ExperienceReleaseHardeningService.php',
    'symfony/src/Command/ExperienceReleaseCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-007 release hardening artifact missing: '.$file);
    }
}

$service = (string) file_get_contents($root.'/symfony/src/Web/Experience/Release/ExperienceReleaseHardeningService.php');
foreach ([
    'registry_coverage',
    'golden_human_approval',
    'p0_v1_ready',
    'p1_production_acceptable',
    'p0_p1_accessibility_qa',
    'dead_css_cleanup',
    'dead_js_cleanup',
    'PENDING_SCANNER_AND_CLEANUP',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('EX-007 release gate missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperienceReleaseCommand.php');
foreach (["name: 'cos:experience:release'", "addOption('strict'", 'Release blockers'] as $marker) {
    if (!str_contains($command, $marker)) {
        throw new RuntimeException('EX-007 release command missing: '.$marker);
    }
}

echo "EX-007 release hardening foundation OK\n";
