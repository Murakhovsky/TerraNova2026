<?php
declare(strict_types=1);

namespace App\Web\Operations\Read;

use Doctrine\DBAL\Connection;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/** Exact, tenant-bound presentation candidates. The canonical writer remains the admission authority. */
final readonly class RecoveryCandidateRead
{
    public function __construct(private Connection $db) {}

    public function forRun(string $org, string $run, int $revision): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT definition_id,definition_json,definition_digest,state,revision
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$run],
        );
        if (!$row || (int)$row['revision'] !== $revision || !in_array($row['state'], ['running','waiting','ambiguous'], true)) return [];
        $graph = json_decode((string)$row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($graph) || !array_is_list($graph)
            || !hash_equals((string)$row['definition_digest'], WorkflowDefinitionFingerprint::digest($graph))) return [];
        $pinned = [];
        foreach ($graph as $definition) {
            if (!is_array($definition) || !is_string($definition['id'] ?? null) || isset($pinned[$definition['id']])) return [];
            $pinned[$definition['id']] = $definition;
        }
        $kernel = in_array($row['definition_id'], ['kernel.agent_execution','kernel.tool_execution'], true);
        $steps = $this->db->fetchAllAssociative(
            "SELECT s.step_id,s.capability_id,s.capability_version,s.side_effect_level,s.state,s.revision,s.attempts,
                    (s.result_reference IS NOT NULL) AS has_receipt,
                    EXISTS(SELECT 1 FROM cos_actions a WHERE a.organization_id=s.organization_id
                        AND a.idempotency_key=CONCAT('wf:',s.idempotency_key)) AS has_action,
                    EXISTS(SELECT 1 FROM cos_workflow_recovery_decisions d
                        WHERE d.organization_id=s.organization_id AND d.run_id=s.run_id AND d.step_id=s.step_id
                        AND d.run_revision=:runrev AND d.step_revision=s.revision
                        AND d.status IN ('REQUESTED','APPROVED')) AS active_request
             FROM cos_workflow_steps s JOIN cos_workflow_runs w
                  ON w.organization_id=s.organization_id AND w.run_id=s.run_id
             WHERE s.organization_id=:org AND s.run_id=:run AND w.revision=:expected
               AND (s.state IN ('ambiguous','waiting') OR (s.state='claimed' AND s.claim_expires_at<=UTC_TIMESTAMP(6)))
             ORDER BY s.ordinal_no",
            ['org'=>$org,'run'=>$run,'runrev'=>$revision,'expected'=>$revision],
        );
        $result = [];
        foreach ($steps as $step) {
            $def = $pinned[$step['step_id']] ?? null;
            if (!is_array($def) || ($def['capability_id'] ?? null) !== $step['capability_id']
                || ($def['capability_version'] ?? null) !== $step['capability_version']
                || ($def['side_effect_level'] ?? null) !== $step['side_effect_level']) continue;
            if ($kernel && ($step['step_id'] !== 'execute' || (int)$step['attempts'] !== 1
                || $step['state'] === 'waiting' || $row['state'] === 'waiting')) continue;
            $modes = ['verified_complete'];
            // Only a pinned read-only binding with no receipt or native Action may be offered a reset.
            // The canonical writer rechecks ALL invariants including Action evidence and prior receipts.
            if (!$kernel && $step['side_effect_level'] === 'none' && !(bool)$step['has_receipt']
                && !(bool)$step['has_action'] && is_string($def['execution_binding'] ?? null)
                && str_starts_with($def['execution_binding'], 'read:')) $modes[] = 'reset_read_only';
            $result[$step['step_id']] = ['revision'=>(int)$step['revision'],'modes'=>$modes,'active'=>(bool)$step['active_request']];
        }
        return $result;
    }

    /** Active decisions at the exact Run revision, independent of the history window. */
    public function activeRequests(string $org,string $run,int $revision):array
    {
        $rows=$this->db->fetchAllAssociative(
            "SELECT request_id,step_id,run_revision,step_revision,resolution,receipt_reference,reason,
                    requested_by,approved_by,status
             FROM cos_workflow_recovery_decisions WHERE organization_id=:org AND run_id=:run
              AND run_revision=:revision AND status IN ('REQUESTED','APPROVED')
             ORDER BY requested_at,request_id",
            ['org'=>$org,'run'=>$run,'revision'=>$revision],
        );
        return array_map(static fn(array $r):array=>[
            'id'=>$r['request_id'],'step'=>$r['step_id'],'resolution'=>$r['resolution'],'status'=>$r['status'],
            'runRevision'=>(int)$r['run_revision'],'stepRevision'=>(int)$r['step_revision'],
            'requestedBy'=>(string)$r['requested_by'],'approvedBy'=>(string)($r['approved_by']??''),
            'receipt'=>$r['receipt_reference'],'reason'=>$r['reason'],
        ],$rows);
    }
}
