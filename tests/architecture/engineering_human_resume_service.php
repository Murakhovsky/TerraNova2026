<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringHumanDecisionService.php');
$command = (string) file_get_contents($root.'/symfony/src/Command/EngineeringDecisionCommand.php');

foreach ([
    'humanDecisions->answer',
    'resumeAfterHumanDecision',
    'previousContext',
    'managerStage->execute',
    'logicalAttempt',
] as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Human resume service missing '.$needle);
}
if (!str_contains($command, 'cos:engineering:decision')) throw new RuntimeException('Human decision CLI command missing.');

echo "Engineering human resume service passed.\n";
