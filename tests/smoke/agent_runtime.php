<?php
declare(strict_types=1);

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Agent\Contract\AgentRunRepositoryInterface;
use Kernel\Agent\Contract\LlmClientInterface;
use Kernel\Agent\LlmResponse;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Agent\Service\StructuredDecisionValidator;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Kernel\\' => '/app/Kernel/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});

$statuses = [];
$contextBuilder = new class implements AgentContextBuilderInterface {
    public function build(AgentInvocation $invocation): array
    {
        return ['deal' => ['stage' => 'qualified', 'next_contact_at' => null]];
    }
};
$runs = new class($statuses) implements AgentRunRepositoryInterface {
    public function __construct(private array &$statuses) {}
    public function start(string $runId, AgentDefinition $agent, AgentInvocation $invocation, array $context): void { $this->statuses[] = 'RUNNING'; }
    public function complete(string $runId, AgentResult $result, LlmResponse $response, int $durationMs): void { $this->statuses[] = 'COMPLETED'; }
    public function fail(string $runId, Throwable $error, int $durationMs, bool $invalidOutput = false): void { $this->statuses[] = $invalidOutput ? 'INVALID_OUTPUT' : 'FAILED'; }
};
$llm = new class implements LlmClientInterface {
    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse
    {
        return new LlmResponse([
            'decision' => 'FOLLOW_UP',
            'reason' => 'Buying intent exists but no next contact is scheduled.',
            'confidence' => 0.87,
            'proposed_actions' => [[
                'type' => 'sales.send_followup',
                'parameters' => ['channel' => 'telegram'],
            ]],
        ], 'fake', 'fake-structured');
    }
};
$agent = new AgentDefinition('sales', '1', 'prompt', 'p1', 's1', ['sales.send_followup']);
$invocation = new AgentInvocation('default', 'deal', '184', 'Best next step?', 'correlation-1');
$runtime = new AgentRuntime($contextBuilder, $llm, new StructuredDecisionValidator(), $runs);
$execution = $runtime->run($agent, $invocation);

if ($statuses !== ['RUNNING', 'COMPLETED']
    || count($execution->proposals) !== 1
    || $execution->proposals[0]->type !== 'sales.send_followup'
    || $execution->proposals[0]->parameters !== ['channel' => 'telegram']
) {
    throw new RuntimeException('Agent Runtime did not produce the expected proposal.');
}

$forbiddenLlm = new class implements LlmClientInterface {
    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse
    {
        return new LlmResponse([
            'decision' => 'MUTATE', 'reason' => 'unsafe', 'confidence' => 1,
            'proposed_actions' => [['type' => 'database.delete_customer', 'parameters' => []]],
        ], 'fake', 'fake-unsafe');
    }
};
$invalidRuns = [];
$invalidRepository = new class($invalidRuns) implements AgentRunRepositoryInterface {
    public function __construct(private array &$statuses) {}
    public function start(string $runId, AgentDefinition $agent, AgentInvocation $invocation, array $context): void { $this->statuses[] = 'RUNNING'; }
    public function complete(string $runId, AgentResult $result, LlmResponse $response, int $durationMs): void { $this->statuses[] = 'COMPLETED'; }
    public function fail(string $runId, Throwable $error, int $durationMs, bool $invalidOutput = false): void { $this->statuses[] = $invalidOutput ? 'INVALID_OUTPUT' : 'FAILED'; }
};
try {
    (new AgentRuntime($contextBuilder, $forbiddenLlm, new StructuredDecisionValidator(), $invalidRepository))->run($agent, $invocation);
    throw new RuntimeException('Forbidden action was accepted.');
} catch (InvalidArgumentException) {
}
if ($invalidRuns !== ['RUNNING', 'INVALID_OUTPUT']) {
    throw new RuntimeException('Invalid agent output was not audited.');
}

echo "Agent Runtime smoke test passed.\n";
