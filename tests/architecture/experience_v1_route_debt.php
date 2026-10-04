<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach ([
    'symfony/src/Web/Experience/Release/ExperienceRouteDebtReport.php',
    'symfony/src/Web/Experience/Release/ExperienceRouteDebtScanner.php',
    'symfony/src/Command/ExperienceRouteDebtCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-007 route debt artifact missing: '.$file);
    }
}

$scanner = (string) file_get_contents($root.'/symfony/src/Web/Experience/Release/ExperienceRouteDebtScanner.php');
foreach (['contract:', 'exemption:', 'both contracted and exempted', 'path mismatch'] as $marker) {
    if (!str_contains($scanner, $marker)) {
        throw new RuntimeException('EX-007 route debt scanner missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperienceRouteDebtCommand.php');
if (!str_contains($command, "name: 'cos:experience:routes:debt'") || !str_contains($command, "addOption('strict'")) {
    throw new RuntimeException('EX-007 route debt command is incomplete.');
}

echo "EX-007 route debt scanner contract OK\n";
