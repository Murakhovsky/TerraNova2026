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
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

$workflow = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::ANALYSIS, 'trace');
$directive = (new EngineeringWorkflowCoordinator())->acceptAgentResult($workflow, AgentRole::ENGINEERING_MANAGER, ['status' => 'SPECIFICATION_READY']);

if (count($directive->transitions) !== 2) throw new RuntimeException('Manager workflow must expose both persisted transitions.');
if ($directive->transitions[0]->from !== EngineeringWorkflowState::ANALYSIS || $directive->transitions[0]->to !== EngineeringWorkflowState::SPECIFICATION_READY) throw new RuntimeException('First Manager transition is incorrect.');
if ($directive->transitions[1]->from !== EngineeringWorkflowState::SPECIFICATION_READY || $directive->transitions[1]->to !== EngineeringWorkflowState::ARCHITECTURE_PENDING) throw new RuntimeException('Second Manager transition is incorrect.');

echo "Engineering transition capture passed.\n";
