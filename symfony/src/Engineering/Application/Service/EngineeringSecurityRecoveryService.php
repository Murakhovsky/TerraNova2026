<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Observability\EngineeringExecutionJournal;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowEngine;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowTransitionContext;
use RuntimeException;
use Throwable;

/**
 * Two explicit operator approvals: prepare a bounded one-shot Architect/Security
 * review; release normal workflow only after independently reviewed approval.
 * Never schedules an autonomous loop during recovery.
 */
final readonly class EngineeringSecurityRecoveryService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringArchitectStageExecutor $architect,
        private EngineeringSpecialistStageExecutor $specialists,
        private EngineeringExecutionJournal $journal,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringWorkflowEngine $engine = new EngineeringWorkflowEngine(),
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $featureId, string $organizationId): array
    {
        $feature = $this->ownedFeature($featureId, $organizationId);
        $workflowId = $this->workflows->activeIdForFeature($featureId);
        if ($workflowId === null) throw new RuntimeException('Security recovery requires an existing workflow.');
        $workflow = $this->workflows->get($workflowId);
        $last = self::latestCheckpoint($feature);
        $report = $this->artifacts->latestIncludingSuperseded($featureId, ArtifactType::SECURITY_REVIEW_REPORT);
        $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $revision = $this->repository->available() ? $this->repository->currentBaseRevision() : 'unknown';
        $findings = (new EngineeringArchitectSecurityRecoveryContext())->fromArtifact($report, $revision);
        $gate = strtoupper(trim((string) (
            $architecture['content']['gate_status']
            ?? $architecture['content']['status']
            ?? ''
        )));
        $running = array_values(array_filter(
            $this->agentRuns->forFeature($featureId),
            static fn (array $run): bool => ($run['status'] ?? null) === 'RUNNING',
        ));
        $same = $last !== null
            && ($last['source_review_artifact_id'] ?? null) === ($report['id'] ?? null);
        $history = is_array($feature['previous_context'] ?? null) ? $feature['previous_context'] : [];
        $cycles = count(array_filter($history, static fn (mixed $entry): bool =>
            is_array($entry) && ($entry['security_recovery']['state'] ?? null) === 'PREPARED'
        ));
        $initial = $workflow->currentState() === EngineeringWorkflowState::BLOCKED
            && $gate === 'REJECTED';
        $rework = $workflow->currentState() === EngineeringWorkflowState::ARCHITECTURE_PENDING
            && ($last['state'] ?? null) === 'SECURITY_REWORK_REQUIRED'
            && in_array($gate, ['APPROVED','APPROVED_WITH_CONDITIONS'], true);
        $eligible = ($initial || $rework)
            && $cycles < 2
            && $findings !== null
            && $running === []
            && !$same
            && $revision !== '' && $revision !== 'unknown';
        return [
            'eligible' => $eligible,
            'feature_id' => $featureId,
            'workflow_id' => $workflowId,
            'state' => $workflow->currentState()->value,
            'architecture_gate' => $gate,
            'recovery_cycles_used' => $cycles,
            'recovery_cycles_max' => 2,
            'repository_revision' => $revision,
            'source_review_artifact_id' => $report['id'] ?? null,
            'starting_architecture_artifact_id' => $architecture['id'] ?? null,
            'finding_ids' => $findings['finding_ids'] ?? [],
            'source_review_status' => $findings['source_status'] ?? null,
            'revision_binding' => $findings['revision_binding'] ?? null,
            'checkpoint' => $last,
            'reason' => $eligible ? 'READY_TO_PREPARE'
                : ($cycles >= 2 ? 'RECOVERY_CYCLE_LIMIT_REACHED'
                : ($same ? 'RECOVERY_ALREADY_ATTEMPTED_FOR_THIS_REVIEW_AND_ARCHITECTURE'
                : ($running !== [] ? 'AGENT_RUN_STILL_RUNNING'
                : ($gate !== 'REJECTED' ? 'ARCHITECTURE_IS_NOT_REJECTED'
                : ($findings === null ? 'NO_UNRESOLVED_SECURITY_FINDINGS'
                : ($revision === 'unknown' ? 'REPOSITORY_REVISION_UNAVAILABLE' : 'STATE_NOT_RECOVERABLE')))))),
        ];
    }

    /** @return array<string,mixed> */
    public function prepare(string $featureId, string $organizationId, string $operatorId): array
    {
        if (trim($operatorId) === '') throw new RuntimeException('Recovery requires an authenticated operator.');
        return $this->lock->synchronized($featureId, function () use ($featureId, $organizationId, $operatorId): array {
            $preview = $this->preview($featureId, $organizationId);
            if (!$preview['eligible']) {
                throw new RuntimeException('Recovery not eligible: '.$preview['reason']);
            }
            $workflow = $this->workflows->get($preview['workflow_id']);
            $recoveryId = EngineeringId::generate();
            if ($workflow->currentState() === EngineeringWorkflowState::BLOCKED) {
                $transition = $this->engine->transition(
                    $workflow,
                    EngineeringWorkflowState::ARCHITECTURE_PENDING,
                    new WorkflowTransitionContext(
                        trigger: 'HUMAN_PREPARED_TARGETED_SECURITY_RECOVERY',
                        reason: 'Prepare precisely one Architect execution and one independent Security re-review.',
                        initiatedByType: 'HUMAN',
                        initiatedById: $operatorId,
                        metadata: [
                            'recovery_id' => $recoveryId,
                            'review_artifact_id' => $preview['source_review_artifact_id'],
                            'finding_ids' => $preview['finding_ids'],
                            'revision' => $preview['repository_revision'],
                        ],
                    ),
                );
                $this->workflows->saveTransition($workflow, $transition);
            }
            // A second, explicitly authorized cycle may already be in
            // ARCHITECTURE_PENDING after the previous Security REQUEST_CHANGES.
            // An audited checkpoint replaces an invalid self-transition.
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            $checkpoint = [
                'recovery_id' => $recoveryId,
                'workflow_id' => $preview['workflow_id'],
                'state' => 'PREPARED',
                'source_review_artifact_id' => $preview['source_review_artifact_id'],
                'starting_architecture_artifact_id' => $preview['starting_architecture_artifact_id'],
                'finding_ids' => $preview['finding_ids'],
                'repository_revision' => $preview['repository_revision'],
                'operator_id' => $operatorId,
            ];
            $this->checkpoint($featureId, $preview['workflow_id'], $checkpoint);
            $this->workflows->markRuntimeIssue(
                $preview['workflow_id'], 'STALLED',
                'Security recovery is operator-controlled; generic queue and Continue must not execute it.',
            );
            return $checkpoint;
        });
    }

    /**
     * Persist a unique dispatch reservation before posting the Messenger job.
     * Duplicate clicks fail closed before another queue message is emitted.
     * @return array<string,mixed>
     */
    public function enqueue(string $featureId, string $organizationId, string $recoveryId): array
    {
        return $this->lock->synchronized($featureId, function () use ($featureId, $organizationId, $recoveryId): array {
            $last = self::latestCheckpoint($this->ownedFeature($featureId, $organizationId));
            if ($last === null || ($last['recovery_id'] ?? null) !== $recoveryId
                || ($last['state'] ?? null) !== 'PREPARED') {
                throw new RuntimeException('Recovery was already queued or has changed.');
            }
            $workflow = $this->workflows->get((string) $last['workflow_id']);
            if ($workflow->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) {
                throw new RuntimeException('Recovery workflow is not at the Architect stage.');
            }
            return $this->checkpoint($featureId, (string) $last['workflow_id'],
                array_replace($last, ['state' => 'QUEUED']));
        });
    }

    /**
     * Runs in the dedicated immediate worker. One Architect logical attempt,
     * then at most one independent Security Specialist review; no Developer.
     * @return array<string,mixed>
     */
    public function executeOnce(string $featureId, string $organizationId, string $recoveryId): array
    {
        $checkpoint = $this->lock->synchronized(
            $featureId,
            function () use ($featureId, $organizationId, $recoveryId): ?array {
                $feature = $this->ownedFeature($featureId, $organizationId);
                $last = self::latestCheckpoint($feature);
                if ($last === null || $last['recovery_id'] !== $recoveryId) {
                    throw new RuntimeException('Recovery does not match the authorized checkpoint.');
                }
                if ($last['state'] !== 'QUEUED') return null; // Duplicate message, no second LLM.
                $workflow = $this->workflows->get($last['workflow_id']);
                if ($workflow->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) {
                    throw new RuntimeException('Targeted recovery Architect stage is no longer pending.');
                }
                foreach ($this->agentRuns->forFeature($featureId) as $run) {
                    if (($run['status'] ?? null) === 'RUNNING') throw new RuntimeException('AgentRun is still running.');
                }
                return $this->checkpoint($featureId, $last['workflow_id'], array_replace($last, ['state' => 'RUNNING']));
            },
        );

        if ($checkpoint === null) return ['state' => 'DUPLICATE_IGNORED', 'executed_llm' => false];
        $workflowId = (string) $checkpoint['workflow_id'];
        $correlation = 'engineering:security-recovery:'.$recoveryId;
        try {
            $attempts = $this->agentRuns->forFeature($featureId);
            $architectCount = count(array_filter($attempts, static fn (array $run): bool =>
                ($run['role'] ?? null) === AgentRole::PRINCIPAL_ARCHITECT->value));
            $next = $this->architect->execute(
                $featureId, $workflowId, $organizationId, $correlation, $architectCount + 1,
            );

            // Release no generic workflow queue between the Architect result and
            // the independent Security review. Auto continuation is also fenced.
            $this->workflows->markRuntimeIssue($workflowId, 'STALLED',
                'Targeted recovery: awaiting independent Security Specialist review.');
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING
                || $next->type !== WorkflowDirectiveType::RUN_AGENT
                || $next->agent !== AgentRole::DEVELOPER) {
                return $this->lock->synchronized($featureId, fn (): array =>
                    $this->checkpoint($featureId, $workflowId, array_replace($checkpoint, ['state' => 'ARCHITECT_NOT_APPROVED'])));
            }

            $this->lock->synchronized($featureId, fn (): array =>
                $this->checkpoint($featureId, $workflowId, array_replace($checkpoint, [
                    'state' => 'SECURITY_REVIEW',
                ])));
            $this->specialists->executeRole(
                $featureId, $workflowId, $organizationId, $correlation,
                AgentRole::SECURITY_SPECIALIST, 'TARGETED_RECOVERY',
            );

            $review = $this->artifacts->latest($featureId, ArtifactType::SECURITY_REVIEW_REPORT);
            $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
            $reviewStatus = strtoupper((string) ($review['content']['status'] ?? ''));
            $approved = $reviewStatus === 'APPROVED'
                && ($review['content']['reviewed_architecture_content_hash'] ?? null) === ($architecture['content_hash'] ?? null)
                && $this->workflows->get($workflowId)->currentState() === EngineeringWorkflowState::DEVELOPMENT_RUNNING;

            return $this->lock->synchronized(
                $featureId,
                function () use ($featureId, $workflowId, $checkpoint, $approved, $review): array {
                    $this->workflows->markRuntimeIssue($workflowId, 'STALLED',
                        'Security recovery completed; explicit operator release required before Developer.');
                    return $this->checkpoint($featureId, $workflowId, array_replace($checkpoint, [
                        'state' => $approved ? 'SECURITY_APPROVED_AWAITING_RELEASE' : 'SECURITY_REWORK_REQUIRED',
                        'security_review_artifact_id' => $review['id'] ?? null,
                    ]));
                },
            );
        } catch (Throwable $e) {
            $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $checkpoint, $e): void {
                $this->checkpoint($featureId, $workflowId, array_replace($checkpoint, [
                    'state' => 'FAILED',
                    'failure_class' => $e::class,
                ]));
                $this->workflows->markRuntimeIssue($workflowId, 'STALLED',
                    'Targeted Security recovery failed; operator inspection required.');
            });
            throw $e;
        }
    }

    /**
     * Second human action: independent Security approved the exact architecture.
     * Restores the ordinary queue with a finite recovery run budget.
     * @return array<string,mixed>
     */
    public function release(string $featureId, string $organizationId, string $operatorId): array
    {
        if (trim($operatorId) === '') throw new RuntimeException('Recovery release requires an operator.');
        return $this->lock->synchronized($featureId, function () use ($featureId, $organizationId, $operatorId): array {
            $last = self::latestCheckpoint($this->ownedFeature($featureId, $organizationId));
            if ($last === null || $last['state'] !== 'SECURITY_APPROVED_AWAITING_RELEASE') {
                throw new RuntimeException('Independent Security approval has not been verified for recovery release.');
            }
            $workflowId = (string) $last['workflow_id'];
            $workflow = $this->workflows->get($workflowId);
            $review = $this->artifacts->latest($featureId, ArtifactType::SECURITY_REVIEW_REPORT);
            $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
            if ($workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING
                || strtoupper((string) ($review['content']['status'] ?? '')) !== 'APPROVED'
                || ($review['content']['reviewed_architecture_content_hash'] ?? null) !== ($architecture['content_hash'] ?? null)) {
                throw new RuntimeException('Independent Security approval no longer matches the current architecture.');
            }
            foreach ($this->agentRuns->forFeature($featureId) as $run) {
                if (($run['status'] ?? null) === 'RUNNING') throw new RuntimeException('AgentRun is still running.');
            }
            $out = $this->checkpoint($featureId, $workflowId, array_replace($last, [
                'state' => 'RELEASED',
                'released_by' => $operatorId,
                'release_run_baseline' => count($this->agentRuns->forFeature($featureId)),
                'release_run_allowance' => 8,
            ]));
            $this->workflows->markImmediate($workflowId);
            return $out;
        });
    }

    /** @param array<string,mixed> $feature @return array<string,mixed>|null */
    public static function latestCheckpoint(array $feature): ?array
    {
        $history = is_array($feature['previous_context'] ?? null) ? $feature['previous_context'] : [];
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (is_array($history[$i] ?? null) && is_array($history[$i]['security_recovery'] ?? null)) {
                return $history[$i]['security_recovery'];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function ownedFeature(string $featureId, string $organizationId): array
    {
        $feature = $this->features->view($featureId);
        if (($feature['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering feature does not belong to the current organization.');
        }
        return $feature;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function checkpoint(string $featureId, string $workflowId, array $entry): array
    {
        $entry['updated_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->features->appendPreviousContext($featureId, ['security_recovery' => $entry]);
        $this->journal->event(
            $featureId, $workflowId, 'MANAGER', 'engineering.security_recovery_checkpoint',
            $entry['state'], 'Targeted security recovery checkpoint persisted.',
            'engineering:security-recovery:'.$entry['recovery_id'],
            [
                'recovery_id' => $entry['recovery_id'],
                'state' => $entry['state'],
                'finding_ids' => $entry['finding_ids'],
                'source_review_artifact_id' => $entry['source_review_artifact_id'],
            ],
        );
        return $entry;
    }
}
