<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/** Read-only native projections. Each report is one statement snapshot, never a second stock authority. */
final readonly class MySqlProductAnalytics
{
    public function __construct(private PDO $connection,private MySqlStockReconciliationAudit $audit){}
    private function tenant(string $org):void
    {
        if($org===''||strlen($org)>40||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D',$org))throw new DomainException('Trusted tenant required.');
    }
    private function page(int $limit,int $offset):void
    {
        if($limit<1||$limit>100||$offset<0||$offset>10000000)throw new DomainException('Invalid analytics page.');
    }
    private function currency(string $currency):void
    {
        if(!preg_match('/^[A-Z]{3}$/D',$currency))throw new DomainException('Explicit currency required.');
    }
    private function rows(string $sql,array $params):array
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function id(?string $id):void
    {
        if($id!==null&&!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid location.');
    }
    public function summary(string $org,string $currency):array
    {
        $this->tenant($org);$this->currency($currency);
        $sql=<<<'SQL'
WITH products AS (SELECT * FROM pm_products WHERE organization_id=?),
 variants AS (SELECT v.* FROM pm_variants v JOIN products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id WHERE v.status='active' AND p.status<>'archived'),
 balances AS (SELECT b.* FROM pm_stock_balances b JOIN variants v ON v.organization_id=b.organization_id AND v.variant_id=b.variant_id),
 stock AS (SELECT v.variant_id,COALESCE(SUM(b.on_hand),0) on_hand,COALESCE(SUM(b.reserved),0) reserved FROM variants v LEFT JOIN balances b ON b.variant_id=v.variant_id WHERE v.track_inventory=1 GROUP BY v.variant_id),
 missing AS (SELECT DISTINCT pc.product_id,a.code FROM pm_product_categories pc JOIN products p ON p.organization_id=pc.organization_id AND p.product_id=pc.product_id JOIN pm_category_attributes a ON a.organization_id=pc.organization_id AND a.category_id=pc.category_id LEFT JOIN pm_product_attribute_values av ON av.organization_id=pc.organization_id AND av.product_id=pc.product_id AND av.code=a.code WHERE p.status<>'archived' AND a.is_required=1 AND av.code IS NULL)
SELECT
 (SELECT COUNT(*) FROM products) products_total,
 (SELECT COUNT(*) FROM products WHERE status='draft') products_draft,
 (SELECT COUNT(*) FROM products WHERE status='active') products_active,
 (SELECT COUNT(*) FROM products WHERE status='archived') products_archived,
 (SELECT COUNT(*) FROM variants) active_skus,
 (SELECT COUNT(*) FROM stock) tracked_skus,
 (SELECT COUNT(*) FROM stock WHERE on_hand-reserved=0) unavailable_skus,
 (SELECT COUNT(*) FROM stock WHERE reserved>0) reserved_skus,
 (SELECT COUNT(*) FROM balances) stock_positions,
 (SELECT COUNT(*) FROM pm_locations WHERE organization_id=? AND status='active') active_locations,
 (SELECT COUNT(*) FROM variants v LEFT JOIN pm_base_prices pr ON pr.organization_id=v.organization_id AND pr.variant_id=v.variant_id AND pr.currency=? WHERE pr.variant_id IS NULL) unpriced_skus,
 (SELECT COUNT(DISTINCT product_id) FROM missing) products_missing_required,
 (SELECT COUNT(*) FROM products p WHERE p.status<>'archived' AND NOT EXISTS (SELECT 1 FROM pm_media_refs m WHERE m.organization_id=p.organization_id AND m.product_id=p.product_id AND m.status='active')) products_without_media,
 (SELECT COUNT(*) FROM stock s LEFT JOIN pm_base_prices pr ON pr.organization_id=? AND pr.variant_id=s.variant_id AND pr.currency=? WHERE s.on_hand>0 AND pr.variant_id IS NULL) unpriced_stock_skus,
 (SELECT CAST(COALESCE(SUM(s.on_hand*pr.amount),0) AS DECIMAL(60,10)) FROM stock s JOIN pm_base_prices pr ON pr.organization_id=? AND pr.variant_id=s.variant_id AND pr.currency=?) priced_stock_at_base
SQL;
        $row=$this->rows($sql,[$org,$org,$currency,$org,$currency,$org,$currency])[0];
        $estimate=(string)$row['priced_stock_at_base'];unset($row['priced_stock_at_base']);
        return ['currency'=>$currency,'metrics'=>array_map('intval',$row),'priced_stock_at_base'=>$estimate,
            'value_basis'=>'current_base_price_not_cost','value_complete'=>(int)$row['unpriced_stock_skus']===0];
    }
    public function stock(string $org,?string $location,string $risk,string $low,string $high,int $limit,int $offset):array
    {
        $this->tenant($org);$this->id($location);$this->page($limit,$offset);
        foreach([$low,$high] as $q)if(!preg_match('/^(0|[1-9][0-9]{0,13})(\.[0-9]{1,6})?$/D',$q))throw new DomainException('Invalid threshold.');
        $where=match($risk){'all'=>'1=1','unavailable'=>'available=0','low'=>'available>0 AND available<=low_threshold','high'=>'available>=high_threshold AND available>0','reserved'=>'reserved>0',default=>throw new DomainException('Invalid stock risk.')};
        $sql=<<<'SQL'
WITH params AS (SELECT ? AS org,? AS loc,CAST(? AS DECIMAL(20,6)) AS low_threshold,CAST(? AS DECIMAL(20,6)) AS high_threshold),
 eligible AS (SELECT v.variant_id,v.sku,v.uom,p.product_id,p.name,v.organization_id FROM pm_variants v JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id JOIN params x ON x.org=v.organization_id WHERE v.status='active' AND p.status<>'archived' AND v.track_inventory=1),
 scope AS (SELECT e.*,l.location_id,l.code location_code,CAST(COALESCE(b.on_hand,0) AS DECIMAL(20,6)) on_hand,CAST(COALESCE(b.reserved,0) AS DECIMAL(20,6)) reserved,CAST(COALESCE(b.on_hand-b.reserved,0) AS DECIMAL(20,6)) available,x.low_threshold,x.high_threshold,(b.variant_id IS NOT NULL) balance_present FROM eligible e JOIN params x ON x.org=e.organization_id JOIN pm_locations l ON l.organization_id=e.organization_id AND (x.loc IS NULL OR l.location_id=x.loc) LEFT JOIN pm_stock_balances b ON b.organization_id=e.organization_id AND b.variant_id=e.variant_id AND b.location_id=l.location_id WHERE l.status='active' AND (b.variant_id IS NOT NULL OR x.loc IS NOT NULL))
SELECT variant_id,sku,uom,product_id,name,location_id,location_code,on_hand,reserved,available,balance_present FROM scope WHERE low_threshold<high_threshold AND
SQL;
        $normalize=static function(string $q):string{[$a,$b]=array_pad(explode('.',$q,2),2,'');return str_pad($a,14,'0',STR_PAD_LEFT).str_pad($b,6,'0');};
        if(strcmp($normalize($low),$normalize($high))>=0)throw new DomainException('Low threshold must be below high threshold.');
        if($location!==null&&$this->rows("SELECT location_id FROM pm_locations WHERE organization_id=? AND location_id=? AND status='active'",[$org,$location])===[])
            throw new DomainException('Unknown active tenant location.');
        $rows=$this->rows($sql.' '.$where.' ORDER BY available,variant_id,location_id LIMIT '.$limit.' OFFSET '.$offset,[$org,$location,$low,$high]);
        return ['items'=>array_map(static function(array $r):array{$r['balance_present']=(bool)$r['balance_present'];return $r;},$rows),'limit'=>$limit,'offset'=>$offset,'scope'=>$location===null?'recorded_active_positions':'selected_active_location_all_tracked_skus','threshold_basis'=>'operator_quantity_not_forecast'];
    }
    public function movement(string $org,string $from,string $to,int $limit,int $offset):array
    {
        $this->tenant($org);$this->page($limit,$offset);$utc=new DateTimeZone('UTC');
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from,$utc);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to,$utc);
        if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$b<=$a||$a->diff($b)->days>366)
            throw new DomainException('Use UTC date interval [from,to), maximum 366 days.');
        $sql=<<<'SQL'
WITH params AS (SELECT ? org,CAST(? AS DATETIME) start_at,CAST(? AS DATETIME) end_at),
 facts AS (
 SELECT m.variant_id,
 SUM(CASE WHEN m.occurred_at<x.start_at THEN m.quantity_delta ELSE 0 END) opening_qty,
 SUM(m.quantity_delta) closing_qty,
 SUM(CASE WHEN m.occurred_at>=x.start_at AND m.movement_type='receipt' AND m.idempotency_key NOT LIKE 'pm-xfer-in:%' THEN m.quantity_delta ELSE 0 END) receipts,
 SUM(CASE WHEN m.occurred_at>=x.start_at AND m.movement_type='issue' AND m.idempotency_key NOT LIKE 'pm-xfer-out:%' THEN -m.quantity_delta ELSE 0 END) issues,
 SUM(CASE WHEN m.occurred_at>=x.start_at AND m.movement_type='adjustment' THEN m.quantity_delta ELSE 0 END) adjustments,
 SUM(CASE WHEN m.occurred_at>=x.start_at AND m.idempotency_key LIKE 'pm-xfer-out:%' THEN -m.quantity_delta ELSE 0 END) transferred,
 SUM(CASE WHEN m.occurred_at>=x.start_at THEN 1 ELSE 0 END) movement_count
 FROM pm_stock_movements m JOIN params x ON x.org=m.organization_id WHERE m.occurred_at<x.end_at GROUP BY m.variant_id
)
SELECT v.variant_id,v.sku,v.uom,p.product_id,p.name,
 CAST(COALESCE(f.opening_qty,0) AS DECIMAL(40,6)) opening_qty,
 CAST(COALESCE(f.closing_qty,0) AS DECIMAL(40,6)) closing_qty,
 CAST(COALESCE(f.receipts,0) AS DECIMAL(40,6)) receipts,
 CAST(COALESCE(f.issues,0) AS DECIMAL(40,6)) issues,
 CAST(COALESCE(f.adjustments,0) AS DECIMAL(40,6)) adjustments,
 CAST(COALESCE(f.transferred,0) AS DECIMAL(40,6)) transferred,
 COALESCE(f.movement_count,0) movement_count,
 CASE WHEN f.opening_qty>=0 AND f.closing_qty>=0 AND f.opening_qty+f.closing_qty>0
 THEN CAST(2*f.issues/(f.opening_qty+f.closing_qty) AS DECIMAL(40,6)) ELSE NULL END physical_turnover
FROM pm_variants v JOIN params x ON x.org=v.organization_id JOIN pm_products p ON p.organization_id=v.organization_id AND p.product_id=v.product_id LEFT JOIN facts f ON f.variant_id=v.variant_id
WHERE v.track_inventory=1 AND (f.variant_id IS NOT NULL OR (v.status='active' AND p.status<>'archived'))
ORDER BY movement_count DESC,v.variant_id
SQL;
        $rows=$this->rows($sql.' LIMIT '.$limit.' OFFSET '.$offset,[$org,$from,$to]);
        return ['from'=>$from,'to'=>$to,'basis'=>'native_ledger_nontransfer_issues_over_endpoint_average','items'=>array_map(static function(array $r):array{$r['movement_count']=(int)$r['movement_count'];return $r;},$rows),'limit'=>$limit,'offset'=>$offset];
    }
    public function quality(string $org,string $currency,string $kind,int $limit,int $offset):array
    {
        $this->tenant($org);$this->currency($currency);$this->page($limit,$offset);
        $where=match($kind){'missing_required'=>'missing_required>0','no_media'=>'has_media=0','no_price'=>'unpriced_skus>0','no_category'=>'has_category=0',default=>throw new DomainException('Unknown catalog quality filter.')};
        $sql=<<<'SQL'
WITH params AS (SELECT ? org,? currency), flags AS (
 SELECT p.product_id,p.name,p.status,
 (SELECT COUNT(DISTINCT a.code) FROM pm_product_categories pc JOIN pm_category_attributes a ON a.organization_id=pc.organization_id AND a.category_id=pc.category_id LEFT JOIN pm_product_attribute_values av ON av.organization_id=pc.organization_id AND av.product_id=pc.product_id AND av.code=a.code WHERE pc.organization_id=p.organization_id AND pc.product_id=p.product_id AND a.is_required=1 AND av.code IS NULL) missing_required,
 EXISTS(SELECT 1 FROM pm_media_refs m WHERE m.organization_id=p.organization_id AND m.product_id=p.product_id AND m.status='active') has_media,
 EXISTS(SELECT 1 FROM pm_product_categories pc WHERE pc.organization_id=p.organization_id AND pc.product_id=p.product_id) has_category,
 (SELECT COUNT(*) FROM pm_variants v LEFT JOIN pm_base_prices pr ON pr.organization_id=v.organization_id AND pr.variant_id=v.variant_id AND pr.currency=x.currency WHERE v.organization_id=p.organization_id AND v.product_id=p.product_id AND v.status='active' AND pr.variant_id IS NULL) unpriced_skus
 FROM pm_products p JOIN params x ON x.org=p.organization_id WHERE p.status<>'archived')
SELECT * FROM flags WHERE
SQL;
        $rows=$this->rows($sql.' '.$where.' ORDER BY product_id LIMIT '.$limit.' OFFSET '.$offset,[$org,$currency]);
        return ['currency'=>$currency,'kind'=>$kind,'items'=>array_map(static function(array $r):array{foreach(['missing_required','unpriced_skus'] as $k)$r[$k]=(int)$r[$k];foreach(['has_media','has_category'] as $k)$r[$k]=(bool)$r[$k];return $r;},$rows),'limit'=>$limit,'offset'=>$offset];
    }
    public function reconciliation(string $org,int $limit):array{return $this->audit->audit($org,$limit);}
}
