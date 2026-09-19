<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$command = (string) file_get_contents($root . '/symfony/src/Command/KernelWorkerCommand.php');
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
$agent = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesAgentContextBuilder.php');
$legacyCompose = (string) file_get_contents($root . '/docker-compose.yml');

$assert(str_contains($command, 'WorkerSupervisor'), 'Symfony Kernel worker command must use the canonical WorkerSupervisor.');
$assert(str_contains($command, "name: 'cos:kernel:worker'"), 'Canonical Kernel worker command name is missing.');
foreach ([
    'Kernel\\Operations\\Service\\WorkerSupervisor:',
    'Kernel\\Queue\\Service\\QueueWorker:',
    'Kernel\\Queue\\Service\\JobHandlerRegistry:',
    'Kernel\\Queue\\Handler\\AgentRunJobHandler:',
    'runtime.sales_crm_job_handler:',
] as $needle) {
    $assert(str_contains($services, $needle), 'Symfony worker composition is missing: ' . $needle);
}

$agentRegistration = strstr($services, 'Domains\\Sales\\Infrastructure\\Persistence\\MySql\\MysqlSalesAgentContextBuilder:', false);
$assert(is_string($agentRegistration), 'Symfony Sales agent context registration is missing.');
$agentRegistration = substr($agentRegistration, 0, 500);
$assert(str_contains($agentRegistration, "PropertyReferencePort'"), 'Worker-safe Sales agent context must receive PropertyReferencePort.');
$assert(!str_contains($agentRegistration, 'organizationContext'), 'Canonical worker must not resolve session-backed organizationContext.');
$assert(!str_contains($agentRegistration, 'authService'), 'Canonical worker must not resolve web authentication.');

$assert(str_contains($agent, 'PropertyReferencePort'), 'Sales agent context builder must depend on PropertyReferencePort.');
$assert(str_contains($agent, '$invocation->organizationId'), 'Sales agent context builder must use AgentInvocation organizationId for tenant scope.');
$assert(str_contains($agent, 'new SalesPropertyReference($this->properties, $invocation->organizationId)'), 'Sales Property adapter must be constructed with explicit invocation tenant scope.');
$assert(!str_contains($agent, 'organizationContext'), 'Sales agent context builder must remain independent from request/session organization context.');
$assert(!str_contains($agent, 'authService'), 'Sales agent context builder must remain independent from web authentication.');

$assert(!str_contains($legacyCompose, 'app/bootstrap_cli.php", "worker", "run'), 'Legacy Phalcon CLI worker must stay retired.');

echo "Canonical worker tenant isolation contract passed.\n";
