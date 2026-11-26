<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOrderRepositoryInterface;
use PDO;
use LogicException;

final readonly class MysqlSalesOrderRepository implements SalesOrderRepositoryInterface, \Domains\Sales\Application\Contract\SalesOrderLifecycleRepositoryInterface, \Domains\Sales\Application\Contract\SalesCommerceOrderEvidencePort, \Domains\Sales\Application\Contract\SalesOrderJourneyStore, \Domains\Sales\Application\Contract\SalesCommerceOrderTouchHistoryReadPort, \Domains\Sales\Application\Contract\SalesCommerceOrderLifecycleEvidencePort, \Domains\Sales\Application\Contract\SalesOrderReportReadPort
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
        $q=$this->connection->prepare('SELECT e.payload,o.deal_id FROM sales_order_evidence e JOIN sales_orders o
            ON o.organization_id=e.organization_id AND o.order_id=e.order_id
            WHERE e.organization_id=? AND BINARY e.organization_id=BINARY ? AND e.order_id=? AND e.revision=1 AND e.event_type="sales.commerce.order.placed"');
        $q->execute([$organizationId,$organizationId,$orderId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $fact=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        if (($fact['organization_id']??null)!==$organizationId||($fact['order_id']??null)!==$orderId||
            ($fact['event_type']??null)!=='sales.commerce.order.placed'||($fact['schema_version']??null)!=='1.0.0')
            throw new LogicException('Native placement evidence integrity failed.');
        // The relationship must be an immutable native placement fact, not a guessed
        // customer match or an inconsistent event. The consumer quarantines this case.
        $deal=$row['deal_id']===null?null:(int)$row['deal_id'];
        if (!array_key_exists('deal_id',$fact)||$fact['deal_id']!==$deal||($deal!==null&&$deal<1))
            throw new \DomainException('Native placement relationship inconsistent.');
        $fact['attribution_observations']=(object)$fact['attribution_observations'];
        return $fact;
    }
    public function reportCandidates(string $org,?int $owner,string $from,string $to):array
    {
        $this->transaction();
        $sql='SELECT order_id,owner_user_id,deal_id,status,currency,total FROM sales_orders
            WHERE organization_id=? AND BINARY organization_id=BINARY ? AND placed_at>=? AND placed_at<?';
        $args=[$org,$org,$from.' 00:00:00',$to.' 00:00:00'];
        if($owner!==null){$sql.=' AND owner_user_id=?';$args[]=$owner;}
        $q=$this->connection->prepare($sql.' ORDER BY order_id LIMIT 501 FOR UPDATE');
        $q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function lifecycleEvidence(string $organizationId,string $orderId,int $afterRevision=1,int $limit=100):?array
    {
        if($organizationId===''||strlen($organizationId)>40||!preg_match('/^[a-f0-9]{32}$/D',$orderId)||
            $afterRevision<1||$afterRevision>4294967295||$limit<1||$limit>100)
            throw new \DomainException('Invalid lifecycle evidence query.');
        // One statement gives a consistent order head and bounded event page, including an empty page.
        $q=$this->connection->prepare('SELECT o.revision AS order_revision,e.event_id,e.revision,e.event_type,e.payload
            FROM sales_orders o LEFT JOIN sales_order_evidence e
                ON e.organization_id=o.organization_id AND e.order_id=o.order_id AND e.revision>?
            WHERE o.organization_id=? AND BINARY o.organization_id=BINARY ? AND o.order_id=?
            ORDER BY e.revision,e.event_id LIMIT '.($limit+1));
        $q->execute([$afterRevision,$organizationId,$organizationId,$orderId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return null;
        $head=(int)$rows[0]['order_revision'];
        if($afterRevision>$head)throw new \DomainException('Lifecycle evidence cursor exceeds order revision.');
        $events=[];$cursor=$afterRevision;
        foreach($rows as $row){
            if($row['event_id']===null)continue;
            $fact=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            $rev=(int)$row['revision'];$type=$row['event_type'];
            if(!is_array($fact)||$rev!==$cursor+1||$rev>$head||($fact['revision']??null)!==$rev||
                ($fact['organization_id']??null)!==$organizationId||($fact['order_id']??null)!==$orderId||
                ($fact['schema_version']??null)!=='1.0.0'||($fact['event_id']??null)!==$row['event_id']||
                !preg_match('/^[a-f0-9]{32}$/D',$row['event_id'])||($fact['event_type']??null)!==$type||
                !in_array($type,['sales.commerce.order.confirm','sales.commerce.order.allocate','sales.commerce.order.release',
                    'sales.commerce.order.fulfill','sales.commerce.order.cancel','sales.commerce.order.return'],true)||
                !in_array($fact['previous_status']??null,['placed','confirmed','cancelled','completed'],true)||
                !in_array($fact['status']??null,['placed','confirmed','cancelled','completed'],true)||
                !is_string($fact['occurred_at']??null)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$fact['occurred_at'])||
                !is_array($fact['effects']??null)||!array_is_list($fact['effects'])||
                !array_key_exists('realized_revenue',$fact)||$fact['realized_revenue']!==null||
                !array_key_exists('financial_evidence_reference',$fact)||$fact['financial_evidence_reference']!==null)
                throw new LogicException('Lifecycle evidence integrity failed.');
            $quantity=0;
            foreach($fact['effects'] as $effect){
                if(!is_array($effect)||!is_int($effect['quantity']??null)||$effect['quantity']<1)
                    throw new LogicException('Lifecycle evidence quantity invalid.');
                $quantity+=$effect['quantity'];
            }
            if(count($events)===$limit)break;
            $events[]=['schema_version'=>'1.0.0','organization_id'=>$organizationId,'order_id'=>$orderId,
                'event_id'=>$row['event_id'],'event_type'=>$type,'revision'=>$rev,'occurred_at'=>$fact['occurred_at'],
                'previous_status'=>$fact['previous_status'],'status'=>$fact['status'],'effect_quantity'=>$quantity];
            $cursor=$rev;
        }
        if($cursor<$head&&count($rows)<=$limit)throw new LogicException('Lifecycle evidence revision gap.');
        return ['order_revision'=>$head,'events'=>$events,'next_after_revision'=>$cursor,'has_more'=>$cursor<$head];
    }
    public function recordJourney(string $org,string $order,string $store,string $external,array $journey):void
    {
        $this->transaction();
        $fact=$this->placement($org,$order);
        if($fact===null||$fact['channel']!=='shopify'||$fact['store_reference']!==$store||$fact['external_order_id']!==$external)
            throw new LogicException('Journey does not belong to native Order.');
        $journey=($journey['model']??null)==='shopify-journey-moments-v1'?\Domains\Sales\Domain\Policy\SalesOrderTouchHistoryEvidence::normalize($journey,$fact['occurred_at']):\Domains\Sales\Domain\Policy\SalesOrderJourneyEvidence::normalize($journey,$fact['occurred_at']);
        $hash=hash('sha256',json_encode($journey,JSON_THROW_ON_ERROR));
        $q=$this->connection->prepare('INSERT INTO sales_order_journey_evidence(organization_id,order_id,evidence_hash,evidence_json)
            VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE evidence_hash=evidence_hash');
        $q->execute([$org,$order,$hash,json_encode($journey,JSON_THROW_ON_ERROR)]);
    }
    public function journey(string $org,string $order):?array
    {return $this->journeyModel($org,$order,'shopify-last-visit-utm-v1');}
    public function touchHistory(string $org,string $order):?array
    {return $this->journeyModel($org,$order,'shopify-journey-moments-v1');}
    private function journeyModel(string $org,string $order,string $model):?array
    {
        if($org===''||!preg_match('/^[a-f0-9]{32}$/D',$order))return null;
        $q=$this->connection->prepare("SELECT evidence_hash,evidence_json FROM sales_order_journey_evidence WHERE organization_id=? AND BINARY organization_id=BINARY ? AND order_id=? AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.model'))=? ORDER BY evidence_hash LIMIT 2");
        $q->execute([$org,$org,$order,$model]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return null;
        if(count($rows)!==1)return ['state'=>'conflict'];
        $e=json_decode($rows[0]['evidence_json'],true,16,JSON_THROW_ON_ERROR);
        $fact=$this->placement($org,$order);
        if($fact===null)throw new LogicException('Journey placement missing.');
        $e=$model==='shopify-journey-moments-v1'?\Domains\Sales\Domain\Policy\SalesOrderTouchHistoryEvidence::normalize($e,$fact['occurred_at']):\Domains\Sales\Domain\Policy\SalesOrderJourneyEvidence::normalize($e,$fact['occurred_at']);
        if(!hash_equals($rows[0]['evidence_hash'],hash('sha256',json_encode($e,JSON_THROW_ON_ERROR))))throw new LogicException('Journey integrity failed.');
        return ['state'=>'observed','evidence_hash'=>$rows[0]['evidence_hash'],...$e];
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
    public function hasDocument(string $org,string $order,string $operation,string $reference):bool
    {
        $this->transaction();
        $q=$this->connection->prepare('SELECT event_id FROM sales_order_lifecycle_documents WHERE organization_id=? AND order_id=? AND operation_type=? AND source_reference=? FOR UPDATE');
        $q->execute([$org,$order,$operation,$reference]);
        return $q->fetchColumn()!==false;
    }
    public function transition(string $org,string $order,int $revision,string $status,array $evidence):void
    {
        $this->transaction();
        $q=$this->connection->prepare('UPDATE sales_orders SET status=?,revision=revision+1 WHERE organization_id=? AND order_id=? AND revision=?');
        $q->execute([$status,$org,$order,$revision]);
        if($q->rowCount()!==1)throw new \DomainException('Order revision changed.');
        $this->connection->prepare('INSERT INTO sales_order_evidence(organization_id,event_id,order_id,revision,event_type,occurred_at,payload) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6),?)')
            ->execute([$org,$evidence['event_id'],$order,$revision+1,$evidence['event_type'],json_encode($evidence,JSON_THROW_ON_ERROR)]);
        $this->connection->prepare('INSERT INTO sales_order_lifecycle_documents(organization_id,order_id,operation_type,source_reference,event_id) VALUES(?,?,?,?,?)')
            ->execute([$org,$order,substr($evidence['event_type'],strlen('sales.commerce.order.')),$evidence['reference'],$evidence['event_id']]);
    }
    private function transaction():void
    {
        if(!$this->connection->inTransaction())throw new LogicException('Order write requires a transaction.');
    }
}
