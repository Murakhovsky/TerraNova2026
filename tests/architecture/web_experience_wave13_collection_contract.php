<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/symfony/src/Web/Experience/Visual/VisualStability.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetype.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetypeDefinition.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php';

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PageArchetypeRegistry;

$collection = (new PageArchetypeRegistry())->get(PageArchetype::Collection);

if ($collection->requiredPatterns !== ['PageHeader']) {
    throw new RuntimeException('Collection must keep only universally required PageHeader as a fixed pattern.');
}

if ($collection->requiredPatternGroups !== [
    ['DataGrid', 'EntityList'],
    ['Toolbar', 'FilterBar'],
]) {
    throw new RuntimeException('Collection required pattern alternatives drifted.');
}

$factory = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PagePresentationFactory.php');
foreach (['requiredPatternGroups', 'requires one pattern from group', 'array_intersect'] as $marker) {
    if (!str_contains($factory, $marker)) {
        throw new RuntimeException('PagePresentationFactory does not enforce alternative pattern groups: ' . $marker);
    }
}

echo "Wave 13 Collection alternative pattern contract passed.\n";
