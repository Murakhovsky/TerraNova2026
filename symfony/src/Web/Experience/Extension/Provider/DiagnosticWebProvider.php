<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Shell\ShellCommandItem;

final class DiagnosticWebProvider implements NavigationProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function serviceId(): string
    {
        return 'diagnosticWebProvider';
    }

    public function navigation(WebExtensionContext $context): array
    {
        return [
            new NavigationContribution(
                'diagnostics',
                'Diagnostics',
                '/admin/diagnostics/methodology-studio',
                priority: 80,
                parentKey: 'cos',
            ),
        ];
    }

    public function commands(WebExtensionContext $context): array
    {
        return [
            new ShellCommandItem(
                'diagnostic.methodology',
                'Open Methodology Studio',
                '/admin/diagnostics/methodology-studio',
                'navigation',
                'Diagnostics',
            ),
        ];
    }

    public function workspaces(WebExtensionContext $context): array
    {
        return [
            new WorkspaceDefinition(
                'diagnostic.methodology',
                'Methodology Studio',
                '/admin/diagnostics/methodology-studio',
                'diagnostic.methodology',
                10,
            ),
        ];
    }
}
