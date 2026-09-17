<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Workflow\SymfonyWorkflowStateManager;
use Kernel\Workflow\Model\WorkflowStatus;

$states = new SymfonyWorkflowStateManager();
$states->assertCanTransition(WorkflowStatus::CREATED, WorkflowStatus::RUNNING);
$states->assertCanTransition(WorkflowStatus::RUNNING, WorkflowStatus::WAITING);
$states->assertCanTransition(WorkflowStatus::WAITING, WorkflowStatus::RUNNING);
$states->assertCanTransition(WorkflowStatus::RUNNING, WorkflowStatus::COMPLETED);

try {
    $states->assertCanTransition(WorkflowStatus::CREATED, WorkflowStatus::COMPLETED);
    throw new RuntimeException('Symfony Workflow state manager accepted an invalid lifecycle transition.');
} catch (LogicException) {
}

echo "Symfony Workflow state manager contract passed.\n";
