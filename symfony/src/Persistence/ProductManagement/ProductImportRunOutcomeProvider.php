<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Independent, run-bound native Product evidence. Never trusts a completed
 * Workflow flag or mutable CSV receipt rows without rereading Product/Variant.
 */
final readonly class ProductImportRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(
        private Connection $db,
        private ProductManagementService $products,
        private DoctrineWorkflowActionReceiptVerifier $receipts,
        private ?ProductGovernedRunOutcomeProvider $assemblyEvidence=null,
    ) {}

    public function domain():string{return 'product_management';}
    public function supports(string $criterionId):bool{
        return $criterionId===ProductImportPlanProposalService::CRITERION;
    }

    public function observe(string $organizationId,string $criterionId,
        DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        throw new FederationOutcomeSourceNotReady('Product CSV outcome needs an attested Workflow Run.');
    }

    public function observeRun(TenantContext $actor,string $runId,string $criterionId,
        DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)
            throw new DomainException('Invalid Product CSV outcome time or criterion.');
        $org=$actor->organizationId()->value();
        $row=$this->db->fetchAssociative(
            'SELECT w.input_digest,w.source_plan_id,w.state,w.actor_id,s.step_id,s.state AS step_state,
                    s.result_reference,p.plan_json
             FROM cos_workflow_runs w
             INNER JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
             INNER JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
             WHERE w.organization_id=:org AND w.run_id=:run
               AND w.definition_id=:definition AND s.capability_id=:cap',[
             'org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan',
             'cap'=>ProductImportPlanProposalService::CAPABILITY,
        ]);
        if(!$row||$row['state']!=='completed'||$row['step_state']!=='completed'
            ||!is_string($row['result_reference']??null)) {
            throw new FederationOutcomeSourceNotReady('Product CSV has no completed attested Action Step.');
        }
        $proof=$this->receipts->attestForAudit(
            new WorkflowRunIdentity($org,$runId),(string)$row['step_id'],
            (string)$row['result_reference'],(string)$row['input_digest']);
        if(!$proof->verified)throw new DomainException('Product CSV Action receipt failed independent verification.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $steps=$plan['steps']??null;
        if(!is_array($steps)||count($steps)!==1
            ||($steps[0]['capability_id']??null)!==ProductImportPlanProposalService::CAPABILITY) {
            throw new DomainException('Product CSV Plan has unknown or multiple business writers.');
        }
        $input=$steps[0]['input']??null;
        if(!is_array($input)||($input['target_type']??null)!=='product_import_batch'
            ||!is_string($input['target_id']??null)
            ||!preg_match('/^[a-f0-9]{32}$/D',$input['target_id'])) {
            throw new DomainException('Product CSV approved Plan input differs from Action binding.');
        }
        $batchId=$input['target_id'];
        $batch=$this->db->fetchAssociative(
            'SELECT state,goal_id,plan_id,digest,rows_digest,action_key FROM pm_import_batches
             WHERE organization_id=:org AND batch_id=:batch AND plan_id=:plan',
            ['org'=>$org,'batch'=>$batchId,'plan'=>$row['source_plan_id']]);
        if(!$batch||$batch['state']!=='applied'||!is_string($batch['action_key'])
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',$batch['action_key'])) {
            throw new FederationOutcomeSourceNotReady('CSV batch has no applied, run-linked canonical Action.');
        }
        if(!hash_equals((string)$batch['digest'],(string)($input['parameters']['batch_digest']??''))
            ||!hash_equals((string)$batch['rows_digest'],(string)($input['parameters']['rows_digest']??''))) {
            throw new DomainException('Product CSV batch digest differs from approved pinned Plan.');
        }
        $items=$this->db->fetchAllAssociative(
            'SELECT row_no,sku,payload_json,status,product_id,receipt_key,
                    staged_hash,staged_status,staged_error_code
             FROM pm_import_rows WHERE organization_id=:org AND batch_id=:batch ORDER BY row_no',
            ['org'=>$org,'batch'=>$batchId]);
        if(count($items)<1||count($items)>5000)throw new DomainException('Invalid approved native Product cardinality.');
        $hashes=[];$evidence=[];
        foreach($items as $idx=>$item) {
            $no=(int)$item['row_no'];
            if($no!==$idx+1||$item['staged_status']!=='ready'||$item['status']!=='applied'
                ||!is_string($item['sku'])||!is_string($item['staged_hash'])
                ||!is_string($item['product_id'])||!preg_match('/^[a-f0-9]{32}$/D',$item['product_id'])){
                throw new FederationOutcomeSourceNotReady('Partial or invalid native Product CSV outcome.');
            }
            $payload=json_decode((string)$item['payload_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||array_is_list($payload)) {
                throw new DomainException('Product CSV evidence payload is malformed.');
            }
            $hash=CsvProductImportService::rowDigest($no,$item['sku'],$payload,
                $item['staged_status'],$item['staged_error_code']);
            if(!hash_equals($item['staged_hash'],$hash)) {
                throw new DomainException('Product CSV approved row fingerprint drift.');
            }
            $hashes[]=$hash;
            $key='pm-csv:'.hash('sha256',$org.':'.$batchId.':'.$no);
            if($item['receipt_key']!==$key) {
                throw new DomainException('Product CSV row receipt differs from approved writer.');
            }
            $native=$this->products->getProduct($org,$item['product_id']);
            if(!is_array($native)||($native['product_id']??null)!==$item['product_id']
                ||$native['name']!==($payload['name']??null)
                ||$native['product_type']!==($payload['type']??null)
                ||$native['status']==='archived') {
                throw new DomainException('Native Product no longer confirms approved CSV content.');
            }
            $found=false;
            foreach($native['variants']??[] as $variant) {
                if(($variant['sku']??null)===$item['sku']){$found=true;break;}
            }
            if(!$found)throw new DomainException('Native Variant SKU differs from approved CSV.');
            if(isset($payload['assembly'])){
                $receipt=$this->db->fetchAssociative('SELECT operation,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
                if($this->assemblyEvidence===null||$receipt===false||$receipt['operation']!=='product.assemble'||!is_string($receipt['result_json']))
                    throw new DomainException('Extended CSV native assembly receipt unavailable.');
                $nativeReceipt=json_decode($receipt['result_json'],true,64,JSON_THROW_ON_ERROR);
                if(($nativeReceipt['product_id']??null)!==$item['product_id'])throw new DomainException('Extended CSV assembly identity mismatch.');
                $this->assemblyEvidence->verifyAssembly($org,$payload['assembly'],$key,$row['actor_id'],$nativeReceipt);
            }
            $evidence[]='product_management:product:'.$item['product_id'];
        }
        if(!hash_equals((string)$batch['rows_digest'],hash('sha256',implode("\\n",$hashes)))
            ||count(array_unique($evidence))!==count($evidence)) {
            throw new DomainException('Product CSV independently verified rowset differs from approved digest.');
        }
        return [
            'value'=>count($evidence),'evidence'=>$evidence,
            'source'=>'product_management.native_products.approved_csv.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId,
        ];
    }
}
