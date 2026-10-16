<?php
declare(strict_types=1);

namespace App\Persistence\Capability;

use App\Application\Capability\AutomationIntentSignatureVerifier;
use App\Persistence\Property\PropertyBulkIntakeService;
use Doctrine\DBAL\Connection;
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
        private PropertyBulkIntakeService $property,
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
            || array_keys($payload)!==['capability_id','items']) {
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
        $this->db->insert('cos_capability_automation_intents',[
            'organization_id'=>$org,'intent_id'=>$id,'origin'=>$origin,
            'nonce'=>$nonce,'request_digest'=>$digest,
            'payload_json'=>json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'state'=>'pending_review','created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return ['intent_id'=>$id,'state'=>'pending_review','origin'=>$origin,
            'digest'=>$digest,'count'=>count($items),'execution_authority'=>'none'];
    }

    /** @return list<array<string,mixed>> */
    public function pending(TenantContext $actor):array
    {
        $this->authorize($actor);
        return $this->db->fetchAllAssociative(
            'SELECT intent_id,origin,request_digest,state,created_at
             FROM cos_capability_automation_intents WHERE organization_id=:org
              AND state=:state ORDER BY created_at DESC LIMIT 25',
            ['org'=>$actor->organizationId()->value(),'state'=>'pending_review']);
    }

    /** @return array<string,mixed> */
    public function inspect(TenantContext $actor,string $id):array
    {
        $this->authorize($actor);
        $row=$this->find($actor->organizationId()->value(),$id);
        return ['intent_id'=>$id,'origin'=>$row['origin'],'state'=>$row['state'],
            'digest'=>$row['request_digest'],
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
            $items=json_decode((string)$row['payload_json'],true,32,JSON_THROW_ON_ERROR);
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
