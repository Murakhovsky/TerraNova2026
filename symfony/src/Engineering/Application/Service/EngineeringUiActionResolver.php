<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

/**
 * Canonical presentation-facing action policy for Engineering workflow controls.
 *
 * It does not execute transitions. Backend use cases remain authoritative.
 * Its purpose is to keep every UI surface consistent with terminal/waiting/runtime states.
 */
final readonly class EngineeringUiActionResolver
{
    /**
     * @param array<string,mixed> $feature
     * @param array<string,mixed>|null $workflow
     * @return array<string,bool>
     */
    public function resolve(array $feature, ?array $workflow, string $health = 'UNKNOWN', int $openHumanDecisions = 0): array
    {
        $featureStatus = strtoupper((string) ($feature['status'] ?? 'NEW'));
        $state = strtoupper((string) ($workflow['state'] ?? $workflow['current_state'] ?? $featureStatus));
        $workflowStatus = strtoupper((string) ($workflow['status'] ?? $workflow['workflow_status'] ?? $featureStatus));
        $health = strtoupper(trim($health));

        $hasWorkflow = $workflow !== null;
        $terminal = in_array($state, ['DONE','CANCELLED','FAILED'], true)
            || in_array($workflowStatus, ['COMPLETED','CANCELLED','FAILED'], true);
        $cancelledOrFailed = in_array($state, ['CANCELLED','FAILED'], true)
            || in_array($workflowStatus, ['CANCELLED','FAILED'], true);
        $readyForHumanApproval = $state === 'READY_FOR_HUMAN_APPROVAL';
        $waitingHuman = $openHumanDecisions > 0
            || in_array($state, ['HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true);
        $draft = !$hasWorkflow && $featureStatus === 'NEW';
        $stalled = in_array($health, ['STALE','STALLED'], true);
        $activeExecution = $hasWorkflow && $workflowStatus === 'RUNNING' && !$stalled;

        return [
            'open' => true,
            'edit' => $draft,
            'queue' => $draft,
            'run' => $draft,
            'continue' => $hasWorkflow && !$terminal && !$waitingHuman && !$readyForHumanApproval && !$stalled && !$activeExecution,
            'resume' => $hasWorkflow && !$terminal && !$waitingHuman && !$readyForHumanApproval && $stalled,
            'cancel' => $hasWorkflow && !$terminal,
            'resolve_human' => $waitingHuman,
            'finalize' => $hasWorkflow && $readyForHumanApproval && !$terminal,
            'retry' => $hasWorkflow && $cancelledOrFailed,
            'delete' => $draft || $cancelledOrFailed,
        ];
    }
}
