<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Model\WorkflowReceiptAttestation;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Receipt authority delegates to canonical Action proof or independent Domain fact.
 * No synthetic receipt or state mutation.
 */
final readonly class WorkflowReceiptAttestationRouter implements WorkflowReceiptVerifierInterface
{
    public function __construct(
        private Connection $db,
        private DoctrineWorkflowActionReceiptVerifier $actions,
        private WorkflowNonActionReceiptEvidenceInterface $nonActions,
        private WorkflowPlanApprovalEvidenceInterface $plans,
    ) {}

    public function attest(WorkflowReceiptRequest $request): WorkflowReceiptAttestation
    {
        $id=$request->identity;
        $ctx=$this->db->fetchAssociative(
            'SELECT r.definition_id,r.definition_json,r.definition_digest,r.input_digest,
                    r.source_plan_id,r.source_plan_digest,r.approval_reference,r.correlation_id,
                    s.side_effect_level,s.capability_id,s.capability_version,s.idempotency_key,
                    s.state,s.result_reference
             FROM cos_workflow_runs r
             INNER JOIN cos_workflow_steps s ON s.organization_id=r.organization_id AND s.run_id=r.run_id
             WHERE r.organization_id=:org AND r.run_id=:run AND s.step_id=:step',
            ['org'=>$id->organizationId,'run'=>$id->runId,'step'=>$request->stepId],
        );
        if (!$ctx || $ctx['input_digest']!==$request->inputDigest) {
            throw new DomainException('Workflow receipt scope differs from pinned Run.');
        }
        if ($ctx['side_effect_level']==='external') return $this->actions->attest($request);
        if ($ctx['side_effect_level']!=='none' || $ctx['definition_id']!=='federation.approved-plan'
            || !in_array($ctx['state'],['claimed','completed'],true)
            || ($ctx['result_reference']!==null
                && $ctx['result_reference']!==$request->resultReference)) {
            throw new DomainException('Non-Action receipt cannot attest this Workflow Step.');
        }
        $graph=json_decode((string)$ctx['definition_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($graph) || !array_is_list($graph)
            || WorkflowDefinitionFingerprint::digest($graph)!==$ctx['definition_digest']) {
            throw new DomainException('Non-Action receipt lacks immutable Workflow definition.');
        }
        $matching=array_values(array_filter($graph,static fn($s):bool=>
            is_array($s)&&($s['id']??null)===$request->stepId));
        $pinned=$matching[0]??null;
        if (count($matching)!==1 || !is_array($pinned)
            || ($pinned['capability_id']??null)!==$ctx['capability_id']
            || ($pinned['capability_version']??null)!==$ctx['capability_version']
            || ($pinned['side_effect_level']??null)!=='none'
            || $ctx['idempotency_key']!==hash('sha256',
                $id->organizationId."\0".$id->runId."\0".$request->stepId)) {
            throw new DomainException('Non-Action receipt is not tied to the pinned Step.');
        }
        $this->plans->assertTrusted($id,(string)$ctx['source_plan_id'],
            (string)$ctx['source_plan_digest'],(string)$ctx['approval_reference']);
        if ((int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_actions
             WHERE organization_id=:org AND idempotency_key IN (:canonical,:legacy)',
            ['org'=>$id->organizationId,'canonical'=>'wf:'.$ctx['idempotency_key'],
                'legacy'=>'fed:'.$ctx['idempotency_key']])!==0) {
            throw new DomainException('Non-Action receipt is contaminated by an Action attempt.');
        }
        $this->nonActions->assertVerified($id,$request->stepId,$request->resultReference,
            $request->inputDigest,$pinned);
        return new WorkflowReceiptAttestation($id,$request->stepId,true,'domain-owned-non-action',
            $request->resultReference,(string)$ctx['correlation_id']);
    }
}
