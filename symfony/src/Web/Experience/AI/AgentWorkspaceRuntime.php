<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

use DomainException;
use Kernel\Tenant\Model\TenantContext;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class AgentWorkspaceRuntime
{
    /** @param iterable<AgentRuntimeAdapterInterface> $adapters */
    public function __construct(
        #[AutowireIterator('cos.web.agent_runtime_adapter')]
        private iterable $adapters,
    ) {}

    /** @return array<string,mixed>|null */
    public function inspect(TenantContext $actor, UIContext $context): ?array
    {
        if (!AgentWorkspaceAccess::allows($actor)) {
            throw new DomainException('Agent runtime scope denied.');
        }

        $selected = null;
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($context)) {
                if ($selected !== null) {
                    throw new LogicException('Ambiguous Agent runtime binding.');
                }
                $selected = $adapter;
            }
        }

        return $selected?->inspect($actor, $context);
    }
}
