<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOrderSourceRepositoryInterface;
use PDO;
use LogicException;

final readonly class MysqlSalesOrderSourceRepository implements SalesOrderSourceRepositoryInterface
{
    public function __construct(private PDO $connection){}
    public function lock(string $org,string $channel,string $connection,string $externalId):array
    {
        $this->transaction();
        $source=hash('sha256',json_encode([$org,$channel,$connection,$externalId],JSON_THROW_ON_ERROR));
        $q=$this->connection->prepare('INSERT INTO sales_order_sources(organization_id,source_id,channel,store_reference,external_order_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE source_id=source_id');
        $q->execute([$org,$source,$channel,$connection,$externalId]);
        $q=$this->connection->prepare('SELECT * FROM sales_order_sources WHERE organization_id=? AND source_id=? FOR UPDATE');
        $q->execute([$org,$source]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||$row['channel']!==$channel||$row['store_reference']!==$connection||$row['external_order_id']!==$externalId)throw new LogicException('Commerce source identity conflict.');
        return $row;
    }
    /** Redacted provider facts, not local fulfillment or payment authority. */
    public function latestForOrder(string $org,string $orderId):?array
    {
        $q=$this->connection->prepare('SELECT s.source_id,s.outcome,s.blocked_reason,o.observation FROM sales_order_sources s JOIN sales_order_source_observations o ON o.organization_id=s.organization_id AND o.source_id=s.source_id AND o.provider_updated_at=s.latest_updated_at AND o.provider_hash=s.latest_hash WHERE s.organization_id=? AND s.order_id=? LIMIT 2');
        $q->execute([$org,$orderId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return null;
        if(count($rows)!==1)throw new LogicException('Ambiguous Order source observation.');
        $row=$rows[0];$fact=json_decode($row['observation'],true,32,JSON_THROW_ON_ERROR);
        $view=['source_id'=>$row['source_id'],'provider_updated_at'=>$fact['updated_at'],'provider_fulfillment_status'=>$fact['fulfillment_status'],
            'provider_financial_status'=>$fact['financial_status'],'provider_cancelled_at'=>$fact['cancelled_at'],
            'reconciliation_outcome'=>$row['outcome'],'review_reason'=>$row['blocked_reason']];
        $q=$this->connection->prepare('SELECT evidence_json,evidence_hash,provider_updated_at,observed_at FROM sales_order_external_fulfillment_evidence WHERE organization_id=? AND BINARY organization_id=BINARY ? AND source_id=? AND order_id=? ORDER BY observed_at DESC,evidence_id DESC LIMIT 1');
        $q->execute([$org,$org,$row['source_id'],$orderId]);$e=$q->fetch(PDO::FETCH_ASSOC);
        if(is_array($e)){
            $evidence=json_decode($e['evidence_json'],true,64,JSON_THROW_ON_ERROR);
            if(!hash_equals($e['evidence_hash'],self::digest($evidence)))throw new LogicException('External fulfillment evidence integrity failed.');
            $view['fulfillment_evidence']=['provider_hash'=>$evidence['provider_hash'],'provider_updated_at'=>$evidence['provider_updated_at'],
                'observed_at'=>str_replace(' ','T',$e['observed_at']).'Z','current_order_revision'=>$evidence['provider_updated_at']===$fact['updated_at'],
                'assignment_count'=>count($evidence['assignments']),'unmapped_locations'=>$evidence['unmapped_locations'],
                'inventory_authority'=>'external_review','physical_commitment_verified'=>false,'local_stock_write_allowed'=>false];
        }
        return $view;
    }
    public function recordFulfillmentObservation(string $org,string $sourceId,string $orderId,string $key,array $evidence):void
    {
        $this->transaction();
        $q=$this->connection->prepare('SELECT order_id,channel,store_reference,external_order_id,latest_updated_at FROM sales_order_sources WHERE organization_id=? AND BINARY organization_id=BINARY ? AND source_id=? FOR UPDATE');
        $q->execute([$org,$org,$sourceId]);$s=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($s)||$s['order_id']!==$orderId||($evidence['organization_id']??null)!==$org||($evidence['channel']??null)!==$s['channel']
            ||($evidence['connection_id']??null)!==$s['store_reference']||($evidence['external_order_id']??null)!==$s['external_order_id']
            ||($evidence['test']??null)!==false||($evidence['inventory_authority']??null)!=='external_review'
            ||($evidence['local_stock_write_allowed']??null)!==false||($evidence['physical_commitment_verified']??null)!==false
            ||($evidence['provider_updated_at']??null)!==str_replace(' ','T',$s['latest_updated_at']).'Z'
            ||!is_string($evidence['provider_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$evidence['provider_hash'])
            ||!is_array($evidence['assignments']??null)||!is_array($evidence['unmapped_locations']??null))
            throw new LogicException('External fulfillment evidence does not match canonical source.');
        $hash=self::digest($evidence);
        $q=$this->connection->prepare('INSERT INTO sales_order_external_fulfillment_evidence(organization_id,evidence_id,source_id,order_id,request_key,provider_updated_at,provider_hash,evidence_hash,evidence_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$org,bin2hex(random_bytes(16)),$sourceId,$orderId,$key,$s['latest_updated_at'],$evidence['provider_hash'],$hash,json_encode($evidence,JSON_THROW_ON_ERROR)]);
    }
    private static function digest(array $evidence):string
    {
        $canonical=static function(array $a)use(&$canonical):array {
            if(!array_is_list($a))ksort($a,SORT_STRING);
            foreach($a as &$v)if(is_array($v))$v=$canonical($v);unset($v);
            return $a;
        };
        return hash('sha256',json_encode($canonical($evidence),JSON_THROW_ON_ERROR));
    }
    public function observe(string $org,string $sourceId,string $updated,string $hash,array $observation,string $outcome,?string $reason,?string $orderId,bool $advance):void
    {
        $this->transaction();
        $q=$this->connection->prepare('INSERT INTO sales_order_source_observations(organization_id,source_id,provider_updated_at,provider_hash,observation,outcome,blocked_reason) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider_hash=provider_hash');
        $q->execute([$org,$sourceId,$updated,$hash,json_encode($observation,JSON_THROW_ON_ERROR),$outcome,$reason]);
        if(!$advance)return;
        $q=$this->connection->prepare('UPDATE sales_order_sources SET latest_updated_at=?,latest_hash=?,outcome=?,blocked_reason=?,order_id=COALESCE(order_id,?) WHERE organization_id=? AND source_id=?');
        $q->execute([$updated,$hash,$outcome,$reason,$orderId,$org,$sourceId]);
    }
    private function transaction():void
    {
        if(!$this->connection->inTransaction())throw new LogicException('Commerce observation requires transaction.');
    }
}
