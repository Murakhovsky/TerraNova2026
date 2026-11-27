<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Runtime-neutral Web delegation boundary. Implementations project native readiness
 * and history, and expose only existing COS-owned planning/approval entry points.
 * They must never dispatch an external turn, resolve credentials or execute mutations.
 */
#[AutoconfigureTag('cos.web.agent_runtime_adapter')]
interface AgentRuntimeAdapterInterface
{
    public function supports(UIContext $context): bool;

    /** Safe, actor-scoped presentation data only. */
    public function inspect(TenantContext $actor, UIContext $context): AgentRuntimeInspection;
}
