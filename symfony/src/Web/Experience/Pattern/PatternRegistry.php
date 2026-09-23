<?php

declare(strict_types=1);

namespace App\Web\Experience\Pattern;

use App\Web\Experience\Visual\VisualStability;
use InvalidArgumentException;

final class PatternRegistry
{
    private const OWNER = 'COS Experience Platform';

    /** @var list<string> */
    private const STABLE_PATTERNS = [
        'PageHeader',
        'WorkspaceHeader',
        'EntityHeader',
        'KpiStrip',
        'FilterBar',
        'EntityList',
        'EmptyState',
        'ErrorState',
    ];

    /** @return array<string,PatternDefinition> */
    public function all(): array
    {
        $patterns = [
            $this->pattern('PageHeader', 'Orient the user inside a page and expose page-level actions.', ['title', 'subtitle', 'breadcrumbs', 'actions'], ['leading', 'actions'], ['default', 'dense'], ['default'], ['normal'], ['actions wrap before title hierarchy collapses', 'mobile keeps title and primary action visible'], ['one h1-equivalent per page', 'actions have accessible names'], ['CosPageHeader', 'CosButton', 'CosIcon']),
            $this->pattern('WorkspaceHeader', 'Identify an operational workspace and expose governed workspace actions.', ['title', 'status', 'metadata', 'actions'], ['identity', 'actions'], ['default', 'dense'], ['default'], ['normal', 'loading'], ['mobile moves secondary actions to action sheet'], ['heading hierarchy remains semantic', 'status is not color-only'], ['CosWorkspaceHeader', 'CosStatus', 'CosActionBar']),
            $this->pattern('EntityHeader', 'Present canonical entity identity, status and metadata.', ['title', 'subtitle', 'status', 'metadata', 'actions'], ['identity', 'metadata', 'actions'], ['default'], ['default'], ['normal', 'loading'], ['mobile stacks metadata and actions'], ['entity title is a heading', 'status includes text'], ['CosEntityHeader', 'CosStatus']),
            $this->pattern('KpiStrip', 'Present a compact scan line of business KPIs.', ['metrics'], ['metrics'], ['default', 'attention'], ['default'], ['normal', 'loading', 'empty'], ['desktop uses grid; mobile becomes horizontal-safe stacked/grid composition'], ['values use textual labels', 'trend meaning is not color-only'], ['CosMetric', 'CosTrendMetric', 'CosMoneyMetric']),
            $this->pattern('FilterBar', 'Expose shareable server-owned filters without hiding business state in browser state.', ['filters', 'submitAction', 'resetAction'], ['filters', 'actions'], ['default', 'compact'], ['default'], ['normal', 'loading'], ['mobile stacks controls and preserves touch targets'], ['every control has a label', 'filter state remains keyboard operable'], ['CosFilterBar', 'CosInput', 'CosSelect', 'UIAction']),
            $this->pattern('SearchBar', 'Search a bounded business collection or workspace.', ['query', 'action'], ['input', 'actions'], ['default'], ['default'], ['normal', 'loading', 'empty'], ['mobile uses full available width'], ['search has programmatic label', 'submit is keyboard accessible'], ['CosInput', 'CosIconButton']),
            $this->pattern('Toolbar', 'Compose contextual controls for a page, collection or viewport.', ['actions'], ['start', 'end'], ['default', 'compact'], ['default'], ['normal', 'disabled'], ['wrap or collapse secondary actions before overflow'], ['logical tab order follows visual order'], ['CosActionBar', 'CosButton', 'CosDropdown']),
            $this->pattern('ActionBar', 'Compose canonical primary, secondary and danger UIActions.', ['actions', 'placement'], ['primary', 'secondary', 'danger'], ['start', 'between', 'end', 'sticky'], ['default'], ['normal', 'disabled', 'loading'], ['mobile keeps primary action reachable and collapses secondary actions'], ['danger actions preserve confirmation contract', 'all icon actions have accessible labels'], ['CosActionBar', 'CosButton', 'UIAction']),
            $this->pattern('ContextPanel', 'Present secondary context without competing with the primary task.', ['title', 'sections'], ['header', 'body', 'actions'], ['default'], ['default'], ['normal', 'loading', 'empty'], ['desktop lives in the workspace rail; tablet moves below main; mobile becomes a labelled single-column region'], ['panel heading programmatically labels its region', 'outer workspace context remains labelled'], ['CosContextPanel']),
            $this->pattern('ActivityFeed', 'Present user-friendly chronological business activity.', ['items'], ['items'], ['default'], ['default'], ['normal', 'loading', 'empty', 'error'], ['mobile preserves chronological order and readable metadata'], ['timestamps and actor context are textual'], ['CosActivityFeed', 'CosAvatar']),
            $this->pattern('Timeline', 'Present chronological lifecycle or history events.', ['items'], ['items'], ['default'], ['default'], ['normal', 'loading', 'empty'], ['mobile uses single-column timeline'], ['sequence is meaningful without decorative line'], ['CosTimeline']),
            $this->pattern('SavedViews', 'Select persisted collection state such as filters, sort, columns and density.', ['views', 'activeView'], ['views', 'actions'], ['default'], ['default'], ['normal', 'loading', 'empty'], ['mobile collapses view management into dropdown/drawer'], ['active view is programmatically indicated'], ['CosDropdown', 'CosButton']),
            $this->pattern('DataGrid', 'Operate dense server-owned tabular data on capable viewports.', ['page', 'columns', 'state'], ['toolbar', 'table', 'pagination'], ['default', 'compact'], ['default'], ['normal', 'loading', 'empty', 'error', 'permission_denied'], ['desktop DataGrid; tablet reduced DataGrid; mobile EntityList'], ['headers are semantic', 'selection and sorting are keyboard operable'], ['CosDataGrid', 'Pagination']),
            $this->pattern('EntityList', 'Represent business collections when table density is inappropriate.', ['items'], ['items', 'actions'], ['default', 'cards'], ['default'], ['normal', 'loading', 'empty', 'error'], ['mobile is the preferred collection projection'], ['each entity has an accessible name', 'row actions remain reachable without hover'], ['CosEntityListItem', 'CosEntityCard']),
            $this->pattern('StatGrid', 'Arrange comparable statistics or operational summaries.', ['items'], ['items'], ['default'], ['default'], ['normal', 'loading', 'empty'], ['columns reduce at canonical breakpoints'], ['metric labels accompany values'], ['CosMetric', 'CosCard']),
            $this->pattern('FormSection', 'Group related fields under one semantic form section.', ['title', 'description'], ['fields', 'help'], ['default'], ['default'], ['normal', 'error', 'disabled'], ['mobile uses one-column field flow unless semantics demand otherwise'], ['fieldset/legend or equivalent labelled grouping'], ['CosInput', 'CosSelect', 'CosTextarea', 'CosValidationSummary']),
            $this->pattern('StickyActions', 'Keep save or primary task actions reachable during long forms or mobile flows.', ['actions'], ['actions'], ['default'], ['default'], ['normal', 'disabled', 'loading'], ['mobile sticks above safe area and bottom navigation'], ['does not obscure focused controls', 'respects reduced motion'], ['CosActionBar', 'CosButton']),
            $this->pattern('EmptyState', 'Explain an empty result and provide the next useful action.', ['title', 'copy', 'action'], ['action'], ['default'], ['default'], ['empty'], ['remains compact on mobile'], ['message explains state without relying on illustration'], ['CosEmptyState']),
            $this->pattern('ErrorState', 'Explain recoverable load or operation failure and expose a safe recovery path.', ['title', 'copy', 'retryAction'], ['action'], ['default'], ['default'], ['error'], ['full-width within owning region'], ['error is announced appropriately', 'raw exceptions are never shown'], ['CosAlert', 'CosButton']),
            $this->pattern('PermissionState', 'Explain unavailable content or actions caused by authorization.', ['title', 'copy'], ['action'], ['default'], ['default'], ['permission_denied'], ['preserves page context without fake disabled content'], ['authorization reason is textual where safe'], ['CosAlert']),
            $this->pattern('AIRecommendations', 'Render structured agent recommendations as governed proposals rather than arbitrary HTML.', ['recommendations', 'actions', 'evidence'], ['summary', 'items', 'actions'], ['default'], ['default'], ['normal', 'loading', 'empty', 'error'], ['mobile stacks recommendation evidence and actions'], ['AI provenance is visible', 'proposed actions retain human confirmation rules'], ['CosAgentRecommendation', 'CosAgentEvidence', 'CosAgentAction']),
            $this->pattern('Pagination', 'Navigate server-owned collection pages while preserving URL state.', ['page', 'totalPages'], ['items'], ['default', 'compact'], ['default'], ['normal', 'disabled'], ['mobile reduces visible page links without hiding next/previous'], ['current page is announced', 'controls have accessible labels'], ['CosButton']),
        ];

        $indexed = [];
        foreach ($patterns as $pattern) {
            $indexed[$pattern->name] = $pattern;
        }

        return $indexed;
    }

