<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Reserves a zero-side-effect Goal step. Never invokes an Action or Domain handler.
 * Only independent Domain-owned evidence can later checkpoint this claim.
 */
final readonly class FederationNonActionClaimAdmission implements WorkflowStepClaimAdmissionInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowPlanApprovalEvidenceInterface $plans,
    ) {}

    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void
    {
        $command=$claim->command;
        $identity=$command->identity;
        if (!$command->actor->isManager() || !$command->actor->allows(TenantPermissions::MANAGE)
            || $command->actor->organizationId()->value()!==$identity->organizationId
            || $step->stepId!==$claim->stepId || $step->state!=='pending'
            || $step->sideEffectLevel!=='none' || $step->attempts!==0) {
            throw new DomainException('Non-Action claim is not an authorized pending Goal step.');
        }
        $run=$this->db->fetchAssociative(
            'SELECT definition_id,definition_json,definition_digest,input_digest,
                    source_plan_id,source_plan_digest,approval_reference,
                    actor_id,correlation_id,state,revision
             FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run FOR UPDATE',
            ['org'=>$identity->organizationId,'run'=>$identity->runId],
        );
        if (!$run || $run['definition_id']!=='federation.approved-plan'
            || $run['state']!=='running'
            || (int)$run['revision']!==$command->expectedRevision
            || $run['actor_id']!==$command->actor->userId()->value()
            || $run['correlation_id']!==$command->correlationId
            || !is_string($run['source_plan_id'])
            || !is_string($run['source_plan_digest'])
            || !is_string($run['approval_reference'])) {
            throw new DomainException('Non-Action claim has no active approved Goal Run.');
        }
        $definition=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($definition) || !array_is_list($definition)
            || !hash_equals((string)$run['definition_digest'],WorkflowDefinitionFingerprint::digest($definition))) {
            throw new DomainException('Non-Action immutable definition changed.');
        }
        $matches=array_values(array_filter($definition,static fn($s):bool=>
            is_array($s) && ($s['id']??null)===$claim->stepId));
        if (count($matches)!==1 || ($matches[0]['capability_id']??null)!==$step->capabilityId
            || ($matches[0]['capability_version']??null)!==$step->capabilityVersion
            || ($matches[0]['side_effect_level']??null)!=='none'
            || !is_string($matches[0]['execution_binding']??null)
            || !str_starts_with($matches[0]['execution_binding'],'read:')
            || ($matches[0]['evidence']['input_digest']??null)!==$run['input_digest']
            || ($matches[0]['evidence']['run_id']??null)!==$identity->runId) {
            throw new DomainException('Non-Action claim does not match pinned evidence binding.');
        }
        $this->plans->assertTrusted(
            $identity,$run['source_plan_id'],$run['source_plan_digest'],$run['approval_reference']);
        $key=$step->idempotencyKey;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/',$key)
            || $key!==hash('sha256',$identity->organizationId."\0".$identity->runId."\0".$step->stepId)
            || (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM cos_actions WHERE organization_id=:org
                 AND idempotency_key IN (:workflow,:legacy)',
                ['org'=>$identity->organizationId,'workflow'=>'wf:'.$key,'legacy'=>'fed:'.$key])!==0) {
            throw new DomainException('Non-Action claim conflicts with existing Action identity.');
        }
    }
}
