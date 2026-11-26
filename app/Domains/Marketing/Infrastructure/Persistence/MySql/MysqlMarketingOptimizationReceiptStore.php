<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationReceiptStore;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use PDO;

/** Marketing-owned immutable governance receipts, never a provider or financial journal. */
final readonly class MysqlMarketingOptimizationReceiptStore implements MarketingOptimizationReceiptStore
{
    public function __construct(private PDO $connection){}
    public function byKey(string $org,string $key):?array{return $this->read($org,'idempotency_key',$key);}
    public function get(string $org,string $id):?array{return $this->read($org,'id',$id);}
    public function create(string $org,string $key,string $requestHash,array $receipt):array
    {
        $hash=MarketingExperimentPolicy::digest($receipt);
        $q=$this->connection->prepare('INSERT INTO marketing_optimization_receipts
            (organization_id,id,idempotency_key,request_hash,kind,actor_id,action_id,evidence_hash,receipt_hash,receipt_json,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
        $q->execute([$org,$receipt['id'],$key,$requestHash,$receipt['kind'],$receipt['actor_id'],$receipt['action_id'],
            $receipt['evidence_hash'],$hash,json_encode($receipt,JSON_THROW_ON_ERROR),$receipt['recorded_at']]);
        $row=$this->byKey($org,$key);
        if($row===null||!hash_equals($requestHash,$row['request_hash']))
            throw new DomainException('Optimization receipt key or evidence already used.');
        return $row;
    }
    private function read(string $org,string $column,string $value):?array
    {
        $q=$this->connection->prepare('SELECT * FROM marketing_optimization_receipts WHERE organization_id=? AND '.$column.'=?');
        $q->execute([$org,$value]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $r=json_decode((string)$row['receipt_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($r)||!hash_equals($row['receipt_hash'],MarketingExperimentPolicy::digest($r)))
            throw new DomainException('Invalid immutable optimization receipt.');
        foreach(['id','kind','actor_id','action_id','evidence_hash'] as $field)
            if(($r[$field]??null)!==$row[$field])throw new DomainException('Optimization receipt identity mismatch.');
        if(($r['recorded_at']??null)!==$row['created_at'])throw new DomainException('Optimization receipt timestamp mismatch.');
        return ['request_hash'=>$row['request_hash'],'receipt_hash'=>$row['receipt_hash'],'receipt'=>$r];
    }
}