    public function get(string $name): PatternDefinition
    {
        $pattern = $this->all()[$name] ?? null;

        if ($pattern === null) {
            throw new InvalidArgumentException('Unknown COS visual pattern: ' . $name);
        }

        return $pattern;
    }

    /**
     * @param list<string> $props
     * @param list<string> $slots
     * @param list<string> $variants
     * @param list<string> $sizes
     * @param list<string> $states
     * @param list<string> $responsiveBehavior
     * @param list<string> $accessibilityRules
     * @param list<string> $dependencies
     */
    private function pattern(
        string $name,
        string $purpose,
        array $props,
        array $slots,
        array $variants,
        array $sizes,
        array $states,
        array $responsiveBehavior,
        array $accessibilityRules,
        array $dependencies,
    ): PatternDefinition {
        return new PatternDefinition(
            name: $name,
            purpose: $purpose,
            props: $props,
            slots: $slots,
            variants: $variants,
            sizes: $sizes,
            states: $states,
            responsiveBehavior: $responsiveBehavior,
            accessibilityRules: $accessibilityRules,
            dependencies: $dependencies,
            owner: self::OWNER,
            stability: in_array($name, self::STABLE_PATTERNS, true)
                ? VisualStability::Stable
                : VisualStability::Experimental,
        );
    }
}
