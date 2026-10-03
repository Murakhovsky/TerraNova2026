<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringAgentOutputValidationException;
use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Domain\Agent\AgentRole;

$validator = new EngineeringAgentOutputValidator();

$valid = [
    'status' => 'SPECIFICATION_READY',
    'feature' => [
        'title' => 'Activity filters',
        'type' => 'FEATURE',
        'business_goal' => 'Users can filter activities.',
        'expected_behavior' => 'Actor/date filters are applied.',
        'scope' => ['Activity Center filtering'],
        'out_of_scope' => [],
        'functional_requirements' => [['id' => 'FR-001']],
        'acceptance_criteria' => [['id' => 'AC-001', 'description' => 'Actor filter returns only selected actor.', 'verification_type' => 'integration']],
        'risks' => [],
        'assumptions' => [],
        'open_questions' => [],
        'priority' => 'P2',
        'complexity' => 'S',
    ],
    'context_map' => [],
    'tasks' => [['id' => 'DEV-1']],
    'risks' => [],
    'assumptions' => [],
    'open_questions' => [],
    'decision' => ['type' => 'RUN_AGENT', 'agent' => 'principal_architect', 'reason' => 'mandatory'],
];
$validator->validate(AgentRole::ENGINEERING_MANAGER, $valid);

$invalid = $valid;
$invalid['feature']['acceptance_criteria'] = [['id' => 'whatever', 'description' => '', 'verification_type' => 'manual']];
try {
    $validator->validate(AgentRole::ENGINEERING_MANAGER, $invalid);
    throw new RuntimeException('Invalid Manager acceptance criteria were accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$qa = [
    'status' => 'PASS', 'tested_revision' => 'abc', 'acceptance_criteria' => [],
    'tests_total' => 2, 'tests_passed' => 1, 'tests_failed' => 1,
    'defects' => [], 'regressions' => [], 'known_limitations' => [],
];
try {
    $validator->validate(AgentRole::QA, $qa);
    throw new RuntimeException('QA PASS with failed tests was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

echo "Engineering agent output validation passed.\n";
