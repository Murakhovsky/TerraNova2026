<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Observability\EngineeringExecutionJournal;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use RuntimeException;
use Throwable;

/**
 * One-shot, explicitly authorized recovery of rejected architecture findings.
 *
 * This service deliberately does NOT use general autonomous progression. The
 * ordinary 16-run budget is neither reset nor silently extended. It executes
 * at most one Architect logical attempt and one independent Security review,
 * then parks the workflow. Duplicate worker deliveries are no-ops.
 */
final readonly class EngineeringControlledSecurityRecoveryService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringArchitectStageExecutor $architect,
        private EngineeringSpecialistStageExecutor $specialists,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringExecutionJournal $journal,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
        private EngineeringArchitectSecurityRecoveryContext $recoveryContext = new EngineeringArchitectSecurityRecoveryContext(),
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $featureId, string $organizationId): array
    {
        $feature = $this->features->view($featureId);
        if (($feature['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Security recovery feature does not belong to the current organization.');
        }
        $workflowId = $this->workflows->activeIdForFeature($featureId);
        $workflow = $workflowId !== null ? $this->workflows->get($workflowId) : null;
        $review = $this->artifacts->latestIncludingSuperseded($featureId, ArtifactType::SECURITY_REVIEW_REPORT);
        $revision = trim((string) ($feature['repository_revision'] ?? 'unknown'));
        $target = $this->recoveryContext->fromArtifact($review, $revision);
        $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $permit = $this->lastPermit($feature);

        $problems = [];
        if ($workflow === null || $workflow->currentState() !== EngineeringWorkflowState::BLOCKED) {
            $problems[] = 'Current workflow is not BLOCKED.';
        }
        if ($target === null) $problems[] = 'No unresolved independent Security findings are available.';
        if ($architecture === null || ($architecture['content']['gate_status'] ?? '') !== 'REJECTED') {
            $problems[] = 'Latest Architecture Gate is not REJECTED.';
        }
        if ($this->artifacts->latest($featureId, ArtifactType::FEATURE_SPEC) === null
            || $this->artifacts->latest($featureId, ArtifactType::CONTEXT_MAP) === null
            || $this->artifacts->latest($featureId, ArtifactType::TEST_PLAN) === null) {
            $problems[] = 'Feature Spec, Context Map and QA Test Plan must exist.';
        }
        if (!$this->repository->available()) $problems[] = 'Read-only repository access is unavailable.';
        if ($this->humanDecisions->openForFeature($featureId) !== []) $problems[] = 'There is an open human decision.';
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            if (($run['status'] ?? '') === 'RUNNING') {
                $problems[] = 'A previous AgentRun is still running.';
                break;
            }
        }
        if ($target !== null && $permit !== null
            && ($permit['source_artifact_hash'] ?? '') === ($target['source_artifact_hash'] ?? '')) {
            $problems[] = 'This Security review already has a one-shot recovery permit; repeated LLM execution is forbidden.';
        }

        return [
            'eligible' => $problems === [],
            'problems' => $problems,
            'workflow_id' => $workflowId,
            'workflow_state' => $workflow?->currentState()->value,
            'source_artifact_id' => $target['source_artifact_id'] ?? null,
            'source_artifact_hash' => $target['source_artifact_hash'] ?? null,
            'source_review_status' => $target['source_artifact_status'] ?? null,
            'finding_ids' => $target['finding_ids'] ?? [],
            'revision_binding' => $target['revision_binding'] ?? null,
            'architecture_binding' => $target['architecture_binding'] ?? null,
            'latest_recovery' => $permit,
            'max_architect_logical_attempts' => 1,
            'max_independent_security_reviews' => 1,
            'automatic_developer_start' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function authorize(
        string $featureId,
        string $organizationId,
        string $actorId,
        string $expectedReviewHash,
        string $confirmation,
    ): array {
        if ($confirmation !== 'RECOVER_SECURITY_FINDINGS' || !str_starts_with($actorId, 'user:')) {
            throw new RuntimeException('Explicit authorized user confirmation is required.');
        }

        return $this->lock->synchronized($featureId, function () use (
            $featureId, $organizationId, $actorId, $expectedReviewHash,
        ): array {
            $plan = $this->preview($featureId, $organizationId);
            $existing = $plan['latest_recovery'];
            if (($existing['state'] ?? null) === 'ARMED'
                && ($existing['source_artifact_hash'] ?? null) === $expectedReviewHash) {
                // The UI may retry sending a message after an unreliable dispatch.
                // The same permit is reused; workers still claim it exactly once.
                return $existing;
            }
            if (!$plan['eligible'] || !hash_equals((string) ($plan['source_artifact_hash'] ?? ''), $expectedReviewHash)) {
                throw new RuntimeException('Security recovery preflight changed; refresh the page and review findings.');
            }
            $permit = [
                'id' => EngineeringId::generate(),
                'state' => 'ARMED',
                'workflow_id' => $plan['workflow_id'],
                'source_artifact_id' => $plan['source_artifact_id'],
                'source_artifact_hash' => $plan['source_artifact_hash'],
                'finding_ids' => $plan['finding_ids'],
                'authorized_by' => $actorId,
                'authorized_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'baseline_agent_runs' => count($this->agentRuns->forFeature($featureId)),
                'limit_architect_attempts' => 1,
                'limit_security_reviews' => 1,
                'no_auto_developer' => true,
            ];
            // An immediate workflow cannot be picked up by the normal scheduler.
            $this->workflows->markImmediate((string) $plan['workflow_id']);
            $this->features->appendPreviousContext($featureId, ['security_recovery' => $permit]);
            $this->journal->event(
                $featureId, (string) $plan['workflow_id'], 'MANAGER',
                'security_recovery.authorized', 'COMPLETED',
                'Operator authorized one targeted architecture and independent Security review.',
                'security-recovery:'.$permit['id'],
                ['permit_id' => $permit['id'], 'finding_ids' => $permit['finding_ids'], 'source_artifact_id' => $permit['source_artifact_id']],
            );
            return $permit;
        });
    }

    /** @return array<string,mixed> */
    public function executeOnce(string $featureId, string $organizationId, string $correlationId): array
    {
        $claim = $this->lock->synchronized($featureId, function () use ($featureId, $organizationId, $correlationId): ?array {
            $feature = $this->features->view($featureId);
            if (($feature['organization_id'] ?? null) !== $organizationId) {
                throw new RuntimeException('Security recovery belongs to another organization.');
            }
            $permit = $this->lastPermit($feature);
            if ($permit === null || ($permit['state'] ?? '') !== 'ARMED') {
                return null; // Duplicate or stale delivery, with zero new LLM calls.
            }
            $workflowId = $this->workflows->activeIdForFeature($featureId);
            if ($workflowId !== ($permit['workflow_id'] ?? null)) throw new RuntimeException('Recovery workflow changed.');
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::BLOCKED) {
                throw new RuntimeException('Recovery is not in the expected BLOCKED state.');
            }
            $latestReview = $this->artifacts->latestIncludingSuperseded($featureId, ArtifactType::SECURITY_REVIEW_REPORT);
            if (($latestReview['content_hash'] ?? null) !== ($permit['source_artifact_hash'] ?? null)) {
                throw new RuntimeException('Security review changed after recovery authorization.');
            }
            $runs = $this->agentRuns->forFeature($featureId);
            if (count($runs) !== (int) ($permit['baseline_agent_runs'] ?? -1)) {
                throw new RuntimeException('AgentRun history changed after recovery authorization.');
            }
            $attempt = 1;
            foreach ($runs as $run) {
                if (($run['role'] ?? null) === AgentRole::PRINCIPAL_ARCHITECT->value) ++$attempt;
            }
            $directive = $this->coordinator->beginTargetedSecurityRecovery(
                $workflow, (string) ($permit['authorized_by'] ?? ''), (string) ($permit['id'] ?? ''),
            );
            foreach ($directive->transitions as $transition) $this->workflows->saveTransition($workflow, $transition);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            $this->recordPermit($featureId, $permit, 'RUNNING', ['claimed_at' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
            $this->journal->event($featureId, $workflowId, 'MANAGER', 'security_recovery.claimed', 'RUNNING',
                'One-shot Architect recovery claimed under per-feature lock.', $correlationId,
                ['permit_id' => $permit['id'], 'architect_logical_attempt' => $attempt]);
            return ['workflow_id' => $workflowId, 'permit' => $permit, 'attempt' => $attempt];
        });

        if ($claim === null) {
            return ['state' => 'DUPLICATE_IGNORED', 'executed_llm' => false];
        }

        $workflowId = (string) $claim['workflow_id'];
        $permit = $claim['permit'];
        try {
            $next = $this->architect->execute(
                $featureId, $workflowId, $organizationId, $correlationId,
                (int) $claim['attempt'], true,
            );
            $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
            $architectApproved = in_array(($architecture['content']['gate_status'] ?? ''), [
                'APPROVED', 'APPROVED_WITH_CONDITIONS',
            ], true) && $next->agent === AgentRole::DEVELOPER;

            if ($architectApproved) {
                // One independent Security call ONLY. Never route through the
                // general progression loop or start Developer.
                $this->specialists->reviewArchitectureOnly(
                    $featureId, $workflowId, $organizationId, $correlationId,
                );
            }
            $review = $architectApproved
                ? $this->artifacts->latest($featureId, ArtifactType::SECURITY_REVIEW_REPORT)
                : null;
            $securityApproved = $architectApproved && $review !== null
                && (($review['content']['status'] ?? '') === 'APPROVED')
                && ($review['content']['reviewed_architecture_content_hash'] ?? null) === ($architecture['content_hash'] ?? '');

            $outcome = !$architectApproved ? 'ARCHITECTURE_NOT_APPROVED'
                : ($securityApproved ? 'SECURITY_APPROVED' : 'SECURITY_FINDINGS_OPEN');
            $this->park($featureId, $workflowId, $permit, $outcome, $correlationId, [
                'architecture_artifact_id' => $architecture['id'] ?? null,
                'security_review_artifact_id' => $review['id'] ?? null,
            ]);
            return ['state' => $outcome, 'workflow_id' => $workflowId, 'executed_llm' => true];
        } catch (Throwable $error) {
            $this->park($featureId, $workflowId, $permit, 'FAILED', $correlationId, [
                'error_type' => get_debug_type($error),
                'error_hash' => hash('sha256', $error->getMessage()),
            ]);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function releaseToDeveloper(string $featureId, string $organizationId, string $actorId, string $confirmation): array
    {
        if ($confirmation !== 'RELEASE_AFTER_SECURITY_REVIEW' || !str_starts_with($actorId, 'user:')) {
            throw new RuntimeException('Explicit user confirmation is required for Developer release.');
        }
        return $this->lock->synchronized($featureId, function () use ($featureId, $organizationId, $actorId): array {
            $feature = $this->features->view($featureId);
            if (($feature['organization_id'] ?? null) !== $organizationId) throw new RuntimeException('Organization mismatch.');
            $permit = $this->lastPermit($feature);
            if (($permit['state'] ?? '') !== 'FINISHED' || ($permit['outcome'] ?? '') !== 'SECURITY_APPROVED') {
                throw new RuntimeException('Independent Security approval for this targeted recovery is not recorded.');
            }
            $workflowId = $this->workflows->activeIdForFeature($featureId);
            if ($workflowId !== ($permit['workflow_id'] ?? null)) throw new RuntimeException('Workflow changed.');
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::BLOCKED) {
                throw new RuntimeException('Release is only permitted from parked BLOCKED state.');
            }
            $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
            $review = $this->artifacts->latest($featureId, ArtifactType::SECURITY_REVIEW_REPORT);
            if ($architecture === null || $review === null
                || !in_array(($architecture['content']['gate_status'] ?? ''), ['APPROVED','APPROVED_WITH_CONDITIONS'], true)
                || !(($review['content']['status'] ?? '') === 'APPROVED')
                || ($review['content']['reviewed_architecture_content_hash'] ?? null) !== ($architecture['content_hash'] ?? null)
                || ($review['id'] ?? null) !== ($permit['security_review_artifact_id'] ?? null)
                || $this->humanDecisions->openForFeature($featureId) !== []) {
                throw new RuntimeException('Independent Security review is stale or no longer authorizes release.');
            }
            $directive = $this->coordinator->releaseAfterTargetedSecurityReview(
                $workflow, $actorId, (string) ($permit['id'] ?? ''),
            );
            foreach ($directive->transitions as $transition) $this->workflows->saveTransition($workflow, $transition);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            $this->recordPermit($featureId, $permit, 'RELEASED', ['released_by' => $actorId]);
            // General execution budget remains enforced by ContinueService.
            return ['workflow_id' => $workflowId, 'state' => $workflow->currentState()->value];
        });
    }

    /** @param array<string,mixed> $permit @param array<string,mixed> $details */
    private function park(string $featureId, string $workflowId, array $permit, string $outcome, string $trace, array $details): void
    {
        $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $permit, $outcome, $trace, $details): void {
            $workflow = $this->workflows->get($workflowId);
            if (in_array($workflow->currentState(), [
                EngineeringWorkflowState::ARCHITECTURE_PENDING,
                EngineeringWorkflowState::DEVELOPMENT_RUNNING,
            ], true)) {
                $directive = $this->coordinator->parkTargetedSecurityRecovery($workflow, $outcome);
                foreach ($directive->transitions as $transition) $this->workflows->saveTransition($workflow, $transition);
                $this->features->updateStatus($featureId, $workflow->currentState()->value);
            }
            $this->recordPermit($featureId, $permit, 'FINISHED', ['outcome' => $outcome] + $details);
            $this->journal->event($featureId, $workflowId, 'MANAGER', 'security_recovery.finished', 'COMPLETED',
                'One-shot security recovery parked without automatic Developer execution.', $trace,
                ['permit_id' => $permit['id'], 'outcome' => $outcome] + $details);
        });
    }

    /** @param array<string,mixed> $permit @param array<string,mixed> $changes */
    private function recordPermit(string $featureId, array $permit, string $state, array $changes): void
    {
        $this->features->appendPreviousContext($featureId, [
            'security_recovery' => array_replace($permit, $changes, [
                'state' => $state,
                'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]),
        ]);
    }

    /** @param array<string,mixed> $feature @return array<string,mixed>|null */
    private function lastPermit(array $feature): ?array
    {
        $history = $feature['previous_context'] ?? [];
        if (!is_array($history)) return null;
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $record = $history[$i] ?? null;
            if (is_array($record) && is_array($record['security_recovery'] ?? null)) {
                return $record['security_recovery'];
            }
        }
        return null;
    }
}
