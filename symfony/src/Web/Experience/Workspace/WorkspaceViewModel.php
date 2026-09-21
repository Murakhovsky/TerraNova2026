<?php

declare(strict_types=1);

namespace App\Web\Experience\Workspace;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Extension\Model\WorkspaceExtension;
use App\Web\Experience\Model\EntityRef;

final readonly class WorkspaceViewModel
{
    /**
     * @param list<WorkspaceExtension> $extensions
     * @param list<UIAction> $primaryActions
     * @param list<UIAction> $secondaryActions
     * @param list<UIAction> $mobilePrimaryActions
     * @param list<UIAction> $mobileMenuActions
     */
    public function __construct(
        public WorkspaceDefinition $definition,
        public WebExtensionContext $context,
        public ?EntityRef $entity,
        public array $extensions,
        public array $primaryActions,
        public array $secondaryActions,
        public array $mobilePrimaryActions,
        public array $mobileMenuActions,
    ) {
    }

    /** @return list<WorkspaceExtension> */
    public function extensions(WorkspaceSlot|string $slot): array
    {
        $resolved = is_string($slot) ? WorkspaceSlot::from($slot) : $slot;

        return array_values(array_filter(
            $this->extensions,
            static fn (WorkspaceExtension $extension): bool => $extension->slot === $resolved,
        ));
    }

    public function hasSlot(WorkspaceSlot|string $slot): bool
    {
        return $this->extensions($slot) !== [];
    }

    public function entityKey(): ?string
    {
        return $this->entity?->key();
    }
}
