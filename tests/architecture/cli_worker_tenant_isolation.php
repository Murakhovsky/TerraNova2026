<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$bootstrap = (string) file_get_contents($root . '/app/bootstrap_cli.php');
$services = (string) file_get_contents($root . '/app/Bootstrap/SalesServices.php');
$agent = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesAgentContextBuilder.php');
$worker = (string) file_get_contents($root . '/app/Interfaces/Cli/Task/WorkerTask.php');

$assert(str_contains($bootstrap, 'FactoryDefault'), 'CLI bootstrap must use the CLI DI container.');
$assert(str_contains($worker, "getShared('cosWorkerSupervisor')"), 'Worker task must resolve the canonical worker supervisor.');

$agentRegistrationStart = strpos($services, "$di->setShared('salesAgentContextBuilder'");
$domainRegistrationStart = strpos($services, "$di->setShared('salesDomainModule'");
$assert($agentRegistrationStart !== false && $domainRegistrationStart !== false && $domainRegistrationStart > $agentRegistrationStart, 'Sales agent DI registration is missing.');
$agentRegistration = substr($services, $agentRegistrationStart, $domainRegistrationStart - $agentRegistrationStart);

$assert(str_contains($agentRegistration, "getShared('propertyReferencePort')"), 'Worker-safe Sales agent context must receive PropertyReferencePort.');
$assert(!str_contains($agentRegistration, "getShared('salesPropertyReference')"), 'Worker-safe Sales agent context must not resolve request-scoped SalesPropertyReference.');
$assert(!str_contains($agentRegistration, 'organizationContext'), 'Worker-safe Sales agent context must not resolve session-backed organizationContext.');
$assert(!str_contains($agentRegistration, 'authService'), 'Worker-safe Sales agent context must not resolve web authentication.');
$assert(!str_contains($agentRegistration, "getShared('session')"), 'Worker-safe Sales agent context must never resolve a web session.');

$assert(str_contains($agent, 'PropertyReferencePort'), 'Sales agent context builder must depend on PropertyReferencePort.');
$assert(str_contains($agent, '$invocation->organizationId'), 'Sales agent context builder must use AgentInvocation organizationId for tenant scope.');
$assert(str_contains($agent, 'new SalesPropertyReference($this->properties, $invocation->organizationId)'), 'Sales agent Property adapter must be constructed with explicit invocation tenant scope.');

$assert(!str_contains($agent, 'organizationContext'), 'Sales agent context builder must remain independent from request/session organization context.');
$assert(!str_contains($agent, 'authService'), 'Sales agent context builder must remain independent from web authentication.');
$assert(!str_contains($agent, "getShared('session')"), 'Sales agent context builder must remain independent from web sessions.');

echo "CLI worker tenant isolation contract passed.\n";
