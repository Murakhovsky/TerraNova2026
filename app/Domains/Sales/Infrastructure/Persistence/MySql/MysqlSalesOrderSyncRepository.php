<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOrderSyncRepositoryInterface;
use PDO;
use DomainException;
use LogicException;

final readonly class MysqlSalesOrderSyncRepository implements SalesOrderSyncRepositoryInterface
{
    public function __construct(private PDO $connection){}
    public function open(string $org,string $run,string $hash,int $actor,string $channel,string $connection,string $mode,string $cutoff):array
    {
        $this->tx();
        $this->query('INSERT INTO sales_order_sync_runs(organization_id,run_id,request_hash,actor_id,channel,connection_id,mode,cutoff_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE run_id=run_id',[$org,$run,$hash,$actor,$channel,$connection,$mode,$cutoff]);
        $row=$this->run($org,$run,true);
        if($row===null||!hash_equals($row['request_hash'],$hash)||(int)$row['actor_id']!==$actor)throw new DomainException('Order sync request conflict.');
        return $row;
    }
    public function run(string $org,string $run,bool $lock=false):?array
    {
        if($lock)$this->tx();
        $row=$this->query('SELECT * FROM sales_order_sync_runs WHERE organization_id=? AND run_id=?'.($lock?' FOR UPDATE':''),[$org,$run])->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }
    public function task(string $org,string $job,bool $lock=false):?array
    {
        if($lock)$this->tx();
        $row=$this->query('SELECT * FROM sales_order_sync_tasks WHERE organization_id=? AND job_id=?'.($lock?' FOR UPDATE':''),[$org,$job])->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $row['input']=json_decode($row['input_json'],true,32,JSON_THROW_ON_ERROR);
        return $row;
    }
    public function register(string $org,string $run,string $key,string $job,string $kind,array $input):bool
    {
        $this->tx();
        $existing=$this->task($org,$job,true);
        if($existing!==null){
            if($existing['run_id']!==$run||$existing['task_key']!==$key||$existing['kind']!==$kind||$existing['input']!=$input)throw new LogicException('Order sync job admission conflict.');
            return false;
        }
        $this->query('INSERT INTO sales_order_sync_tasks(organization_id,run_id,task_key,job_id,kind,input_json) VALUES(?,?,?,?,?,?)',[$org,$run,$key,$job,$kind,json_encode($input,JSON_THROW_ON_ERROR)]);
        return true;
    }
    public function advance(string $org,string $run,?string $expected,?string $next,?string $history,?string $review):void
    {
        $this->tx();
        $row=$this->run($org,$run,true);
        if($row===null||$row['discovery_state']!=='pending'||$row['cursor_value']!==$expected)throw new DomainException('Stale order discovery checkpoint.');
        if($row['historical_access']!==null&&$row['historical_access']!==$history)throw new DomainException('Shopify historical scope changed during discovery.');
        $q=$this->query("UPDATE sales_order_sync_runs SET cursor_value=?,historical_access=?,discovery_state=?,pages=pages+1,review_reason=? WHERE organization_id=? AND run_id=?",[$next,$history,$review!==null?'review':($next===null?'complete':'pending'),$review,$org,$run]);
        if($q->rowCount()!==1)throw new LogicException('Order discovery checkpoint not committed.');
    }
    public function finish(string $org,string $job,string $state,string $outcome,?string $reason,?string $order):void
    {
        $this->tx();
        $q=$this->query("UPDATE sales_order_sync_tasks SET state=?,outcome=?,blocked_reason=?,order_id=? WHERE organization_id=? AND job_id=? AND state='pending'",[$state,$outcome,$reason,$order,$org,$job]);
        if($q->rowCount()!==1)throw new LogicException('Order sync task receipt not committed.');
    }
    public function summary(string $org,string $run):array
    {
        $rows=$this->query('SELECT kind,state,COUNT(*) AS count FROM sales_order_sync_tasks WHERE organization_id=? AND run_id=? GROUP BY kind,state',[$org,$run])->fetchAll(PDO::FETCH_ASSOC);
        $out=['discovery_pending'=>0,'discovery_done'=>0,'discovery_review'=>0,'orders_pending'=>0,'orders_done'=>0,'orders_review'=>0];
        foreach($rows as $r){$key=($r['kind']==='page'?'discovery':'orders').'_'.$r['state'];if(!array_key_exists($key,$out))throw new LogicException('Unknown order task state.');$out[$key]=(int)$r['count'];}
        return $out;
    }
    public function tasks(string $org,string $run,?string $after):array
    {
        $rows=$this->query('SELECT task_key,job_id,kind,state,outcome,blocked_reason,order_id FROM sales_order_sync_tasks WHERE organization_id=? AND run_id=?'.($after===null?'':' AND task_key>?').' ORDER BY task_key LIMIT 26',$after===null?[$org,$run]:[$org,$run,$after])->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25;$rows=array_slice($rows,0,25);
        return ['tasks'=>$rows,'next_cursor'=>$more?$rows[24]['task_key']:null];
    }
    private function query(string $sql,array $params):\PDOStatement{$q=$this->connection->prepare($sql);$q->execute($params);return $q;}
    private function tx():void{if(!$this->connection->inTransaction())throw new LogicException('Order sync write requires transaction.');}
}
