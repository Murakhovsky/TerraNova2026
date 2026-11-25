<?php
declare(strict_types=1);
namespace Domains\Finance\Infrastructure\Persistence\MySql;
use DomainException;
use PDO;
use Throwable;
use Domains\Finance\Domain\CommerceOrderMoney as Money;
use Domains\Finance\Application\Contract\CommerceOrderFinancialEvidenceReadPort;
use Domains\Finance\Application\Contract\FinanceOrderExecutionAuthority;
use Domains\Sales\Application\Contract\SalesCommerceOrderEvidencePort;
use Kernel\Module\ActiveModuleResolver;

/** Approved Action-only mutations. No provider writes; independently reconciled documentary evidence only. */
final readonly class MysqlCommerceOrderPaymentStore implements CommerceOrderFinancialEvidenceReadPort
{
    public function __construct(private PDO $connection,private SalesCommerceOrderEvidencePort $sales,private ActiveModuleResolver $modules,private FinanceOrderExecutionAuthority $authority){}
    public function financialEvidence(string $organizationId,string $orderId):?array
    {
        if(!$this->modules->isEnabled($organizationId,'finance'))return null;
        $b=$this->one('SELECT * FROM cos_finance_order_balances WHERE organization_id=? AND order_id=?',[$organizationId,Money::id($orderId)]);
        return $b===null||(int)$b['revision']===0?null:Money::projection($b);
    }
    public function register(string $org,string $id,array $p,string $actor,string $action,string $trace,string $key):array
    {
        $this->active($org);Money::id($id);
        $kind=Money::kind($p['kind']??null);$n=Money::amount($p['amount']??null);$currency=Money::currency($p['currency']??null);
        $account=Money::ref($p['source_account']??null,120);$source=Money::ref($p['source_reference']??null);
        $parent=$p['parent_id']??null;
        if($kind==='capture'&&$parent!==null)throw new DomainException('Unexpected capture parent.');
        if($kind!=='capture')$parent=Money::id($parent);
        $hash=self::hash([$id,$kind,$n,$currency,$account,$source,$parent,$actor]);
        return $this->tx(function()use($org,$id,$kind,$n,$currency,$account,$source,$parent,$actor,$action,$trace,$key,$hash,$p):array{
            $this->authority->assertApproved($org,$id,'finance.order.payment.register',$p,$actor,$action,$trace,$key);
            $placed=$this->sales->placement($org,$id);
            if($placed===null||($placed['organization_id']??null)!==$org||($placed['order_id']??null)!==$id||($placed['currency']??null)!==$currency)throw new DomainException('Order/currency unavailable.');
            $total=Money::amount($placed['order_total']??null,true);
            $this->exec('INSERT INTO cos_finance_order_balances (organization_id,order_id,currency,order_total_minor) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE order_id=order_id',[$org,$id,$currency,$total]);
            $b=$this->lock($org,$id);
            if($b['currency']!==$currency||(int)$b['order_total_minor']!==$total)throw new DomainException('Commercial terms conflict.');
            $old=$this->one('SELECT * FROM cos_finance_order_operations WHERE organization_id=? AND idempotency_key=? FOR UPDATE',[$org,$key]);
            if($old!==null)return $this->receipt($old,$hash,'pending_registration');
            if($parent!==null){
                $base=$this->operation($org,$id,$parent);
                if($base['kind']!==($kind==='chargeback_reversal'?'chargeback':'capture')||$base['source_account']!==$account)throw new DomainException('Invalid operation parent.');
            }
            if($this->one('SELECT id FROM cos_finance_order_operations WHERE organization_id=? AND source_account=? AND source_reference=? FOR UPDATE',[$org,$account,$source])!==null)throw new DomainException('Source transaction already registered.');
            $op=bin2hex(random_bytes(16));
            $this->exec('INSERT INTO cos_finance_order_operations (organization_id,id,order_id,kind,amount_minor,parent_id,source_account,source_reference,actor_id,action_id,correlation_id,idempotency_key,request_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$op,$id,$kind,$n,$parent,$account,$source,$actor,$action,$trace,$key,$hash]);
            return ['finance_reference'=>$op,'state'=>'pending_registration'];
        });
    }
    public function reconcile(string $org,string $id,array $p,string $actor,string $action,string $trace,string $key):array
    {
        $this->active($org);Money::id($id);
        $op=Money::id($p['operation_id']??null);$n=Money::amount($p['amount']??null);$currency=Money::currency($p['currency']??null);
        $account=Money::ref($p['source_account']??null,120);$source=Money::ref($p['source_reference']??null);
        $statement=Money::ref($p['statement_reference']??null);$kind=Money::kind($p['kind']??null);$rev=$p['expected_revision']??null;
        if(!is_int($rev)||$rev<0)throw new DomainException('Invalid financial revision.');
        $hash=self::hash([$id,$op,$n,$currency,$account,$source,$statement,$kind,$rev,$actor]);
        return $this->tx(function()use($org,$id,$op,$n,$currency,$account,$source,$statement,$kind,$rev,$actor,$action,$trace,$key,$hash,$p):array{
            $this->authority->assertApproved($org,$id,'finance.order.payment.reconcile',$p,$actor,$action,$trace,$key);
            $b=$this->lock($org,$id);
            $old=$this->one('SELECT * FROM cos_finance_order_reconciliations WHERE organization_id=? AND idempotency_key=? FOR UPDATE',[$org,$key]);
            if($old!==null)return $this->receipt($old,$hash,'reconciled');
            $o=$this->operation($org,$id,$op);
            if((int)$b['revision']!==$rev||$b['currency']!==$currency||(int)$o['amount_minor']!==$n||$o['kind']!==$kind||$o['source_account']!==$account||$o['source_reference']!==$source)throw new DomainException('Statement mismatch or stale revision.');
            if((string)$o['actor_id']===$actor)throw new DomainException('Independent reconciliation requester required.');
            if($this->one('SELECT id FROM cos_finance_order_reconciliations WHERE organization_id=? AND operation_id=? FOR UPDATE',[$org,$op])!==null)throw new DomainException('Operation already reconciled.');
            if($this->one('SELECT id FROM cos_finance_order_reconciliations WHERE organization_id=? AND source_account=? AND statement_reference=? FOR UPDATE',[$org,$account,$statement])!==null)throw new DomainException('Statement line already reconciled.');
            $next=Money::apply($b,$kind,$n,$this->available($org,$id,$o));
            $e=bin2hex(random_bytes(16));
            $this->exec('INSERT INTO cos_finance_order_reconciliations (organization_id,id,order_id,operation_id,statement_reference,source_account,actor_id,action_id,correlation_id,idempotency_key,request_hash,revision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$e,$id,$op,$statement,$account,$actor,$action,$trace,$key,$hash,$rev+1]);
            $this->exec('UPDATE cos_finance_order_balances SET captured_minor=?,refunded_minor=?,chargeback_minor=?,revision=revision+1,evidence_reference=? WHERE organization_id=? AND order_id=?',[$next['captured_minor'],$next['refunded_minor'],$next['chargeback_minor'],$e,$org,$id]);
            return ['finance_reference'=>$e,'state'=>'reconciled'];
        });
    }
    /** Manager-only through the query adapter; pending rows never enter the financial evidence port. */
    public function operations(string $org,string $id,int $offset,int $limit):array
    {
        $this->active($org);Money::id($id);
        if($offset<0||$offset>10000||$limit<1||$limit>100)throw new DomainException('Invalid financial operations page.');
        return $this->tx(function()use($org,$id,$offset,$limit):array{
            if($this->sales->placement($org,$id)===null)throw new DomainException('Order unavailable.');
            $b=$this->one('SELECT revision FROM cos_finance_order_balances WHERE organization_id=? AND order_id=?',[$org,$id]);
            $q=$this->connection->prepare("SELECT o.id AS operation_id,o.kind,o.amount_minor,o.parent_id,o.source_account,o.source_reference,o.created_at,c.id AS reconciliation_id,c.statement_reference,c.revision AS reconciled_revision FROM cos_finance_order_operations o LEFT JOIN cos_finance_order_reconciliations c ON c.organization_id=o.organization_id AND c.operation_id=o.id WHERE o.organization_id=? AND o.order_id=? ORDER BY o.created_at,o.id LIMIT ".($limit+1)." OFFSET ".$offset);
            $q->execute([$org,$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;
            if($more)array_pop($rows);
            foreach($rows as &$row){
                $minor=(int)$row['amount_minor'];unset($row['amount_minor']);
                $row['amount']=intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
                $row['state']=$row['reconciliation_id']===null?'pending_registration':'reconciled';
                $row['reconciled_revision']=$row['reconciled_revision']===null?null:(int)$row['reconciled_revision'];
            }unset($row);
            return ['order_id'=>$id,'revision'=>(int)($b['revision']??0),'operations'=>$rows,'has_more'=>$more,'offset'=>$offset,'limit'=>$limit];
        });
    }
    private function available(string $org,string $id,array $o):int
    {
        if($o['kind']==='capture')return 0;
        $parent=$this->operation($org,$id,$o['parent_id']);
        if($this->one('SELECT id FROM cos_finance_order_reconciliations WHERE organization_id=? AND operation_id=?',[$org,$parent['id']])===null)throw new DomainException('Parent operation is not reconciled.');
        $used=$this->one('SELECT COALESCE(SUM(o.amount_minor),0) AS total FROM cos_finance_order_operations o JOIN cos_finance_order_reconciliations c ON c.organization_id=o.organization_id AND c.operation_id=o.id WHERE o.organization_id=? AND o.order_id=? AND o.parent_id=?',[$org,$id,$parent['id']]);
        $available=(int)$parent['amount_minor']-(int)$used['total'];
        if($o['kind']!=='chargeback_reversal'){
            $r=$this->one("SELECT COALESCE(SUM(r.amount_minor),0) AS total FROM cos_finance_order_operations r JOIN cos_finance_order_reconciliations c ON c.organization_id=r.organization_id AND c.operation_id=r.id JOIN cos_finance_order_operations b ON b.organization_id=r.organization_id AND b.id=r.parent_id WHERE r.organization_id=? AND r.order_id=? AND b.parent_id=? AND r.kind='chargeback_reversal'",[$org,$id,$parent['id']]);
            $available+=(int)$r['total'];
        }
        return $available;
    }
    private function active(string $org):void
    {
        if($org===''||strlen($org)>40||!$this->modules->isEnabled($org,'finance')||!$this->modules->isEnabled($org,'sales'))throw new DomainException('Finance/Sales unavailable.');
    }
    private function operation(string $org,string $id,string $op):array{return $this->one('SELECT * FROM cos_finance_order_operations WHERE organization_id=? AND order_id=? AND id=?',[$org,$id,$op])??throw new DomainException('Financial operation unavailable.');}
    private function lock(string $org,string $id):array{return $this->one('SELECT * FROM cos_finance_order_balances WHERE organization_id=? AND order_id=? FOR UPDATE',[$org,$id])??throw new DomainException('Financial balance unavailable.');}
    private function receipt(array $row,string $hash,string $state):array
    {
        if(!hash_equals($row['request_hash'],$hash))throw new DomainException('Conflicting Finance receipt.');
        return ['finance_reference'=>$row['id'],'state'=>$state];
    }
    private function one(string $sql,array $args):?array
    {
        $q=$this->connection->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    private function exec(string $sql,array $args):void{$this->connection->prepare($sql)->execute($args);}
    private static function hash(array $data):string{return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));}
    private function tx(callable $run):mixed
    {
        if($this->connection->inTransaction())throw new DomainException('Nested Finance payment transactions forbidden.');
        $this->connection->beginTransaction();
        try{$result=$run();$this->connection->commit();return $result;}
        catch(Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }
}
