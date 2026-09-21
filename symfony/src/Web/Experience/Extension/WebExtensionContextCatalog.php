<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Shell\ShellCommandItem;

final readonly class WebExtensionContextCatalog
{
    public function __construct(
        public WebExtensionContext $context,
        private WebExtensionProviderSet $providers,
    ) {
    }

    /** @return list<NavigationContribution> */
    public function navigation(): array
    {
        $items = [];

        foreach ($this->providers->navigation() as $provider) {
            array_push($items, ...$provider->navigation($this->context));
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
    public function commands(): array
    {
        $items = [];

        foreach ($this->providers->commands() as $provider) {
            array_push($items, ...$provider->commands($this->context));
        }

        return $items;
    }

    /** @return list<WorkspaceDefinition> */
    public function workspaces(): array
    {
        $items = [];

        foreach ($this->providers->workspaces() as $provider) {
            array_push($items, ...$provider->workspaces($this->context));
        }

        usort(
            $items,
            static fn (WorkspaceDefinition $left, WorkspaceDefinition $right): int
                => [$left->priority, $left->id] <=> [$right->priority, $right->id],
        );

        return $items;
    }

    public function providers(): WebExtensionProviderSet
    {
        return $this->providers;
    }
}
