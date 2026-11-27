<?php
declare(strict_types=1);

namespace App\Web\Operations\Read;

use Doctrine\DBAL\Connection;
use App\Persistence\Federation\FederationCanonicalReceiptInspector;
use Kernel\Module\ActiveModuleResolver;

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
    public function rows(string $org, string $source, int $limit, ?string $id = null): array
    {
        $limit = max(1, min(100, $limit));
        $params = ['org' => $org];
        if ($id !== null) $params['id'] = $id;
        $where = static fn(string $column): string => $id === null ? '' : ' AND '.$column.'=:id';
        $sql = match ($source) {
            'action' => 'SELECT a.id,a.type AS title,a.status AS native_status,a.target_type,a.target_id,
                    a.source_type,a.source_id,a.created_at,a.started_at,a.updated_at,a.correlation_id,a.risk_level,
                    a.id AS action_id, t.id AS receipt_id,t.status AS receipt_status,t.attempt,t.finished_at
                FROM cos_actions a LEFT JOIN cos_action_attempts t
                  ON t.organization_id=a.organization_id AND t.action_id=a.id
                  AND t.attempt=(SELECT MAX(attempt) FROM cos_action_attempts x
                  WHERE x.organization_id=a.organization_id AND x.action_id=a.id)
                WHERE a.organization_id=:org'.$where('a.id').' ORDER BY a.updated_at DESC,a.id',
            'approval' => 'SELECT p.id,a.type AS title,p.status AS native_status,a.target_type,a.target_id,
                    p.requested_by_type AS source_type,p.requested_by_id AS source_id,
                    p.created_at,p.updated_at,a.correlation_id,a.risk_level,a.id AS action_id,
                    p.id AS approval_id,p.approver_type,p.approver_id,p.expires_at,a.status AS action_status
                FROM cos_approvals p JOIN cos_actions a
                   ON a.organization_id=p.organization_id AND a.id=p.action_id
                WHERE p.organization_id=:org'.$where('p.id').' ORDER BY p.updated_at DESC,p.id',
            'workflow' => 'SELECT w.run_id AS id,w.definition_id AS title,w.state AS native_status,
                    w.actor_id AS source_id,w.correlation_id,w.created_at,w.updated_at,w.revision,
                    w.source_plan_id,p.goal_id,
                    (SELECT COUNT(*) FROM cos_workflow_steps s WHERE s.organization_id=w.organization_id
                        AND s.run_id=w.run_id) AS total_steps,
                    (SELECT COUNT(*) FROM cos_workflow_steps s WHERE s.organization_id=w.organization_id
                        AND s.run_id=w.run_id AND s.state=\'completed\') AS completed_steps,
                    (SELECT COUNT(*) FROM cos_workflow_steps s WHERE s.organization_id=w.organization_id
                        AND s.run_id=w.run_id AND (s.state=\'ambiguous\' OR
                        (s.state=\'claimed\' AND s.claim_expires_at<=NOW(6)))) AS uncertain_steps
                FROM cos_workflow_runs w LEFT JOIN cos_federation_plans p
                  ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
                WHERE w.organization_id=:org'.$where('w.run_id').' ORDER BY w.updated_at DESC,w.run_id',
            'import' => 'SELECT b.batch_id AS id,b.source AS title,b.state AS native_status,b.actor_ref AS source_id,
                    b.created_at,b.updated_at,b.plan_id AS source_plan_id,b.goal_id,
                    (SELECT COUNT(*) FROM pm_import_rows r WHERE r.organization_id=b.organization_id
                        AND r.batch_id=b.batch_id) AS total_steps,
                    (SELECT COUNT(*) FROM pm_import_rows r WHERE r.organization_id=b.organization_id
                        AND r.batch_id=b.batch_id AND r.status=\'applied\') AS completed_steps
                FROM pm_import_batches b WHERE b.organization_id=:org'.$where('b.batch_id').' ORDER BY b.updated_at DESC,b.batch_id',
            default => throw new \InvalidArgumentException('Unsupported operation source.'),
        };
        if ($source === 'import' && !$this->modules->isEnabled($org, 'product_management')) return [];
        return $this->db->fetchAllAssociative($sql.' LIMIT '.$limit, $params);
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
            'SELECT row_no,sku,status,error_code,product_id FROM pm_import_rows
             WHERE organization_id=:org AND batch_id=:id ORDER BY row_no LIMIT 100',
            ['org'=>$org,'id'=>$batch],
        );
    }
}
