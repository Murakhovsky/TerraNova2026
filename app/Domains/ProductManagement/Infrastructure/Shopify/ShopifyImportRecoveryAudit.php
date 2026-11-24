<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use PDO;
use Throwable;

/**
 * Read-only native Shopify import recovery evidence, scoped to tenant and connection.
 *
 * An incomplete Action may have already committed Product, Variant, Price and
 * DomainEvent+Outbox writes. Never replay, compensate or change a claim based
 * only on this report. Human review through the canonical governance path
 * is required to resolve unknown outcomes.
 */
final readonly class ShopifyImportRecoveryAudit
{
    public function __construct(
        private PDO $connection,
        private ShopifyObservationStore $observations,
        private ShopifyImportApplyService $writer,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(string $org,string $conn,string $previewId):array
    {
        if($org===''||strlen($org)>40
            ||!preg_match('/^[a-f0-9]{32}$/D',$conn)
            ||!preg_match('/^[a-f0-9]{32}$/D',$previewId)) {
            throw new DomainException('Shopify recovery audit needs bounded tenant, connection and preview IDs.');
        }
        $this->observations->connection($org,$conn);
        $row=$this->query('SELECT * FROM pm_shopify_import_previews
            WHERE organization_id=? AND connection_id=? AND preview_id=?',
            [$org,$conn,$previewId])->fetch(PDO::FETCH_ASSOC);
        if($row===false)throw new DomainException('Unknown Shopify preview in the selected tenant connection.');
        $candidate=ShopifyImportApplyService::candidate($row);
        $source=$this->observations->productSnapshot($org,$conn,(string)$row['product_gid']);
        $sourceValid=$source!==null
            &&(int)$source['is_complete']===1
            &&hash_equals((string)$row['source_hash'],(string)$source['payload_hash']);

        $productReceipt=$this->receipt($org,$previewId,'product','product.create');
        $productResult=$this->result($productReceipt);
        $nativeId=$productResult['product_id']??null;
        $firstVariantId=$productResult['variant_id']??null;
        $nativeProduct=is_string($nativeId)&&preg_match('/^[a-f0-9]{32}$/D',$nativeId)
            ?$this->query('SELECT product_id,name,product_type,status FROM pm_products
                WHERE organization_id=? AND product_id=?',[$org,$nativeId])->fetch(PDO::FETCH_ASSOC)
            :false;

        $productMapping=$this->observations->mapping($org,$conn,'product',(string)$row['product_gid']);
        $productMapped=is_string($nativeId)
            &&($productMapping['canonical_id']??null)===$nativeId;
        $verified=0;$sideEffects=$productReceipt!==null||$productMapping!==null;
        $steps=[];
        foreach($candidate['variants'] as $i=>$variant) {
            $variantReceipt=$i===0?$productReceipt:$this->receipt($org,$previewId,'variant:'.$i,'variant.create');
            $created=$i===0?$firstVariantId:($this->result($variantReceipt)['variant_id']??null);
            $barcodeReceipt=$this->receipt($org,$previewId,'barcode:'.$i,'variant.update');
            $priceReceipt=$this->receipt($org,$previewId,'price:'.$i,'price.set');
            $native=is_string($created)&&preg_match('/^[a-f0-9]{32}$/D',$created)
                ?$this->query('SELECT variant_id,product_id,sku,barcode,uom,track_inventory,status
                    FROM pm_variants WHERE organization_id=? AND variant_id=?',
                    [$org,$created])->fetch(PDO::FETCH_ASSOC)
                :false;
            $price=$native!==false
                ?$this->query('SELECT amount FROM pm_base_prices
                    WHERE organization_id=? AND variant_id=? AND currency=?',
                    [$org,$created,$variant['currency']])->fetchColumn()
                :false;
            $step=$this->query('SELECT external_gid,native_id,payload_digest
                FROM pm_shopify_import_step_receipts
                WHERE organization_id=? AND preview_id=? AND step_key=?',
                [$org,$previewId,'variant:'.$i])->fetch(PDO::FETCH_ASSOC);
            $variantMapping=$this->observations->mapping($org,$conn,'variant',$variant['gid']);
            $mapped=is_string($created)&&($variantMapping['canonical_id']??null)===$created;
            $fingerprint=hash('sha256',json_encode($variant,JSON_THROW_ON_ERROR));
            $nativeMatch=$native!==false
                &&$native['product_id']===$nativeId
                &&$native['sku']===$variant['sku']
                &&$native['status']==='active'
                &&(bool)$native['track_inventory']===$variant['track_inventory']
                &&$native['uom']===$variant['uom']
                &&(string)($native['barcode']??'')===(string)($variant['barcode']??'');
            $priceMatch=$price!==false
                &&self::amount((string)$price)===self::amount($variant['price']);
            $receiptMatch=$step!==false
                &&$step['external_gid']===$variant['gid']
                &&$step['native_id']===$created
                &&hash_equals((string)$step['payload_digest'],$fingerprint);
            $complete=$nativeMatch&&$priceMatch&&$receiptMatch&&$mapped
                &&$priceReceipt!==null&&($variant['barcode']===null||$barcodeReceipt!==null);
            if($complete)++$verified;
            $sideEffects=$sideEffects||$variantReceipt!==null||$barcodeReceipt!==null
                ||$priceReceipt!==null||$step!==false||$variantMapping!==null||$native!==false;
            $steps[]=[
                'index'=>$i,'native_variant_present'=>$native!==false,
                'native_variant_matches'=>$nativeMatch,
                'native_price_matches'=>$priceMatch,
                'product_variant_mapping_matches'=>$mapped,
                'native_step_receipt_matches'=>$receiptMatch,
                'native_price_receipt_present'=>$priceReceipt!==null,
                'native_barcode_receipt_present'=>$barcodeReceipt!==null,
                'complete'=>$complete,
            ];
        }

        $state=(string)$row['state'];
        $productMatch=$nativeProduct!==false
            &&$nativeProduct['name']===$candidate['name']
            &&$nativeProduct['product_type']==='stock'
            &&$nativeProduct['status']!=='archived';
        $independentlyVerified=false;
        if($state==='applied') {
            try {
                $attested=$this->writer->verifyApplied($org,$previewId,
                    (string)$row['source_hash'],(string)$row['normalized_digest']);
                $independentlyVerified=($attested['verified_variants']??0)===count($steps);
            }catch(Throwable) {
                $independentlyVerified=false;
            }
        }
        $decision=match(true) {
            $state==='applied' && $independentlyVerified => 'applied_verified',
            $state==='staged' && !$sideEffects && $row['action_key']===null
                &&$sourceValid => 'staged_no_native_effects',
            default => 'manual_reconciliation_required',
        };
        return [
            'preview_id'=>$previewId,'connection_id'=>$conn,
            'state'=>$state,'source_hash_matches_current'=>$sourceValid,
            'approved_plan_bound'=>$row['plan_id']!==null,
            'action_identity_bound'=>$row['action_key']!==null,
            'native_product_receipt_present'=>$productReceipt!==null,
            'native_product_matches'=>$productMatch,
            'native_product_mapping_matches'=>$productMapped,
            'expected_variants'=>count($steps),
            'verified_variant_steps'=>$verified,
            'native_side_effects_detected'=>$sideEffects,
            'independent_applied_evidence'=>$independentlyVerified,
            'variant_steps'=>$steps,'decision'=>$decision,
            'automatic_replay_authorized'=>false,
        ];
    }

    /** @return array<string,mixed>|null */
    private function receipt(string $org,string $previewId,string $step,string $operation):?array
    {
        $key='pm-shopify:'.hash('sha256',$org.':'.$previewId.':'.$step);
        $row=$this->query('SELECT operation,result_json FROM pm_operation_receipts
            WHERE organization_id=? AND idempotency_key=?',[$org,$key])->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        if($row['operation']!==$operation||!is_string($row['result_json'])) {
            throw new DomainException('Conflicting canonical Shopify operation receipt.');
        }
        return $row;
    }

    /** @param array<string,mixed>|null $receipt @return array<string,mixed> */
    private function result(?array $receipt):array
    {
        if($receipt===null)return [];
        $decoded=json_decode((string)$receipt['result_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)||array_is_list($decoded)) {
            throw new DomainException('Malformed native Shopify operation receipt.');
        }
        return $decoded;
    }

    private static function amount(string $number):string
    {
        [$whole,$fraction]=array_pad(explode('.',$number,2),2,'');
        $fraction=rtrim($fraction,'0');
        return $whole.($fraction===''?'':'.'.$fraction);
    }

    /** @param list<mixed> $values */
    private function query(string $sql,array $values):\PDOStatement
    {
        $stmt=$this->connection->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    }
}
