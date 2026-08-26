<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Module\DomainModuleRegistry;
use RuntimeException;

final readonly class RoutedAgentContextBuilder implements AgentContextBuilderInterface
{
    public function __construct(private DomainModuleRegistry $domains)
    {
    }

    public function build(AgentInvocation $invocation): array
    {
        if ($invocation->agentName === '') {
            throw new RuntimeException('Agent invocation must identify its agent.');
        }

        return $this->domains->agentContextBuilder($invocation->agentName)->build($invocation);
    }
}
