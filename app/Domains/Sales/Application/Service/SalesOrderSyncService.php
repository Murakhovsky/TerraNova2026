<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Queue\Job;
use Domains\ProductManagement\Application\Contract\CommerceOrderSourcePort;
use Domains\Sales\Application\Contract\SalesOrderSyncRepositoryInterface;

/** One human-authorized batch; Kernel JobQueue is the sole worker/retry engine. */
final readonly class SalesOrderSyncService
{
    public const JOB='SALES_ORDER_SOURCE_SYNC';
    private const PERMISSION='sales.admin.integrations.manage';
    private const MAX_PAGES=1000;
    public function __construct(private SalesOrderSyncRepositoryInterface $runs,private CommerceOrderSourcePort $source,
        private SalesOrderReconciliationService $reconcile,private SalesMutationGuard $guard,private JobQueueInterface $queue,
        private AsyncOperationReadModelInterface $operations,private ActiveModuleResolver $modules){}

    public function start(string $org,int $actor,array $input,string $key,string $correlation):array
    {
        $channel=$input['channel']??null;$conn=$input['connection_id']??null;$mode=$input['mode']??null;
        if($org===''||strlen($org)>40||$actor<1||trim($key)===''||strlen($key)>191||trim($correlation)===''||strlen($correlation)>191||
            $channel!=='shopify'||!is_string($conn)||preg_match('/^[a-f0-9]{32}$/D',$conn)!==1||!in_array($mode,['backfill','webhooks'],true)||array_diff(array_keys($input),['channel','connection_id','mode'])!==[])
            throw new DomainException('Invalid order sync request.');
        $run=substr(hash('sha256',json_encode([$org,'sales.order.source.sync.start',trim($key)],JSON_THROW_ON_ERROR)),0,32);
        $hash=hash('sha256',json_encode([$actor,$channel,$conn,$mode],JSON_THROW_ON_ERROR));
        $cutoff=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        return $this->guard->run($org,$actor,function()use($org,$actor,$channel,$conn,$mode,$run,$hash,$cutoff,$correlation):array{
            $this->assertActive($org);
            $this->source->pending($org,$channel,$conn); // Re-attest tenant and connection, no provider network.
            $row=$this->runs->open($org,$run,$hash,$actor,$channel,$conn,$mode,$cutoff);
            if((int)$row['pages']===0&&$row['discovery_state']==='pending')$this->enqueue($org,$row,'page',['cursor'=>null],$correlation);
            return ['run_id'=>$run,'discovery_state'=>$row['discovery_state']];
        },permission:self::PERMISSION,targetOwner:$actor);
    }

    public function status(string $org,int $actor,string $run,?string $after):array
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$run)!==1||($after!==null&&preg_match('/^[a-f0-9]{64}$/D',$after)!==1))throw new DomainException('Invalid order sync cursor.');
        return $this->guard->run($org,$actor,function()use($org,$actor,$run,$after):array{
            $row=$this->runs->run($org,$run);
            if($row===null||(int)$row['actor_id']!==$actor)throw new DomainException('Order sync unavailable.');
            $result=$this->runs->tasks($org,$run,$after);
            foreach($result['tasks'] as &$task){
                $job=$this->operations->find($org,$task['job_id']);
                $task['queue_status']=$job===null?'unknown':($job->canRetry?'dead':($job->retryScheduled?'retry_scheduled':$job->status->value));
                $task['attempts']=$job?->attempts;
            }unset($task);
            return ['run_id'=>$run,'mode'=>$row['mode'],'connection_id'=>$row['connection_id'],'discovery_state'=>$row['discovery_state'],
                'cutoff_at'=>self::iso($row['cutoff_at']),'pages'=>(int)$row['pages'],'checkpoint'=>$row['cursor_value'],'historical_access'=>$row['historical_access'],
                'review_reason'=>$row['review_reason'],'counts'=>$this->runs->summary($org,$run),...$result];
        },permission:self::PERMISSION);
    }

    /** Admitted job ID, not a payload-supplied tenant/actor, determines authority. */
    public function execute(Job $job):void
    {
        $org=$job->organizationId;
        if($job->type!==self::JOB||$org==='')throw ExecutionFailureException::permanent('Invalid Sales order sync job.');
        $task=$this->runs->task($org,$job->id);
        $run=$task===null?null:$this->runs->run($org,$task['run_id']);
        if($task===null||$run===null||($job->payload['run_id']??null)!==$run['run_id']||($job->payload['task_key']??null)!==$task['task_key']||
            ($job->payload['actor_id']??null)!==(string)$run['actor_id'])throw ExecutionFailureException::policyDenied('Unadmitted Sales order sync job.');
        $actor=(int)$run['actor_id'];
        $this->guard->run($org,$actor,function()use($org,$job,$actor):void{
            $this->assertActive($org);
            $task=$this->runs->task($org,$job->id,true);
            $run=$task===null?null:$this->runs->run($org,$task['run_id'],true);
            if($task===null||$run===null||(int)$run['actor_id']!==$actor)throw ExecutionFailureException::policyDenied('Order sync admission changed.');
            if($task['state']!=='pending')return; // Commit-before-queue-ack crash is safe.
            if($task['kind']==='page'){$this->discover($org,$run,$task,$job);return;}
            if($task['kind']!=='order')throw ExecutionFailureException::permanent('Unknown order sync task.');
            $result=$this->reconcile->reconcile($org,$actor,['channel'=>$run['channel'],'connection_id'=>$run['connection_id'],...$task['input']],
                'order-sync:'.$task['task_key'],$job->correlationId);
            $review=in_array($result['outcome'],['blocked','conflict','stale'],true);
            $this->runs->finish($org,$job->id,$review?'review':'done',$result['outcome'],$result['blocked_reason'],$result['order_id']);
        },permission:self::PERMISSION,targetOwner:$actor);
    }

    private function discover(string $org,array $run,array $task,Job $job):void
    {
        $cursor=$task['input']['cursor']??null;
        if($run['discovery_state']!=='pending'||$run['cursor_value']!==$cursor)throw ExecutionFailureException::permanent('Order discovery checkpoint mismatch.');
        if($run['mode']==='backfill'){
            $page=$this->source->page($org,$run['channel'],$run['connection_id'],$cursor,self::iso($run['cutoff_at']));
            $history=$page['historical_access'];
            foreach($page['order_ids'] as $id)$this->enqueue($org,$run,'order',['external_order_id'=>$id],$job->correlationId);
        }else{
            $page=$this->source->pendingPage($org,$run['channel'],$run['connection_id'],$cursor);
            $history=null;
            foreach($page['deliveries'] as $delivery)$this->enqueue($org,$run,'order',['external_order_id'=>$delivery['external_order_id'],'delivery_id'=>$delivery['delivery_id']],$job->correlationId);
        }
        $next=$page['next_cursor'];$reason=null;
        if($next!==null){
            if((int)$run['pages']+1>=self::MAX_PAGES)$reason='page_budget_exhausted';
            elseif(!$this->enqueue($org,$run,'page',['cursor'=>$next],$job->correlationId))$reason='cursor_cycle_detected';
        }
        // Child job admissions and the next cursor commit together; no lost page on a crash.
        $this->runs->advance($org,$run['run_id'],$cursor,$next,$history,$reason);
        $this->runs->finish($org,$job->id,$reason===null?'done':'review','page_discovered',$reason,null);
    }
    private function enqueue(string $org,array $run,string $kind,array $input,string $correlation):bool
    {
        ksort($input);
        $key=hash('sha256',json_encode([$run['run_id'],$kind,$input],JSON_THROW_ON_ERROR));
        $job=$this->queue->enqueue($org,self::JOB,['run_id'=>$run['run_id'],'task_key'=>$key,'actor_id'=>(string)$run['actor_id']],
            $correlation,'sales-order-sync:'.$key,5,900);
        return $this->runs->register($org,$run['run_id'],$key,$job,$kind,$input);
    }
    private function assertActive(string $org):void
    {
        if(!$this->modules->isEnabled($org,'sales')||!$this->modules->isEnabled($org,'product_management'))
            throw ExecutionFailureException::policyDenied('Order sync modules inactive.');
    }
    private static function iso(string $time):string{return str_replace(' ','T',$time).'Z';}
}
