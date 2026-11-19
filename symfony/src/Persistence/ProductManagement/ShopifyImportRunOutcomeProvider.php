<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportApplyService;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Attests one canonical Shopify Action Step AND independently re-reads native
 * Product, every Variant/Price, mapping, and immutable step receipts.
 */
final readonly class ShopifyImportRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(
        private Connection $db,
        private ShopifyImportApplyService $imports,
        private DoctrineWorkflowActionReceiptVerifier $receipts,
    ) {}

    public function domain():string{return 'product_management';}
    public function supports(string $criterionId):bool{
        return $criterionId===ShopifyImportPlanProposalService::CRITERION;
    }
    public function observe(string $organizationId,string $criterionId,
        DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        throw new FederationOutcomeSourceNotReady('Shopify import outcome requires a canonical Workflow Run.');
    }

    public function observeRun(TenantContext $actor,string $runId,string $criterionId,
        DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from) {
            throw new DomainException('Invalid Shopify import outcome criterion or time.');
        }
        $org=$actor->organizationId()->value();
        $row=$this->db->fetchAssociative(
            'SELECT w.input_digest,w.source_plan_id,w.state,s.step_id,s.state AS step_state,
                    s.result_reference,p.plan_json
             FROM cos_workflow_runs w
             INNER JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
             INNER JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
             WHERE w.organization_id=:org AND w.run_id=:run
               AND w.definition_id=:definition AND s.capability_id=:cap',
            ['org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan',
             'cap'=>ShopifyImportPlanProposalService::CAPABILITY]);
        if(!$row||$row['state']!=='completed'||$row['step_state']!=='completed'
            ||!is_string($row['result_reference']??null)) {
            throw new FederationOutcomeSourceNotReady('Shopify import lacks a completed approved Action Step.');
        }
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),
            (string)$row['step_id'],(string)$row['result_reference'],(string)$row['input_digest']);
        if(!$proof->verified)throw new DomainException('Shopify import Action receipt not independently attested.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $steps=$plan['steps']??null;
        if(!is_array($steps)||count($steps)!==1
            ||($steps[0]['capability_id']??null)!==ShopifyImportPlanProposalService::CAPABILITY) {
            throw new DomainException('Shopify import Plan must have exactly one canonical writer.');
        }
        $input=$steps[0]['input']??null;
        if(!is_array($input)||($input['target_type']??null)!=='shopify_import_preview'
            ||!is_string($input['target_id']??null)
            ||!preg_match('/^[a-f0-9]{32}$/D',$input['target_id'])) {
            throw new DomainException('Shopify import Plan target differs from approved Action.');
        }
        $previewId=$input['target_id'];
        $preview=$this->db->fetchAssociative(
            'SELECT plan_id,goal_id,state,action_key FROM pm_shopify_import_previews
             WHERE organization_id=:org AND preview_id=:id AND plan_id=:plan',
            ['org'=>$org,'id'=>$previewId,'plan'=>$row['source_plan_id']]);
        if(!$preview||$preview['state']!=='applied'
            ||!is_string($preview['action_key'])
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',$preview['action_key'])) {
            throw new FederationOutcomeSourceNotReady('Shopify import has no approved applied native state.');
        }
        $hash=$input['parameters']['source_hash']??null;
        $digest=$input['parameters']['normalized_digest']??null;
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)
            ||!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)) {
            throw new DomainException('Shopify import Plan omitted pinned source evidence.');
        }
        $result=$this->imports->verifyApplied($org,$previewId,$hash,$digest);
        $variants=(int)$result['verified_variants'];
        $evidence=array_map(static fn(string $id):string=>'product_management:variant:'.$id,
            $result['variant_ids']);
        if($variants<1||$variants!==count($evidence)
            ||count(array_unique($evidence))!==$variants) {
            throw new DomainException('Shopify import independent Variant count changed.');
        }
        return ['value'=>$variants,'evidence'=>$evidence,
            'source'=>'product_management.native_shopify.approved_import.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
