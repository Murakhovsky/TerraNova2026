<?php
declare(strict_types=1);

namespace App\Web\Operations\Read;

use App\Web\Operations\OperationStatus;

/**
 * SQL candidate filter, never an outcome attestation. The typed projection
 * always rechecks the exact status after reading. Native completed Workflow
 * remains a candidate for BOTH completion statuses until receipt attestation.
 */
final class OperationStatusPredicate
{
    /** @return array{sql:string,value:string} */
    public static function forSource(string $source,OperationStatus $status):array
    {
        $native = 'LOWER(history.native_status)';
        $generic = "CASE
            WHEN $native IN ('created','queued','pending') THEN 'queued'
            WHEN $native IN ('running','claimed') THEN 'running'
            WHEN $native IN ('proposed','waiting','blocked') THEN 'blocked'
            WHEN $native IN ('pending_approval','waiting_approval') THEN 'waiting_approval'
            WHEN $native IN ('completed','succeeded') THEN 'completed_unverified'
            WHEN $native IN ('ambiguous','external_outcome_unknown') THEN 'external_outcome_unknown'
            WHEN $native='reconciliation_required' THEN 'reconciliation_required'
            WHEN $native IN ('failed','dead') THEN 'failed'
            WHEN $native IN ('cancelled','rejected') THEN 'cancelled'
            ELSE 'blocked' END";
        $expression = match ($source) {
            'action' => $generic,
            'workflow' => "CASE
                WHEN $native IN ('ambiguous','external_outcome_unknown') THEN 'external_outcome_unknown'
                WHEN history.uncertain_steps>0 THEN 'reconciliation_required'
                ELSE ($generic) END",
            'approval' => "CASE
                WHEN $native='pending' AND history.action_status='PENDING_APPROVAL'
                    AND (history.expires_at IS NULL OR history.expires_at>UTC_TIMESTAMP(6)) THEN 'waiting_approval'
                WHEN $native='approved' THEN 'completed_unverified'
                WHEN $native IN ('rejected','cancelled') THEN 'cancelled'
                ELSE 'blocked' END",
            'import' => "CASE
                WHEN $native='applying' THEN 'running'
                WHEN $native='applied' THEN 'completed_unverified'
                ELSE 'blocked' END",
            // Keep these in sync with MysqlAgentRunReadModel/MysqlAsyncOperationReadModel:
            // typed projection maps INVALID_OUTPUT to failed and retry-scheduled FAILED to queued.
            'agent' => "CASE
                WHEN TRIM($native)='running' THEN 'running'
                WHEN TRIM($native)='completed' THEN 'completed_unverified'
                WHEN TRIM($native) IN ('failed','invalid_output') THEN 'failed'
                WHEN TRIM($native)='cancelled' THEN 'cancelled'
                WHEN TRIM($native)='waiting' THEN 'blocked'
                ELSE 'queued' END",
            'async' => "CASE
                WHEN $native='running' THEN 'running'
                WHEN $native='completed' THEN 'completed_unverified'
                WHEN $native='dead' THEN 'failed'
                WHEN $native='cancelled' THEN 'cancelled'
                ELSE 'queued' END",
            default => throw new \InvalidArgumentException('Unsupported operation source.'),
        };
        $value=$status->value;
        if ($source==='workflow' && $status===OperationStatus::Verified) $value=OperationStatus::Unverified->value;
        return ['sql'=>'('.$expression.')=:projected_status','value'=>$value];
    }
}
