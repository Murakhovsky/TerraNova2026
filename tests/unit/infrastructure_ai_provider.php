<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infrastructure\AI\StructuredLlmAgentProvider;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Model\AgentContext;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

function expectAiProvider(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$captured = null;
$client = new class($captured) implements StructuredLlmClientInterface {
    public ?StructuredLlmRequest $request = null;

    public function __construct(&$captured)
    {
        $captured = &$this->request;
    }

    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->request = $request;

        return new StructuredLlmResponse(
            [
                'decision' => 'follow_up',
                'reason' => 'Client needs another contact.',
                'confidence' => 0.91,
                'proposed_actions' => [],
            ],
            'fake-provider',
            'fake-model',
            120,
            35,
            0.0042,
            'USD',
        );
    }
};

$definition = new AgentDefinition(
    name: 'sales_assistant',
    version: '1.0',
    systemPrompt: 'You are the sales assistant.',
    promptVersion: '1',
    schemaVersion: '1',
    allowedActionTypes: ['sales.schedule_followup'],
    model: 'configured-model',
    maxActionsPerRun: 3,
);

$context = new AgentContext(
    OrganizationId::fromString('org-ai'),
    'corr-ai-1',
    ['question' => 'What should happen next?'],
    ['deal' => ['id' => 'deal-1']],
    UserId::fromString('42'),
    ['source' => 'test'],
);

$output = (new StructuredLlmAgentProvider($client))->execute($definition, $context);

expectAiProvider($client->request instanceof StructuredLlmRequest, 'Provider must delegate through the provider-neutral structured LLM contract.');
expectAiProvider($client->request->organizationId === 'org-ai', 'Organization scope must reach the LLM request.');
expectAiProvider($client->request->correlationId === 'corr-ai-1', 'Correlation id must reach the LLM request.');
expectAiProvider($client->request->useCase === 'agent.run', 'Agent runtime must use a stable provider routing use case.');
expectAiProvider(($client->request->context['requested_by'] ?? null) === '42', 'Requesting user must be represented in provider context.');
expectAiProvider(($client->request->responseSchema['properties']['proposed_actions']['maxItems'] ?? null) === 3, 'Agent action limit must shape structured output.');
expectAiProvider($output->provider === 'fake-provider' && $output->model === 'fake-model', 'Provider-neutral output metadata must be preserved.');
expectAiProvider(($output->usage['input_tokens'] ?? null) === 120, 'Token usage must be preserved.');
expectAiProvider($output->content === 'Client needs another contact.', 'Human-readable reason should become AgentOutput content.');

echo "Infrastructure AI provider contract passed.\n";
