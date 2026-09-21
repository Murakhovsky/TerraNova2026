<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;
use LogicException;

final readonly class UIContextFactory
{
    public function __construct(
        private WebExtensionCatalog $extensions,
        private UIActionResolver $actions,
    ) {
    }

    /**
     * @param list<EntityRef> $selectedEntities
     * @param array<string,mixed> $activeFilters
     * @param list<string> $capabilities
     */
    public function create(
        TenantContext $tenant,
        WebExtensionContext $context,
        string $workspaceId,
        ?EntityRef $entity = null,
        array $selectedEntities = [],
        array $activeFilters = [],
        array $capabilities = [],
    ): UIContext {
        if ($tenant->organizationId()->value() !== $context->organizationId) {
            throw new LogicException('UIContext organization does not match the authenticated tenant.');
        }
        if ($tenant->role()->value() !== $context->role) {
            throw new LogicException('UIContext role does not match the authenticated tenant.');
        }

        $workspace = null;
        foreach ($this->extensions->forContext($context)->workspaces() as $candidate) {
            if ($candidate->id === $workspaceId) {
                $workspace = $candidate;
                break;
            }
        }
        if ($workspace === null) {
            throw new InvalidArgumentException('Unknown UIContext workspace: ' . $workspaceId);
        }
        if ($workspace->entityType !== null && $entity !== null && $workspace->entityType !== $entity->type) {
            throw new InvalidArgumentException('UIContext entity type does not match Workspace definition.');
        }

        foreach ($selectedEntities as $selected) {
            if (!$selected instanceof EntityRef) {
                throw new InvalidArgumentException('UIContext selectedEntities must contain EntityRef values.');
            }
        }

        $available = array_map(
            static fn ($action): UIContextAction => new UIContextAction(
                id: $action->id,
                intent: $action->intent->value,
                enabled: $action->enabled,
                resourceId: $action->resourceId,
                command: $action->command,
                dangerLevel: $action->dangerLevel,
                confirmation: $action->confirmationContract(),
                disabledReason: $action->disabledReason,
            ),
            $this->actions->resolve(
                $tenant,
                $context,
                $entity,
                UIActionPlacement::AI_PROPOSAL,
            ),
        );

        $capabilities = array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $capabilities),
            static fn (string $item): bool => $item !== '',
        )));
        sort($capabilities, SORT_STRING);

        return new UIContext(
            workspace: $workspaceId,
            entity: $entity,
            selectedEntities: $selectedEntities,
            availableActions: $available,
            activeFilters: $activeFilters,
            capabilities: $capabilities,
        );
    }
}
