<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingExperimentStore;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use PDO;

final readonly class MysqlMarketingExperimentStore implements MarketingExperimentStore
{
    public function __construct(private PDO $connection){}
    public function byKey(string $org,string $key):?array{return $this->read($org,'idempotency_key',$key);}
    public function get(string $org,string $id):?array{return $this->read($org,'id',$id);}
    public function create(string $org,string $actor,string $key,string $requestHash,array $protocol):array
    {
        $ids=[$protocol['control']['plan_id'],$protocol['candidate']['plan_id']];sort($ids,SORT_STRING);
        $pairHash=MarketingExperimentPolicy::digest($ids);
        if($this->connection->inTransaction())throw new DomainException('Nested experiment registration denied.');
        $this->connection->beginTransaction();
        try{
            // Same native plan lock as beginCommand; sorted locking prevents competing arm registrations.
            $lock=$this->connection->prepare('SELECT id FROM marketing_paid_launch_plans WHERE organization_id=? AND id=? FOR UPDATE');
            foreach($ids as $id){$lock->execute([$org,$id]);if($lock->fetchColumn()!==$id)throw new DomainException('Experiment paid plan unavailable.');}
            $old=$this->byKey($org,$key);
            if($old!==null){
                if(!hash_equals($requestHash,$old['request_hash']))throw new DomainException('Experiment registration conflict.');
                $this->connection->commit();return $old;
            }
            $q=$this->connection->prepare('SELECT operation FROM marketing_paid_operation_journal WHERE organization_id=? AND plan_id IN (?,?) LIMIT 1');
            $q->execute([$org,...$ids]);if($q->fetchColumn()!==false)throw new DomainException('Experiment must precede all paid operations.');
            $this->connection->prepare('INSERT INTO marketing_experiment_protocols
                (organization_id,id,actor_id,idempotency_key,request_hash,pair_hash,protocol_hash,protocol_json,created_at)
                SELECT ?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6) WHERE UTC_DATE() < ?
                ON DUPLICATE KEY UPDATE id=id')
                ->execute([$org,'mkex-'.bin2hex(random_bytes(12)),$actor,$key,$requestHash,$pairHash,
                    MarketingExperimentPolicy::digest($protocol),json_encode($protocol,JSON_THROW_ON_ERROR),$protocol['control']['period_start']]);
            $row=$this->byKey($org,$key);
            if($row===null||!hash_equals($requestHash,$row['request_hash']))
                throw new DomainException('Experiment registration conflict or window has started.');
            $this->connection->commit();return $row;
        }catch(\Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }
    private function read(string $org,string $column,string $value):?array
    {
        $q=$this->connection->prepare('SELECT id,actor_id,request_hash,protocol_hash,protocol_json,created_at FROM marketing_experiment_protocols WHERE organization_id=? AND '.$column.'=?');
        $q->execute([$org,$value]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $protocol=json_decode((string)$row['protocol_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($protocol)||!hash_equals($row['protocol_hash'],MarketingExperimentPolicy::digest($protocol))
            ||substr($row['created_at'],0,10)>=$protocol['control']['period_start'])
            throw new DomainException('Invalid immutable experiment registration.');
        unset($row['protocol_json']);
        return [...$row,'protocol'=>$protocol];
    }
}
