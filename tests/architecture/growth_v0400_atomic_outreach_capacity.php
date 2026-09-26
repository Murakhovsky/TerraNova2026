<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.40.0','>='),'Growth manifest must remain V0.40+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.40.0','>='),'Growth schema must remain V0.40+.');
$assert(
    in_array('growth.engagement.atomic_capacity_admission',$manifest['contributions']['capabilities']??[],true),
    'Growth atomic capacity capability missing.',
);

$migration='app/migrations/20260924_000105_growth_v0400_atomic_outreach_capacity.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.40 migration missing.');
$sql=$read($migration);
foreach([
    'tn_growth_engagement_capacity_locks',
    'PRIMARY KEY (organization_id)',
    "installed_version='0.40.0'",
    "installed_version='0.39.0'",
    "schema_version='0.40.0'",
    "schema_version='0.39.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.40 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(
    str_contains($ownership,"'tn_growth_engagement_capacity_locks'"),
    'Growth capacity lock table must be Growth-owned.',
);

$contract=$read('app/Domains/Growth/Application/Contract/GrowthEngagementExecutionRepositoryInterface.php');
$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementExecutionRepository.php');
foreach([
    'lockPreHandoffCapacity',
    'inTransaction()',
    'tn_growth_engagement_capacity_locks',
    'FOR UPDATE',
] as $needle){
    $assert(str_contains($contract.$repo,$needle),'Growth atomic capacity repository missing: '.$needle);
}

$execution=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
$transactionPos=strpos($execution,'return $this->transactions->transactional(function()use(');
$lockPos=strpos($execution,'$this->executions->lockPreHandoffCapacity($organizationId);',$transactionPos);
$authoritativeCheckPos=strpos($execution,'$limitDecision=$this->preHandoffLimitDecision($organizationId,$targetReferenceId,$channel);',$lockPos);
$actionPos=strpos($execution,'$action=match($kernelActionType){',$authoritativeCheckPos);
$linkPos=strpos($execution,'$this->executions->createOrVerify(',$actionPos);
$assert($transactionPos!==false,'Growth execution transaction missing.');
$assert($lockPos!==false&&$lockPos>$transactionPos,'Capacity lock must happen inside the canonical transaction.');
$assert($authoritativeCheckPos!==false&&$authoritativeCheckPos>$lockPos,'Authoritative limits must be re-checked after lock acquisition.');
$assert($actionPos!==false&&$actionPos>$authoritativeCheckPos,'Kernel Action must not exist before authoritative capacity admission.');
$assert($linkPos!==false&&$linkPos>$actionPos,'Execution link must be written after the governed Action proposal.');

$tx=$read('app/Infrastructure/Platform/Persistence/MySql/Transaction/TransactionManager.php');
$policy=$read('app/Kernel/Policy/Service/ActionPolicyService.php');
$assert(
    str_contains($tx,'if ($this->connection->inTransaction())')
    &&str_contains($tx,'return $operation();')
    &&str_contains($policy,'$this->transactions->transactional('),
    'Kernel Action policy must join the existing canonical transaction.',
);

$readme=$read('app/Domains/Growth/README.md');
$assert(str_contains($readme,'V0.40 makes pre-handoff quota admission concurrency-safe'),'Growth V0.40 README contract missing.');
$assert(str_contains($readme,'does **not** enable autonomous outreach'),'V0.40 must not grant autonomous execution authority.');

echo "Growth V0.40 Atomic Outreach Capacity architecture: OK\n";
