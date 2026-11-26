<?php
declare(strict_types=1);
namespace App\Application\Sales\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesMutationGuard;
use Domains\Sales\Application\Service\SalesOrderSyncService;
use PDO;
use Throwable;
use DomainException;

/** Deployment-owned opt-in schedules; existing Sales scheduler and Kernel jobs remain sole execution path. */
final readonly class PeriodicSalesOrderSync
{
    public function __construct(private PDO $db,private SalesMutationGuard $guard,private SalesOrderSyncService $sync,private string $profiles='[]') {}

    public static function parseProfiles(string $json):array
    {
        $profiles=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($profiles)||!array_is_list($profiles)||count($profiles)>50)throw new DomainException('Invalid Sales sync schedule list.');
        $seen=[];
        foreach($profiles as $p){
            if(!is_array($p)||count($p)!==5||array_diff(array_keys($p),['organization_id','actor_id','connection_id','mode','interval_minutes'])!==[]
                ||!is_string($p['organization_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$p['organization_id'])
                ||!is_int($p['actor_id']??null)||$p['actor_id']<1
                ||!is_string($p['connection_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$p['connection_id'])
                ||!in_array($p['mode']??null,['webhooks','backfill'],true)
                ||!is_int($p['interval_minutes']??null)||$p['interval_minutes']<5||$p['interval_minutes']>1440||$p['interval_minutes']%5!==0)
                throw new DomainException('Invalid Sales sync schedule profile.');
            $identity=$p['organization_id'].':'.$p['connection_id'];
            if(isset($seen[$identity]))throw new DomainException('Duplicate Sales sync schedule connection.');
            $seen[$identity]=true;
        }
        return $profiles;
    }
    public static function slotKey(array $p,DateTimeImmutable $now):string
    {
        return 'sales-periodic:'.hash('sha256',json_encode([$p['organization_id'],$p['actor_id'],$p['connection_id'],$p['mode'],$p['interval_minutes'],intdiv($now->getTimestamp(),60*$p['interval_minutes'])],JSON_THROW_ON_ERROR));
    }
    public function run(?string $onlyOrganization,DateTimeImmutable $now):array
    {
        $result=[];
        foreach(self::parseProfiles($this->profiles) as $p){
            $org=$p['organization_id'];$actor=$p['actor_id'];$conn=$p['connection_id'];
            if($onlyOrganization!==null&&$onlyOrganization!==$org)continue;
            $key=self::slotKey($p,$now);
            try{
                $status=$this->guard->run($org,$actor,function()use($org,$actor,$conn,$p,$key):array{
                    // Serialize scheduler admission on the native actor/permission transaction.
                    // Never spawn a second scheduled batch while a prior connection batch has pending work.
                    $q=$this->db->prepare('SELECT r.run_id FROM sales_order_sync_runs r WHERE r.organization_id=? AND BINARY r.organization_id=BINARY ? AND r.connection_id=? AND (r.discovery_state="pending" OR EXISTS(SELECT 1 FROM sales_order_sync_tasks t WHERE t.organization_id=r.organization_id AND t.run_id=r.run_id AND t.state="pending")) LIMIT 1 FOR UPDATE');
                    $q->execute([$org,$org,$conn]);$pending=$q->fetchColumn();
                    if($pending!==false)return ['state'=>'deferred','run_id'=>$pending];
                    $run=$this->sync->start($org,$actor,['channel'=>'shopify','connection_id'=>$conn,'mode'=>$p['mode']],$key,$key);
                    return ['state'=>'admitted','run_id'=>$run['run_id'],'discovery_state'=>$run['discovery_state']];
                },permission:'sales.admin.integrations.manage',targetOwner:$actor);
            }catch(Throwable $e){$status=['state'=>'rejected','error_type'=>get_class($e)];}
            $result[]=['organization_id'=>$org,'connection_id'=>$conn]+$status;
        }
        return $result;
    }
}
