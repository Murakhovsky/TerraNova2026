<?php
declare(strict_types=1);

namespace App\Persistence\Capability;

use App\Application\Capability\AutomationIntentSignatureVerifier;
use App\Persistence\Property\PropertyBulkIntakeService;
use App\Persistence\ProductManagement\ProductGovernedPlanProposalService;
use Domains\ProductManagement\Automation\Action\ProductGovernedOperationHandler;
use Kernel\Module\CanonicalCapabilityCatalog;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * AI/CRON cannot mutate Property: only durable review intents. Human adoption
 * calls existing staging and canonical Goal/Plan; no alternate execution.
 */
final readonly class CanonicalAutomationIntentStore
{
    public function __construct(
        private Connection $db,
        private AutomationIntentSignatureVerifier $signatures,
        private ?PropertyBulkIntakeService $property=null,
        private ?ProductGovernedPlanProposalService $productPlans=null,
        private ?CanonicalCapabilityCatalog $catalog=null,
    ) {}

    /** @return array<string,mixed> */
    public function submit(string $origin,string $timestamp,string $nonce,string $signature,string $raw):array
    {
        $this->signatures->verify($origin,$timestamp,$nonce,$signature,$raw);
        $org=$this->signatures->organization();
        $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload)||array_is_list($payload)
            || ($payload['capability_id']??null)!=='property.bulk.create'
            || !is_array($payload['items']??null)
            || !array_is_list($payload['items'])
            || count($payload['items'])<1 || count($payload['items'])>10
            || count($payload)!==2
            || !array_key_exists('capability_id',$payload)
            || !array_key_exists('items',$payload)) {
            throw new DomainException('Automation intent is not an approved, bounded Property batch proposal.');
        }
        $items=[];
        foreach($payload['items'] as $row) {
            if (!is_array($row)||array_is_list($row)) {
                throw new DomainException('Automation item must be an object.');
            }
            $items[]=PropertyBulkIntakeService::canonicalize($row);
        }
        // Semantic normalization does not mean independent Domain duplicate check:
        // that runs at human adoption and again at approved Action execution.
        $digest=hash('sha256',json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $id='intent-'.bin2hex(random_bytes(12));
        try {
            $this->db->insert('cos_capability_automation_intents',[
                'organization_id'=>$org,'intent_id'=>$id,'origin'=>$origin,
                'nonce'=>$nonce,'request_digest'=>$digest,
                'payload_json'=>json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'state'=>'pending_review','created_at'=>gmdate('Y-m-d H:i:s'),
            ]);
        } catch(UniqueConstraintViolationException) {
            // A same-nonce network retry must resolve to exactly one intent.
            // A changed signed payload with a previously used nonce is refused.
            $previous=$this->db->fetchAssociative(
                'SELECT intent_id,request_digest,state,payload_json
                 FROM cos_capability_automation_intents
                 WHERE organization_id=:org AND origin=:origin AND nonce=:nonce',
                ['org'=>$org,'origin'=>$origin,'nonce'=>$nonce],
            );
            if(!$previous || !hash_equals((string)$previous['request_digest'],$digest)) {
                throw new DomainException('Reused automation nonce has conflicting payload.');
            }
            $persisted=json_decode((string)$previous['payload_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($persisted)||!array_is_list($persisted)) {
                throw new DomainException('Reused automation nonce has invalid persisted content.');
            }
            $canonicalPersisted=[];
            foreach($persisted as $item) {
                if(!is_array($item)||array_is_list($item)) {
                    throw new DomainException('Reused automation nonce has an invalid Property item.');
                }
                $canonicalPersisted[]=PropertyBulkIntakeService::canonicalize($item);
            }
            if($canonicalPersisted!==$items) {
                throw new DomainException('Reused automation nonce has conflicting native content.');
            }
            return ['intent_id'=>$previous['intent_id'],'state'=>$previous['state'],
                'origin'=>$origin,'digest'=>$digest,'count'=>count($items),
                'execution_authority'=>'none','replayed'=>true];
        }
        return ['intent_id'=>$id,'state'=>'pending_review','origin'=>$origin,
            'digest'=>$digest,'count'=>count($items),'execution_authority'=>'none'];
    }

    /** Product uses the existing canonical intent table, nonce fence and manager review.
     * Legacy Property payloads are JSON lists; the Product envelope is a typed JSON object.
     */
    public function submitProduct(string $origin,string $timestamp,string $nonce,string $signature,string $raw):array
    {
        $this->signatures->verify($origin,$timestamp,$nonce,$signature,$raw);
        $org=$this->signatures->organization();
        $payload=$this->normalizeProduct(json_decode($raw,true,64,JSON_THROW_ON_ERROR));
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $digest=hash('sha256',$encoded);$id='intent-'.bin2hex(random_bytes(12));
        try{
            $this->db->insert('cos_capability_automation_intents',[
                'organization_id'=>$org,'intent_id'=>$id,'origin'=>$origin,'nonce'=>$nonce,
                'request_digest'=>$digest,'payload_json'=>$encoded,'state'=>'pending_review','created_at'=>gmdate('Y-m-d H:i:s'),
            ]);
        }catch(UniqueConstraintViolationException){
            $old=$this->db->fetchAssociative('SELECT intent_id,request_digest,state,payload_json FROM cos_capability_automation_intents WHERE organization_id=? AND origin=? AND nonce=?',[$org,$origin,$nonce]);
            if(!$old||!hash_equals($old['request_digest'],$digest)
                ||$this->normalizeProduct(json_decode($old['payload_json'],true,64,JSON_THROW_ON_ERROR))!==$payload)
                throw new DomainException('Reused machine nonce has conflicting Product content.');
            return ['intent_id'=>$old['intent_id'],'state'=>$old['state'],'origin'=>$origin,'digest'=>$digest,
                'count'=>count($payload['operations']),'execution_authority'=>'none','replayed'=>true];
        }
        return ['intent_id'=>$id,'state'=>'pending_review','origin'=>$origin,'digest'=>$digest,'count'=>count($payload['operations']),'execution_authority'=>'none'];
    }
    private function normalizeProduct(mixed $payload):array
    {
        if($this->catalog===null||$this->productPlans===null||!is_array($payload)||count($payload)!==3
            ||($payload['kind']??null)!=='product_management.governed_plan.v1'
            ||!is_string($payload['description']??null)||trim($payload['description'])===''||mb_strlen($payload['description'])>500
            ||!is_array($payload['operations']??null)||!array_is_list($payload['operations'])||count($payload['operations'])<1||count($payload['operations'])>20
            ||strlen(json_encode($payload['operations'],JSON_THROW_ON_ERROR))>64000)
            throw new DomainException('Invalid Product machine proposal envelope.');
        $seen=[];
        foreach($payload['operations'] as $op){
            if(!is_array($op)||count($op)!==3||!is_string($op['id']??null)||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$op['id'])
                ||isset($seen[$op['id']])||!is_string($op['capability_id']??null)||!in_array($op['capability_id'],ProductGovernedOperationHandler::types(),true)
                ||!is_array($op['parameters']??null))throw new DomainException('Unsupported Product machine step.');
            $seen[$op['id']]=true;
            $contract=$this->catalog->describe($op['capability_id']);
            if($contract===null||$contract->approvalPolicy!=='required'||$contract->executionBinding!=='action:'.$contract->id)
                throw new DomainException('Unregistered Product governed capability.');
            (new CapabilityJsonInputValidator())->validate($contract,['target_type'=>'product_governed_operation','target_id'=>str_repeat('0',32),'parameters'=>$op['parameters']]);
        }
        $payload['description']=trim($payload['description']);
        $sort=static function(mixed $v)use(&$sort):mixed{
            if(!is_array($v))return $v;
            if(!array_is_list($v))ksort($v,SORT_STRING);
            foreach($v as $k=>$child)$v[$k]=$sort($child);
            return $v;
        };
        return $sort($payload);
    }

    /** @return list<array<string,mixed>> */
    public function pending(TenantContext $actor):array
    {
        $this->authorize($actor);
        return $this->db->fetchAllAssociative(
            'SELECT intent_id,origin,request_digest,state,created_at,
                    CASE WHEN JSON_TYPE(payload_json)="OBJECT" THEN "product" ELSE "property" END AS intent_kind
             FROM cos_capability_automation_intents WHERE organization_id=:org
              AND state=:state ORDER BY created_at DESC LIMIT 25',
            ['org'=>$actor->organizationId()->value(),'state'=>'pending_review']);
    }

    /** @return array<string,mixed> */
    public function inspect(TenantContext $actor,string $id):array
    {
        $this->authorize($actor);
        $row=$this->find($actor->organizationId()->value(),$id);
        $payload=json_decode((string)$row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(is_array($payload)&&!array_is_list($payload)){
            $payload=$this->normalizeProduct($payload);
            if(!hash_equals($row['request_digest'],hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))))
                throw new DomainException('Product intent digest drift.');
            $identity=ProductGovernedPlanProposalService::identities($actor->organizationId()->value(),ProductGovernedPlanProposalService::intentKey($id));
            return ['intent_id'=>$id,'origin'=>$row['origin'],'state'=>$row['state'],'digest'=>$row['request_digest'],
                'kind'=>'product','description'=>$payload['description'],'operations'=>$payload['operations'],
                'adopted_plan_id'=>$row['state']==='adopted'?$identity['plan_id']:null,'items'=>[],'adopted_batch_id'=>null];
        }
        return ['intent_id'=>$id,'origin'=>$row['origin'],'state'=>$row['state'],
            'kind'=>'property','digest'=>$row['request_digest'],
            'items'=>json_decode((string)$row['payload_json'],true,32,JSON_THROW_ON_ERROR),
            'adopted_batch_id'=>$row['adopted_batch_id']];
    }

    /** Human explicitly adopts a signed request, NEVER an automatic approval. */
    public function adopt(TenantContext $actor,string $id,string $reviewedDigest):array
    {
        $this->authorize($actor);
        $org=$actor->organizationId()->value();
        return $this->db->transactional(function()use($actor,$org,$id,$reviewedDigest):array {
            $this->db->fetchOne('SELECT intent_id FROM cos_capability_automation_intents
                WHERE organization_id=:org AND intent_id=:id FOR UPDATE',
                ['org'=>$org,'id'=>$id]);
            $row=$this->find($org,$id);
            if ($row['state']!=='pending_review'
                || !hash_equals((string)$row['request_digest'],$reviewedDigest)) {
                throw new DomainException('Automation intent is already adopted or review digest changed.');
            }
            $items=json_decode((string)$row['payload_json'],true,64,JSON_THROW_ON_ERROR);
            if(is_array($items)&&!array_is_list($items)){
                $payload=$this->normalizeProduct($items);
                if(!hash_equals($row['request_digest'],hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))))
                    throw new DomainException('Reviewed Product intent digest drift.');
                $plan=$this->productPlans->propose($actor,ProductGovernedPlanProposalService::intentKey($id),$payload['description'],$payload['operations']);
                if($this->db->executeStatement("UPDATE cos_capability_automation_intents SET state='adopted',reviewed_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND intent_id=? AND state='pending_review'",[$org,$id])!==1)
                    throw new DomainException('Product intent review conflict.');
                // Deterministic Plan identity is recoverable from tenant+intent ID. No second execution store.
                return ['intent_id'=>$id,'plan_id'=>$plan['plan_id'],'goal_id'=>$plan['goal_id'],'state'=>'adopted',
                    'next'=>'independent_plan_approval'];
            }
            if($this->property===null)throw new DomainException('Property intake unavailable.');
            $batch=$this->property->stage($actor,$items);
            $this->db->executeStatement('UPDATE cos_capability_automation_intents
                SET state=:state,adopted_batch_id=:batch,reviewed_at=UTC_TIMESTAMP(6)
                WHERE organization_id=:org AND intent_id=:id AND state=:expected',[
                'state'=>'adopted','batch'=>$batch['batch_id'],'org'=>$org,
                'id'=>$id,'expected'=>'pending_review',
            ]);
            return ['intent_id'=>$id,'batch_id'=>$batch['batch_id'],'state'=>'adopted',
                'next'=>'human_review_and_canonical_federation_plan'];
        });
    }

    /** @return array<string,mixed> */
    private function find(string $org,string $id):array
    {
        if (!preg_match('/^intent-[a-f0-9]{24}$/D',$id)) throw new DomainException('Invalid intent ID.');
        return $this->db->fetchAssociative(
            'SELECT * FROM cos_capability_automation_intents
             WHERE organization_id=:org AND intent_id=:id',['org'=>$org,'id'=>$id]
        ) ?: throw new DomainException('Automation intent not found.');
    }

    private function authorize(TenantContext $actor):void
    {
        if (!$actor->isManager()||!$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Human tenant manager review is required.');
        }
    }
}
