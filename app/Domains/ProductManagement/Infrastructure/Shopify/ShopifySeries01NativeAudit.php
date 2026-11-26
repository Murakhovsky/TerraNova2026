<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use PDO;

/** Read-only S2 evidence. Native existence is NOT proof of approved import. */
final readonly class ShopifySeries01NativeAudit
{
    public function __construct(private PDO $connection) {}
    public function inspect(string $org):array
    {
        $expected=[];foreach(['L','F','A'] as $family)foreach([1,2,3] as $n)$expected[]='SERIES01-'.$family.'0'.$n;
        $stmt=$this->connection->prepare("SELECT p.product_id,p.status,v.variant_id,v.sku,v.status AS variant_status,b.amount,b.revision AS price_revision FROM pm_products p JOIN pm_variants v ON v.organization_id=p.organization_id AND v.product_id=p.product_id LEFT JOIN pm_base_prices b ON b.organization_id=v.organization_id AND b.variant_id=v.variant_id AND b.currency='USD' WHERE p.organization_id=? AND v.sku IN (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$org,...$expected]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $bySku=[];foreach($rows as $row)$bySku[$row['sku']][]=$row;
        $items=[];$seen=[];$ready=0;
        foreach($expected as $sku){
            $matches=$bySku[$sku]??[];$row=count($matches)===1?$matches[0]:null;
            $valid=$row!==null&&$row['status']!=='archived'&&$row['variant_status']==='active'&&is_string($row['amount'])&&preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D',$row['amount'])&&(float)$row['amount']>=300&&!isset($seen[$row['product_id']]);
            if($valid){$seen[$row['product_id']]=true;++$ready;}
            $items[]=['sku'=>$sku,'canonical_product_id'=>$row['product_id']??null,'canonical_variant_id'=>$row['variant_id']??null,
                'usd_price'=>$row['amount']??null,'native_identity_verified'=>$valid];
        }
        return ['expected'=>9,'verified_native_identities'=>$ready,'all_nine_present'=>$ready===9,'items'=>$items,
            'approved_import_proven'=>false,'note'=>'Verify linked approved import Run and native receipts separately.'];
    }
}
