<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Model\EntityRef;
use Kernel\Agent\AgentRunProjection;
use Kernel\Tenant\Model\TenantContext;

final class AgentWorkspaceScope
{
    public static function permitsEntity(WorkspaceDefinition $workspace, ?EntityRef $entity): bool
    {
        if ($entity === null) {
            return true;
        }

        // Marketing currently registers a collection rather than an entity workspace.
        $type = $workspace->entityType
            ?? ($workspace->id === 'marketing.campaigns' ? 'marketing_campaign' : null);

        return $type !== null && $type === $entity->type;
    }

    public static function permitsRun(TenantContext $actor, ?EntityRef $entity, AgentRunProjection $run): bool
    {
        if (!AgentWorkspaceAccess::allows($actor) || $run->organizationId !== $actor->organizationId()->value()) {
            return false;
        }
        if ($entity === null) {
            return true;
        }

        // Match the existing canonical read model's legacy subject alias, never another ID.
        $parts = explode('.', $entity->type);
        return $run->subjectId === $entity->id
            && in_array($run->subjectType, [$entity->type, end($parts)], true);
    }
}
