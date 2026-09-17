<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;
use LogicException;
use Kernel\Workflow\Contract\WorkflowStateManagerInterface;

final class WorkflowExecution
{
    private WorkflowStatus $status = WorkflowStatus::CREATED;
    private string $currentStepId;
    /** @var list<StepExecution> */
    private array $stepExecutions = [];
    /** @var array<string,mixed> */
    private array $context;
    private ?string $waitingReason = null;
    private ?string $error = null;

    /** @param array<string,mixed> $input */
    public function __construct(
        public readonly string $id,
        public readonly WorkflowInstance $instance,
        array $input = [],
    ) {
        if (trim($id) === '') throw new InvalidArgumentException('Workflow execution id cannot be empty.');
        $this->currentStepId = $instance->workflow->definition->entryStepId;
        $this->context = [
            'input' => $input,
            'steps' => [],
            'variables' => $instance->configuration,
        ];
    }

    public function status(): WorkflowStatus { return $this->status; }
    public function currentStepId(): string { return $this->currentStepId; }
    public function waitingReason(): ?string { return $this->waitingReason; }
    public function error(): ?string { return $this->error; }
    /** @return array<string,mixed> */
    public function context(): array { return $this->context; }
    /** @return list<StepExecution> */
    public function stepExecutions(): array { return $this->stepExecutions; }

    public function currentStepExecution(): ?StepExecution
    {
        for ($i = count($this->stepExecutions) - 1; $i >= 0; --$i) {
            if ($this->stepExecutions[$i]->stepId === $this->currentStepId) return $this->stepExecutions[$i];
        }
        return null;
    }

    public function latestStepExecution(string $stepId): ?StepExecution
    {
        for ($i = count($this->stepExecutions) - 1; $i >= 0; --$i) {
            if ($this->stepExecutions[$i]->stepId === $stepId) return $this->stepExecutions[$i];
        }
        return null;
    }

    public function beginStep(string $stepId): StepExecution
    {
        if ($this->status !== WorkflowStatus::RUNNING) throw new LogicException('Workflow must be running to begin a step.');
        $execution = new StepExecution($stepId);
        $execution->start();
        $this->stepExecutions[] = $execution;
        return $execution;
    }

    public function advanceTo(string $stepId): void
    {
        if ($this->status !== WorkflowStatus::RUNNING) throw new LogicException('Workflow must be running to advance.');
        $this->currentStepId = $stepId;
    }

    /** @param array<string,mixed> $output */
    public function recordOutput(string $stepId, array $output): void
    {
        $this->context['steps'][$stepId] = $output;
    }

    public function transitionTo(WorkflowStatus $next, WorkflowStateManagerInterface $states): void
    {
        $states->assertCanTransition($this->status, $next);
        $this->status = $next;
        if ($next !== WorkflowStatus::WAITING) $this->waitingReason = null;
    }

    public function wait(string $reason, WorkflowStateManagerInterface $states): void
    {
        $this->transitionTo(WorkflowStatus::WAITING, $states);
        $this->waitingReason = trim($reason) !== '' ? trim($reason) : 'workflow_wait';
    }

    public function fail(string $error, WorkflowStateManagerInterface $states): void
    {
        if ($this->status->terminal()) return;
        $this->transitionTo(WorkflowStatus::FAILED, $states);
        $this->error = trim($error) !== '' ? trim($error) : 'Workflow execution failed.';
    }

    public function cancel(WorkflowStateManagerInterface $states): void
    {
        if ($this->status->terminal()) return;
        $this->transitionTo(WorkflowStatus::CANCELLED, $states);
    }
}
