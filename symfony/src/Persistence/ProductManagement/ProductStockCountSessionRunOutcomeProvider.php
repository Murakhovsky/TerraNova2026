<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Attest the exact approved native count and immutable correction ledger, not today's mutable balance. */
final readonly class ProductStockCountSessionRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(private Connection $db,private ProductManagementService $products,private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'product_management';}
    public function supports(string $criterionId):bool{return $criterionId===ProductStockCountSessionPlanProposalService::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Stock count requires an attested Run.');}
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid stock count criterion or window.');
        $org=$actor->organizationId()->value();
        $row=$this->db->fetchAssociative('SELECT w.input_digest,w.state,s.step_id,s.state AS step_state,s.result_reference,s.idempotency_key,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=? AND w.run_id=? AND w.definition_id=? AND s.capability_id=?',
            [$org,$runId,'federation.approved-plan',ProductStockCountSessionPlanProposalService::CAPABILITY]);
        if(!$row||$row['state']!=='completed'||$row['step_state']!=='completed'||!is_string($row['result_reference']))
            throw new FederationOutcomeSourceNotReady('No completed approved stock count Action.');
        if(!$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),(string)$row['step_id'],(string)$row['result_reference'],(string)$row['input_digest'])->verified)
            throw new DomainException('Stock count Action attestation failed.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $steps=$plan['steps']??[];$input=$steps[0]['input']??[];$p=$input['parameters']??[];
        if(count($steps)!==1||($steps[0]['capability_id']??null)!==ProductStockCountSessionPlanProposalService::CAPABILITY||($input['target_type']??null)!=='product_stock_count_session')
            throw new DomainException('Unexpected stock count Plan shape.');
        $session=$this->products->countSession($org,(string)($input['target_id']??''));
        if($session===null)throw new FederationOutcomeSourceNotReady('Native count session not found.');
        $rows=ProductManagementService::countSessionRows($p['rows']??[]);
        if($session['location_id']!==($p['location_id']??null)||$session['source_ref']!==($p['source_ref']??null)
            ||$session['action_key']!=='wf:'.$row['idempotency_key']||$session['row_count']!==count($rows)||count($session['rows'])!==count($rows))
            throw new DomainException('Session approval identity or cardinality drift.');
        $evidence=['product_management:stock_count_session:'.$session['session_id']];
        foreach($rows as $i=>$expected){
            $count=$session['rows'][$i];
            $key='wf:'.hash('sha256',$session['action_key'].':'.$expected['variant_id']);
            $id=substr(hash('sha256',$session['session_id'].':'.$expected['variant_id']),0,32);
            if($count['count_id']!==$id||$count['action_key']!==$key||$count['variant_id']!==$expected['variant_id']
                ||$count['location_id']!==$session['location_id']||$count['source_ref']!==$session['source_ref']
                ||$count['counted']!==$expected['counted']||$count['expected_revision']!==$expected['expected_revision']
                ||$count['result_revision']!==$expected['expected_revision']+1||$count['actor_ref']!==$session['actor_ref'])
                throw new DomainException('Native session line differs from approval.');
            $ledger=$this->db->fetchAssociative('SELECT movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref FROM pm_stock_movements WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
            $evidence[]='product_management:stock_count:'.$id;
            if($count['delta']==='0.000000'){
                if($ledger!==false||$count['movement_id']!==null)throw new DomainException('Zero discrepancy must not create movement.');
            }else{
                if(!$ledger||$ledger['movement_id']!==$count['movement_id']||$ledger['variant_id']!==$count['variant_id']
                    ||$ledger['location_id']!==$count['location_id']||$ledger['quantity_delta']!==$count['delta']
                    ||$ledger['movement_type']!=='adjustment'||$ledger['source_ref']!==$count['source_ref'])
                    throw new DomainException('Session ledger evidence differs from count.');
                $evidence[]='product_management:stock_movement:'.$ledger['movement_id'];
            }
        }
        return ['value'=>count($rows),'evidence'=>$evidence,'source'=>'product_management.approved_stock_count_session.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
