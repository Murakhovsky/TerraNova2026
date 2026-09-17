<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Agent\AgentInvocation;
use Kernel\Shared\Domain\OrganizationId;
use Infrastructure\Knowledge\PlatformAgentContextBuilder;
use Platform\Knowledge\Contract\RetrieverInterface;
use Platform\Knowledge\Model\ContextRequest;
use Platform\Knowledge\Model\RetrievalResult;
use Platform\Knowledge\Service\ContextBuilder;

$retriever = new class implements RetrieverInterface {
    public function retrieve(ContextRequest $request): array
    {
        return [
            new RetrievalResult('c-low', 'd1', 's1', 'low', 0.2, 2),
            new RetrievalResult('c-best', 'd2', 's2', 'best', 0.95, 4),
            new RetrievalResult('c-next', 'd3', 's3', 'next', 0.8, 4),
            new RetrievalResult('c-best', 'd2', 's2', 'duplicate', 0.7, 4),
        ];
    }
};

$builder = new ContextBuilder($retriever);
$context = $builder->build(new ContextRequest(OrganizationId::fromString('org-1'), 'pipeline risk', maxTokens: 8, limit: 10, correlationId: 'corr-1'));
assert(count($context->results) === 2);
assert($context->results[0]->chunkId === 'c-best');
assert($context->results[1]->chunkId === 'c-next');
assert($context->tokenCount === 8);

$agentBuilder = new PlatformAgentContextBuilder($builder, 8);
$agentContext = $agentBuilder->build(new AgentInvocation('org-1', 'deal', 'deal-1', 'pipeline risk', 'corr-2', ['crm:deal-1']));
assert(isset($agentContext['knowledge']['sources'][0]['source_id']));
assert($agentContext['knowledge']['token_count'] === 8);

echo "Platform Knowledge context OK\n";
