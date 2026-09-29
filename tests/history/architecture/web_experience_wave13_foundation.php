<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/symfony/src/Web/Experience/Visual/VisualStability.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetype.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetypeDefinition.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php';
require_once $root . '/symfony/src/Web/Experience/Pattern/PatternDefinition.php';
require_once $root . '/symfony/src/Web/Experience/Pattern/PatternRegistry.php';

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PageArchetypeRegistry;
use App\Web\Experience\Pattern\PatternRegistry;
use App\Web\Experience\Visual\VisualStability;

$archetypes = new PageArchetypeRegistry();
$definitions = $archetypes->all();

if (count(PageArchetype::cases()) !== 12 || count($definitions) !== 12) {
    throw new RuntimeException('Wave 13 must expose exactly 12 canonical Page Archetypes.');
}

$standardStates = [
    'normal',
    'loading',
    'empty',
    'error',
    'permission_denied',
    'stale',
    'offline',
    'reconnecting',
];

$stableArchetypes = [
    PageArchetype::ExecutiveDashboard->value,
    PageArchetype::DomainDashboard->value,
    PageArchetype::Collection->value,
    PageArchetype::EntityWorkspace->value,
];
$stableStateMatrix = [
    PageArchetype::ExecutiveDashboard->value => ['normal', 'error', 'permission_denied'],
    PageArchetype::DomainDashboard->value => ['normal', 'error'],
    PageArchetype::Collection->value => ['normal', 'empty', 'error'],
    PageArchetype::EntityWorkspace->value => ['normal', 'error'],
];

foreach ($definitions as $definition) {
    $expectedStability = in_array($definition->id->value, $stableArchetypes, true)
        ? VisualStability::Stable
        : VisualStability::Experimental;

    if ($definition->stability !== $expectedStability) {
        throw new RuntimeException('Archetype stability drift: ' . $definition->id->value);
    }

    $expectedStates = $stableStateMatrix[$definition->id->value] ?? $standardStates;
    if ($definition->states !== $expectedStates) {
        throw new RuntimeException('Archetype state matrix drift: ' . $definition->id->value);
    }

    if ($definition->requiredPatterns === [] || $definition->responsiveContract === []) {
        throw new RuntimeException('Archetype composition contract is incomplete: ' . $definition->id->value);
    }

    foreach ($definition->requiredPatternGroups as $group) {
        if ($group === []) {
            throw new RuntimeException('Archetype required pattern group may not be empty: ' . $definition->id->value);
        }
    }
}

$patterns = new PatternRegistry();
$registeredPatterns = $patterns->all();

$expectedPatterns = [
    'PageHeader',
    'WorkspaceHeader',
    'EntityHeader',
    'KpiStrip',
    'FilterBar',
    'SearchBar',
    'Toolbar',
    'ActionBar',
    'ContextPanel',
    'ActivityFeed',
    'Timeline',
    'SavedViews',
    'DataGrid',
    'EntityList',
    'StatGrid',
    'FormSection',
    'StickyActions',
    'EmptyState',
    'ErrorState',
    'PermissionState',
    'AIRecommendations',
    'Pagination',
];

foreach ($expectedPatterns as $patternName) {
    if (!isset($registeredPatterns[$patternName])) {
        throw new RuntimeException('Canonical Pattern is missing: ' . $patternName);
    }
}

$stablePatterns = [
    'PageHeader',
    'WorkspaceHeader',
    'EntityHeader',
    'KpiStrip',
    'FilterBar',
    'EntityList',
    'EmptyState',
    'ErrorState',
];

foreach ($registeredPatterns as $pattern) {
    $expectedStability = in_array($pattern->name, $stablePatterns, true)
        ? VisualStability::Stable
        : VisualStability::Experimental;

    if ($pattern->stability !== $expectedStability) {
        throw new RuntimeException('Pattern stability drift: ' . $pattern->name);
    }

    if (
        $pattern->purpose === ''
        || $pattern->props === []
        || $pattern->variants === []
        || $pattern->sizes === []
        || $pattern->states === []
        || $pattern->responsiveBehavior === []
        || $pattern->accessibilityRules === []
        || $pattern->dependencies === []
        || $pattern->owner === ''
    ) {
        throw new RuntimeException('Pattern contract is incomplete: ' . $pattern->name);
    }
}

foreach ($definitions as $definition) {
    $referenced = array_merge($definition->requiredPatterns, $definition->optionalPatterns);
    foreach ($definition->requiredPatternGroups as $group) {
        array_push($referenced, ...$group);
    }

    foreach (array_unique($referenced) as $patternName) {
        if (!isset($registeredPatterns[$patternName])) {
            throw new RuntimeException(sprintf(
                'Archetype %s references an unregistered Pattern: %s',
                $definition->id->value,
                $patternName,
            ));
        }
    }
}

