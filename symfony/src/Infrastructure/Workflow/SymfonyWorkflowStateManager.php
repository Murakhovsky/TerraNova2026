<?php
declare(strict_types=1);

namespace App\Infrastructure\Workflow;

use Kernel\Workflow\Contract\WorkflowStateManagerInterface;
use Kernel\Workflow\Model\WorkflowStatus;
use LogicException;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow as SymfonyWorkflow;

final class SymfonyWorkflowStateManager implements WorkflowStateManagerInterface
{
    private SymfonyWorkflow $workflow;

    public function __construct()
    {
        $builder = new DefinitionBuilder(array_map(static fn (WorkflowStatus $status): string => $status->value, WorkflowStatus::cases()));
        foreach ($this->transitions() as [$from, $to]) {
            $builder->addTransition(new Transition($this->name($from, $to), $from->value, $to->value));
        }
        $this->workflow = new SymfonyWorkflow(
            $builder->build(),
            new MethodMarkingStore(true, 'state'),
            null,
            'cos_workflow_execution',
            [],
        );
    }

    public function assertCanTransition(WorkflowStatus $from, WorkflowStatus $to): void
    {
        $subject = new WorkflowStateSubject($from);
        $name = $this->name($from, $to);
        if (!$this->workflow->can($subject, $name)) {
            throw new LogicException(sprintf('Invalid workflow state transition %s -> %s.', $from->value, $to->value));
        }
        $this->workflow->apply($subject, $name);
        if ($subject->getState() !== $to) {
            throw new LogicException('Symfony Workflow did not apply the requested COS state transition.');
        }
    }

    /** @return list<array{WorkflowStatus,WorkflowStatus}> */
    private function transitions(): array
    {
        return [
            [WorkflowStatus::CREATED, WorkflowStatus::RUNNING],
            [WorkflowStatus::CREATED, WorkflowStatus::CANCELLED],
            [WorkflowStatus::RUNNING, WorkflowStatus::WAITING],
            [WorkflowStatus::RUNNING, WorkflowStatus::COMPLETED],
            [WorkflowStatus::RUNNING, WorkflowStatus::FAILED],
            [WorkflowStatus::RUNNING, WorkflowStatus::CANCELLED],
            [WorkflowStatus::WAITING, WorkflowStatus::RUNNING],
            [WorkflowStatus::WAITING, WorkflowStatus::FAILED],
            [WorkflowStatus::WAITING, WorkflowStatus::CANCELLED],
        ];
    }

    private function name(WorkflowStatus $from, WorkflowStatus $to): string
    {
        return $from->value . '_to_' . $to->value;
    }
}
