<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Component/CosFormSection.php',
    'symfony/templates/components/experience/cos_form_section.html.twig',
    'symfony/src/Web/Experience/Component/CosStickyActions.php',
    'symfony/templates/components/experience/cos_sticky_actions.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-024 canonical form pattern missing: ' . $relative);
    }
}

$form = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_form_section.html.twig');
foreach (['<fieldset', '<legend', 'role="alert"', 'cos-form-section__fields'] as $marker) {
    if (!str_contains($form, $marker)) {
        throw new RuntimeException('CosFormSection semantic contract incomplete: ' . $marker);
    }
}

$sticky = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_sticky_actions.html.twig');
foreach (['role="group"', 'aria-label="{{ label }}"', 'aria-busy="true"', 'cos-sticky-actions__inner'] as $marker) {
    if (!str_contains($sticky, $marker)) {
        throw new RuntimeException('CosStickyActions accessibility contract incomplete: ' . $marker);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/business-patterns.css');
foreach ([
    '.cos-form-section {',
    '.cos-form-section__fields',
    '.cos-form-section__wide',
    '.cos-sticky-actions {',
    'env(safe-area-inset-bottom)',
    '@media (max-width: 650px)',
] as $marker) {
    if (!str_contains($styles, $marker)) {
        throw new RuntimeException('Canonical form pattern styling incomplete: ' . $marker);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
foreach (['CosFormSection', 'CosStickyActions'] as $component) {
    if (!str_contains($catalog, $component)) {
        throw new RuntimeException('Canonical form pattern is missing from /dev/ui: ' . $component);
    }
}

echo "Wave 13 canonical FormSection and StickyActions patterns passed.\n";
