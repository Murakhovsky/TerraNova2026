<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/symfony/templates/experience/spatial/edit.html.twig');
$repository = (string) file_get_contents($root . '/app/Domains/Spatial/Infrastructure/Persistence/MySql/MysqlSpatialSceneRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($template, 'name="version_label"'),
    'Spatial capture form must submit the canonical version_label field.',
);
$assert(
    str_contains($template, 'data-turbo="false"'),
    'Spatial capture SSR mutation must bypass Turbo form interception.',
);
$assert(
    str_contains($template, 'data-spatial-capture-form'),
    'Spatial capture form must expose the deterministic browser-submit hook.',
);
$assert(
    str_contains($template, 'data-spatial-save-form')
        && str_contains($template, 'data-spatial-external-form')
        && str_contains($template, 'data-spatial-hotspot-form')
        && str_contains($template, 'data-spatial-publish-form'),
    'Spatial SSR mutation forms must have explicit stable form hooks.',
);
$assert(
    substr_count($template, 'data-turbo="false"') >= 5,
    'Spatial SSR mutations must opt out of Turbo replacement races.',
);

$assert(
    str_contains($template, "importmap(['app', 'spatial_admin'])")
        && !str_contains($template, '{{ parent() }}'),
    'Spatial editor must render app + spatial_admin through one canonical importmap call.',
);

$spatialAdmin = (string) file_get_contents($root . '/symfony/assets/spatial_admin.js');
$assert(
    str_contains($spatialAdmin, "document.querySelectorAll('[data-spatial-capture-form]')")
        && str_contains($spatialAdmin, 'new URLSearchParams()')
        && str_contains($spatialAdmin, 'new FormData(form)'),
    'Spatial capture browser mutation must snapshot current form values before POST.',
);
$assert(
    str_contains($repository, "\$input['version_label'] ?? \$input['label'] ?? ''"),
    'Spatial capture persistence must accept canonical version_label with legacy label fallback.',
);
$assert(
    str_contains($repository, 'v.label AS version_label'),
    'Spatial capture read projection must expose the version label.',
);

echo "Spatial capture HTTP contract passed.\n";
