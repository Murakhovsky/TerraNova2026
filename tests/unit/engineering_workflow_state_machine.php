<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowEngine;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\InvalidWorkflowTransitionException;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransitionContext;

$workflow = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::NEW, 'trace-test');
$engine = new EngineeringWorkflowEngine();
$context = new WorkflowTransitionContext('TEST', 'contract', 'SYSTEM', 'unit');

$engine->transition($workflow, EngineeringWorkflowState::ANALYSIS, $context);
$engine->transition($workflow, EngineeringWorkflowState::SPECIFICATION_READY, $context);
$engine->transition($workflow, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, $context);

if ($workflow->resumeState() !== EngineeringWorkflowState::SPECIFICATION_READY) {
    throw new RuntimeException('Human decision did not preserve resume state.');
}

$engine->transition($workflow, EngineeringWorkflowState::SPECIFICATION_READY, $context);
$engine->transition($workflow, EngineeringWorkflowState::QA_PLANNING, $context);
$engine->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_PENDING, $context);

$engine->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_APPROVED, $context);
$engine->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_PENDING, $context);
$engine->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, $context);
$engine->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_PENDING, $context);

try {
    $engine->transition($workflow, EngineeringWorkflowState::QA_PENDING, $context);
    throw new RuntimeException('Invalid workflow transition was accepted.');
} catch (InvalidWorkflowTransitionException) {
}

echo "Engineering workflow state machine passed.\n";
