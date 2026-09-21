<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\CoreCommandCatalog;
use App\Web\Experience\Shell\ShellCommandItem;
use App\Web\Experience\Shell\ShellNavigationItem;

final readonly class ProviderBackedShellNavigation
{
    public function __construct(
        private WebExtensionCatalog $extensions,
        private CoreCommandCatalog $coreCommands,
    ) {
    }

    /**
     * @return array{
     *   primary:list<ShellNavigationItem>,
     *   utility:list<ShellNavigationItem>,
     *   commands:list<ShellCommandItem>
     * }
     */
    public function compose(WebExtensionContext $context): array
    {
        $extensions = $this->extensions->forContext($context);

        $contributions = [
            new NavigationContribution('home', 'Overview', '/admin', 'HM', 10),
            new NavigationContribution('cos', 'COS', '/cos/control-center', 'OS', 50),
            new NavigationContribution('cos-overview', 'Overview', '/cos/control-center', priority: 10, parentKey: 'cos'),
            new NavigationContribution('architecture', 'Architecture', '/cos/architecture', priority: 20, parentKey: 'cos'),
            new NavigationContribution('analytics', 'Analytics', '/admin/analytics', 'AN', 60),
            new NavigationContribution('administration', 'Administration', '/admin/content', 'AD', 70),
            new NavigationContribution('content', 'Content', '/admin/content', priority: 20, parentKey: 'administration'),
        ];

        if ($context->role === 'admin') {
            $contributions[] = new NavigationContribution(
                'users',
                'Users',
                '/admin/users',
                priority: 10,
                parentKey: 'administration',
            );
        }

        array_push($contributions, ...$extensions->navigation());

        $primary = $this->buildTree($contributions, $context);
        $utility = [
            new ShellNavigationItem(
                'cabinet',
                'Cabinet',
                '/cabinet',
                'ME',
                $context->activeItem === 'cabinet',
            ),
        ];

        $commands = [
            ...$this->coreCommands->commands($context),
            ...$extensions->commands(),
        ];

        return [
            'primary' => $primary,
            'utility' => $utility,
            'commands' => $commands,
        ];
    }

    /**
     * @param list<NavigationContribution> $contributions
     * @return list<ShellNavigationItem>
     */
    private function buildTree(array $contributions, WebExtensionContext $context): array
    {
        $roots = [];
        $children = [];

        foreach ($contributions as $contribution) {
            if ($contribution->parentKey === null) {
                $roots[] = $contribution;
                continue;
            }

            $children[$contribution->parentKey][] = $contribution;
        }

        usort(
            $roots,
            static fn (NavigationContribution $left, NavigationContribution $right): int
                => [$left->priority, $left->key] <=> [$right->priority, $right->key],
        );

        $items = [];

        foreach ($roots as $root) {
            $childContributions = $children[$root->key] ?? [];
            usort(
                $childContributions,
                static fn (NavigationContribution $left, NavigationContribution $right): int
                    => [$left->priority, $left->key] <=> [$right->priority, $right->key],
            );

            $childItems = array_map(
                fn (NavigationContribution $child): ShellNavigationItem => new ShellNavigationItem(
                    $child->key,
                    $child->label,
                    $child->path,
                    $child->glyph,
                    $context->activeItem === $child->key,
                ),
                $childContributions,
            );

            $items[] = new ShellNavigationItem(
                $root->key,
                $root->label,
                $root->path,
                $root->glyph,
                $context->activeSection === $root->key || $context->activeItem === $root->key,
                $childItems,
            );
        }

        return $items;
    }
}
