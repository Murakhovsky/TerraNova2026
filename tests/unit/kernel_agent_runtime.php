<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\LlmProviderInterface;
use Kernel\Agent\Model\Agent;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentOutput;
use Kernel\Agent\Model\AgentRunStatus;
use Kernel\Agent\Service\AgentRuntimeEngine;
use Kernel\Shared\Domain\OrganizationId;

function agentExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$definition = new AgentDefinition('sales_assistant', '1.0', 'system', '1', '1', []);
$agent = new Agent('sales_assistant', $definition);
$organization = OrganizationId::fromString('org-agent-test');
$instance = new AgentInstance('instance-1', $organization, $agent);
$context = new AgentContext($organization, 'corr-1', ['question' => 'hello']);

$provider = new class implements LlmProviderInterface {
    public int $calls = 0;
    public function execute(AgentDefinition $definition, AgentContext $context): AgentOutput
    {
        ++$this->calls;
        return new AgentOutput('answer', ['decision' => 'ok'], 'test', 'model');
    }
};

$run = (new AgentRuntimeEngine($provider))->execute($instance, $context);
agentExpect($provider->calls === 1, 'LLM provider must be invoked exactly once.');
agentExpect($run->status() === AgentRunStatus::COMPLETED, 'Agent run must complete.');
agentExpect(count($run->steps()) === 1, 'Agent run must record its LLM step.');
agentExpect($run->steps()[0]->status() === AgentRunStatus::COMPLETED, 'LLM step must complete.');
agentExpect($run->output()?->structured['decision'] === 'ok', 'Agent output must be provider-neutral and preserved.');

$failing = new class implements LlmProviderInterface {
    public function execute(AgentDefinition $definition, AgentContext $context): AgentOutput
    {
        throw new RuntimeException('provider failed');
    }
};
$failedRun = (new AgentRuntimeEngine($failing))->execute($instance, $context);
agentExpect($failedRun->status() === AgentRunStatus::FAILED, 'Provider exceptions must become failed runs.');
agentExpect($failedRun->steps()[0]->status() === AgentRunStatus::FAILED, 'Provider exceptions must fail current step.');

echo "Kernel Agent Runtime contract passed.\n";
