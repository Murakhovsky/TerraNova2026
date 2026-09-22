<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.9.0','Growth V0.9 manifest version must be 0.9.0.');
$assert(($manifest['schema_version']??null)==='0.8.0','Growth V0.9 must not invent a schema migration.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.9 must remain disabled before delivery cutover.');
$assert(in_array('growth.handoff.target.sales',$manifest['contributions']['capabilities']??[],true),'Growth Sales handoff capability is missing.');

$contract=$read('app/Domains/Growth/Application/Contract/GrowthHandoffTargetInterface.php');
$assert(str_contains($contract,'int $actorId'),'Growth handoff target contract must propagate actor provenance.');

$service=$read('app/Domains/Growth/Application/Service/GrowthHandoffService.php');
$assert(str_contains($service,'target->accept($handoff,$actorId,$correlationId,$targetIdempotencyKey)'),'Growth handoff dispatcher must pass actor provenance.');

$adapter=$read('app/Domains/Growth/Infrastructure/Handoff/SalesGrowthHandoffTarget.php');
foreach([
    'GrowthHandoffTargetInterface','GrowthBuyingCommitteeRepositoryInterface','SalesWriteServiceFactoryInterface',
    "return 'sales'","subjectType==='contact'","subjectType!=='account'",'latestAssessment',
    "'identity_type'","$identityType!=='email'","createLead([","'source'=>'growth-handoff'",
    "'sales_lead'","idempotency_conflict",
] as $needle){
    $assert(str_contains($adapter,$needle),'Growth Sales handoff adapter missing: '.$needle);
}
foreach(['PDO','Mysql','tn_leads','tn_client_cases','Infrastructure\\Platform'] as $forbidden){
    $assert(!str_contains($adapter,$forbidden),'Growth Sales handoff adapter bypasses Sales application boundary: '.$forbidden);
}

$services=$read('symfony/config/services.yaml');
foreach(['SalesGrowthHandoffTarget','GrowthBuyingCommitteeRepositoryInterface','SalesWriteServiceFactoryInterface'] as $needle){
    $assert(str_contains($services,$needle),'Growth Sales handoff DI missing: '.$needle);
}

echo "Growth V0.9 Sales Handoff Adapter architecture: OK\n";
