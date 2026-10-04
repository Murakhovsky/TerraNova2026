<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Migration/WorkspaceMigrationPlan.php',
    'symfony/src/Web/Experience/Migration/WorkspaceMigrationPlanner.php',
    'symfony/src/Web/Experience/Migration/WorkspaceMigrationRunner.php',
    'symfony/src/Command/ExperienceWorkspaceMigrationCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-005 migration artifact missing: '.$file);
    }
}

$planner = (string) file_get_contents($root.'/symfony/src/Web/Experience/Migration/WorkspaceMigrationPlanner.php');
$positions = [];
foreach (['Sales','Growth','Property','Diagnostic/Core','Service','RealEstate','Admin'] as $stage) {
    $position = strpos($planner, "'".$stage."'");
    if ($position === false) {
        throw new RuntimeException('EX-005 stage missing: '.$stage);
    }
    $positions[] = $position;
}
if ($positions !== array_values($positions) || $positions !== (function (array $x): array { $y=$x; sort($y); return $y; })($positions)) {
    throw new RuntimeException('EX-005 migration stage order is not canonical.');
}

$runner = (string) file_get_contents($root.'/symfony/src/Web/Experience/Migration/WorkspaceMigrationRunner.php');
foreach ([
    'if ($plan->blocked)',
    'EX-005 is blocked',
    'PageDeliveryWorkflow',
] as $marker) {
    if (!str_contains($runner, $marker)) {
        throw new RuntimeException('EX-005 hard gate missing: '.$marker);
    }
}

foreach ([
    '$this->autonomy->riskFor($page->id->value)',
    '$this->autonomy->levelFor($page->id->value)->value',
] as $marker) {
    if (!str_contains($planner, $marker)) {
        throw new RuntimeException('EX-005 policy-driven autonomy missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperienceWorkspaceMigrationCommand.php');
foreach ([
    "name: 'cos:experience:migrate'",
    "addOption('start'",
    "['Autonomy' => 'L3 max; HIGH risk capped at L2']"
    "['Auto merge' => 'disabled']",
] as $marker) {
    if (!str_contains($command, $marker)) {
        throw new RuntimeException('EX-005 migration command missing marker: '.$marker);
    }
}

echo "EX-005 Workspace Mass Migration planner/gate contract OK\n";
