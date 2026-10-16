<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Federation-specific business approval adapter for the shared Workflow port.
 * READ-ONLY: does not approve, start or mutate any Workflow or Action.
 */
final readonly class FederationWorkflowPlanApprovalEvidence implements WorkflowPlanApprovalEvidenceInterface
{
    public function __construct(
        private Connection $db,
        private FederationPlanApprovalEvidenceReader $legacyEvidence,
    ) {}

    public function assertTrusted(
        WorkflowRunIdentity $identity,
        string $planId,
        string $planDigest,
        string $approvalReference,
    ): void {
        if (!preg_match('/^[a-f0-9]{32}$/', $approvalReference)
            || !preg_match('/^[a-f0-9]{64}$/', $planDigest)
            || $planId === '') {
            throw new DomainException('Federation canonical Workflow Plan binding is invalid.');
        }
        $org = $identity->organizationId;
        $plan = $this->db->fetchAssociative(
            'SELECT p.goal_id,p.spec_version,p.plan_json,p.state,
                    g.owner_id,g.current_spec_version
             FROM cos_federation_plans p
             INNER JOIN cos_federation_goals g
               ON g.organization_id=p.organization_id AND g.goal_id=p.goal_id
             WHERE p.organization_id=:org AND p.plan_id=:plan',
            ['org'=>$org,'plan'=>$planId],
        );
        $run = $this->db->fetchAssociative(
            'SELECT definition_json,source_plan_id,source_plan_digest,
                    approval_reference,actor_id
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$identity->runId],
        );
        if (!$plan || !$run || $plan['state'] !== 'approved'
            || (int) $plan['spec_version'] !== (int) $plan['current_spec_version']
            || $run['source_plan_id'] !== $planId
            || $run['source_plan_digest'] !== $planDigest
            || $run['approval_reference'] !== $approvalReference
            || $run['actor_id'] !== $plan['owner_id']
            || !hash_equals($planDigest, hash('sha256', (string) $plan['plan_json']))) {
            throw new DomainException('Federation Plan/Goal/Run immutable approval provenance differs.');
        }
        $original = json_decode((string) $plan['plan_json'], true, 512, JSON_THROW_ON_ERROR);
        $canonical = json_decode((string) $run['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($original) || !is_array($original['steps'] ?? null)
            || !array_is_list($original['steps'])
            || !is_array($canonical) || !array_is_list($canonical)
            || WorkflowDefinitionFingerprint::digest($original['steps'])
                !== WorkflowDefinitionFingerprint::digest($canonical)) {
            throw new DomainException('Canonical Workflow graph does not match the approved Federation Plan.');
        }
        // Existing, battle-tested human approval and latest Policy guards
        // remain the authority, including independent human decision.
        $this->legacyEvidence->requireForOrganization(
            $org, $approvalReference, (string) $plan['goal_id'], $planId,
            (int) $plan['spec_version'], (string) $plan['plan_json'],
            (string) $plan['owner_id'], 'COMPLETED',
        );
    }
}
