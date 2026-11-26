<?php
declare(strict_types=1);
namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Automation\Action\ShopifyDraftWritebackHandler;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyDraftWritebackService;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

final readonly class ShopifyDraftRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(private Connection $db,private ShopifyDraftWritebackService $writer,
        private DoctrineWorkflowActionReceiptVerifier $receipts) {}
    public function domain():string{return 'product_management';}
    public function supports(string $criterionId):bool{return $criterionId===ShopifyDraftPlanProposalService::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Shopify draft requires approved Workflow Run.');}
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid Shopify outcome criterion.');
        $org=$actor->organizationId()->value();
        $row=$this->db->fetchAssociative('SELECT w.input_digest,w.state,s.step_id,s.state AS step_state,s.idempotency_key,s.result_reference,p.plan_json
          FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
          JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
          WHERE w.organization_id=? AND w.run_id=? AND w.definition_id=? AND s.capability_id=?',
          [$org,$runId,'federation.approved-plan',ShopifyDraftWritebackHandler::TYPE]);
        if(!$row||$row['state']!=='completed'||$row['step_state']!=='completed'||!is_string($row['result_reference']))
            throw new FederationOutcomeSourceNotReady('No completed Shopify draft Action.');
        if(!$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$row['step_id'],$row['result_reference'],$row['input_digest'])->verified)
            throw new DomainException('Unverified canonical Action receipt.');
        $steps=json_decode($row['plan_json'],true,flags:JSON_THROW_ON_ERROR)['steps']??[];
        if(count($steps)!==1||($steps[0]['capability_id']??null)!==ShopifyDraftWritebackHandler::TYPE)
            throw new DomainException('One pinned Shopify Action required.');
        $in=$steps[0]['input'];$product=$in['target_id'];$conn=$in['parameters']['connection_id'];$digest=$in['parameters']['payload_digest'];
        $claim=$this->db->fetchAssociative('SELECT action_key,payload_hash,state,updated_at FROM pm_shopify_outbound_receipts WHERE organization_id=? AND connection_id=? AND product_id=?',[$org,$conn,$product]);
        if(!$claim||$claim['state']!=='verified'||$claim['action_key']!=='wf:'.$row['idempotency_key']||$claim['payload_hash']!==$digest)
            throw new FederationOutcomeSourceNotReady('No verified outbound claim for this Action.');
        $audit=$this->writer->audit($org,$conn,$product);
        if(($audit['independent_verified']??false)!==true)throw new FederationOutcomeSourceNotReady('Independent Shopify readback not verified.');
        return ['value'=>1,'evidence'=>['product_management:product:'.$product],
            'source'=>'product_management.shopify.draft_readback.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
