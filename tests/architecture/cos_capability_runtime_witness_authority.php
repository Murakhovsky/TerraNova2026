<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $file)use($root):string {
    $data=file_get_contents($root.'/'.$file);
    if(!is_string($data))throw new RuntimeException('Missing '.$file);
    return $data;
};
$assert=static function(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
};
$gate=$read('app/Kernel/Action/Service/ModuleActionExecutionGate.php');
$cli=$read('symfony/src/Command/CapabilityRuntimeWitnessCommand.php');
$closed=$read('symfony/src/Persistence/Workflow/FailClosedWorkflowActionWorkerAdmission.php');
$gateway=$read('symfony/src/Application/Capability/CanonicalCapabilityGateway.php');
$di=$read('symfony/config/services.yaml');
$assert(str_contains($gate,'workflowAdmissionMode()')
    && str_contains($gate,'FailClosedWorkflowAdmissionMarkerInterface'),
    'Worker mode must be inspected from actual execution gate.');
$assert(str_contains($closed,'FailClosedWorkflowAdmissionMarkerInterface')
    && str_contains($di,"$"."workflow: '@App\\Persistence\\Workflow\\FailClosedWorkflowActionWorkerAdmission'"),
    'Production DI must remain disabled and explicitly recognizable.');
$assert(str_contains($di,"$"."domains: '@Kernel\\Module\\DomainModuleRegistry'")
    && str_contains($di,"$"."executionGate: '@Kernel\\Action\\Service\\ModuleActionExecutionGate'"),
    'Capability registry witness must receive the exact ActionExecutor registry/gate DI.');
$assert(str_contains($gateway,'$this->domains?->actionHandlerMap()')
    && str_contains($gateway,'$this->domains->ownerOfAction($type)')
    && str_contains($gateway,'$handlers[$type]->supports($type)')
    && str_contains($gateway,"'worker_execution_certified'=>false"),
    'Live Action witness must prove owner/registered handler and deny certification.');
$assert(str_contains($cli,"name: 'cos:capability:runtime:witness'")
    && str_contains($cli,'$this->domains->actionHandlerMap()')
    && str_contains($cli,'$this->gate->workflowAdmissionMode()')
    && str_contains($cli,"'worker_execution_certified' => false")
    && !str_contains($cli,'->assertExecutable(')
    && !str_contains($cli,'->execute(')
    && !str_contains($cli,'->invoke('),
    'Operator runtime witness must inspect actual DI without dispatch.');
$assert(!str_contains($gateway,'$bindingWitness = $status')
    && !str_contains($gateway,"$"."status === 'workflow_approval_required') {\n            $"."bindingWitness"),
    'Status-derived Action handler witness must never return.');
echo "Capability binding evidence uses actual Domain registry and fail-closed Worker gate PASS.\n";
