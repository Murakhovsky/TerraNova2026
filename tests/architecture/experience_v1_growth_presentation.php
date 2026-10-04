<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/symfony/src/Web/Growth/GrowthPageController.php');
$template = (string) file_get_contents($root . '/symfony/templates/experience/growth/workspace.html.twig');

foreach ([
    'PagePresentationFactory',
    'PageArchetype::DomainDashboard',
    'private function presentation',
    "if ($view !== 'growth/dashboard')",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Growth Experience presentation contract missing: ' . $marker);
    }
}

foreach ([
    'data-cos-archetype="{{ page.archetypeId() }}"',
    'data-cos-page-state="{{ page.state }}"',
    'data-cos-page-density="{{ page.density }}"',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Growth template missing canonical runtime marker: ' . $marker);
    }
}

echo "Growth canonical PagePresentation contract OK\n";
