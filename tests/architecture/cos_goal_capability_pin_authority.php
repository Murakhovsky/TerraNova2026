<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string {
    $body=file_get_contents($root.'/'.$path);
    if (!is_string($body)) throw new RuntimeException('Missing '.$path);
    return $body;
};
$check=static function(bool $ok,string $why):void {
    if (!$ok)throw new RuntimeException($why);
};
$store=$read('symfony/src/Persistence/Federation/FederationGoalStore.php');
$validator=$read('app/Platform/Orchestration/Goal/GoalPlanValidator.php');
$bridge=$read('symfony/src/Persistence/Federation/FederationCanonicalWorkflowBridge.php');
$claim=$read('symfony/src/Persistence/Workflow/DoctrineWorkflowActionClaimAdmission.php');
$worker=$read('symfony/src/Persistence/Workflow/DoctrineWorkflowActionWorkerAdmission.php');
$services=$read('symfony/config/services.yaml');
$check(str_contains($store,'$this->validator->validate($goal, $steps, $available, true)'),
    'Durable Goal proposal must pin contract schemas.');
$check(str_contains($validator,'CapabilityContractFingerprint::inputDigest')
    && str_contains($validator,"'contract_digest' =>"),
    'Planner must embed a deterministic digest in each new Step.');
$check(substr_count($bridge, 'new GoalCapabilityPinGuard($this->capabilities)')>=2,
    'Canonical start/advance must recheck approved capability pins.');
$check(str_contains($claim,'new GoalCapabilityPinGuard($this->catalog)')
    && str_contains($worker,'new GoalCapabilityPinGuard($this->catalog)'),
    'Both claim and worker candidates must attest pinning before side effects.');
// Production remains explicitly fail-closed until independent Action worker
// MySQL/failover/approval parity and operator cutover are accepted.
$check(str_contains($services,"$"."workflow: '@App\\Persistence\\Workflow\\FailClosedWorkflowActionWorkerAdmission'"),
    'Unsafe premature activation of external Action worker detected.');
$check(!str_contains($validator,'->dispatch(') && !str_contains($validator,'->submit('),
    'Goal pinning must not introduce a second dispatcher.');
echo "Goal Capability pin execution-authority boundary PASS.\n";
