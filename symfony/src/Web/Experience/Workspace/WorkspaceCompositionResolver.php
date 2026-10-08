<?php

declare(strict_types=1);

namespace App\Web\Experience\Workspace;

use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Adaptive\AdaptiveExperienceResolver;
use App\Web\Experience\Adaptive\ExperienceContext;
use App\Web\Experience\Adaptive\ExperienceComposition;
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
        private ?AdaptiveExperienceResolver $adaptive = null,
    ) {
    }

    /**
     * Optional adaptive projection of an already-resolved, tenant-checked Workspace.
     * Reuses canonical UIAction objects; never constructs new mutation authority.
     */
    public function resolveAdaptive(
        TenantContext $tenant,
        WebExtensionContext $context,
        string $workspaceId,
        ExperienceContext $experience,
        ?EntityRef $entity = null,
    ): ExperienceComposition {
        if ($experience->organizationId !== $tenant->organizationId()->value()) {
            throw new LogicException('Adaptive experience tenant differs from authenticated context.');
        }
        if ($experience->userId !== $tenant->userId()->value()) {
            throw new LogicException('Adaptive experience user differs from authenticated context.');
        }
        $view = $this->resolve($tenant, $context, $workspaceId, $entity);
        return ($this->adaptive ?? new AdaptiveExperienceResolver())->compose(
            $experience,
            [
                ...$view->primaryActions, ...$view->secondaryActions,
                ...$view->mobilePrimaryActions, ...$view->mobileMenuActions,
            ],
        );
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
            mobilePrimaryActions: array_values(array_filter(
                $resolvedActions,
                static fn ($action): bool => $action->supportsPlacement(UIActionPlacement::MOBILE_PRIMARY),
            )),
            mobileMenuActions: array_values(array_filter(
                $resolvedActions,
                static fn ($action): bool => $action->supportsPlacement(UIActionPlacement::MOBILE_MENU),
            )),
        );
    }
}
