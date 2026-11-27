<?php

declare(strict_types=1);

use App\Web\Experience\Extension\Contract\ActionProviderInterface;
namespace App\Web\Experience\Action;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Model\EntityRef;
use LogicException;

final readonly class UIActionRegistry
{
    public function __construct(
        private WebExtensionCatalog $extensions,
        private RuntimeUIActionProvider $runtimeActions,
        /** @var iterable<ActionProviderInterface> */
        private iterable $platformProviders = [],
    ) {
    }

    /** @return list<UIAction> */
    public function actions(WebExtensionContext $context, ?EntityRef $entity = null): array
    {
        $byId = [];

        $sources = [
            ...$this->extensions->forContext($context)->actions($entity),
            ...$this->runtimeActions->actions($context, $entity),
        ];
        // Platform-wide capabilities are not conditional on a domain module manifest.
        foreach ($this->platformProviders as $provider) {
            array_push($sources, ...$provider->actions($context, $entity));
        }


        foreach ($sources as $action) {
            if (isset($byId[$action->id])) {
                throw new LogicException(sprintf(
                    'Duplicate UIAction id "%s" was contributed for organization %s.',
                    $action->id,
                    $context->organizationId,
                ));
            }

            $byId[$action->id] = $action;
        }

        $actions = array_values($byId);
        usort(
            $actions,
            static fn (UIAction $left, UIAction $right): int
                => [$left->priority, $left->id] <=> [$right->priority, $right->id],
        );

        return $actions;
    }

    public function find(
        string $actionId,
        WebExtensionContext $context,
        ?EntityRef $entity = null,
    ): ?UIAction {
        foreach ($this->actions($context, $entity) as $action) {
            if ($action->id === $actionId) {
                return $action;
            }
        }

        return null;
    }
}
