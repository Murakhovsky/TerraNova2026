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

$archetypes = (new PageArchetypeRegistry())->all();
$patterns = (new PatternRegistry())->all();

$stableArchetypes = [
    PageArchetype::ExecutiveDashboard->value,
    PageArchetype::DomainDashboard->value,
    PageArchetype::Collection->value,
    PageArchetype::EntityWorkspace->value,
];

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

$actualStableArchetypes = [];
foreach ($archetypes as $id => $definition) {
    if ($definition->stability === VisualStability::Stable) {
        $actualStableArchetypes[] = $id;
    }
    if ($definition->stability === VisualStability::Deprecated) {
        throw new RuntimeException('No Wave 13 archetype may be deprecated during first stability promotion: ' . $id);
    }
}
sort($actualStableArchetypes);
$expectedStableArchetypes = $stableArchetypes;
sort($expectedStableArchetypes);
if ($actualStableArchetypes !== $expectedStableArchetypes) {
    throw new RuntimeException('Stable archetype allowlist drifted: ' . implode(', ', $actualStableArchetypes));
}

$actualStablePatterns = [];
foreach ($patterns as $name => $pattern) {
    if ($pattern->stability === VisualStability::Stable) {
        $actualStablePatterns[] = $name;
    }
    if ($pattern->stability === VisualStability::Deprecated) {
        throw new RuntimeException('No Wave 13 Pattern may be deprecated during first stability promotion: ' . $name);
    }
}
sort($actualStablePatterns);
$expectedStablePatterns = $stablePatterns;
sort($expectedStablePatterns);
if ($actualStablePatterns !== $expectedStablePatterns) {
    throw new RuntimeException('Stable Pattern allowlist drifted: ' . implode(', ', $actualStablePatterns));
}

$stateMatrix = [
    PageArchetype::ExecutiveDashboard->value => ['normal', 'error', 'permission_denied'],
    PageArchetype::DomainDashboard->value => ['normal', 'error'],
    PageArchetype::Collection->value => ['normal', 'empty', 'error'],
    PageArchetype::EntityWorkspace->value => ['normal', 'error'],
];
foreach ($stateMatrix as $id => $states) {
    if ($archetypes[$id]->states !== $states) {
        throw new RuntimeException('Stable archetype exposes unproven top-level states: ' . $id);
    }
}

foreach ($stableArchetypes as $id) {
    $definition = $archetypes[$id];

    foreach ($definition->requiredPatterns as $patternName) {
        if ($patterns[$patternName]->stability !== VisualStability::Stable) {
            throw new RuntimeException($id . ' depends on non-stable required Pattern ' . $patternName);
        }
    }

    foreach ($definition->requiredPatternGroups as $group) {
        $stablePath = array_values(array_filter(
            $group,
            static fn (string $patternName): bool =>
                $patterns[$patternName]->stability === VisualStability::Stable,
        ));
        if ($stablePath === []) {
            throw new RuntimeException($id . ' has no stable path through required group ' . implode(' | ', $group));
        }
    }
}

$collection = $archetypes[PageArchetype::Collection->value];
if (
    !in_array('EntityList', $collection->requiredPatternGroups[0] ?? [], true)
    || !in_array('FilterBar', $collection->requiredPatternGroups[1] ?? [], true)
) {
    throw new RuntimeException('Stable Collection path must retain EntityList + FilterBar.');
}
if ($patterns['DataGrid']->stability !== VisualStability::Experimental
    || $patterns['Toolbar']->stability !== VisualStability::Experimental
) {
    throw new RuntimeException('Unproven Collection alternatives must remain experimental.');
}

$factory = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PagePresentationFactory.php');
foreach (['received undeclared patterns', '$definition->optionalPatterns', 'array_unique($declared)'] as $marker) {
    if (!str_contains($factory, $marker)) {
        throw new RuntimeException('Stable PagePresentation allowlist enforcement is incomplete: ' . $marker);
    }
}

$dealController = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDealController.php');
if (!str_contains($dealController, "'EmptyState'")) {
    throw new RuntimeException('Entity Workspace must declare its rendered EmptyState before stability.');
}

echo "Wave 13 Phase 2.5 selective visual stability contract passed.\n";
