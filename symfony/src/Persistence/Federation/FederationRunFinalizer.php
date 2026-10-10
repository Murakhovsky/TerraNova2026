<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;

/**
 * Finishes a multi-step Federation Run only after every durable Step receipt
 * is verified. This changes no Domain business state and does not evaluate Goal
 * success. External receipts are re-attested against canonical Action/Policy/
 * Approval, immutable Plan input, tenant and exactly one successful attempt.
 */
final readonly class FederationRunFinalizer
{
    public function __construct(
        private Connection $db,
        private FederatedActionAdmission $admission,
    ) {}

    /** @return array{run_id:string,state:string,revision:int,steps:int} */
    public function finalize(TenantContext $actor, string $runId, int $expectedRevision): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $runId)
            || $expectedRevision < 1) {
            throw new DomainException('Unauthorized or invalid Federation finalization request.');
        }
        $org = $actor->organizationId()->value();
        return $this->db->transactional(function () use ($org, $runId, $expectedRevision): array {
            $run = $this->db->fetchAssociative(
                'SELECT state, revision FROM cos_federation_runs
                 WHERE organization_id = :org AND run_id = :run FOR UPDATE',
                ['org' => $org, 'run' => $runId],
            );
            if (!$run || $run['state'] !== 'running'
                || (int) $run['revision'] !== $expectedRevision) {
                throw new LogicException('Run is not running or the expected revision is stale.');
            }
            $steps = $this->db->fetchAllAssociative(
                'SELECT step_id, side_effect_level, state, idempotency_key, result_reference
                 FROM cos_federation_steps WHERE organization_id = :org AND run_id = :run
                 ORDER BY step_id FOR UPDATE',
                ['org' => $org, 'run' => $runId],
            );
            if ($steps === []) {
                throw new DomainException('An empty Federation Run cannot be completed.');
            }
            foreach ($steps as $step) {
                if ($step['state'] !== 'completed'
                    || !is_string($step['result_reference'])
                    || $step['result_reference'] === '') {
                    throw new DomainException('All Federation steps must have completed receipts.');
                }
                if ($step['side_effect_level'] === 'none') {
                    continue;
                }
                if ($step['side_effect_level'] !== 'external'
                    || !preg_match('/^action:([a-f0-9]{32})$/', $step['result_reference'], $m)) {
                    throw new DomainException('Unverifiable side-effect receipt in Federation Run.');
                }
                $action = $this->db->fetchAssociative(
                    'SELECT id, type, target_type, target_id, parameters, source_type, source_id,
                            execution_mode, risk_level, idempotency_key, correlation_id, status
                     FROM cos_actions WHERE organization_id = :org AND id = :id
                       AND idempotency_key = :key FOR UPDATE',
                    ['org' => $org, 'id' => $m[1], 'key' => 'fed:' . $step['idempotency_key']],
                );
                if (!$action || $action['status'] !== 'COMPLETED') {
                    throw new DomainException('Federation external receipt has no matching completed Action.');
                }
                $parameters = json_decode((string) $action['parameters'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parameters)) {
                    throw new DomainException('Federation external Action has malformed parameters.');
                }
                $completed = new Action(
                    (string) $action['id'], $org, (string) $action['type'],
                    $action['target_type'] !== null ? (string) $action['target_type'] : null,
                    $action['target_id'] !== null ? (string) $action['target_id'] : null,
                    $parameters, (string) $action['source_type'], (string) $action['source_id'],
                    (string) $action['execution_mode'], (string) $action['risk_level'],
                    (string) $action['idempotency_key'], new DateTimeImmutable(),
                    ActionStatus::Completed, (string) $action['correlation_id'],
                );
                $this->admission->assertCompletedReceipt($completed);
            }
            $updated = $this->db->executeStatement(
                'UPDATE cos_federation_runs
                 SET state = :completed, revision = revision + 1, updated_at = NOW(6)
                 WHERE organization_id = :org AND run_id = :run
                   AND state = :running AND revision = :revision',
                ['completed' => 'completed', 'org' => $org, 'run' => $runId,
                    'running' => 'running', 'revision' => $expectedRevision],
            );
            if ($updated !== 1) {
                throw new LogicException('Concurrent Federation finalization was rejected.');
            }
            return ['run_id' => $runId, 'state' => 'completed',
                'revision' => $expectedRevision + 1, 'steps' => count($steps)];
        });
    }
}
