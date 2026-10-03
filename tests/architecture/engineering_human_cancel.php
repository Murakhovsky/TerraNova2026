<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringHumanDecisionService.php');

foreach ([
    "strtoupper(trim(\$selectedOption)) === 'CANCEL'",
    "offersOption",
    "coordinator->cancel",
    "Human decision selected CANCEL",
] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException('Engineering human CANCEL contract missing '.$needle);
    }
}

echo "Engineering human CANCEL contract passed.\n";
