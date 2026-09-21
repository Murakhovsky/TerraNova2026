<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Extension\WebExtensionProviderRegistry;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:web:extensions:smoke',
    description: 'Validate tenant-aware COS Web extension providers.',
)]
final class WebExtensionsSmokeCommand extends Command
{
    public function __construct(
        private readonly WebExtensionProviderRegistry $registry,
        private readonly WebExtensionCatalog $catalog,
        private readonly ProviderBackedShellNavigation $shellNavigation,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = new WebExtensionContext(
            organizationId: 'default',
            role: 'admin',
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'pipeline',
        );

        $providers = $this->registry->forContext($context);

        foreach ([
            'navigation' => $providers->navigation(),
            'search' => $providers->search(),
            'commands' => $providers->commands(),
            'workspaces' => $providers->workspaces(),
        ] as $surface => $resolved) {
            $ids = array_map(static fn($provider): string => $provider->serviceId(), $resolved);
            sort($ids, SORT_STRING);

            $expected = [
                'diagnosticNavigationContributor',
                'propertyNavigationContributor',
                'salesNavigationContributor',
            ];

            if ($ids !== $expected) {
                $output->writeln(sprintf(
                    '<error>Unexpected active %s providers: %s</error>',
                    $surface,
                    implode(', ', $ids),
                ));

                return Command::FAILURE;
            }
        }

        $actionProviderIds = array_map(
            static fn($provider): string => $provider->serviceId(),
            $providers->actions(),
        );
        if ($actionProviderIds !== ['salesNavigationContributor']) {
            $output->writeln(sprintf(
                '<error>Unexpected active action providers: %s</error>',
                implode(', ', $actionProviderIds),
            ));

            return Command::FAILURE;
        }

        foreach ([
            'workspace extensions' => $providers->workspaceExtensions(),
            'dashboard widgets' => $providers->dashboardWidgets(),
            'entity links' => $providers->entityLinks(),
            'notifications' => $providers->notifications(),
            'activity' => $providers->activity(),
        ] as $surface => $resolved) {
            if ($resolved !== []) {
                $output->writeln(sprintf('<error>Unexpected providers for undeclared surface: %s</error>', $surface));

                return Command::FAILURE;
            }
        }

        $contextCatalog = $this->catalog->forContext($context);
        $workspaceIds = array_map(
            static fn($workspace): string => $workspace->id,
            $contextCatalog->workspaces(),
        );

        foreach ([
            'sales.lead',
            'sales.deal',
            'property.asset',
            'property.spatial',
            'diagnostic.methodology',
        ] as $workspaceId) {
            if (!in_array($workspaceId, $workspaceIds, true)) {
                $output->writeln(sprintf('<error>Missing module Workspace contribution: %s</error>', $workspaceId));

                return Command::FAILURE;
            }
        }

        $shell = $this->shellNavigation->compose($context);
        $rootKeys = array_map(static fn($item): string => $item->key, $shell['primary']);

        foreach (['home', 'sales', 'clients', 'properties', 'cos', 'analytics', 'administration'] as $key) {
            if (!in_array($key, $rootKeys, true)) {
                $output->writeln(sprintf('<error>Provider-backed Shell navigation is missing: %s</error>', $key));

                return Command::FAILURE;
            }
        }

        $cos = null;
        foreach ($shell['primary'] as $item) {
            if ($item->key === 'cos') {
                $cos = $item;
                break;
            }
        }

        $cosChildren = array_map(
            static fn($item): string => $item->key,
            $cos?->children ?? [],
        );

        if (!in_array('diagnostics', $cosChildren, true)) {
            $output->writeln('<error>Diagnostics did not extend the COS navigation section.</error>');

            return Command::FAILURE;
        }

        $commandIds = array_map(static fn($command): string => $command->id, $shell['commands']);
        foreach (['sales.open', 'property.open', 'diagnostic.methodology'] as $commandId) {
            if (!in_array($commandId, $commandIds, true)) {
                $output->writeln(sprintf('<error>Provider-backed command is missing: %s</error>', $commandId));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Web extension provider runtime passed.');

        return Command::SUCCESS;
    }
}
