<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOrderRepositoryInterface;
use PDO;
use LogicException;

final readonly class MysqlSalesOrderRepository implements SalesOrderRepositoryInterface, \Domains\Sales\Application\Contract\SalesOrderLifecycleRepositoryInterface, \Domains\Sales\Application\Contract\SalesCommerceOrderEvidencePort
{
    public function __construct(private PDO $connection){}
    public function create(string $org,array $order,array $lines,array $evidence):void
    {
        $this->transaction();
        $this->connection->prepare('INSERT INTO sales_orders(organization_id,order_id,owner_user_id,deal_id,customer_id,channel,store_reference,external_order_id,currency,subtotal,discount,tax,tax_included,shipping,total,attribution_json,placed_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$org,$order['order_id'],$order['owner_id'],$order['deal_id'],$order['customer_id'],$order['channel'],$order['store_reference'],$order['external_order_id'],$order['currency'],$order['subtotal'],$order['discount'],$order['tax'],(int)$order['tax_included'],$order['shipping'],$order['total'],json_encode((object)$order['attribution'],JSON_THROW_ON_ERROR),$order['placed_at'],$order['created_by']]);
        $stmt=$this->connection->prepare('INSERT INTO sales_order_lines(organization_id,order_id,line_no,sku,title,product_id,variant_id,quantity,unit_price,subtotal) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach($lines as $line)$stmt->execute([$org,$order['order_id'],$line['line_no'],$line['sku'],$line['title'],$line['product_id'],$line['variant_id'],$line['quantity'],$line['unit_price'],$line['subtotal']]);
        $this->connection->prepare('INSERT INTO sales_order_evidence(organization_id,event_id,order_id,revision,event_type,occurred_at,payload) VALUES(?,?,?,?,?,?,?)')
            ->execute([$org,$evidence['event_id'],$order['order_id'],1,$evidence['event_type'],$order['placed_at'],json_encode($evidence,JSON_THROW_ON_ERROR)]);
    }
    public function get(string $org,string $id,bool $lock=false):?array
    {
        if ($lock)$this->transaction();
        $q=$this->connection->prepare('SELECT * FROM sales_orders WHERE organization_id=? AND order_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $q=$this->connection->prepare('SELECT line_no,sku,title,product_id,variant_id,quantity,unit_price,subtotal FROM sales_order_lines WHERE organization_id=? AND order_id=? ORDER BY line_no');
        $q->execute([$org,$id]);$lines=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($lines as &$line){$line['line_no']=(int)$line['line_no'];$line['quantity']=(int)$line['quantity'];}unset($line);
        $result=['order_id'=>$row['order_id'],'owner_id'=>(int)$row['owner_user_id'],'deal_id'=>$row['deal_id']===null?null:(int)$row['deal_id'],
            'customer_id'=>$row['customer_id']===null?null:(int)$row['customer_id'],'channel'=>$row['channel'],'store_reference'=>$row['store_reference'],
            'external_order_id'=>$row['external_order_id'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'currency'=>$row['currency'],
            'tax_included'=>(bool)$row['tax_included'],'placed_at'=>str_replace(' ','T',$row['placed_at']).'Z','lines'=>$lines];
        foreach(['subtotal','discount','tax','shipping','total'] as $key)$result[$key]=(string)$row[$key];
        return $result; // Attribution observations are not a user-visible customer profile.
    }
    public function placement(string $organizationId,string $orderId):?array
    {
        if ($organizationId===''||preg_match('/^[a-f0-9]{32}$/D',$orderId)!==1) return null;
        $q=$this->connection->prepare('SELECT payload FROM sales_order_evidence WHERE organization_id=? AND order_id=? AND revision=1 AND event_type="sales.commerce.order.placed"');
        $q->execute([$organizationId,$orderId]);$raw=$q->fetchColumn();
        if (!is_string($raw)) return null;
        $fact=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (($fact['organization_id']??null)!==$organizationId||($fact['order_id']??null)!==$orderId||
            ($fact['event_type']??null)!=='sales.commerce.order.placed'||($fact['schema_version']??null)!=='1.0.0')
            throw new LogicException('Native placement evidence integrity failed.');
        $fact['attribution_observations']=(object)$fact['attribution_observations'];
        return $fact;
    }
    public function customerForDeal(string $org,int $deal):?int
    {
        $this->transaction();
        $q=$this->connection->prepare('SELECT person_id FROM tn_client_cases WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$org,$deal]);$id=(int)$q->fetchColumn();return $id>0?$id:null;
    }
    public function allocations(string $org,string $order):array
    {
        $q=$this->connection->prepare('SELECT allocation_id,line_no,location_id,reservation_id,stock_mode,quantity,fulfilled,cancelled,returned FROM sales_order_allocations WHERE organization_id=? AND order_id=? ORDER BY line_no,allocation_id'.($this->connection->inTransaction()?' FOR UPDATE':''));
        $q->execute([$org,$order]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r)foreach(['line_no','quantity','fulfilled','cancelled','returned'] as $field)$r[$field]=(int)$r[$field];unset($r);
        return $rows;
    }
    public function addAllocation(string $org,string $order,array $a):void
    {
        $this->transaction();
        $this->connection->prepare('INSERT INTO sales_order_allocations(organization_id,order_id,allocation_id,line_no,location_id,reservation_id,stock_mode,quantity) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$org,$order,$a['allocation_id'],$a['line_no'],$a['location_id'],$a['reservation_id'],$a['stock_mode'],$a['quantity']]);
    }
    public function updateAllocation(string $org,string $order,array $a):void
    {
        $this->transaction();
        $q=$this->connection->prepare('UPDATE sales_order_allocations SET fulfilled=?,cancelled=?,returned=? WHERE organization_id=? AND order_id=? AND allocation_id=?');
        $q->execute([$a['fulfilled'],$a['cancelled'],$a['returned'],$org,$order,$a['allocation_id']]);
        if($q->rowCount()!==1)throw new LogicException('Order allocation write failed.');
    }
    public function transition(string $org,string $order,int $revision,string $status,array $evidence):void
    {
        $this->transaction();
        $q=$this->connection->prepare('UPDATE sales_orders SET status=?,revision=revision+1 WHERE organization_id=? AND order_id=? AND revision=?');
        $q->execute([$status,$org,$order,$revision]);
        if($q->rowCount()!==1)throw new \DomainException('Order revision changed.');
        $this->connection->prepare('INSERT INTO sales_order_evidence(organization_id,event_id,order_id,revision,event_type,occurred_at,payload) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6),?)')
            ->execute([$org,$evidence['event_id'],$order,$revision+1,$evidence['event_type'],json_encode($evidence,JSON_THROW_ON_ERROR)]);
    }
    private function transaction():void
    {
        if(!$this->connection->inTransaction())throw new LogicException('Order write requires a transaction.');
    }
}
