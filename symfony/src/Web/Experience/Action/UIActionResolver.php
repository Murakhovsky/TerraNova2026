<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;
use LogicException;

final readonly class UIActionResolver
{
    public function __construct(
        private UIActionRegistry $registry,
        private UIActionPermissionResolver $permissions,
    ) {
    }

    /** @return list<UIAction> */
    public function resolve(
        TenantContext $tenant,
        WebExtensionContext $context,
        ?EntityRef $entity = null,
        ?string $placement = null,
    ): array {
        if ($tenant->organizationId()->value() !== $context->organizationId) {
            throw new LogicException('UIAction resolution context does not belong to the authenticated organization.');
        }

        if ($tenant->role()->value() !== $context->role) {
            throw new LogicException('UIAction resolution role does not match the authenticated tenant context.');
        }

        if ($placement !== null) {
            UIActionPlacement::assert($placement);
        }

        $resolved = [];

        foreach ($this->registry->actions($context, $entity) as $action) {
            if ($placement !== null && !$action->supportsPlacement($placement)) {
                continue;
            }

            if (!$action->enabled) {
                $resolved[] = $action;
                continue;
            }

            $decision = $this->permissions->decide($action->permission, $tenant, $entity);
            $resolved[] = $decision->allowed
                ? $action
                : $action->withAvailability(false, $decision->reason);
        }

        return $resolved;
    }

    public function resolveOne(
        string $actionId,
        TenantContext $tenant,
        WebExtensionContext $context,
        ?EntityRef $entity = null,
        ?string $placement = null,
    ): UIAction {
        foreach ($this->resolve($tenant, $context, $entity, $placement) as $action) {
            if ($action->id === $actionId) {
                return $action;
            }
        }

        throw new InvalidArgumentException('Unknown UIAction: ' . $actionId);
    }
}
