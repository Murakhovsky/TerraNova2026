<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$source=static function(string $path)use($root):string {
    $value=file_get_contents($root.'/'.$path);
    if (!is_string($value)) throw new RuntimeException('Missing source: '.$path);
    return $value;
};
$check=static function(bool $ok,string $why):void {
    if (!$ok) throw new RuntimeException($why);
};
$receipt=$source('symfony/src/Persistence/Workflow/DoctrineWorkflowActionReceiptVerifier.php');
$yaml=$source('symfony/config/services.yaml');
$routes=$source('symfony/config/routes.yaml');
$controller=$source('symfony/src/Http/Api/V1/Controller/CanonicalGoalPlanPreflightController.php');
$gateway=$source('symfony/src/Application/Capability/CanonicalCapabilityGateway.php');
$check(str_contains($receipt,'GoalCapabilityPinGuard')
    && str_contains($receipt,'SELECT attempt, status, finished_at, result')
    && str_contains($receipt,'validateOutput($contract, $output)')
    && str_contains($receipt,'Pinned Action output unverified'),
    'Canonical persisted Action receipt must be validated against pinned output schema.');
$check(str_contains($yaml,"$"."capabilities: '@Kernel\\Module\\CanonicalCapabilityCatalog'"),
    'Receipt verifier lacks authoritative catalog DI.');
$check(str_contains($yaml,"$"."workflow: '@App\\Persistence\\Workflow\\FailClosedWorkflowActionWorkerAdmission'"),
    'No-go: external Workflow Action worker must remain fail-closed.');
$check(str_contains($routes,'/api/v1/capabilities/bindings')
    && str_contains($routes,'/api/v1/capability-plans/validate'),
    'Binding audit or Goal capability preflight route absent.');
$check(str_contains($controller,'$this->validator->validate($goal,$steps,$this->bindings->available($actor),true)')
    && str_contains($controller,"'executed'=>false")
    && str_contains($controller,"'persisted'=>false"),
    'Goal Plan dry-run must use canonical validator and remain proposal-only.');
$check(!str_contains($controller,'->submit(')
    && !str_contains($controller,'->dispatch(')
    && !str_contains($controller,'->proposePlan(')
    && !str_contains($controller,'->start('),
    'Goal Plan preflight introduced a mutating execution path.');
$check(str_contains($gateway,'function bindingAudit(')
    && str_contains($gateway,"'certified' => false")
    && str_contains($gateway,"'worker_deployment_verified' => false"),
    'Binding inventory must not infer certification from one request container.');
echo "Capability P0 runtime bindings, pinned receipt and Goal preflight authority PASS.\n";
