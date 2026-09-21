<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Model\EntityRef;
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

    /** @return list<SearchResult> */
    public function search(string $query, int $limit = 20): array
    {
        $items = [];
        $limit = max(1, min(50, $limit));

        foreach ($this->providers->search() as $provider) {
            array_push($items, ...$provider->search($this->context, $query, $limit));
        }

        usort(
            $items,
            static fn (SearchResult $left, SearchResult $right): int
                => [-$left->score, $left->kind, $left->label, $left->id]
                <=> [-$right->score, $right->kind, $right->label, $right->id],
        );

        return array_slice($items, 0, $limit);
    }

    /** @return list<UIAction> */
    public function actions(?EntityRef $entity = null): array
    {
        $items = [];

        foreach ($this->providers->actions() as $provider) {
            array_push($items, ...$provider->actions($this->context, $entity));
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
