<?php
declare(strict_types=1);

namespace App\Web\Operations\Read;

use Doctrine\DBAL\Connection;
use App\Persistence\Federation\FederationCanonicalReceiptInspector;
use Kernel\Module\ActiveModuleResolver;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Service\WorkflowDependencyGraph;

/**
 * Narrow, tenant-bound read adapter over existing authoritative execution tables.
 * No writes, raw payloads, worker leases, secrets or audit records are returned.
 * Missing read ports (global Action/Workflow/import list) are adapted here;
 * this is not a new store or a new lifecycle.
 */
final readonly class CanonicalOperationRead implements OperationListReadInterface
{
    public function __construct(
        private Connection $db,
        private FederationCanonicalReceiptInspector $receipts,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return list<array<string,mixed>> */
    public function rows(string $org, string $source, int $limit, ?string $id = null, ?array $before = null, string $correlation = '', ?\App\Web\Experience\Model\EntityRef $entity = null, ?\App\Web\Operations\OperationStatus $status = null): array
    {
        $limit = max(1, min(100, $limit));
        $params = ['org' => $org];
        if ($id !== null) $params['id'] = $id;
        $where = static fn(string $column): string => $id === null ? '' : ' AND '.$column.'=:id';
        $sql = match ($source) {
            'action' => 'SELECT a.id,a.type AS title,a.status AS native_status,a.target_type,a.target_id,
                    a.source_type,a.source_id,a.created_at,a.started_at,a.updated_at,a.correlation_id,a.risk_level,
                    a.id AS action_id, t.id AS receipt_id,t.status AS receipt_status,t.attempt,t.finished_at,(a.last_error IS NOT NULL) AS has_error
                FROM cos_actions a LEFT JOIN cos_action_attempts t
                  ON t.organization_id=a.organization_id AND t.action_id=a.id
                  AND t.attempt=(SELECT MAX(attempt) FROM cos_action_attempts x
                  WHERE x.organization_id=a.organization_id AND x.action_id=a.id)
                WHERE a.organization_id=:org'.$where('a.id'),
            'approval' => 'SELECT p.id,a.type AS title,p.status AS native_status,a.target_type,a.target_id,
                    p.requested_by_type AS source_type,p.requested_by_id AS source_id,
                    p.created_at,p.updated_at,a.correlation_id,a.risk_level,a.id AS action_id,
                    p.id AS approval_id,p.approver_type,p.approver_id,p.expires_at,p.decided_at,
                    p.decided_by_type,p.decided_by_id,a.status AS action_status
                FROM cos_approvals p JOIN cos_actions a
                   ON a.organization_id=p.organization_id AND a.id=p.action_id
                WHERE p.organization_id=:org'.$where('p.id'),
            'workflow' => 'SELECT w.run_id AS id,w.definition_id AS title,w.state AS native_status,
                    w.actor_id AS source_id,w.correlation_id,w.created_at,w.updated_at,w.revision,
                    w.source_plan_id,p.goal_id,\'goal\' AS target_type,p.goal_id AS target_id,
                    (SELECT COUNT(*) FROM cos_workflow_steps s WHERE s.organization_id=w.organization_id
                        AND s.run_id=w.run_id) AS total_steps,
                    (SELECT COUNT(*) FROM cos_workflow_steps s WHERE s.organization_id=w.organization_id
                        AND s.run_id=w.run_id AND s.state=\'completed\') AS completed_steps,
                    (SELECT COUNT(*) FROM cos_workflow_steps s WHERE s.organization_id=w.organization_id
                        AND s.run_id=w.run_id AND (s.state=\'ambiguous\' OR
                        (s.state=\'claimed\' AND s.claim_expires_at<=NOW(6)))) AS uncertain_steps
                FROM cos_workflow_runs w LEFT JOIN cos_federation_plans p
                  ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
                WHERE w.organization_id=:org'.$where('w.run_id'),
            'import' => 'SELECT b.batch_id AS id,b.source AS title,b.state AS native_status,b.actor_ref AS source_id,
                    b.created_at,b.updated_at,b.plan_id AS source_plan_id,b.goal_id,a.correlation_id,
                    a.id AS action_id,w.run_id AS linked_run,\'product_import_batch\' AS target_type,b.batch_id AS target_id,
                    (SELECT COUNT(*) FROM pm_import_rows r WHERE r.organization_id=b.organization_id
                        AND r.batch_id=b.batch_id) AS total_steps,
                    (SELECT COUNT(*) FROM pm_import_rows r WHERE r.organization_id=b.organization_id
                        AND r.batch_id=b.batch_id AND r.status=\'applied\') AS completed_steps
                FROM pm_import_batches b
                LEFT JOIN cos_actions a ON a.organization_id=b.organization_id AND a.idempotency_key=b.action_key
                    AND a.target_type=\'product_import_batch\' AND a.target_id=b.batch_id
                LEFT JOIN cos_workflow_runs w ON w.organization_id=b.organization_id AND w.source_plan_id=b.plan_id
                WHERE b.organization_id=:org'.$where('b.batch_id'),
            'agent' => 'SELECT id,created_at,correlation_id,subject_type AS target_type,subject_id AS target_id FROM cos_agent_runs
                WHERE organization_id=:org'.$where('id'),
            'async' => 'SELECT id,created_at,correlation_id FROM cos_jobs
                WHERE organization_id=:org'.$where('id'),
            default => throw new \InvalidArgumentException('Unsupported operation source.'),
        };
        if ($source === 'import' && !$this->modules->isEnabled($org, 'product_management')) return [];
        // Immutable creation ordering keeps pages stable as lifecycle states change.
        // Import correlation is available only through its exact native Action key/target.
        $sql = 'SELECT history.* FROM ('.$sql.') history WHERE 1=1';
        if ($correlation !== '') {
            $sql .= ' AND history.correlation_id=:correlation';
            $params['correlation'] = $correlation;
        }
        if ($entity !== null && $source !== 'async') {
            if ($source === 'import' && $entity->type === 'goal') {
                $sql .= ' AND history.goal_id=:entity_id';
            } else {
                $sql .= ' AND LOWER(history.target_type)=:entity_type AND history.target_id=:entity_id';
                $params['entity_type'] = $entity->type;
            }
            $params['entity_id'] = $entity->id;
        }
        // Prioritize actionable approvals across the full history, not just a recent slice.
        // Other status predicates remain post-projection because verification and retry
        // semantics are not implied by the source flag.
        if ($status === \App\Web\Operations\OperationStatus::WaitingApproval) {
            if ($source === 'approval') {
                $sql .= " AND history.native_status='PENDING' AND history.action_status='PENDING_APPROVAL'
                          AND (history.expires_at IS NULL OR history.expires_at>UTC_TIMESTAMP(6))";
            } elseif ($source === 'action') {
                $sql .= " AND history.native_status='PENDING_APPROVAL'";
            } else {
                return [];
            }
        }
        if ($before !== null && $id === null) {
            $sql .= ' AND (history.created_at < :before_date OR (history.created_at = :same_date AND history.id < :before_id))';
            $params['before_date'] = $params['same_date'] = $before['date'];
            $params['before_id'] = $before['id'];
        }
        return $this->db->fetchAllAssociative($sql.' ORDER BY history.created_at DESC,history.id DESC LIMIT '.$limit, $params);
    }

    /** The pinned graph is validated before any prerequisite is shown. */
    public function workflowGraph(string $org, string $run): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT definition_json,definition_digest FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:id',
            ['org'=>$org,'id'=>$run],
        );
        if (!$row) throw new \DomainException('Run not found.');
        $steps = json_decode((string)$row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($steps) || !array_is_list($steps)
            || !hash_equals((string)$row['definition_digest'], WorkflowDefinitionFingerprint::digest($steps))) {
            throw new \DomainException('Invalid pinned graph.');
        }
        return (new WorkflowDependencyGraph())->resolve($steps);
    }

    public function actionAttempts(string $org, string $action): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id,attempt,status,started_at,finished_at,(error IS NOT NULL) AS has_error FROM cos_action_attempts
             WHERE organization_id=:org AND action_id=:id ORDER BY attempt DESC LIMIT 101',
            ['org'=>$org,'id'=>$action],
        );
    }

    /** Existing independent attestation, not inferred from a completed flag. */
    public function workflowProof(string $org, string $run): array
    {
        return $this->receipts->inspect($org, $run);
    }

    /** @return list<array<string,mixed>> */
    public function importItems(string $org, string $batch): array
    {
        if (!$this->modules->isEnabled($org, 'product_management')) return [];
        return $this->db->fetchAllAssociative(
            'SELECT row_no,sku,status,error_code,product_id,receipt_key FROM pm_import_rows
             WHERE organization_id=:org AND batch_id=:id ORDER BY row_no LIMIT 100',
            ['org'=>$org,'id'=>$batch],
        );
    }
}
