<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Domain-owned Property identity resolution with human review audit.
 * An asset existence or identity score alone never counts as verified.
 */
final readonly class PropertyIdentityResolutionNonActionEvidence implements WorkflowNonActionEvidenceAdapterInterface
{
    public function __construct(private Connection $db) {}

    public function kind(): string { return 'property.identity.reviewed.v1'; }
    public function ownerDomain(): string { return 'property'; }

    public function assertVerified(
        WorkflowRunIdentity $identity, string $stepId, string $reference, array $evidence,
    ): void {
        if(!preg_match('/^property:identity:reviewed:v1:([1-9][0-9]*):([1-9][0-9]*):([a-f0-9]{64})$/D',
            $reference,$matched)) {
            throw new DomainException('Property identity review receipt format is invalid.');
        }
        $resolution=(int)$matched[1];
        $audit=(int)$matched[2];
        $asset=$evidence['asset_id']??null;
        $reviewer=$evidence['reviewer_reference']??null;
        $expectedDecision=$evidence['decision']??null;
        if ($resolution<1 || $audit<1
            || !is_string($asset) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $asset)
            || !is_string($reviewer) || $reviewer==='' || strlen($reviewer)>191
            || !is_string($expectedDecision) || $expectedDecision==='') {
            throw new DomainException('Pinned Property review evidence identity invalid.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT x.decision,x.review_status,x.resolved_asset_id,x.reviewer_reference,
                    x.resolved_at,a.action AS reviewed_action,a.resolved_asset_id AS audit_asset,
                    a.reviewer_reference AS audit_reviewer,a.created_at AS reviewed_at,
                    r.created_at AS run_started,s.updated_at AS step_completed
             FROM tn_property_identity_resolutions x
             INNER JOIN tn_property_identity_review_audit a
               ON a.organization_id=x.organization_id AND a.resolution_id=x.id
             INNER JOIN tn_property_assets asset
               ON asset.organization_id=x.organization_id AND asset.asset_id=x.resolved_asset_id
             INNER JOIN cos_workflow_runs r ON r.organization_id=x.organization_id
             INNER JOIN cos_workflow_steps s
               ON s.organization_id=r.organization_id AND s.run_id=r.run_id
             WHERE x.organization_id=:org AND x.id=:resolution AND a.id=:audit
               AND r.run_id=:run AND s.step_id=:step',
            ['org'=>$identity->organizationId,'resolution'=>$resolution,'audit'=>$audit,
             'run'=>$identity->runId,'step'=>$stepId],
        );
        $decision=$row['decision']??null;
        if (!$row || $row['review_status']!=='resolved'
            || !is_string($decision) || $decision===''
            || $decision!==$expectedDecision || $decision!==$row['reviewed_action']
            || $row['resolved_asset_id']!==$asset || $row['audit_asset']!==$asset
            || $row['reviewer_reference']!==$reviewer
            || $row['audit_reviewer']!==$reviewer
            || $row['resolved_at']===null
            || $row['resolved_at'] <= substr((string) $row['run_started'], 0, 19)
            || $row['resolved_at'] > $row['step_completed']
            || $row['reviewed_at'] <= substr((string) $row['run_started'], 0, 19)
            || $row['reviewed_at'] > $row['step_completed']) {
            throw new DomainException('Property identity was not independently resolved and reviewed in this Run.');
        }
        $digest=hash('sha256',implode("\0",[
            (string)$resolution,(string)$audit,$asset,$decision,$reviewer,
        ]));
        if(!hash_equals('property:identity:reviewed:v1:'.$resolution.':'.$audit.':'.$digest,$reference)) {
            throw new DomainException('Property identity review proof differs from authoritative audit.');
        }
    }
}
