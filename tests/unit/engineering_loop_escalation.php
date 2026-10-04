<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowCounters;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

$workflow = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::REVIEW_PENDING, 'loop-trace');
$directive = (new EngineeringWorkflowCoordinator())->acceptAgentResult(
    $workflow,
    AgentRole::REVIEWER,
    ['status' => 'REQUEST_CHANGES'],
    new WorkflowCounters(3, 3, 0),
);

if ($directive->type !== WorkflowDirectiveType::REQUEST_HUMAN_DECISION) throw new RuntimeException('Review limit did not require human decision.');
if ($workflow->currentState() !== EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) throw new RuntimeException('Escalated workflow did not stop for human decision.');
if ($workflow->resumeState() !== EngineeringWorkflowState::ESCALATED) throw new RuntimeException('Escalation resume state was not preserved.');

echo "Engineering loop escalation passed.\n";
