<?php
declare(strict_types=1);

$autoload = (string) (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php');
require $autoload;

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'mysql',
        (int) (getenv('DB_PORT') ?: 3306),
        getenv('DB_DATABASE') ?: 'cos',
    ),
    getenv('DB_USERNAME') ?: 'cos',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$featureId = '00000000-0000-4000-8000-00000000a306';
$workflowId = '00000000-0000-4000-8000-00000000b306';
$runId = '00000000-0000-4000-8000-00000000c306';
$executionTaskId = '00000000-0000-4000-8000-00000000d306';
$traceId = 'engineering:integration:agent-run-fk';

$cleanup = static function () use ($pdo, $featureId): void {
    $stmt = $pdo->prepare('DELETE FROM cos_engineering_features WHERE id = ?');
    $stmt->execute([$featureId]);
};
$cleanup();

try {
    $pdo->prepare(
        'INSERT INTO cos_engineering_features
         (id,organization_id,title,type,status,priority,request_payload,created_at,updated_at)
         VALUES (:id,"default","AgentRun FK integration","FEATURE","ANALYSIS","P2",JSON_OBJECT(),NOW(6),NOW(6))'
    )->execute(['id' => $featureId]);

    $pdo->prepare(
        'INSERT INTO cos_engineering_workflows
         (id,feature_id,workflow_type,current_state,status,started_at,last_activity_at,heartbeat_at,health_status,version,trace_id,lock_key)
         VALUES (:id,:feature_id,"ENGINEERING","ANALYSIS","RUNNING",NOW(6),NOW(6),NOW(6),"HEALTHY",1,:trace_id,:lock_key)'
    )->execute([
        'id' => $workflowId,
        'feature_id' => $featureId,
        'trace_id' => $traceId,
        'lock_key' => 'engineering:feature:'.$featureId.':workflow',
    ]);

    $insertRun = $pdo->prepare(
        'INSERT INTO cos_engineering_agent_runs
         (id,feature_id,task_id,workflow_execution_id,agent_id,agent_role,idempotency_key,model_provider,model,input_snapshot,status,technical_retry,logical_attempt,started_at,trace_id)
         VALUES (:id,:feature_id,:task_id,:workflow_id,:agent_id,"PRODUCT_REQUIREMENTS",:idempotency_key,"pending","pending",:input_snapshot,"RUNNING",0,1,NOW(6),:trace_id)'
    );

    $oldMappingRejected = false;
    try {
        $insertRun->execute([
            'id' => $runId,
            'feature_id' => $featureId,
            'task_id' => $executionTaskId,
            'workflow_id' => $workflowId,
            'agent_id' => 'product_requirements:'.$executionTaskId,
            'idempotency_key' => $featureId.':product-requirements:1:test',
            'input_snapshot' => json_encode(['_execution_task_id' => $executionTaskId], JSON_THROW_ON_ERROR),
            'trace_id' => $traceId,
        ]);
    } catch (PDOException) {
        $oldMappingRejected = true;
    }
    if (!$oldMappingRejected) {
        throw new RuntimeException('Expected foreign-key rejection when execution task id is persisted as cos_engineering_tasks.id.');
    }

    $insertRun->execute([
        'id' => $runId,
        'feature_id' => $featureId,
        'task_id' => null,
        'workflow_id' => $workflowId,
        'agent_id' => 'product_requirements:'.$executionTaskId,
        'idempotency_key' => $featureId.':product-requirements:1:test',
        'input_snapshot' => json_encode(['_execution_task_id' => $executionTaskId], JSON_THROW_ON_ERROR),
        'trace_id' => $traceId,
    ]);

    $pdo->prepare(
        'UPDATE cos_engineering_workflows
         SET current_agent_run_id=:run_id,current_task_id=NULL,heartbeat_at=NOW(6),last_activity_at=NOW(6),health_status="HEALTHY"
         WHERE id=:workflow_id'
    )->execute(['run_id' => $runId, 'workflow_id' => $workflowId]);

    $row = $pdo->prepare(
        'SELECT r.id,r.task_id,r.input_snapshot,w.current_agent_run_id,w.current_task_id
         FROM cos_engineering_agent_runs r
         INNER JOIN cos_engineering_workflows w ON w.id=r.workflow_execution_id
         WHERE r.id=:run_id'
    );
    $row->execute(['run_id' => $runId]);
    $actual = $row->fetch();

    if (!is_array($actual) || $actual['id'] !== $runId) {
        throw new RuntimeException('AgentRun was not persisted.');
    }
    if ($actual['task_id'] !== null || $actual['current_task_id'] !== null) {
        throw new RuntimeException('Stage AgentRun incorrectly populated persisted task FK.');
    }
    if ($actual['current_agent_run_id'] !== $runId) {
        throw new RuntimeException('Workflow did not point to the persisted AgentRun.');
    }
    $snapshot = json_decode((string) $actual['input_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    if (($snapshot['_execution_task_id'] ?? null) !== $executionTaskId) {
        throw new RuntimeException('Execution task correlation id was not preserved outside task FK.');
    }

    echo "Engineering AgentRun FK integration passed.\n";
} finally {
    $cleanup();
}
