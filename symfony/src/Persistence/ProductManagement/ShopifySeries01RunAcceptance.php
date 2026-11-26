<?php
declare(strict_types=1);
namespace App\Persistence\ProductManagement;

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifySeries01NativeAudit;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Supervised read-only S2 acceptance: all nine canonical identities are
 * attested by the exact approved CSV Workflow Action, not by SKU presence. */
final readonly class ShopifySeries01RunAcceptance
{
    public function __construct(private Connection $db,private DoctrineWorkflowActionReceiptVerifier $receipts,
        private ShopifySeries01NativeAudit $native){}
    public function inspect(string $org,string $runId):array
    {
        $run=$this->db->fetchAssociative("SELECT w.input_digest,w.source_plan_id,w.state,s.step_id,s.state AS step_state,s.result_reference,s.idempotency_key,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=? AND w.run_id=? AND w.definition_id='federation.approved-plan' AND s.capability_id='product_management.import.apply'",[$org,$runId]);
        if(!$run||$run['state']!=='completed'||$run['step_state']!=='completed'||!is_string($run['result_reference'])
            ||!$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$run['step_id'],$run['result_reference'],$run['input_digest'])->verified)
            throw new DomainException('Completed attested import Action required.');
        $steps=json_decode($run['plan_json'],true,flags:JSON_THROW_ON_ERROR)['steps']??[];
        if(count($steps)!==1||($steps[0]['capability_id']??null)!=='product_management.import.apply')throw new DomainException('Single approved CSV import Plan required.');
        $in=$steps[0]['input']??[];$batch=$in['target_id']??null;
        $receipt=$this->db->fetchAssociative('SELECT state,digest,rows_digest,action_key FROM pm_import_batches WHERE organization_id=? AND batch_id=? AND plan_id=?',[$org,$batch,$run['source_plan_id']]);
        if(!$receipt||$receipt['state']!=='applied'||$receipt['action_key']!=='wf:'.$run['idempotency_key']
            ||$receipt['digest']!==($in['parameters']['batch_digest']??null)||$receipt['rows_digest']!==($in['parameters']['rows_digest']??null))
            throw new DomainException('Import batch is not bound to approved Action.');
        $audit=$this->native->inspect($org);
        $bySku=array_column($audit['items'],null,'sku');
        $rows=$this->db->fetchAllAssociative('SELECT row_no,sku,product_id,status,receipt_key,payload_json,staged_hash,staged_status,staged_error_code FROM pm_import_rows WHERE organization_id=? AND batch_id=? ORDER BY row_no',[$org,$batch]);
        if(!$audit['all_nine_present']||count($rows)!==9)throw new DomainException('Nine approved SERIES01 rows and native prices required.');
        $hashes=[];$seen=[];
        foreach($rows as $i=>$row){
            $sku=$row['sku'];$native=$bySku[$sku]??null;$payload=json_decode($row['payload_json'],true,flags:JSON_THROW_ON_ERROR);
            $hash=CsvProductImportService::rowDigest($i+1,$sku,$payload,$row['staged_status'],$row['staged_error_code']);
            $key='pm-csv:'.hash('sha256',$org.':'.$batch.':'.($i+1));
            $proof=$this->db->fetchAssociative('SELECT operation,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
            $actual=$proof?json_decode($proof['result_json'],true,flags:JSON_THROW_ON_ERROR):null;
            if(!is_array($native)||isset($seen[$sku])||$row['status']!=='applied'||$row['staged_status']!=='ready'||(int)$row['row_no']!==$i+1
                ||$row['product_id']!==$native['canonical_product_id']||$row['receipt_key']!==$key||$hash!==$row['staged_hash']
                ||($actual['product_id']??null)!==$row['product_id']||!in_array($proof['operation']??null,['product.create','product.assemble'],true))
                throw new DomainException('Approved SERIES01 row or native receipt differs.');
            // A legacy identity-only CSV cannot prove the approved USD price.
            $variants=$payload['assembly']['variants']??[];
            if(!is_array($variants)||count($variants)!==1||($variants[0]['sku']??null)!==$sku
                ||($variants[0]['price']['currency']??null)!=='USD'||!is_string($variants[0]['price']['amount']??null)
                ||self::money($variants[0]['price']['amount'])!==self::money($native['usd_price']))
                throw new DomainException('Approved SERIES01 USD price not independently verified.');
            $seen[$sku]=true;$hashes[]=$hash;
        }
        if(!hash_equals($receipt['rows_digest'],hash('sha256',implode("\n",$hashes))))throw new DomainException('Approved SERIES01 rowset digest differs.');
        $audit['approved_import_proven']=true;$audit['run_id']=$runId;$audit['batch_id']=$batch;
        unset($audit['note']);
        return $audit;
    }
    private static function money(string $value):string
    {
        if(!preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D',$value))throw new DomainException('Invalid price evidence.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        return (ltrim($whole,'0')?:'0').'.'.rtrim($fraction,'0');
    }
}
