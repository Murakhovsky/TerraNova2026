<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowEngine;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransitionContext;

$workflow = new WorkflowExecution(
    EngineeringId::generate(),
    EngineeringId::generate(),
    EngineeringWorkflowState::ANALYSIS,
    'human-resume',
);
$engine = new EngineeringWorkflowEngine();
$engine->transition(
    $workflow,
    EngineeringWorkflowState::HUMAN_DECISION_REQUIRED,
    new WorkflowTransitionContext('TEST', 'ambiguity', 'AGENT', 'manager'),
);

$decisionId = EngineeringId::generate();
$directive = (new EngineeringWorkflowCoordinator())->resumeAfterHumanDecision($workflow, $decisionId);

if ($workflow->currentState() !== EngineeringWorkflowState::ANALYSIS) throw new RuntimeException('Human decision did not restore ANALYSIS.');
if ($directive->agent !== AgentRole::ENGINEERING_MANAGER) throw new RuntimeException('Manager was not selected after ANALYSIS resume.');
if (($directive->transitions[0]->context->humanDecisionId ?? null) !== $decisionId) throw new RuntimeException('Human decision id was not attached to transition audit.');

echo "Engineering human decision resume passed.\n";
