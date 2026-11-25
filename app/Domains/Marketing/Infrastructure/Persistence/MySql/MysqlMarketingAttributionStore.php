<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingAttributionStore;
use PDO;
final readonly class MysqlMarketingAttributionStore implements MarketingAttributionStore, \Domains\Marketing\Application\Contract\MarketingAttributionReceiptReadPort
{
    public function __construct(private PDO $connection){}
    public function batch(string $org,string $key,string $hash,callable $capture):array
    {
        if(!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||$this->connection->inTransaction())
            throw new DomainException('Invalid attribution capture transaction.');
        $this->connection->beginTransaction();
        try{
            $q=$this->connection->prepare('INSERT INTO marketing_attribution_capture_intents (organization_id,idempotency_key,request_hash)
                VALUES (?,?,?) ON DUPLICATE KEY UPDATE idempotency_key=idempotency_key');
            $q->execute([$org,$key,$hash]);
            $q=$this->connection->prepare('SELECT request_hash,receipt_json FROM marketing_attribution_capture_intents WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$org,$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($r)||!hash_equals($r['request_hash'],$hash))throw new DomainException('Attribution capture idempotency conflict.');
            if($r['receipt_json']!==null){$result=json_decode($r['receipt_json'],true,32,JSON_THROW_ON_ERROR);$this->connection->commit();return $result;}
            $result=$capture();
            $q=$this->connection->prepare('UPDATE marketing_attribution_capture_intents SET receipt_json=? WHERE organization_id=? AND idempotency_key=? AND receipt_json IS NULL');
            $q->execute([json_encode($result,JSON_THROW_ON_ERROR),$org,$key]);
            if($q->rowCount()!==1)throw new DomainException('Attribution capture receipt lost.');
            $q=$this->connection->prepare('SELECT receipt_json FROM marketing_attribution_capture_intents WHERE organization_id=? AND idempotency_key=?');
            $q->execute([$org,$key]);$stored=$q->fetchColumn();
            if(!is_string($stored))throw new DomainException('Attribution capture receipt readback missing.');
            $result=json_decode($stored,true,32,JSON_THROW_ON_ERROR);
            $this->connection->commit();return $result;
        }catch(\Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }
    public function receipt(string $org,string $key):?array
    {
        if($org===''||!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D',$key))throw new DomainException('Invalid capture receipt scope.');
        $q=$this->connection->prepare('SELECT request_hash,receipt_json FROM marketing_attribution_capture_intents
            WHERE organization_id=? AND BINARY organization_id=BINARY ? AND idempotency_key=? AND BINARY idempotency_key=BINARY ?');
        $q->execute([$org,$org,$key,$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($r)||$r['receipt_json']===null)return null;
        return ['request_hash'=>$r['request_hash'],'receipt'=>json_decode($r['receipt_json'],true,32,JSON_THROW_ON_ERROR)];
    }
    public function record(string $org,string $campaign,array $evidence):array
    {
        $id='mkat-'.bin2hex(random_bytes(12));
        $q=$this->connection->prepare("INSERT INTO marketing_attribution_evidence
            (organization_id,id,campaign_id,source_domain,source_event_id,policy_version,confidence,evidence_json,observed_at)
            VALUES (?,?,?,'sales',?,'utm-exact-v1',1.0000,?,?) ON DUPLICATE KEY UPDATE id=id");
        $q->execute([$org,$id,$campaign,$evidence['lead_id'],json_encode($evidence,JSON_THROW_ON_ERROR),$evidence['created_at']]);
        $q=$this->connection->prepare("SELECT id,campaign_id,evidence_json FROM marketing_attribution_evidence
            WHERE organization_id=? AND source_domain='sales' AND source_event_id=? AND policy_version='utm-exact-v1'");
        $q->execute([$org,$evidence['lead_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
        $old=is_array($r)?json_decode($r['evidence_json'],true,16,JSON_THROW_ON_ERROR):null;
        if(!is_array($old)||$r['campaign_id']!==$campaign||$old['evidence_hash']!==$evidence['evidence_hash'])
            throw new DomainException('Attribution already recorded with different source evidence; manual reconciliation required.');
        return ['id'=>$r['id'],'campaign_id'=>$campaign,...$old];
    }
    public function list(string $org,string $campaign,string $from,string $to,int $limit,int $offset):array
    {
        if($limit<1||$limit>501||$offset<0||$offset>100000)throw new DomainException('Attribution page outside bounds.');
        $q=$this->connection->prepare("SELECT id,campaign_id,evidence_json FROM marketing_attribution_evidence
            WHERE organization_id=? AND campaign_id=? AND source_domain='sales' AND policy_version='utm-exact-v1'
            AND observed_at>=? AND observed_at<? ORDER BY observed_at,id LIMIT ".$limit." OFFSET ".$offset);
        $q->execute([$org,$campaign,$from.' 00:00:00',(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00']);
        return array_map(static fn(array $r)=>['id'=>$r['id'],'campaign_id'=>$r['campaign_id'],...json_decode($r['evidence_json'],true,16,JSON_THROW_ON_ERROR)],$q->fetchAll(PDO::FETCH_ASSOC));
    }
}
