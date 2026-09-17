<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

use InvalidArgumentException;
use LogicException;

final class AgentStep
{
    private AgentRunStatus $status = AgentRunStatus::CREATED;
    private array $output = [];
    private ?string $error = null;

    public function __construct(
        public readonly string $id,
        public readonly int $sequence,
        public readonly string $type,
        public readonly array $input = [],
    ) {
        if (trim($id) === '' || trim($type) === '' || $sequence < 1) {
            throw new InvalidArgumentException('Agent step requires id, positive sequence and type.');
        }
    }

    public function status(): AgentRunStatus { return $this->status; }
    public function output(): array { return $this->output; }
    public function error(): ?string { return $this->error; }

    public function queue(): void { $this->moveTo(AgentRunStatus::QUEUED); }
    public function start(): void { $this->moveTo(AgentRunStatus::RUNNING); }
    public function wait(): void { $this->moveTo(AgentRunStatus::WAITING); }
    public function resume(): void { $this->moveTo(AgentRunStatus::RUNNING); }

    public function complete(array $output = []): void
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
            throw new LogicException(sprintf('Invalid agent step transition %s -> %s.', $this->status->value, $next->value));
        }
        $this->status = $next;
    }
}
