<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/symfony/src/Web/Experience/Appearance/AppearanceTheme.php';
require_once $root . '/symfony/src/Web/Experience/Appearance/AppearanceDensity.php';

if (\App\Web\Experience\Appearance\AppearanceTheme::Light->value !== 'light') {
    throw new RuntimeException('Canonical light theme contract drifted.');
}

if (\App\Web\Experience\Appearance\AppearanceTheme::Dark->value !== 'dark') {
    throw new RuntimeException('Canonical dark theme contract drifted.');
}

if (\App\Web\Experience\Appearance\AppearanceDensity::Comfortable->value !== 'comfortable') {
    throw new RuntimeException('Canonical comfortable density contract drifted.');
}

if (\App\Web\Experience\Appearance\AppearanceDensity::Compact->value !== 'compact') {
    throw new RuntimeException('Canonical compact density contract drifted.');
}

$tokensPath = $root . '/symfony/assets/styles/tokens.css';
$tokens = (string) file_get_contents($tokensPath);

foreach ([
    '--cos-color-canvas',
    '--cos-color-surface',
    '--cos-color-text',
    '--cos-color-text-muted',
    '--cos-color-primary',
    '--cos-color-positive',
    '--cos-color-warning',
    '--cos-color-danger',
    '--cos-color-info',
    '--cos-color-on-primary',
    '--cos-control-height',
    '--cos-density-panel-padding',
] as $token) {
    if (!str_contains($tokens, $token)) {
        throw new RuntimeException('Design System semantic token is missing: ' . $token);
    }
}

foreach ([
    'html[data-cos-theme="dark"]',
    'html[data-cos-density="compact"]',
    'html[data-cos-density="comfortable"]',
] as $selector) {
    if (!str_contains($tokens, $selector)) {
        throw new RuntimeException('Design System appearance selector is missing: ' . $selector);
    }
}

$styleDir = $root . '/symfony/assets/styles';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($styleDir));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'css') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());

    if (str_contains($source, '--tn-')) {
        throw new RuntimeException('Legacy --tn-* token leaked into Symfony Design System: ' . $file->getFilename());
    }

    if ($file->getFilename() !== 'tokens.css' && preg_match('/#[0-9a-fA-F]{3,8}\b/', $source) === 1) {
        throw new RuntimeException('Raw hex color leaked outside semantic tokens: ' . $file->getFilename());
    }
}

$base = (string) file_get_contents($root . '/symfony/templates/base.html.twig');
foreach ([
    'data-cos-theme="light"',
    'data-cos-density="comfortable"',
    '{% block meta %}',
] as $marker) {
    if (!str_contains($base, $marker)) {
        throw new RuntimeException('Canonical base layout Design System contract is missing: ' . $marker);
    }
}

$appearance = (string) file_get_contents($root . '/symfony/assets/controllers/appearance_controller.js');
foreach (['localStorage', 'sessionStorage', 'fetch(', '/api/'] as $forbidden) {
    if (str_contains($appearance, $forbidden)) {
        throw new RuntimeException('Appearance controller contains forbidden persistence/data access: ' . $forbidden);
    }
}

foreach ([
    'CosButton',
    'CosBadge',
    'CosCard',
    'CosMetric',
    'CosEmptyState',
] as $component) {
    $class = $root . '/symfony/src/Web/Experience/Component/' . $component . '.php';
    if (!is_file($class)) {
        throw new RuntimeException('Design System Twig Component is missing: ' . $component);
    }

    $source = (string) file_get_contents($class);
    foreach (['Domains\\', 'Doctrine\\', '/api/'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Design System component %s contains forbidden business/data dependency: %s',
                $component,
                $forbidden,
            ));
        }
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
if (!str_contains($routes, 'cos_web_design_system_catalog:') || !str_contains($routes, 'path: /dev/ui')) {
    throw new RuntimeException('Canonical /dev/ui catalog route is missing.');
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
if (!str_contains($security, "path: '^/dev(?:/|$)'") || !str_contains($security, 'roles: ROLE_MANAGER')) {
    throw new RuntimeException('Design System catalog must remain manager-only.');
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'Semantic color tokens',
    'Typography and rhythm',
    'Buttons',
    'Status language',
    'Cards and metrics',
    'Form primitives',
    'System states',
] as $section) {
    if (!str_contains($catalog, $section)) {
        throw new RuntimeException('Design System catalog section is missing: ' . $section);
    }
}

echo "Wave 12.3 Design System foundation passed.\n";
