<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$patterns = (string) file_get_contents($root . '/symfony/src/Web/Experience/Pattern/PatternRegistry.php');
$archetypes = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');

foreach ([
    "'PageHeader'",
    "'WorkspaceHeader'",
    "'EntityHeader'",
    "'KpiStrip'",
    "'FilterBar'",
    "'SearchBar'",
    "'Toolbar'",
    "'ActionBar'",
    "'ContextPanel'",
    "'Timeline'",
    "'EntityList'",
    "'EmptyState'",
    "'ErrorState'",
] as $marker) {
    $start = strpos($patterns, 'private const STABLE_PATTERNS');
    $end = strpos($patterns, '];', $start);
    $stable = $start === false || $end === false ? '' : substr($patterns, $start, $end - $start);
    if (!str_contains($stable, $marker)) {
        throw new RuntimeException('Golden-required pattern is not stable V1: ' . $marker);
    }
}

foreach ([
    "'executive_dashboard'",
    "'domain_dashboard'",
    "'operational_queue'",
    "'collection'",
    "'entity_workspace'",
    "'process_pipeline'",
    "'map_spatial'",
] as $marker) {
    $start = strpos($archetypes, 'private const STABLE_ARCHETYPE_IDS');
    $end = strpos($archetypes, '];', $start);
    $stable = $start === false || $end === false ? '' : substr($archetypes, $start, $end - $start);
    if (!str_contains($stable, $marker)) {
        throw new RuntimeException('Golden-required archetype is not stable V1: ' . $marker);
    }
}

echo "Golden-required Pattern/Archetype API V1 freeze OK\n";