foreach ($definitions as $definition) {
    if ($definition->stability !== VisualStability::Stable) {
        continue;
    }

    foreach ($definition->requiredPatterns as $requiredPattern) {
        if ($registeredPatterns[$requiredPattern]->stability !== VisualStability::Stable) {
            throw new RuntimeException(sprintf(
                'Stable archetype %s depends on experimental required Pattern %s.',
                $definition->id->value,
                $requiredPattern,
            ));
        }
    }

    foreach ($definition->requiredPatternGroups as $group) {
        $stablePath = array_filter(
            $group,
            static fn (string $patternName): bool =>
                $registeredPatterns[$patternName]->stability === VisualStability::Stable,
        );
        if ($stablePath === []) {
            throw new RuntimeException(sprintf(
                'Stable archetype %s has no stable required Pattern path for group %s.',
                $definition->id->value,
                implode(' | ', $group),
            ));
        }
    }
}

$collection = $definitions[PageArchetype::Collection->value];
if ($collection->requiredPatternGroups !== [
    ['DataGrid', 'EntityList'],
    ['Toolbar', 'FilterBar'],
]) {
    throw new RuntimeException('Collection archetype must allow canonical projection and control-surface alternatives.');
}

foreach ([
    'symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php',
    'symfony/src/Web/Experience/Pattern/PatternRegistry.php',
] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);

    foreach (['Domains\\', 'Doctrine\\', 'Repository', '/api/'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('Visual registry leaked Domain/data dependency: ' . $relative . ' -> ' . $forbidden);
        }
    }
}

$tokens = (string) file_get_contents($root . '/symfony/assets/styles/tokens.css');
foreach ([
    '--cos-layout-page-max:',
    '--cos-layout-content-max:',
    '--cos-layout-reading-max:',
    '--cos-layout-sidebar-width:',
    '--cos-layout-context-panel-width:',
    '--cos-layout-gutter:',
    '--cos-layout-section-gap:',
    '--cos-layout-page-padding:',
    '--cos-shell-topbar-height:',
    '--cos-shell-mobile-nav-height:',
] as $token) {
    if (!str_contains($tokens, $token)) {
        throw new RuntimeException('Wave 13 layout token is missing: ' . $token);
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
$surfaces = strpos($appCss, "@import './surfaces.css';");
$layout = strpos($appCss, "@import './layout.css';");
$primitives = strpos($appCss, "@import './primitives.css';");

if ($surfaces === false || $layout === false || $primitives === false || !($surfaces < $layout && $layout < $primitives)) {
    throw new RuntimeException('layout.css must load after surfaces.css and before primitive/component styles.');
}

$layoutCss = (string) file_get_contents($root . '/symfony/assets/styles/layout.css');
foreach ([
    '.cos-page',
    '.cos-page--content',
    '.cos-page--reading',
    '.cos-layout-main-context',
    '.cos-section-grid',
    '@media (max-width: 1050px)',
    '@media (max-width: 650px)',
    '@media (max-width: 390px)',
] as $contract) {
    if (!str_contains($layoutCss, $contract)) {
        throw new RuntimeException('Canonical layout contract is incomplete: ' . $contract);
    }
}

$canonicalVisualRoots = [
    $root . '/symfony/assets/styles',
    $root . '/symfony/templates',
];

$inlineStyles = 0;

foreach ($canonicalVisualRoots as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $extension = strtolower($file->getExtension());
        if (!in_array($extension, ['css', 'twig'], true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace($root . '/', '', $file->getPathname());

        if (
            str_contains($source, '--tn-')
            || preg_match('/\.tn-[a-z0-9_-]*/i', $source) === 1
            || str_contains($source, 'tn-ui-')
        ) {
            throw new RuntimeException('Legacy TN visual convention leaked into canonical Symfony visual code: ' . $relative);
        }

        if ($extension === 'twig') {
            if (preg_match('/\bon[a-z]+\s*=/i', $source) === 1) {
                throw new RuntimeException('Inline browser event handler is forbidden in canonical Twig: ' . $relative);
            }

            $inlineStyles += preg_match_all('/\bstyle\s*=/i', $source);
        }
    }
}

if ($inlineStyles > 12) {
    throw new RuntimeException(sprintf(
        'Wave 13 inline-style baseline exceeded: %d > 12. New inline visual styles are forbidden.',
        $inlineStyles,
    ));
}

$adr = (string) file_get_contents($root . '/docs/11-decisions/ADR-0012-wave13-visual-migration.md');
foreach (['status: accepted', 'PageArchetypeRegistry', 'PatternRegistry', 'experimental', 'Golden Four'] as $marker) {
    if (!str_contains($adr, $marker)) {
        throw new RuntimeException('Wave 13 ADR is incomplete: ' . $marker);
    }
}

echo sprintf(
    "Wave 13.0 visual-system foundation passed: %d archetypes, %d patterns, %d inline-style debt occurrences.\n",
    count($definitions),
    count($registeredPatterns),
    $inlineStyles,
);
