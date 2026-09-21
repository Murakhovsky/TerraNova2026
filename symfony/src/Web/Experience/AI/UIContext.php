<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Model\EntityRef;

final readonly class UIContext
{
    /**
     * @param list<EntityRef> $selectedEntities
     * @param list<UIContextAction> $availableActions
     * @param array<string,mixed> $activeFilters
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $workspace,
        public ?EntityRef $entity,
        public array $selectedEntities,
        public array $availableActions,
        public array $activeFilters,
        public array $capabilities,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'workspace' => $this->workspace,
            'entity' => $this->entity?->key(),
            'selected_entities' => array_map(
                static fn (EntityRef $entity): string => $entity->key(),
                $this->selectedEntities,
            ),
            'available_actions' => array_map(
                static fn (UIContextAction $action): array => $action->toArray(),
                $this->availableActions,
            ),
            'active_filters' => $this->activeFilters,
            'capabilities' => $this->capabilities,
        ];
    }
}
