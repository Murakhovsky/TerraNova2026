<?php
declare(strict_types=1);

/**
 * Real MySQL P0 failover acceptance. Generic queue maintenance, operator dead
 * replay and old Action stale recovery must never redispatch Kernel native
 * Agent/Tool effects. Legacy jobs remain independently recoverable.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineKernelExecutionEvidence;
use App\Persistence\Workflow\DoctrineKernelExecutionFence;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use Doctrine\DBAL\DriverManager;
use Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository;
use Infrastructure\Platform\Persistence\MySql\Queue\MysqlJobQueue;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Queue\Job;
use RuntimeException;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$pdo=$db->getNativeConnection();
if(!$pdo instanceof PDO)throw new RuntimeException('CI must use the real native MySQL PDO connection.');
$tx=new TransactionManager($pdo);
$events=new EventBus(new class implements EventStoreInterface {
    public function append(DomainEvent $event):void {}
    public function find(string $eventId):?DomainEvent {return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,
        string $aggregateId,int $limit=100):array{return [];}
},$tx);
$queue=new MysqlJobQueue($pdo,$tx,$events);
$actions=new MysqlActionRepository($pdo);
$tag=bin2hex(random_bytes(6));$org='p0-fail-'.$tag;$legacy='p0-old-'.$tag;
$agentId=bin2hex(random_bytes(16));$agent2=bin2hex(random_bytes(16));
$agent3=bin2hex(random_bytes(16));$agent4=bin2hex(random_bytes(16));
$legacyId=bin2hex(random_bytes(16));$toolJob=bin2hex(random_bytes(16));
$actionId=bin2hex(random_bytes(16));$ordinaryAction=bin2hex(random_bytes(16));
$worker='worker-'.$tag;$corr='fence-'.$tag;
$check=static function(bool $condition,string $name):void {
    if(!$condition)throw new RuntimeException('P0 failover acceptance: '.$name);
};
$payload=['agent_name'=>'test.agent','subject_type'=>'lead','subject_id'=>'lead-'.$tag,
    'question'=>'Canonical protected attempt','context_references'=>[]];
$jobInsert=static function(string $id,string $tenant,string $type,array $body,
    string $status,int $attempts,string $trace) use ($db,$worker):void {
    $db->executeStatement(
        'INSERT INTO cos_jobs (id,organization_id,type,payload,status,attempts,
           max_attempts,timeout_seconds,available_at,locked_at,locked_by,correlation_id)
         VALUES (:id,:org,:type,:body,:status,:attempts,5,60,
           DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE),
           DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE),:worker,:trace)',
        ['id'=>$id,'org'=>$tenant,'type'=>$type,
         'body'=>json_encode($body,JSON_THROW_ON_ERROR),
         'status'=>$status,'attempts'=>$attempts,'worker'=>$worker,'trace'=>$trace]
    );
};
$status=static fn(string $id):string=>(string)$db->fetchOne(
    'SELECT status FROM cos_jobs WHERE id=:id',['id'=>$id]);
try {
    $db->insert('cos_kernel_execution_rollouts',[
        'organization_id'=>$org,'runtime_kind'=>'agent','mode'=>'canonical',
        'approved_by'=>'separate-p0-operator-'.$tag,'approved_at'=>gmdate('Y-m-d H:i:s.u')
    ]);
    $jobInsert($agentId,$org,'AGENT_RUN',$payload,'RUNNING',1,$corr);
    $jobInsert($legacyId,$legacy,'AGENT_RUN',$payload,'RUNNING',1,$corr);
    $db->executeStatement(
        'INSERT INTO cos_actions
         (id,organization_id,type,target_type,target_id,parameters,source_type,
          source_id,status,execution_mode,risk_level,idempotency_key,correlation_id,
          started_at)
         VALUES (:id,:org,"kernel.tool.invoke","tool","ci.mutation","{}",
          "USER","owner","RUNNING","APPROVAL_REQUIRED","HIGH",:key,:trace,
          DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE))',
        ['id'=>$actionId,'org'=>$org,'key'=>'p0-tool-'.$tag,'trace'=>$corr]
    );
    $db->executeStatement(
        'INSERT INTO cos_actions
         (id,organization_id,type,target_type,target_id,parameters,source_type,
          source_id,status,execution_mode,risk_level,idempotency_key,correlation_id,
          started_at)
         VALUES (:id,:org,"test.ordinary","lead","ordinary","{}",
          "USER","owner","RUNNING","AUTO","LOW",:key,:trace,
          DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE))',
        ['id'=>$ordinaryAction,'org'=>$org,'key'=>'ordinary-'.$tag,'trace'=>$corr]
    );
    $jobInsert($toolJob,$org,'ACTION_EXECUTION',['action_id'=>$actionId],
        'RUNNING',1,$corr);

    $check($queue->recoverTimedOut()>=3,'missed real expired MySQL jobs');
    $check($status($agentId)==='DEAD','canonical Agent timed out into retryable FAILED');
    $check($status($toolJob)==='DEAD','canonical Tool Action queue timed out into FAILED');
    $check($status($legacyId)==='FAILED','legacy job recovery changed unexpectedly');
    $check($queue->replayDead($org,$agentId)===0,
        'operator dead replay reopened a canonical Agent');
    $check($queue->replayDead($org,$toolJob)===0,
        'operator dead replay reopened a Tool mutation');
    $check($actions->requeueStale(30)===1,
        'stale Action recovery requeued a Tool effect or skipped ordinary Action');
    $check((string)$db->fetchOne('SELECT status FROM cos_actions WHERE id=:id',
        ['id'=>$actionId])==='RUNNING','Tool action was requeued after uncertain effect');

    // First canonical claim is native persisted evidence, not just the current
    // rollout flag. Turning canonical mode off MUST NOT allow re-execution.
    $jobInsert($agent2,$org,'AGENT_RUN',$payload,'RUNNING',1,'pin-'.$tag);
    $db->executeStatement(
        'UPDATE cos_jobs SET locked_at=UTC_TIMESTAMP(6) WHERE id=:id',
        ['id'=>$agent2]);
    $store=new DoctrineWorkflowExecutionStore(
        $db,new FailClosedWorkflowReceiptVerifier(),
        new FailClosedWorkflowStepClaimAdmission(),null,
        new DoctrineKernelExecutionEvidence($db)
    );
    $fence=new DoctrineKernelExecutionFence($db,$store);
    $claim=$fence->beginAgent(new Job(
        $agent2,$org,'AGENT_RUN',$payload,1,5,60,'pin-'.$tag,null,$worker
    ),'test.agent');
    $check($claim!==null,'could not create independently fenced Agent Run');
    $db->executeStatement(
        'UPDATE cos_kernel_execution_rollouts SET mode="off" WHERE organization_id=:org
         AND runtime_kind="agent"',['org'=>$org]
    );
    $db->executeStatement(
        'UPDATE cos_jobs SET locked_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE)
         WHERE id=:id',['id'=>$agent2]);
    $check($queue->recoverTimedOut()>=1,'pinned expired Agent not inspected');
    $check($status($agent2)==='DEAD',
        'disabling rollout reopened existing canonical Run');
    $check($queue->replayDead($org,$agent2)===0,
        'operator replay reopened a previously canonical Agent after rollout off');

    // Recoverable legacy DEAD jobs still follow their prior documented path.
    $db->executeStatement('UPDATE cos_jobs SET status="DEAD" WHERE id=:id',
        ['id'=>$legacyId]);
    $check($queue->replayDead($legacy,$legacyId)===1
        && $status($legacyId)==='PENDING',
        'legacy explicit replay was unnecessarily disabled');

    // Canonical new first attempt may still be admitted normally. Once it
    // fails, the queue must never claim it again in the enrolled tenant.
    $db->executeStatement(
       'UPDATE cos_kernel_execution_rollouts SET mode="canonical" WHERE organization_id=:org
        AND runtime_kind="agent"',['org'=>$org]);
    $jobInsert($agent3,$org,'AGENT_RUN',$payload,'RUNNING',1,'fail-'.$tag);
    $queue->failWithRetryPolicy(
        new Job($agent3,$org,'AGENT_RUN',$payload,1,5,60,'fail-'.$tag,null,$worker),
        'remote result is unknown',true
    );
    $check($status($agent3)==='DEAD',
        'classified retryable error reopened protected Agent effect');
    $check($queue->replayDead($org,$agent3)===0,
        'manual replay bypassed canonical Agent fallback');

    echo "P0 Kernel queue failover MySQL PASS: expired, explicit dead replay, canonical rollback fence, protected Tool Action, legacy parity.\n";
} finally {
    foreach(['cos_workflow_recovery_decisions','cos_workflow_steps','cos_workflow_runs',
             'cos_action_attempts','cos_approvals','cos_policy_evaluations',
             'cos_jobs','cos_actions','cos_kernel_execution_rollouts'] as $table) {
        $db->executeStatement("DELETE FROM $table WHERE organization_id IN (:org,:legacy)",
            ['org'=>$org,'legacy'=>$legacy]);
    }
}
