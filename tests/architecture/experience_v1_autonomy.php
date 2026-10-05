<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Delivery/ExperienceAutonomyLevel.php',
    'symfony/src/Web/Experience/Delivery/ExperienceAutonomyPolicy.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('Experience autonomy artifact missing: '.$file);
    }
}

$policy = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/ExperienceAutonomyPolicy.php');
foreach ([
    'ExperienceAutonomyLevel::L2',
    'ExperienceAutonomyLevel::L3',
    'Experience V1 L4 auto-merge is disabled.',
    "risk(\$page) === 'HIGH'",
    'isGolden',
] as $marker) {
    if (!str_contains($policy, $marker)) {
        throw new RuntimeException('Experience autonomy policy missing: '.$marker);
    }
}

$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
foreach ([
    'experience_autonomy_level',
    'Experience autonomy ceiling',
    'WorkflowDirectiveType::STOP',
    "'L2' => 2",
    "'L3' => 3",
] as $marker) {
    if (!str_contains($progression, $marker)) {
        throw new RuntimeException('Engineering progression does not enforce Experience autonomy: '.$marker);
    }
}

$delivery = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryWorkflow.php');
foreach ([
    "'experience_autonomy_level' => \$autonomy->value",
    "'experience_risk' => \$risk",
    "'experience_auto_merge' => false",
] as $marker) {
    if (!str_contains($delivery, $marker)) {
        throw new RuntimeException('Experience delivery metadata missing: '.$marker);
    }
}

echo "Experience autonomy policy/enforcement OK\n";
