<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Shell\ShellCommandItem;

final readonly class WebExtensionCatalog
{
    public function __construct(private WebExtensionProviderRegistry $registry)
    {
    }

    /** @return list<NavigationContribution> */
    public function navigation(WebExtensionContext $context): array
    {
        $items = [];

        foreach ($this->registry->forContext($context)->navigation() as $provider) {
            array_push($items, ...$provider->navigation($context));
        }

        usort(
            $items,
            static fn (NavigationContribution $left, NavigationContribution $right): int
                => [$left->parentKey ?? '', $left->priority, $left->key]
                <=> [$right->parentKey ?? '', $right->priority, $right->key],
        );

        return $items;
    }

    /** @return list<ShellCommandItem> */
    public function commands(WebExtensionContext $context): array
    {
        $items = [];

        foreach ($this->registry->forContext($context)->commands() as $provider) {
            array_push($items, ...$provider->commands($context));
        }

        return $items;
    }

    /** @return list<WorkspaceDefinition> */
    public function workspaces(WebExtensionContext $context): array
    {
        $items = [];

        foreach ($this->registry->forContext($context)->workspaces() as $provider) {
            array_push($items, ...$provider->workspaces($context));
        }

        usort(
            $items,
            static fn (WorkspaceDefinition $left, WorkspaceDefinition $right): int
                => [$left->priority, $left->id] <=> [$right->priority, $right->id],
        );

        return $items;
    }
}
