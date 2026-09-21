<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;

final class DiagnosticWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function __construct(private readonly SearchResultMatcher $matcher)
    {
    }

    public function serviceId(): string
    {
        return 'diagnosticNavigationContributor';
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

    public function search(WebExtensionContext $context, string $query, int $limit = 10): array
    {
        return $this->matcher->match([
            new SearchResult('diagnostic.search.methodology', 'Methodology Studio', '/admin/diagnostics/methodology-studio', 'workspace', 'Diagnostics'),
            new SearchResult('diagnostic.search.overview', 'Diagnostics', '/admin/diagnostics/methodology-studio', 'workspace', 'Business diagnostics'),
        ], $query, $limit);
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
