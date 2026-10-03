<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

final class EngineeringWorkflowDefinition
{
    /** @var array<string, list<EngineeringWorkflowState>> */
    private const TRANSITIONS = [
        'NEW' => [EngineeringWorkflowState::ANALYSIS, EngineeringWorkflowState::CANCELLED],
        'ANALYSIS' => [EngineeringWorkflowState::SPECIFICATION_READY, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'SPECIFICATION_READY' => [EngineeringWorkflowState::ARCHITECTURE_PENDING, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'ARCHITECTURE_PENDING' => [EngineeringWorkflowState::ARCHITECTURE_APPROVED, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'ARCHITECTURE_APPROVED' => [EngineeringWorkflowState::DEVELOPMENT_PENDING, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'DEVELOPMENT_PENDING' => [EngineeringWorkflowState::DEVELOPMENT_RUNNING, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'DEVELOPMENT_RUNNING' => [EngineeringWorkflowState::ARCHITECTURE_PENDING, EngineeringWorkflowState::REVIEW_PENDING, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'REVIEW_PENDING' => [EngineeringWorkflowState::QA_PENDING, EngineeringWorkflowState::CHANGES_REQUESTED, EngineeringWorkflowState::ESCALATED, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'CHANGES_REQUESTED' => [EngineeringWorkflowState::DEVELOPMENT_RUNNING, EngineeringWorkflowState::ESCALATED, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'QA_PENDING' => [EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL, EngineeringWorkflowState::QA_FAILED, EngineeringWorkflowState::ESCALATED, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'QA_FAILED' => [EngineeringWorkflowState::DEVELOPMENT_RUNNING, EngineeringWorkflowState::ESCALATED, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'ESCALATED' => [EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::FAILED, EngineeringWorkflowState::CANCELLED],
        'READY_FOR_HUMAN_APPROVAL' => [EngineeringWorkflowState::DONE, EngineeringWorkflowState::DEVELOPMENT_RUNNING, EngineeringWorkflowState::CANCELLED],
    ];

    public function canTransition(
        EngineeringWorkflowState $from,
        EngineeringWorkflowState $to,
        ?EngineeringWorkflowState $resumeState = null,
    ): bool {
        if ($from === EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) {
            return $to === EngineeringWorkflowState::CANCELLED
                || $to === EngineeringWorkflowState::BLOCKED
                || $to === EngineeringWorkflowState::FAILED
                || ($resumeState !== null && $to === $resumeState);
        }

        foreach (self::TRANSITIONS[$from->value] ?? [] as $allowed) {
            if ($allowed === $to) return true;
        }

        return false;
    }

    public function assertCanTransition(
        EngineeringWorkflowState $from,
        EngineeringWorkflowState $to,
        ?EngineeringWorkflowState $resumeState = null,
    ): void {
        if (!$this->canTransition($from, $to, $resumeState)) {
            throw InvalidWorkflowTransitionException::between($from, $to);
        }
    }
}
