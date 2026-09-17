<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use LogicException;

final class StepExecution
{
    private StepStatus $status = StepStatus::PENDING;
    private array $output = [];
    private ?string $error = null;

    public function __construct(public readonly string $stepId) {}

    public function status(): StepStatus { return $this->status; }
    /** @return array<string,mixed> */
    public function output(): array { return $this->output; }
    public function error(): ?string { return $this->error; }

    public function start(): void
    {
        if ($this->status !== StepStatus::PENDING) throw new LogicException('Workflow step is not pending.');
        $this->status = StepStatus::RUNNING;
    }

    public function wait(): void
    {
        if ($this->status !== StepStatus::RUNNING) throw new LogicException('Workflow step is not running.');
        $this->status = StepStatus::WAITING;
    }

    public function resume(): void
    {
        if ($this->status !== StepStatus::WAITING) throw new LogicException('Workflow step is not waiting.');
        $this->status = StepStatus::RUNNING;
    }

    /** @param array<string,mixed> $output */
    public function complete(array $output = []): void
    {
        if ($this->status !== StepStatus::RUNNING) throw new LogicException('Workflow step is not running.');
        $this->status = StepStatus::COMPLETED;
        $this->output = $output;
    }

    public function fail(string $error): void
    {
        if (!in_array($this->status, [StepStatus::RUNNING, StepStatus::WAITING], true)) {
            throw new LogicException('Workflow step cannot fail from its current state.');
        }
        $this->status = StepStatus::FAILED;
        $this->error = trim($error) !== '' ? trim($error) : 'Workflow step failed.';
    }
}
