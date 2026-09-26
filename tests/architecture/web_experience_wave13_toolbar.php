<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Component/CosToolbar.php',
    'symfony/templates/components/experience/cos_toolbar.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Canonical Toolbar artifact is missing: ' . $relative);
    }
}

$component = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/CosToolbar.php');
foreach (['AsTwigComponent', "name: 'CosToolbar'", "'compact'"] as $marker) {
    if (!str_contains($component, $marker)) {
        throw new RuntimeException('CosToolbar component contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_toolbar.html.twig');
foreach (['role="toolbar"', 'aria-label="{{ label }}"', 'cos-toolbar__start', 'cos-toolbar__end'] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('CosToolbar template contract is incomplete: ' . $marker);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/business-patterns.css');
foreach (['.cos-toolbar {', '.cos-toolbar--compact', '.cos-toolbar__start', '.cos-toolbar__end'] as $selector) {
    if (!str_contains($styles, $selector)) {
        throw new RuntimeException('CosToolbar canonical style is missing: ' . $selector);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
if (!str_contains($catalog, "CosToolbar")) {
    throw new RuntimeException('CosToolbar must be governed through /dev/ui.');
}

echo "Wave 13 canonical Toolbar pattern passed.\n";
