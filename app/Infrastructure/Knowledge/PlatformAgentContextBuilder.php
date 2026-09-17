<?php
declare(strict_types=1);

namespace Infrastructure\Knowledge;

use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Knowledge\Contract\ContextBuilderInterface;
use Platform\Knowledge\Model\ContextRequest;

final readonly class PlatformAgentContextBuilder implements AgentContextBuilderInterface
{
    public function __construct(private ContextBuilderInterface $contexts, private int $maxTokens = 4000)
    {
    }

    public function build(AgentInvocation $invocation): array
    {
        $context = $this->contexts->build(new ContextRequest(
            organizationId: OrganizationId::fromString($invocation->organizationId),
            query: $invocation->question,
            references: array_values(array_filter($invocation->contextReferences, 'is_string')),
            maxTokens: $this->maxTokens,
            correlationId: $invocation->correlationId,
            filters: ['subject_type' => $invocation->subjectType, 'subject_id' => $invocation->subjectId],
        ));

        return ['knowledge' => $context->toArray()];
    }
}
