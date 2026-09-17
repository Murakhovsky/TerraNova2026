<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

use InvalidArgumentException;
use LogicException;

final class AgentRun
{
    private AgentRunStatus $status = AgentRunStatus::CREATED;
    /** @var list<AgentStep> */
    private array $steps = [];
    private ?AgentOutput $output = null;
    private ?string $error = null;
    private ?string $waitingReason = null;

    public function __construct(
        public readonly string $id,
        public readonly AgentInstance $instance,
        public readonly AgentContext $context,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Agent run id cannot be empty.');
        }
        if ($instance->organizationId->value() !== $context->organizationId->value()) {
            throw new InvalidArgumentException('Agent instance and context must belong to the same organization.');
        }
    }

    public function status(): AgentRunStatus { return $this->status; }
    /** @return list<AgentStep> */
    public function steps(): array { return $this->steps; }
    public function output(): ?AgentOutput { return $this->output; }
    public function error(): ?string { return $this->error; }
    public function waitingReason(): ?string { return $this->waitingReason; }

    public function addStep(AgentStep $step): void
    {
        if ($this->status->terminal()) {
            throw new LogicException('Cannot add steps to a terminal agent run.');
        }
        $this->steps[] = $step;
    }

    public function queue(): void { $this->moveTo(AgentRunStatus::QUEUED); }
    public function start(): void { $this->moveTo(AgentRunStatus::RUNNING); }

    public function wait(string $reason): void
    {
        $this->moveTo(AgentRunStatus::WAITING);
        $this->waitingReason = $reason;
    }

    public function resume(): void
    {
        $this->moveTo(AgentRunStatus::RUNNING);
        $this->waitingReason = null;
    }

    public function complete(AgentOutput $output): void
    {
        $this->moveTo(AgentRunStatus::COMPLETED);
        $this->output = $output;
    }

    public function fail(string $error): void
    {
        $this->moveTo(AgentRunStatus::FAILED);
        $this->error = $error;
    }

    public function cancel(): void { $this->moveTo(AgentRunStatus::CANCELLED); }

    private function moveTo(AgentRunStatus $next): void
    {
        if (!$this->status->allows($next)) {
            throw new LogicException(sprintf('Invalid agent run transition %s -> %s.', $this->status->value, $next->value));
        }
        $this->status = $next;
    }
}
