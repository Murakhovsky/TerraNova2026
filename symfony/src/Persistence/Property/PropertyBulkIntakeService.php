<?php
declare(strict_types=1);

namespace App\Persistence\Property;

use App\Application\Property\Service\PropertyWriteService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * Business intake only. The ONLY write to Property native records is through
 * existing PropertyWriteService; approval and execution belong to Federation /
 * Platform Workflow / Kernel Action.
 */
final readonly class PropertyBulkIntakeService
{
    public const CAPABILITY = 'property.bulk.create';
    private const MAX_ITEMS = 10;

    public function __construct(
        private Connection $db,
        private PropertyReferencePort $properties,
        private PropertyWriteService $writes,
    ) {}

    /** @param list<array<string,mixed>> $source @return array<string,mixed> */
    public function stage(TenantContext $actor, array $source): array
    {
        $this->authorize($actor);
        if (count($source) < 1 || count($source) > self::MAX_ITEMS) {
            throw new DomainException('Batch requires 1 to 10 Property assets.');
        }
        $org = $actor->organizationId()->value();
        $items = [];
        $fingerprints = [];
        foreach ($source as $index => $row) {
            $row = self::canonicalize($row);
            $fingerprint = self::fingerprint($row);
            if (isset($fingerprints[$fingerprint])) {
                throw new DomainException('Duplicate address and type within the submitted batch.');
            }
            $fingerprints[$fingerprint] = true;
            $items[] = ['index'=>$index,'fingerprint'=>$fingerprint,'input'=>$row];
        }
        // Check native Property before creating the proposal. A search limit hit
        // must block, not be treated as an empty existing inventory.
        foreach ($items as $item) $this->assertNoNativeDuplicate($org, $item['input']);

        $digest=hash('sha256',json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $id='bulk-'.bin2hex(random_bytes(12));
        $now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $this->db->transactional(function() use($org,$actor,$items,$digest,$id,$now):void {
            $this->db->insert('cos_property_bulk_batches', [
                'organization_id'=>$org,'batch_id'=>$id,
                'owner_id'=>$actor->userId()->value(),'digest'=>$digest,
                'state'=>'staged','created_at'=>$now,'updated_at'=>$now,
            ]);
            foreach($items as $item) {
                $this->db->insert('cos_property_bulk_items',[
                    'organization_id'=>$org,'batch_id'=>$id,
                    'item_index'=>$item['index'],'fingerprint'=>$item['fingerprint'],
                    'payload_json'=>json_encode($item['input'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                    'status'=>'staged','updated_at'=>$now,
                ]);
            }
        });
        return ['batch_id'=>$id,'state'=>'staged','digest'=>$digest,'count'=>count($items)];
    }

    /** @return array<string,mixed> */
    public function preview(TenantContext $actor,string $id):array
    {
        $this->authorize($actor);
        $batch=$this->batch($actor->organizationId()->value(),$id);
        $rows=$this->db->fetchAllAssociative(
            'SELECT item_index,status,native_asset_id,payload_json
             FROM cos_property_bulk_items
             WHERE organization_id=:org AND batch_id=:id ORDER BY item_index',
            ['org'=>$actor->organizationId()->value(),'id'=>$id],
        );
        return ['batch_id'=>$id,'state'=>$batch['state'],'digest'=>$batch['digest'],
            'goal_id'=>$batch['goal_id'],'plan_id'=>$batch['plan_id'],
            'count'=>count($rows),
            'items'=>array_map(static fn(array $r):array=>[
                'index'=>(int)$r['item_index'],'status'=>$r['status'],
                'native_asset_id'=>$r['native_asset_id'],
                'input'=>json_decode((string)$r['payload_json'],true,32,JSON_THROW_ON_ERROR),
            ],$rows)];
    }

    /** @return list<array{index:int,input:array<string,mixed>}> */
    public function reviewForPlan(TenantContext $actor,string $id,string $digest):array
    {
        $this->authorize($actor);
        $org=$actor->organizationId()->value();
        $batch=$this->batch($org,$id);
        if ($batch['state']!=='staged'
            || $batch['owner_id']!==$actor->userId()->value()
            || !hash_equals((string)$batch['digest'],$digest)) {
            throw new DomainException('Property batch is stale or not owned by this manager.');
        }
        $items=$this->items($org,$id);
        foreach($items as $item) $this->assertNoNativeDuplicate($org,$item['input']);
        return $items;
    }

    /** Domain-owned Action business handler. No direct HTTP/AI/cron caller. */
    public function executeApproved(
        string $organizationId,string $batchId,string $digest,int $actorId,string $actionKey,string $correlation
    ):array {
        if (!preg_match('/^bulk-[a-f0-9]{24}$/D',$batchId)
            || !preg_match('/^[a-f0-9]{64}$/D',$digest)
            || $actorId<1 || trim($actionKey)==='') {
            throw new DomainException('Invalid approved Property bulk Action binding.');
        }
        $this->db->transactional(function()use($organizationId,$batchId,$digest,$actionKey):void {
            $this->db->fetchOne('SELECT batch_id FROM cos_property_bulk_batches
                WHERE organization_id=:org AND batch_id=:id FOR UPDATE',
                ['org'=>$organizationId,'id'=>$batchId]);
            $batch=$this->batch($organizationId,$batchId);
            if (!hash_equals((string)$batch['digest'],$digest)
                || !in_array($batch['state'],['planned','executing','verified'],true)
                || ($batch['action_key']!==null && $batch['action_key']!==$actionKey)) {
                throw new DomainException('Batch digest, Action fence or approval state mismatch.');
            }
            if ($batch['state']==='planned') {
                $this->db->executeStatement('UPDATE cos_property_bulk_batches
                    SET state=:state,action_key=:key,updated_at=UTC_TIMESTAMP(6)
                    WHERE organization_id=:org AND batch_id=:id AND state=:expected',[
                    'state'=>'executing','key'=>$actionKey,
                    'org'=>$organizationId,'id'=>$batchId,'expected'=>'planned']);
            }
        });
        $items=$this->items($organizationId,$batchId);
        // Approval of a batch implies an immutable claim for every Property identity.
        // An unclaimed or differently claimed row MUST NOT reach the Property writer.
        foreach($items as $item) {
            $claim=$this->db->fetchOne(
                'SELECT batch_id FROM cos_property_bulk_identity_claims
                 WHERE organization_id=:org AND fingerprint=:fp',
                ['org'=>$organizationId,'fp'=>self::fingerprint($item['input'])],
            );
            if ($claim!==$batchId) {
                throw new DomainException('Bulk identity reservation missing or owned by another Plan.');
            }
        }
        $verified=[];
        foreach($items as $item) {
            $input=$item['input'];
            $key='cos-property-bulk:'.$batchId.':'.$item['index'];
            $assetId='PROP-'.strtoupper(substr(hash('sha256',
                $organizationId.':create:'.$key),0,20));
            // On recovery, an existing deterministic Asset is checked against
            // its receipt and native read. No second create or blind retry.
            $existing=$this->properties->getPropertyPresentation($organizationId,$assetId);
            if ($existing===null) {
                $this->assertNoNativeDuplicate($organizationId,$input);
                $result=$this->writes->create(
                    \Kernel\Shared\Domain\OrganizationId::fromString($organizationId),
                    $actorId,$correlation,$key,$input,
                );
                if (($result['asset']['asset_id']??null)!==$assetId) {
                    throw new DomainException('Native Property writer returned unexpected Asset identity.');
                }
            }
            $native=$this->properties->getPropertyPresentation($organizationId,$assetId);
            $property=$native['property']??null;
            if (!is_array($property)||($property['asset_id']??null)!==$assetId
                || ($property['type_code']??null)!==$input['type_code']) {
                throw new DomainException('Native Property result remains unverified; manual reconciliation required.');
            }
            $affected=$this->db->executeStatement(
                'UPDATE cos_property_bulk_items SET status=:state,native_asset_id=:asset,
                 receipt_key=:receipt,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND batch_id=:batch AND item_index=:idx
                   AND (native_asset_id IS NULL OR native_asset_id=:same)
                   AND (receipt_key IS NULL OR receipt_key=:receipt)',[
                 'state'=>'verified','asset'=>$assetId,'receipt'=>$key,
                 'org'=>$organizationId,'batch'=>$batchId,'idx'=>$item['index'],'same'=>$assetId,
            ]);
            if ($affected!==1) {
                throw new DomainException('A native Property receipt conflicts with approved batch item.');
            }
            $verified[]=$assetId;
        }
        if (count($verified)!==count($items) || count($verified)>self::MAX_ITEMS) {
            throw new DomainException('Not all Property Assets independently verified.');
        }
        $this->db->executeStatement('UPDATE cos_property_bulk_batches SET state=:state,
            updated_at=UTC_TIMESTAMP(6) WHERE organization_id=:org AND batch_id=:id
            AND state IN (:running,:done)',[
            'state'=>'verified','org'=>$organizationId,'id'=>$batchId,
            'running'=>'executing','done'=>'verified',
        ]);
        return ['batch_id'=>$batchId,'verified_count'=>count($verified),'asset_ids'=>$verified,
            'publication'=>'not_published'];
    }

    /** @return array<string,mixed> */
    private function batch(string $org,string $id):array
    {
        if (!preg_match('/^bulk-[a-f0-9]{24}$/D',$id)) throw new DomainException('Invalid bulk batch ID.');
        return $this->db->fetchAssociative(
            'SELECT * FROM cos_property_bulk_batches WHERE organization_id=:org AND batch_id=:id',
            ['org'=>$org,'id'=>$id],
        ) ?: throw new DomainException('Property bulk batch not found.');
    }

    /** @return list<array{index:int,input:array<string,mixed>}> */
    private function items(string $org,string $id):array
    {
        $batch=$this->batch($org,$id);
        $rows=$this->db->fetchAllAssociative(
            'SELECT item_index,fingerprint,payload_json FROM cos_property_bulk_items
             WHERE organization_id=:org AND batch_id=:id ORDER BY item_index',
            ['org'=>$org,'id'=>$id],
        );
        if(count($rows)<1||count($rows)>self::MAX_ITEMS) {
            throw new DomainException('Invalid batch size.');
        }
        $sealed=[];
        foreach($rows as $offset=>$row) {
            $input=json_decode((string)$row['payload_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($input)||array_is_list($input)) {
                throw new DomainException('Invalid persisted Property item object.');
            }
            // MySQL JSON may reorder keys; canonicalization recovers staging order.
            $normalized=self::canonicalize($input);
            $fingerprint=self::fingerprint($normalized);
            $index=(int)$row['item_index'];
            if($index!==$offset || !hash_equals($fingerprint,(string)$row['fingerprint'])) {
                throw new DomainException('Property batch row index or fingerprint was altered.');
            }
            $sealed[]=['index'=>$index,'fingerprint'=>$fingerprint,'input'=>$normalized];
        }
        $digest=hash('sha256',json_encode($sealed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        if(!hash_equals((string)$batch['digest'],$digest)) {
            throw new DomainException('Immutable Property batch digest mismatch.');
        }
        return array_map(static fn(array $row):array=>[
            'index'=>$row['index'],'input'=>$row['input'],
        ],$sealed);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function canonicalize(array $row):array
    {
        $permitted=['type_code','city','address','region','district','country_code','area_total',
            'area_living','land_area','rooms','floors','floor','built_year','kind'];
        foreach(array_keys($row) as $field) {
            if (!in_array($field,$permitted,true)) {
                throw new DomainException('Unknown or forbidden Property field: '.$field);
            }
        }
        foreach(['type_code','city','address'] as $field) {
            if (!is_string($row[$field]??null)
                || mb_strlen(trim($row[$field]))<2 || mb_strlen($row[$field])>200) {
                throw new DomainException('A complete type, city and address are required: '.$field);
            }
            $row[$field]=trim($row[$field]);
        }
        foreach(['region','district','country_code','kind'] as $field) {
            if (isset($row[$field]) && (!is_string($row[$field]) || mb_strlen($row[$field])>200)) {
                throw new DomainException('Invalid Property field: '.$field);
            }
        }
        foreach(['area_total','area_living','land_area','rooms'] as $field) {
            if (isset($row[$field]) && (!is_int($row[$field])&&!is_float($row[$field])
                || !is_finite((float)$row[$field]) || $row[$field]<0)) {
                throw new DomainException('Invalid numeric Property field: '.$field);
            }
        }
        foreach(['floor','floors','built_year'] as $field) {
            if (isset($row[$field]) && !is_int($row[$field])) throw new DomainException('Invalid integer Property field: '.$field);
        }
        ksort($row,SORT_STRING);
        return $row;
    }

    /** @param array<string,mixed> $input */
    public static function fingerprint(array $input):string
    {
        $parts=array_map(static fn(string $value):string=>mb_strtolower(
            preg_replace('/\s+/u',' ',trim($value))??trim($value)
        ),[$input['type_code'],$input['city'],$input['address']]);
        return hash('sha256',implode("\0",$parts));
    }

    /** @param array<string,mixed> $input */
    private function assertNoNativeDuplicate(string $org,array $input):void
    {
        $matches=$this->properties->searchPropertyReferences($org,$input['address'],200);
        if (count($matches)>=200) throw new DomainException('Ambiguous Property duplicate search; operator review required.');
        $targetAddress=mb_strtolower(trim($input['address']));
        foreach($matches as $match) {
            $reference=$match['asset_id']??null;
            if (!is_string($reference)) continue;
            $native=$this->properties->getPropertyReference($org,$reference);
            if (!is_array($native)) continue;
            $address=mb_strtolower(trim((string)($native['formatted_address']??'')));
            if ($address!=='' && (
                $address===$targetAddress
                || str_contains($address,$targetAddress)
            )) throw new DomainException('Potential existing Property at this address: '.$reference);
        }
    }

    private function authorize(TenantContext $actor):void
    {
        if (!$actor->isManager()||!$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('A tenant manager is required for Property bulk intake.');
        }
    }
}
