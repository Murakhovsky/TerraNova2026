<?php

declare(strict_types=1);

namespace App\Web\Experience\Workspace;

use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;
use LogicException;

final readonly class WorkspaceCompositionResolver
{
    public function __construct(
        private WebExtensionCatalog $extensions,
        private UIActionResolver $actions,
    ) {
    }

    public function resolve(
        TenantContext $tenant,
        WebExtensionContext $context,
        string $workspaceId,
        ?EntityRef $entity = null,
    ): WorkspaceViewModel {
        if ($tenant->organizationId()->value() !== $context->organizationId) {
            throw new LogicException('Workspace context does not belong to the authenticated organization.');
        }

        if ($tenant->role()->value() !== $context->role) {
            throw new LogicException('Workspace context role does not match the authenticated tenant context.');
        }

        $catalog = $this->extensions->forContext($context);
        $definition = null;

        foreach ($catalog->workspaces() as $candidate) {
            if ($candidate->id === $workspaceId) {
                $definition = $candidate;
                break;
            }
        }

        if (!$definition instanceof WorkspaceDefinition) {
            throw new InvalidArgumentException('Unknown Workspace: ' . $workspaceId);
        }

        if ($definition->entityType !== null && $entity !== null && $entity->type !== $definition->entityType) {
            throw new InvalidArgumentException(sprintf(
                'Workspace %s expects entity type %s, %s given.',
                $workspaceId,
                $definition->entityType,
                $entity->type,
            ));
        }

        $resolvedActions = $this->actions->resolve($tenant, $context, $entity);

        return new WorkspaceViewModel(
            definition: $definition,
            context: $context,
            entity: $entity,
            extensions: $catalog->workspaceExtensions($workspaceId),
            primaryActions: array_values(array_filter(
                $resolvedActions,
                static fn ($action): bool => $action->supportsPlacement(UIActionPlacement::WORKSPACE_PRIMARY),
            )),
            secondaryActions: array_values(array_filter(
                $resolvedActions,
                static fn ($action): bool => $action->supportsPlacement(UIActionPlacement::WORKSPACE_SECONDARY),
            )),
        );
    }
}
