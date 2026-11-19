<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use PDO;
use Throwable;

/**
 * Only the canonical approved Action handler may call applyApproved().
 * Reuses tenant-local Product/Variant/Price writers and their native,
 * transactionally durable idempotency receipts. No Shopify network/write,
 * stock movement, secondary dispatcher or implicit ownership switch.
 */
final readonly class ShopifyImportApplyService
{
    public function __construct(
        private PDO $connection,
        private ProductManagementService $products,
        private PricingService $pricing,
        private ShopifyObservationStore $observations,
    ) {}

    /** @return array<string,mixed> */
    public function applyApproved(
        string $org,string $previewId,string $sourceHash,string $digest,
        string $actor,string $actionKey,string $correlation
    ):array {
        if($org===''||strlen($org)>40
            ||!preg_match('/^[a-f0-9]{32}$/D',$previewId)
            ||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash)
            ||!preg_match('/^[a-f0-9]{64}$/D',$digest)
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',$actionKey)
            ||!ctype_digit($actor)||$actor==='0'||strlen($actor)>100
            ||$correlation===''||strlen($correlation)>191) {
            throw new DomainException('Invalid approved Shopify import Action identity.');
        }
        $scope=$this->preview($org,$previewId);
        if($scope===null)throw new DomainException('Unknown tenant Shopify import candidate.');
        $lockName='pm-shopify:'.substr(hash('sha256',$org.':'.$scope['connection_id'].':'.$scope['product_gid']),0,48);
        if((int)$this->query('SELECT GET_LOCK(?,0)',[$lockName])->fetchColumn()!==1) {
            throw new DomainException('Shopify candidate writer already active; reconcile before retry.');
        }
        $claimed=false;
        try {
            $this->connection->beginTransaction();
            try {
                $scope=$this->query('SELECT * FROM pm_shopify_import_previews WHERE organization_id=? AND preview_id=? FOR UPDATE',
                    [$org,$previewId])->fetch(PDO::FETCH_ASSOC);
                if($scope===false||!is_string($scope['plan_id'])||$scope['plan_id']===''
                    ||!hash_equals((string)$scope['source_hash'],$sourceHash)
                    ||!hash_equals((string)$scope['normalized_digest'],$digest)
                    ||!in_array($scope['state'],['staged','applying','applied'],true)
                    ||($scope['action_key']!==null&&!hash_equals((string)$scope['action_key'],$actionKey))) {
                    throw new DomainException('Shopify candidate approval, version or Action fence mismatch.');
                }
                $candidate=self::candidate($scope);
                $current=$this->observations->productSnapshot($org,(string)$scope['connection_id'],(string)$scope['product_gid']);
                if($scope['state']!=='applied'
                    &&($current===null||(int)$current['is_complete']!==1
                        ||!hash_equals($sourceHash,(string)$current['payload_hash']))) {
                    throw new DomainException('Shopify snapshot changed since approval; reconcile before materialization.');
                }
                if($scope['state']==='staged') {
                    $this->query("UPDATE pm_shopify_import_previews SET state='applying',action_key=? WHERE organization_id=? AND preview_id=? AND state='staged'",
                        [$actionKey,$org,$previewId]);
                }
                $this->connection->commit();
                $claimed=true;
            }catch(Throwable $e) {
                if($this->connection->inTransaction())$this->connection->rollBack();
                throw $e;
            }
            if($scope['state']==='applied') {
                return $this->verifyApplied($org,$previewId,$sourceHash,$digest);
            }
            $variants=$candidate['variants'];
            $first=$variants[0];
            $productKey=self::key($org,$previewId,'product');
            $created=$this->products->createProduct($org,[
                'sku'=>$first['sku'],'name'=>$candidate['name'],
                'type'=>'stock','uom'=>$first['uom'],
                'track_inventory'=>$first['track_inventory'],
            ],$productKey,$actor,$correlation);
            $productId=(string)$created['product_id'];
            foreach($variants as $index=>$variant) {
                $nativeId=(string)$created['variant_id'];
                if($index!==0) {
                    $createdVariant=$this->products->createVariant($org,$productId,
                        $variant['sku'],$variant['uom'],$variant['track_inventory'],
                        self::key($org,$previewId,'variant:'.$index),$actor,$correlation);
                    $nativeId=(string)$createdVariant['variant_id'];
                }
                if($variant['barcode']!==null) {
                    $this->products->updateVariant($org,$nativeId,['barcode'=>$variant['barcode']],1,
                        self::key($org,$previewId,'barcode:'.$index),$actor,$correlation);
                }
                $this->pricing->set($org,$nativeId,$variant['currency'],$variant['price'],
                    self::key($org,$previewId,'price:'.$index),$actor,$correlation);
                $this->assertVariant($org,$productId,$nativeId,$variant);
                $price=$this->pricing->get($org,$nativeId,$variant['currency']);
                if(!is_string($price['price']??null)
                    ||self::amount((string)$price['price'])!==self::amount($variant['price'])) {
                    throw new DomainException('Canonical Variant base price differs from approved source.');
                }
                $this->observations->map($org,(string)$scope['connection_id'],'variant',$variant['gid'],$nativeId);
                // May crash after native commits but before this receipt; identical
                // approved Action reuses the original Product/Price operation keys.
                $this->receipt($org,$previewId,'variant:'.$index,$variant['gid'],$nativeId,
                    hash('sha256',json_encode($variant,JSON_THROW_ON_ERROR)));
            }
            // Attach Product mapping only after all native Variants and prices
            // have committed, so Shopify-owned mutability fences do not
            // block the importer's remaining canonical initialization.
            $this->observations->map($org,(string)$scope['connection_id'],'product',(string)$scope['product_gid'],$productId);
            $verified=$this->verifyNative($org,$scope,$candidate,$productId);
            $latest=$this->observations->productSnapshot(
                $org,(string)$scope['connection_id'],(string)$scope['product_gid']);
            if($latest===null||(int)$latest['is_complete']!==1
                ||!hash_equals($sourceHash,(string)$latest['payload_hash'])) {
                throw new DomainException('Shopify source changed during approved import; reconcile native effects.');
            }
            $updated=$this->query("UPDATE pm_shopify_import_previews SET state='applied',product_id=?,applied_at=UTC_TIMESTAMP(6)
                WHERE organization_id=? AND preview_id=? AND action_key=? AND state='applying'",
                [$productId,$org,$previewId,$actionKey]);
            if($updated->rowCount()!==1) {
                throw new DomainException('Shopify import final Action receipt state conflict.');
            }
            return $verified;
        }catch(Throwable $e) {
            if($claimed) {
                try {
                    $this->query("UPDATE pm_shopify_import_previews SET state='blocked'
                        WHERE organization_id=? AND preview_id=? AND action_key=? AND state='applying'",
                        [$org,$previewId,$actionKey]);
                }catch(Throwable) {/* Preserve original; never claim a clean failure. */}
            }
            throw $e;
        }finally {
            if($this->connection->inTransaction())$this->connection->rollBack();
            $this->query('SELECT RELEASE_LOCK(?)',[$lockName]);
        }
    }

    /** Independent read path usable by an attested Run outcome provider. */
    public function verifyApplied(string $org,string $previewId,string $hash,string $digest):array
    {
        $scope=$this->preview($org,$previewId);
        if($scope===null||$scope['state']!=='applied'||$scope['product_id']===null
            ||!is_string($scope['plan_id'])||$scope['plan_id']===''
            ||!is_string($scope['action_key'])||!preg_match('/^wf:[a-f0-9]{64}$/D',$scope['action_key'])
            ||!hash_equals((string)$scope['source_hash'],$hash)
            ||!hash_equals((string)$scope['normalized_digest'],$digest)) {
            throw new DomainException('Approved Shopify import has no complete native evidence.');
        }
        return $this->verifyNative($org,$scope,self::candidate($scope),(string)$scope['product_id']);
    }

    /** @return array<string,mixed>|null */
    private function preview(string $org,string $id):?array
    {
        $row=$this->query('SELECT * FROM pm_shopify_import_previews WHERE organization_id=? AND preview_id=?',
            [$org,$id])->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function candidate(array $row):array
    {
        $candidate=json_decode((string)$row['normalized_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($candidate)||array_is_list($candidate)
            ||!hash_equals((string)$row['normalized_digest'],ShopifyImportCandidateNormalizer::fingerprint($candidate))
            ||($candidate['gid']??null)!==$row['product_gid']
            ||($candidate['currency']??null)!==$row['currency']
            ||!isset($candidate['variants'])||!is_array($candidate['variants'])
            ||!array_is_list($candidate['variants'])||$candidate['variants']===[]) {
            throw new DomainException('Immutable Shopify import candidate digest or identity differs.');
        }
        return $candidate;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $candidate */
    private function verifyNative(string $org,array $source,array $candidate,string $productId):array
    {
        $native=$this->products->getProduct($org,$productId);
        if($native===null||($native['name']??null)!==$candidate['name']
            ||($native['product_type']??null)!=='stock'||($native['status']??null)==='archived') {
            throw new DomainException('Native Shopify Product differs from approved candidate.');
        }
        $mapping=$this->observations->mapping($org,$source['connection_id'],'product',$source['product_gid']);
        if(($mapping['canonical_id']??null)!==$productId) {
            throw new DomainException('External Product mapping differs from approved native identity.');
        }
        if(count($native['variants'])!==count($candidate['variants'])) {
            throw new DomainException('Native Product has unexpected Variant cardinality.');
        }
        $ids=[];
        foreach($candidate['variants'] as $index=>$variant) {
            $matches=array_values(array_filter($native['variants'],
                static fn(array $v):bool=>$v['sku']===$variant['sku']));
            if(count($matches)!==1)throw new DomainException('Native Variant SKU is absent or ambiguous.');
            $v=$matches[0];
            $id=(string)$v['variant_id'];
            $this->assertVariant($org,$productId,$id,$variant);
            $price=$this->pricing->get($org,$id,$variant['currency']);
            if(!is_string($price['price']??null)
                ||self::amount($price['price'])!==self::amount($variant['price'])) {
                throw new DomainException('Approved Shopify Variant Price differs from native state.');
            }
            $mapped=$this->observations->mapping($org,$source['connection_id'],'variant',$variant['gid']);
            if(($mapped['canonical_id']??null)!==$id)throw new DomainException('Shopify Variant mapping changed.');
            $receipt=$this->query('SELECT native_id,payload_digest FROM pm_shopify_import_step_receipts
                WHERE organization_id=? AND preview_id=? AND step_key=?',
                [$org,$source['preview_id'],'variant:'.$index])->fetch(PDO::FETCH_ASSOC);
            if($receipt===false||$receipt['native_id']!==$id
                ||!hash_equals((string)$receipt['payload_digest'],hash('sha256',json_encode($variant,JSON_THROW_ON_ERROR)))) {
                throw new DomainException('Shopify Variant native receipt is absent or inconsistent.');
            }
            $ids[]=$id;
        }
        return [
            'preview_id'=>$source['preview_id'],'product_id'=>$productId,
            'verified_variants'=>count($ids),'variant_ids'=>$ids,
            'state'=>'applied','source_hash'=>$source['source_hash'],
            'normalized_digest'=>$source['normalized_digest'],
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function assertVariant(string $org,string $productId,string $variantId,array $candidate):void
    {
        $native=$this->products->getProduct($org,$productId);
        if($native===null)throw new DomainException('Native Shopify Product absent.');
        foreach($native['variants'] as $v) {
            if($v['variant_id']===$variantId && $v['sku']===$candidate['sku']
                &&$v['status']==='active'
                &&(bool)$v['track_inventory']===$candidate['track_inventory']
                &&$v['uom']===$candidate['uom']
                &&(string)($v['barcode']??'')===(string)($candidate['barcode']??''))return;
        }
        throw new DomainException('Native Shopify Variant disagrees with approved source.');
    }

    private function receipt(string $org,string $previewId,string $step,string $gid,string $nativeId,string $hash):void
    {
        $this->query('INSERT INTO pm_shopify_import_step_receipts
            (organization_id,preview_id,step_key,external_gid,native_id,payload_digest)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE step_key=step_key',
            [$org,$previewId,$step,$gid,$nativeId,$hash]);
        $row=$this->query('SELECT external_gid,native_id,payload_digest FROM pm_shopify_import_step_receipts
            WHERE organization_id=? AND preview_id=? AND step_key=?',
            [$org,$previewId,$step])->fetch(PDO::FETCH_ASSOC);
        if($row===false||$row['external_gid']!==$gid||$row['native_id']!==$nativeId
            ||!hash_equals((string)$row['payload_digest'],$hash)) {
            throw new DomainException('Shopify import receipt collision.');
        }
    }

    private static function key(string $org,string $previewId,string $step):string
    {
        return 'pm-shopify:'.hash('sha256',$org.':'.$previewId.':'.$step);
    }

    /** MySQL DECIMAL padding must not create a false price difference. */
    private static function amount(string $decimal):string
    {
        [$integer,$fraction]=array_pad(explode('.',$decimal,2),2,'');
        $fraction=rtrim($fraction,'0');
        return $integer.($fraction===''?'':'.'.$fraction);
    }

    /** @param list<mixed> $parameters */
    private function query(string $sql,array $parameters):\PDOStatement
    {
        $stmt=$this->connection->prepare($sql);
        $stmt->execute($parameters);
        return $stmt;
    }
}
