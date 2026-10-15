<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Agent\EngineeringTechnicalExecutionAdmissionInterface;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Model\WorkflowParityStatus;

/**
 * Verified last-moment, read-only pre-provider admission.
 *
 * Mode "off" runs the existing legacy AgentRun after verifying ownership and
 * excluding any active canonical link. Mode "canonical" requires a human
 * allowlist and exact real-MySQL parity of the claimed canonical Run/Step.
 * This does NOT write AgentRuns, switch modes or replay external operations.
 */
final readonly class EngineeringCanonicalTechnicalExecutionAdmission implements EngineeringTechnicalExecutionAdmissionInterface
{
    public function __construct(
        private Connection $db,
        private DoctrineWorkflowShadowParityReader $shadow,
    ) {}

    public function assertReady(EngineeringAgentTask $task, string $organizationId, string $runTraceId): void
    {
        if ($organizationId === '' || $runTraceId === '') {
            throw new DomainException('Engineering Agent invocation requires tenant and trace.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT a.id,a.status,a.trace_id,a.idempotency_key,a.agent_role,
                    a.workflow_execution_id,w.status AS workflow_status,
                    f.organization_id,rollout.mode,rollout.approved_by,rollout.approved_at,
                    l.technical_state,l.canonical_run_id
             FROM cos_engineering_agent_runs a
             JOIN cos_engineering_features f ON f.id=a.feature_id
             JOIN cos_engineering_workflows w
               ON w.id=a.workflow_execution_id AND w.feature_id=a.feature_id
             LEFT JOIN cos_engineering_technical_rollouts rollout
               ON rollout.organization_id=f.organization_id AND rollout.feature_id=f.id
             LEFT JOIN cos_engineering_technical_links l
               ON l.organization_id=f.organization_id AND l.agent_run_id=a.id
             WHERE a.idempotency_key=:key AND a.feature_id=:feature
               AND a.agent_role=:role AND f.organization_id=:org',
            ['key'=>$task->idempotencyKey,'feature'=>$task->featureId,
                'role'=>$task->role->value,'org'=>$organizationId],
        );
        if (!$row || $row['status']!=='RUNNING'
            || $row['trace_id']!==$runTraceId
            || !in_array($row['workflow_status'],['RUNNING','WAITING'],true)) {
            throw new DomainException('Engineering Agent provider has no active tenant-owned AgentRun and trace.');
        }
        $mode=$row['mode']??'off';
        if ($mode==='off') {
            if ($row['canonical_run_id']!==null) {
                throw new DomainException('Canonical Engineering claim exists after rollout was disabled.');
            }
            return;
        }
        if ($mode!=='canonical'
            || !is_string($row['approved_by']) || trim($row['approved_by'])===''
            || $row['approved_at']===null
            || $row['technical_state']!=='claimed'
            || !is_string($row['canonical_run_id']) || $row['canonical_run_id']==='') {
            throw new DomainException('Engineering canonical provider invocation lacks human approval or fenced technical claim.');
        }
        $report=$this->shadow->engineeringAgent($organizationId,(string)$row['id']);
        if ($report->status !== WorkflowParityStatus::MATCH) {
            throw new DomainException(
                'Engineering canonical provider invocation denied by real shadow parity: ' .
                implode(',', $report->differences)
            );
        }
    }
}
