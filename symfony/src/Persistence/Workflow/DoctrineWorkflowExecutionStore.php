<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\StepStatus;
use Kernel\Workflow\Model\WorkflowStatus;
use LogicException;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepCheckpoint;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\DurableWorkflowTransitionPolicy;
use Platform\Workflow\Service\WorkflowDependencyGraph;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Service\WorkflowStepScheduler;

/**
 * FIRST DURABLE FOUNDATION, not a production execution switch.
 * No Action dispatch, Agent execution, automatic recovery or dual writes.
 * A run row serializes every transition/claim/checkpoint for its tenant.
 */
final readonly class DoctrineWorkflowExecutionStore implements WorkflowExecutionStoreInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowReceiptVerifierInterface $receipts,
        private WorkflowStepClaimAdmissionInterface $admission,
    ) {}

    public function create(WorkflowRunDraft $draft, TenantContext $actor): WorkflowRunSnapshot
    {
        self::authorized($actor, $draft->identity);
        if ($draft->actorId !== $actor->userId()->value()) {
            throw new DomainException('Workflow creator and authenticated actor differ.');
        }
        if ($draft->sourcePlanId !== null && $draft->approvalReference === null) {
            throw new DomainException('Workflow Plan reference requires an approval reference.');
        }
        $definition = $draft->definition;
        if ($definition === null || $definition->steps === []) {
            throw new DomainException('Durable Run requires a pinned executable definition.');
        }
        $graph = (new WorkflowDependencyGraph())->resolve($definition->steps);
        foreach ($definition->steps as $step) {
            foreach (['capability_id','capability_version','side_effect_level'] as $field) {
                if (!is_string($step[$field] ?? null) || $step[$field] === '') {
                    throw new DomainException('Workflow definition lacks executable binding: ' . $field);
                }
            }
            if (!in_array($step['side_effect_level'], ['external','none'], true)) {
                throw new DomainException('Workflow definition has unsupported side-effect class.');
            }
        }
        // A pure dry-run selection detects inconsistent topology before persistence.
        if (count($graph) !== count($definition->steps)) {
            throw new DomainException('Workflow definition topology validation failed.');
        }
        $serialized = json_encode($definition->steps, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $this->db->transactional(function () use ($draft, $serialized, $definition): WorkflowRunSnapshot {
            $id = $draft->identity;
            $now = self::now();
            $this->db->insert('cos_workflow_runs', [
                'organization_id' => $id->organizationId, 'run_id' => $id->runId,
                'definition_id' => $draft->definitionId, 'definition_version' => $draft->definitionVersion,
                'definition_json' => $serialized, 'definition_digest' => WorkflowDefinitionFingerprint::digest($definition->steps),
                'input_digest' => $draft->inputDigest, 'actor_id' => $draft->actorId,
                'correlation_id' => $draft->correlationId, 'state' => WorkflowStatus::CREATED->value,
                'revision' => 1, 'source_plan_id' => $draft->sourcePlanId,
                'source_plan_digest' => $draft->sourcePlanDigest, 'approval_reference' => $draft->approvalReference,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($definition->steps as $ordinal => $step) {
                $this->db->insert('cos_workflow_steps', [
                    'organization_id' => $id->organizationId, 'run_id' => $id->runId,
                    'step_id' => $step['id'], 'ordinal_no' => $ordinal,
                    'capability_id' => $step['capability_id'], 'capability_version' => $step['capability_version'],
                    'side_effect_level' => $step['side_effect_level'],
                    'state' => StepStatus::PENDING->value, 'revision' => 1, 'attempts' => 0,
                    'idempotency_key' => hash('sha256', $id->organizationId . "\0" . $id->runId . "\0" . $step['id']),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return $this->mustGet($id);
        });
    }

    public function get(WorkflowRunIdentity $identity): ?WorkflowRunSnapshot
    {
        // Internal read API. External/controller callers must authorize the tenant first.
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            self::keys($identity),
        );
        return $row ? self::runSnapshot($row) : null;
    }

    /** @return list<WorkflowStepSnapshot> */
    public function steps(WorkflowRunIdentity $identity): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no',
            self::keys($identity),
        );
        return array_map(static fn(array $row): WorkflowStepSnapshot => self::stepSnapshot($row), $rows);
    }

    public function compareAndTransition(WorkflowRunTransition $transition): WorkflowRunSnapshot
    {
        $command = $transition->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($transition, $command): WorkflowRunSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run, $command->expectedRevision, $transition->expectedState);
            DurableWorkflowTransitionPolicy::assertTransition($transition->expectedState->value, $transition->nextState->value);
            if ($transition->nextState === WorkflowStatus::COMPLETED) {
                $rows = $this->lockedStepRows($command->identity);
                if ($rows === []) throw new DomainException('Empty Workflow cannot finalize.');
                foreach ($rows as $row) {
                    if ($row['state'] !== StepStatus::COMPLETED->value
                        || !is_string($row['result_reference']) || $row['result_reference'] === '') {
                        throw new DomainException('Workflow cannot finalize without completed verified steps.');
                    }
                    $this->assertReceipt($command->actor, $command->identity, $run, $row);
                }
            }
            $count = $this->db->executeStatement(
                'UPDATE cos_workflow_runs SET state=:next, revision=revision+1, updated_at=:now
                  WHERE organization_id=:org AND run_id=:run AND revision=:revision AND state=:expected',
                ['next' => $transition->nextState->value, 'now' => self::now(),
                    ...self::keys($command->identity), 'revision' => $command->expectedRevision,
                    'expected' => $transition->expectedState->value],
            );
            if ($count !== 1) throw new LogicException('Stale Workflow Run transition.');
            return $this->mustGet($command->identity);
        });
    }

    public function claim(WorkflowStepClaim $claim): WorkflowStepSnapshot
    {
        $command = $claim->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($claim, $command): WorkflowStepSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run, $command->expectedRevision, WorkflowStatus::RUNNING);
            $rows = $this->lockedStepRows($command->identity);
            $plan = json_decode((string) $run['definition_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($plan) || !array_is_list($plan)
                || !hash_equals((string) $run['definition_digest'],
                    WorkflowDefinitionFingerprint::digest($plan))) {
                throw new DomainException('Pinned Workflow definition has been modified.');
            }
            $byId = [];
            foreach ($rows as $row) $byId[$row['step_id']] = $row;
            $selection = (new WorkflowStepScheduler())->select(
                new WorkflowDefinitionSnapshot((string) $run['definition_id'], (string) $run['definition_version'], $plan),
                array_map(static fn(array $row): WorkflowStepSnapshot => self::stepSnapshot($row), $rows),
                ['none','external'],
            );
            if ($selection->state->value !== 'pending' || $selection->stepId !== $claim->stepId) {
                throw new DomainException('Workflow claim bypasses the immutable dependency order or active claim.');
            }
            $row = $byId[$claim->stepId];
            // Production's composite admission explicitly guards BOTH Action
            // and evidence-only claims. Historical isolated Store callers using
            // a custom admission retain their narrow fixture semantics.
            if ($row['side_effect_level'] === 'external'
                || ($row['side_effect_level'] === 'none'
                    && $this->admission instanceof WorkflowClaimAdmissionRouter)) {
                $this->admission->assertAuthorized($claim, self::stepSnapshot($row));
            }
            $token = bin2hex(random_bytes(16));
            $changed = $this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state=:next, attempts=attempts+1, revision=revision+1,
                        claim_token=:token, claimed_by=:worker,
                        claim_expires_at=DATE_ADD(NOW(6), INTERVAL :lease SECOND), updated_at=NOW(6)
                  WHERE organization_id=:org AND run_id=:run AND step_id=:step AND state=:pending AND revision=:step_revision',
                ['next' => StepStatus::CLAIMED->value, 'token' => $token,
                    'worker' => $claim->workerId, 'lease' => $claim->leaseSeconds,
                    ...self::keys($command->identity), 'step' => $claim->stepId,
                    'pending' => StepStatus::PENDING->value, 'step_revision' => (int) $row['revision']],
            );
            if ($changed !== 1) throw new LogicException('Stale Workflow Step claim.');
            $this->advanceRunRevision($command->identity, $command->expectedRevision);
            $fresh = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run AND step_id=:step',
                [...self::keys($command->identity), 'step' => $claim->stepId],
            );
            if (!$fresh) throw new LogicException('Claimed Step disappeared.');
            return self::stepSnapshot($fresh, true);
        });
    }

    public function checkpoint(WorkflowStepCheckpoint $checkpoint): WorkflowStepSnapshot
    {
        $command = $checkpoint->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($checkpoint, $command): WorkflowStepSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run, $command->expectedRevision, WorkflowStatus::RUNNING);
            $row = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run AND step_id=:step FOR UPDATE',
                [...self::keys($command->identity), 'step' => $checkpoint->stepId],
            );
            if (!$row || $row['state'] !== StepStatus::CLAIMED->value
                || !is_string($row['claim_token'])
                || !hash_equals($row['claim_token'], $checkpoint->claimToken)) {
                throw new DomainException('Invalid or stale Workflow Step claim token.');
            }
            if ($row['claim_expires_at'] === null
                || strtotime((string) $row['claim_expires_at'] . ' UTC') <= time()) {
                throw new DomainException('Expired Workflow claim requires reconciliation, not automatic completion.');
            }
            DurableWorkflowTransitionPolicy::assertTransition(
                StepStatus::CLAIMED->value, $checkpoint->nextState->value, true);
            if ($checkpoint->nextState === StepStatus::COMPLETED) {
                if ($checkpoint->resultReference === null || $checkpoint->resultReference === '') {
                    throw new DomainException('Completed Workflow Step must provide an independently attested receipt.');
                }
                if ($row['side_effect_level'] === 'none') {
                    // Verify the independently persisted fact against the
                    // exact checkpoint time, not the old claim timestamp.
                    // Both timestamp update and receipt attestation happen
                    // inside ONE transaction: failure rolls back the timestamp,
                    // retains the claimed Step and cannot invent completion.
                    $changed=$this->db->executeStatement(
                        'UPDATE cos_workflow_steps SET updated_at=NOW(6)
                         WHERE organization_id=:org AND run_id=:run AND step_id=:step
                           AND state=:state AND claim_token=:token',
                        [...self::keys($command->identity),'step'=>$checkpoint->stepId,
                            'state'=>StepStatus::CLAIMED->value,'token'=>$checkpoint->claimToken],
                    );
                    if ($changed!==1) throw new DomainException('Stale evidence-only Step claim.');
                }
                $this->assertReceipt(
                    $command->actor, $command->identity, $run,
                    ['step_id' => $checkpoint->stepId, 'result_reference' => $checkpoint->resultReference],
                );
            }
            $changed = $this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state=:next, revision=revision+1,
                    result_reference=:receipt, claim_token=NULL, claimed_by=NULL,
                    claim_expires_at=NULL, updated_at=NOW(6)
                  WHERE organization_id=:org AND run_id=:run AND step_id=:step
                    AND state=:claimed AND claim_token=:token AND revision=:step_revision',
                ['next' => $checkpoint->nextState->value, 'receipt' => $checkpoint->resultReference,
                    ...self::keys($command->identity), 'step' => $checkpoint->stepId,
                    'claimed' => StepStatus::CLAIMED->value, 'token' => $checkpoint->claimToken,
                    'step_revision' => (int) $row['revision']],
            );
            if ($changed !== 1) throw new LogicException('Stale Workflow checkpoint.');
            $this->advanceRunRevision($command->identity, $command->expectedRevision);
            $fresh = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run AND step_id=:step',
                [...self::keys($command->identity), 'step' => $checkpoint->stepId],
            );
            if (!$fresh) throw new LogicException('Checkpointed Workflow Step disappeared.');
            return self::stepSnapshot($fresh);
        });
    }

    /** @param array<string,mixed> $row */
    private function assertReceipt(TenantContext $actor, WorkflowRunIdentity $identity, array $run, array $row): void
    {
        $reference = $row['result_reference'] ?? null;
        if (!is_string($reference) || $reference === '') {
            throw new DomainException('Workflow receipt reference is absent.');
        }
        $proof = $this->receipts->attest(new WorkflowReceiptRequest(
            $identity, $actor, (string) $row['step_id'], $reference, (string) $run['input_digest'],
        ));
        if (!$proof->verified || $proof->identity != $identity
            || $proof->stepId !== $row['step_id'] || $proof->evidenceReference === null) {
            throw new DomainException('Workflow receipt could not be independently verified.');
        }
    }

    /** @return array<string,mixed> */
    private function lockedRun(WorkflowRunIdentity $identity): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run FOR UPDATE',
            self::keys($identity),
        );
        if (!$row) throw new DomainException('Workflow Run not found in tenant.');
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function lockedStepRows(WorkflowRunIdentity $identity): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no FOR UPDATE',
            self::keys($identity),
        );
    }

    /** @param array<string,mixed> $row */
    private static function assertRunRevision(array $row, int $revision, WorkflowStatus $status): void
    {
        if ((int) $row['revision'] !== $revision || $row['state'] !== $status->value) {
            throw new LogicException('Stale Workflow Run revision or incompatible state.');
        }
    }

    private function advanceRunRevision(WorkflowRunIdentity $identity, int $revision): void
    {
        $changed = $this->db->executeStatement(
            'UPDATE cos_workflow_runs SET revision=revision+1, updated_at=NOW(6)
              WHERE organization_id=:org AND run_id=:run AND revision=:revision AND state=:state',
            [...self::keys($identity), 'revision' => $revision, 'state' => WorkflowStatus::RUNNING->value],
        );
        if ($changed !== 1) throw new LogicException('Workflow Run claim/checkpoint revision collision.');
    }

    private function mustGet(WorkflowRunIdentity $identity): WorkflowRunSnapshot
    {
        return $this->get($identity) ?? throw new LogicException('Persisted Workflow Run missing.');
    }

    /** @param array<string,mixed> $row */
    private static function runSnapshot(array $row): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            new WorkflowRunIdentity((string) $row['organization_id'], (string) $row['run_id']),
            (string) $row['definition_id'], (string) $row['definition_version'],
            WorkflowStatus::from((string) $row['state']), (int) $row['revision'],
            (string) $row['correlation_id'],
            $row['source_plan_id'] === null ? null : (string) $row['source_plan_id'],
            $row['checkpoint_reference'] === null ? null : (string) $row['checkpoint_reference'],
            $row['source_plan_digest'] === null ? null : (string) $row['source_plan_digest'],
        );
    }

    /** @param array<string,mixed> $row */
    private static function stepSnapshot(array $row, bool $withClaimToken = false): WorkflowStepSnapshot
    {
        return new WorkflowStepSnapshot(
            (string) $row['step_id'], (string) $row['capability_id'], (string) $row['capability_version'],
            (string) $row['side_effect_level'], (string) $row['state'],
            (int) $row['attempts'], (int) $row['revision'],
            $row['result_reference'] === null ? null : (string) $row['result_reference'],
            $withClaimToken && $row['claim_token'] !== null ? (string) $row['claim_token'] : null,
            (string) $row['idempotency_key'],
        );
    }

    private static function authorized(TenantContext $actor, WorkflowRunIdentity $identity): void
    {
        if ($actor->organizationId()->value() !== $identity->organizationId
            || !$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Durable Workflow mutation requires authorized tenant manager.');
        }
    }

    /** @return array{org:string,run:string} */
    private static function keys(WorkflowRunIdentity $identity): array
    {
        return ['org' => $identity->organizationId, 'run' => $identity->runId];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
