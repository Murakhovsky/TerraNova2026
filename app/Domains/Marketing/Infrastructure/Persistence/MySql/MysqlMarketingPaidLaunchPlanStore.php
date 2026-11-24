<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingPaidLaunchPlanStore;
use PDO;

final readonly class MysqlMarketingPaidLaunchPlanStore implements MarketingPaidLaunchPlanStore
{
    public function __construct(private PDO $connection){}
    public function byKey(string $org,string $key):?array{return $this->read($org,'idempotency_key',$key);}
    public function get(string $org,string $id):?array{return $this->read($org,'id',$id);}
    public function create(string $org,string $actor,string $key,string $requestHash,array $plan):array
    {
        $id='mkpl-'.bin2hex(random_bytes(12));
        $json=json_encode($plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $hash=self::digest($plan);
        $this->connection->prepare('INSERT INTO marketing_paid_launch_plans
            (organization_id,id,campaign_id,actor_id,idempotency_key,request_hash,plan_hash,plan_json)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')
            ->execute([$org,$id,$plan['campaign_id'],$actor,$key,$requestHash,$hash,$json]);
        $stored=$this->byKey($org,$key);
        if($stored===null||!hash_equals($requestHash,$stored['request_hash']))throw new DomainException('Paid plan idempotency conflict.');
        return $stored;
    }
    private function read(string $org,string $column,string $value):?array
    {
        $q=$this->connection->prepare('SELECT id,actor_id,request_hash,plan_hash,plan_json,created_at FROM marketing_paid_launch_plans WHERE organization_id=? AND '.$column.'=?');
        $q->execute([$org,$value]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($plan)||!hash_equals($row['plan_hash'],self::digest($plan)))throw new DomainException('Paid plan snapshot corrupted.');
        unset($row['plan_json']);
        return [...$plan,...$row,'finance_operation_key'=>'mkt-paid:'.$row['id'].':activate'];
    }
    // MySQL JSON object keys may be reordered on readback.
    public static function digest(array $value):string
    {
        $normalize=static function(array $v)use(&$normalize):array{
            if(!array_is_list($v))ksort($v,SORT_STRING);
            foreach($v as &$item){if(is_array($item))$item=$normalize($item);}unset($item);
            return $v;
        };
        return hash('sha256',json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
