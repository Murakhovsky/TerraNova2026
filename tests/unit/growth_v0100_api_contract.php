<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/GrowthApiController.php');

function expectGrowthV0100(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

preg_match_all('/^(cos_api_v1_growth_[a-z0-9_]+):$/m',$routes,$matches);
$names=$matches[1]??[];
expectGrowthV0100(count($names)===34,'Growth API route count changed unexpectedly.');
expectGrowthV0100(count(array_unique($names))===count($names),'Growth API route names must be unique.');

$mutations=[
    'runCollector','createSignal','createCandidate','researchCandidate','scoreCandidate','qualifyCandidate','monitorCandidate',
    'disqualifyCandidate','prepareHandoff','createIcp','reviseIcp','activateIcp','createAccount',
    'snapshotAccount','scoreAccount','createContact','snapshotContact','assessCommittee',
    'generateResearch','acceptResearch','createQualificationPolicy','reviseQualificationPolicy',
    'activateQualificationPolicy','evaluateCandidate','dispatchHandoff',
];
foreach($mutations as $method){
    $pos=strpos($controller,'function '.$method.'(');
    expectGrowthV0100($pos!==false,'Growth mutation method missing: '.$method);
    $slice=substr($controller,$pos,1600);
    expectGrowthV0100(str_contains($slice,'$this->mutate(')||str_contains($slice,'candidateReasonMutation('),'Growth mutation bypasses common mutation guard: '.$method);
}

$reads=['collectors','signal','candidate','account','committee','researchBrief','decisionBrief','handoffTargets','handoffBrief'];
foreach($reads as $method){
    $pos=strpos($controller,'function '.$method.'(');
    expectGrowthV0100($pos!==false,'Growth read method missing: '.$method);
    $slice=substr($controller,$pos,900);
    expectGrowthV0100(str_contains($slice,'$this->read('),'Growth read bypasses common read guard: '.$method);
}

expectGrowthV0100(str_contains($controller,"\$permission=\$mutation?TenantPermissions::MANAGE:TenantPermissions::ACCESS"),'Growth API permission split is missing.');
expectGrowthV0100(str_contains($controller,"\$this->csrf->isValid(\$request)"),'Growth API mutation CSRF guard is missing.');
expectGrowthV0100(str_contains($controller,"headers->get('X-Idempotency-Key'"),'Growth API idempotency header guard is missing.');

echo "Growth V0.10 API contract checks passed.\n";
