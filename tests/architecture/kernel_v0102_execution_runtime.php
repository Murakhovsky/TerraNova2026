<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (version_compare(KernelVersion::VERSION, '0.10.2', '<')) {
    throw new RuntimeException('COS Kernel execution runtime closure requires Kernel 0.10.2+.');
}

$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) {
        throw new RuntimeException('Kernel execution runtime artifact is missing: ' . $path);
    }
    return (string) file_get_contents($file);
};

$assertContains = static function (string $source, array $needles, string $context): void {
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            throw new RuntimeException($context . ' is missing invariant: ' . $needle);
        }
    }
};

$assertNotContains = static function (string $source, array $needles, string $context): void {
    foreach ($needles as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException($context . ' bypasses the canonical runtime boundary: ' . $needle);
        }
    }
};

$ruleHandler = $read('app/Kernel/Rule/Service/RuleEngineEventHandler.php');
$assertContains($ruleHandler, [
    'ActionProposalSinkInterface',
    '$this->actions->accept($event, $proposal)',
], 'RuleEngineEventHandler');
$assertNotContains($ruleHandler, [
    'ActionService',
    'ActionExecutor',
    '$this->actions->execute(',
], 'RuleEngineEventHandler');

$proposalSink = $read('app/Kernel/Rule/Service/QueuedActionProposalSink.php');
$assertContains($proposalSink, [
    'ActionPolicyService',
    'JobQueueInterface',
    '$this->policies->submit(',
    'AgentRunJobHandler::TYPE',
    'ActionExecutionJobHandler::TYPE',
], 'QueuedActionProposalSink');
$assertNotContains($proposalSink, [
    'ActionExecutor',
    '$this->actions->execute(',
], 'QueuedActionProposalSink');

$agentRuntime = $read('app/Kernel/Agent/Service/AgentRuntime.php');
$assertContains($agentRuntime, [
    'new ActionProposal(',
    'return new AgentExecution(',
], 'AgentRuntime');
$assertNotContains($agentRuntime, [
    'ActionService',
    'ActionExecutor',
    'ActionPolicyService',
    'JobQueueInterface',
], 'AgentRuntime');

$agentHandler = $read('app/Kernel/Queue/Handler/AgentRunJobHandler.php');
$assertContains($agentHandler, [
    'AgentRuntime',
    'ActionPolicyService',
    'JobQueueInterface',
    '$this->runtime->run(',
    '$this->policies->submit(',
    'ActionExecutionJobHandler::TYPE',
], 'AgentRunJobHandler');
$assertNotContains($agentHandler, [
    'ActionExecutor',
    '$this->actions->execute(',
], 'AgentRunJobHandler');

$policy = $read('app/Kernel/Policy/Service/ActionPolicyService.php');
$assertContains($policy, [
    'PolicyEngine',
    'ApprovalRepositoryInterface',
    'TransactionManagerInterface',
    'PolicyDecision::Auto',
    'PolicyDecision::ApprovalRequired',
    'PolicyDecision::Denied',
    '$this->actions->queue(',
    '$this->requestApproval(',
], 'ActionPolicyService');
$assertNotContains($policy, ['ActionExecutor'], 'ActionPolicyService');

$executionHandler = $read('app/Kernel/Queue/Handler/ActionExecutionJobHandler.php');
$assertContains($executionHandler, [
    'ActionService',
    '$this->actions->execute(',
], 'ActionExecutionJobHandler');

$supervisor = $read('app/Kernel/Operations/Service/WorkerSupervisor.php');
$assertContains($supervisor, [
    'OutboxPublisher',
    'QueueWorker',
    '$this->outbox->runOne(',
    '$this->queue->runOne(',
], 'WorkerSupervisor');

$bootstrap = $read('app/Bootstrap/KernelServices.php');
$assertContains($bootstrap, [
    "'cosRuleEngineEventHandler'",
    "'cosActionProposalSink'",
    "'cosActionPolicyService'",
    "'cosAgentRunJobHandler'",
    "'cosActionExecutionJobHandler'",
    "'cosQueueWorker'",
    "'cosWorkerSupervisor'",
], 'KernelServices composition root');

echo "COS Kernel V0.10.2 execution runtime invariant passed.\n";
