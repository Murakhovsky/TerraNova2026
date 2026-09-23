<?php

declare(strict_types=1);

namespace App\Web\Experience\Archetype;

use App\Web\Experience\Visual\VisualStability;
use InvalidArgumentException;

final class PageArchetypeRegistry
{
    /** @var list<string> */
    private const STANDARD_STATES = [
        'normal',
        'loading',
        'empty',
        'error',
        'permission_denied',
        'stale',
        'offline',
        'reconnecting',
    ];

    /** @var list<string> */
    private const STABLE_ARCHETYPE_IDS = [
        'executive_dashboard',
        'domain_dashboard',
        'collection',
        'entity_workspace',
    ];

    /** @var array<string,list<string>> */
    private const STABLE_STATE_MATRIX = [
        'executive_dashboard' => ['normal', 'error', 'permission_denied'],
        'domain_dashboard' => ['normal', 'error'],
        'collection' => ['normal', 'empty', 'error'],
        'entity_workspace' => ['normal', 'error'],
    ];

    /** @return array<string,PageArchetypeDefinition> */
    public function all(): array
    {
        $workspaceResponsive = [
            'desktop: shell + main + optional context panel',
            'tablet: collapsible shell + main + context drawer',
            'mobile: header + main + tabs + sticky actions + bottom navigation',
        ];

        $entityWorkspaceResponsive = [
            'desktop: workspace header + main + context rail',
            'tablet: shell collapses; main becomes one column; context rail moves below main',
            'mobile: stacked workspace header + single-column context rail + mobile workspace actions',
        ];

        $publicResponsive = [
            'desktop: bounded content grid',
            'tablet: reduced columns and preserved hierarchy',
            'mobile: single-column task-first composition',
        ];

        $definitions = [
            $this->definition(
                PageArchetype::ExecutiveDashboard,
                'workspace',
                'Answer what is happening across the business.',
                ['PageHeader', 'KpiStrip'],
                ['EntityList', 'EmptyState', 'ErrorState'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::DomainDashboard,
                'workspace',
                'Answer what is happening inside one business Domain.',
                ['PageHeader', 'KpiStrip'],
                ['EntityList', 'EmptyState', 'ErrorState'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::OperationalQueue,
                'workspace',
                'Surface work that needs attention now.',
                ['PageHeader', 'EntityList'],
                ['FilterBar', 'Toolbar', 'ActionBar', 'Pagination'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::Collection,
                'workspace',
                'Explore, filter and act on a collection of business entities.',
                ['PageHeader'],
                ['SearchBar', 'Toolbar', 'FilterBar', 'SavedViews', 'ActionBar', 'DataGrid', 'EntityList', 'Pagination', 'EmptyState', 'ErrorState'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
                [
                    ['DataGrid', 'EntityList'],
                    ['Toolbar', 'FilterBar'],
                ],
            ),
            $this->definition(
                PageArchetype::EntityWorkspace,
                'workspace',
                'Operate on one business entity with context, history and governed actions.',
                ['WorkspaceHeader', 'EntityHeader'],
                ['KpiStrip', 'ContextPanel', 'Timeline', 'ActionBar', 'EmptyState', 'ErrorState'],
                ['comfortable', 'compact'],
                $entityWorkspaceResponsive,
            ),
            $this->definition(
                PageArchetype::ProcessPipeline,
                'workspace',
                'Operate a staged business workflow with metrics and governed transitions.',
                ['PageHeader', 'Toolbar'],
                ['FilterBar', 'KpiStrip', 'EntityList', 'ActionBar'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::FormEditor,
                'workspace',
                'Create or edit structured business data with explicit validation and save state.',
                ['PageHeader', 'FormSection', 'StickyActions'],
                ['ErrorState', 'PermissionState'],
                ['comfortable'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::MapSpatial,
                'workspace',
                'Operate spatial data while preserving selection and entity context.',
                ['Toolbar', 'ContextPanel'],
                ['FilterBar', 'ActionBar', 'EntityList'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::SystemControlSurface,
                'system',
                'Inspect runtime state, diagnostics, architecture and controlled system actions.',
                ['PageHeader', 'Toolbar'],
                ['KpiStrip', 'ActivityFeed', 'Timeline', 'ContextPanel', 'ActionBar'],
                ['comfortable', 'compact'],
                $workspaceResponsive,
            ),
            $this->definition(
                PageArchetype::Portal,
                'portal',
                'Communicate status, progress and the next task with lower information density.',
                ['PageHeader'],
                ['KpiStrip', 'EntityList', 'Timeline', 'StickyActions'],
                ['comfortable'],
                $publicResponsive,
            ),
            $this->definition(
                PageArchetype::PublicCatalog,
                'public',
                'Discover public entities through search, filters and browseable collections.',
                ['PageHeader', 'EntityList'],
                ['SearchBar', 'FilterBar', 'Pagination', 'EmptyState'],
                ['comfortable'],
                $publicResponsive,
            ),
            $this->definition(
                PageArchetype::PublicDetailMarketing,
                'public',
                'Present one public entity, service or narrative with a clear next action.',
                ['PageHeader'],
                ['StatGrid', 'ActionBar'],
                ['comfortable'],
                $publicResponsive,
            ),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->id->value] = $definition;
        }

        return $indexed;
    }

    public function get(PageArchetype|string $archetype): PageArchetypeDefinition
    {
        $id = $archetype instanceof PageArchetype ? $archetype->value : $archetype;
        $definition = $this->all()[$id] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException('Unknown COS page archetype: ' . $id);
        }

        return $definition;
    }

    /**
     * @param list<string> $requiredPatterns
     * @param list<string> $optionalPatterns
     * @param list<string> $densities
     * @param list<string> $responsiveContract
     * @param list<list<string>> $requiredPatternGroups
     */
    private function definition(
        PageArchetype $id,
        string $surface,
        string $purpose,
        array $requiredPatterns,
        array $optionalPatterns,
        array $densities,
        array $responsiveContract,
        array $requiredPatternGroups = [],
    ): PageArchetypeDefinition {
        return new PageArchetypeDefinition(
            id: $id,
            surface: $surface,
            purpose: $purpose,
            requiredPatterns: $requiredPatterns,
            optionalPatterns: $optionalPatterns,
            requiredPatternGroups: $requiredPatternGroups,
            states: self::STABLE_STATE_MATRIX[$id->value] ?? self::STANDARD_STATES,
            densities: $densities,
            responsiveContract: $responsiveContract,
            stability: in_array($id->value, self::STABLE_ARCHETYPE_IDS, true)
                ? VisualStability::Stable
                : VisualStability::Experimental,
        );
    }
}
