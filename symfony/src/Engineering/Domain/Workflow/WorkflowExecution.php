<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

use DateTimeImmutable;
use LogicException;

final class WorkflowExecution
{
    private ?EngineeringWorkflowState $resumeState = null;
    private ?DateTimeImmutable $finishedAt = null;
    private DateTimeImmutable $lastActivityAt;
    private DateTimeImmutable $startedAt;

    public function __construct(
        private readonly string $id,
        private readonly string $featureId,
        private EngineeringWorkflowState $currentState,
        private readonly string $traceId,
        private int $version = 1,
        ?DateTimeImmutable $startedAt = null,
    ) {
        EngineeringId::assert($id);
        EngineeringId::assert($featureId);
        if (trim($traceId) === '') {
            throw new LogicException('Engineering workflow trace id must be non-empty.');
        }

        $this->startedAt = $startedAt ?? new DateTimeImmutable();
        $this->lastActivityAt = $this->startedAt;
    }

    public static function restore(
        string $id,
        string $featureId,
        EngineeringWorkflowState $currentState,
        ?EngineeringWorkflowState $resumeState,
        string $traceId,
        int $version,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $lastActivityAt,
        ?DateTimeImmutable $finishedAt,
    ): self {
        $workflow = new self($id, $featureId, $currentState, $traceId, $version, $startedAt);
        $workflow->resumeState = $resumeState;
        $workflow->lastActivityAt = $lastActivityAt;
        $workflow->finishedAt = $finishedAt;
        return $workflow;
    }

    public function id(): string { return $this->id; }
    public function featureId(): string { return $this->featureId; }
    public function currentState(): EngineeringWorkflowState { return $this->currentState; }
    public function resumeState(): ?EngineeringWorkflowState { return $this->resumeState; }
    public function traceId(): string { return $this->traceId; }
    public function version(): int { return $this->version; }
    public function startedAt(): DateTimeImmutable { return $this->startedAt; }
    public function lastActivityAt(): DateTimeImmutable { return $this->lastActivityAt; }
    public function finishedAt(): ?DateTimeImmutable { return $this->finishedAt; }

    public function moveTo(EngineeringWorkflowState $target): void
    {
        if ($target === EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) {
            $this->resumeState = $this->currentState;
        } elseif ($this->currentState === EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) {
            $this->resumeState = null;
        }

        $this->currentState = $target;
        $this->lastActivityAt = new DateTimeImmutable();
        ++$this->version;

        if ($target->isTerminal()) {
            $this->finishedAt = $this->lastActivityAt;
        }
    }
}
