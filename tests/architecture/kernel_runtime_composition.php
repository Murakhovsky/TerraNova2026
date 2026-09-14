<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) {
        throw new RuntimeException('Kernel runtime composition artifact is missing: ' . $path);
    }
    return (string) file_get_contents($file);
};

$kernel = $read('app/Bootstrap/KernelServices.php');
$salesAgent = $read('app/Bootstrap/SalesAgentServices.php');
$services = $read('app/config/services_kernel.php');
$extensions = $read('app/Kernel/Module/ModuleExtensionRegistry.php');
$moduleServices = $read('app/Bootstrap/ModuleServices.php');
$agentRuntime = $read('app/Kernel/Agent/Service/AgentRuntime.php');
$actionExecutor = $read('app/Kernel/Action/Service/ActionExecutor.php');
$actionGate = $read('app/Kernel/Action/Service/ModuleActionExecutionGate.php');

if (substr_count($kernel, "setShared('cosAgentRuntime'") !== 1) {
    throw new RuntimeException('KernelServices must own exactly one cosAgentRuntime registration.');
}
if (str_contains($salesAgent, "setShared('cosAgentRuntime'")) {
    throw new RuntimeException('Domain bootstrap must not override Kernel-owned cosAgentRuntime.');
}
if (!str_contains($kernel, "getShared('cosAgentConfigurationProvider')")) {
    throw new RuntimeException('Canonical AgentRuntime wiring must include the managed configuration provider.');
}
if (strpos($services, "SalesAgentServices.php") > strpos($services, "KernelServices.php")) {
    throw new RuntimeException('Agent configuration provider must be registered before KernelServices composition.');
}
if (!str_contains($agentRuntime, 'requires managed configuration, but no configuration provider is available')) {
    throw new RuntimeException('Managed agents must fail closed when configuration resolution is unavailable.');
}
if (!str_contains($extensions, "EVENT_CONSUMERS = 'event.consumers'")) {
    throw new RuntimeException('Durable event consumers must be a first-class module extension point.');
}
foreach (['cosModuleEventConsumers', 'DurableEventConsumerInterface'] as $needle) {
    if (!str_contains($moduleServices, $needle)) {
        throw new RuntimeException('ModuleServices is missing durable event consumer wiring: ' . $needle);
    }
}
if (!str_contains($kernel, "getShared('cosModuleEventConsumers')")) {
    throw new RuntimeException('DurableEventDispatcher must include module-owned event consumers.');
}
if (!str_contains($kernel, "setShared('cosActionExecutionGate'")
    || !str_contains($kernel, "getShared('cosActionExecutionGate')")) {
    throw new RuntimeException('Canonical ActionExecutor wiring must include an explicit execution gate.');
}
if (str_contains($actionExecutor, '?DomainModuleRegistry') || str_contains($actionExecutor, '?ActiveModuleResolver')) {
    throw new RuntimeException('ActionExecutor must not contain nullable module-governance bypasses.');
}
foreach (['ownerOfAction', 'isEnabled', 'has no owning domain module'] as $needle) {
    if (!str_contains($actionGate, $needle)) {
        throw new RuntimeException('Module action execution gate is missing fail-closed invariant: ' . $needle);
    }
}

echo "COS Kernel runtime composition invariant passed.\n";
