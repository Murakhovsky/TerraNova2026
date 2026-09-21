<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Dev/VisualStressController.php',
    'symfony/templates/experience/visual_stress.html.twig',
    'symfony/assets/styles/stress-screens.css',
    'symfony/src/Command/VisualStressSmokeCommand.php',
    'docs/03-architecture/cos-stress-screens.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('PHASE 8 artifact is missing: ' . $relative);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/VisualStressController.php');
foreach (['Domains\\', 'Doctrine\\', 'Repository', '/api/'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('PHASE 8 stress controller contains forbidden dependency: ' . $forbidden);
    }
}

foreach (['X-Robots-Tag', 'noindex, nofollow', 'no-store, private'] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('PHASE 8 response contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/visual_stress.html.twig');
foreach ([
    'Canonical Stress Screens',
    'data-stress-screen="company-home"',
    'data-stress-screen="data-grid"',
    'data-stress-screen="entity-workspace"',
    'data-stress-screen="control-ai"',
    '<twig:CosDataGrid',
    '<twig:CosEntityHeader',
    '<twig:CosMoneyMetric',
    '<twig:CosNextAction',
    '<twig:CosActivityFeed',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('PHASE 8 stress surface is incomplete: ' . $marker);
    }
}

$css = (string) file_get_contents($root . '/symfony/assets/styles/stress-screens.css');
foreach ([
    '.cos-stress__executive-grid',
    '.cos-stress__entity-grid',
    '.cos-stress__control-grid',
    '@media (max-width: 1100px)',
    '@media (max-width: 760px)',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException('PHASE 8 responsive CSS is incomplete: ' . $marker);
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (!str_contains($appCss, "@import './stress-screens.css';")) {
    throw new RuntimeException('PHASE 8 stress CSS is not loaded by the canonical app bundle.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_web_visual_stress:', 'path: /dev/stress', 'VisualStressController'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('PHASE 8 route contract is incomplete: ' . $marker);
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
if (!str_contains($services, 'App\\Web\\Experience\\Dev\\VisualStressController:')) {
    throw new RuntimeException('PHASE 8 controller is not registered as a web service.');
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
if (!str_contains($security, "path: '^/dev(?:/|$)'") || !str_contains($security, 'roles: ROLE_MANAGER')) {
    throw new RuntimeException('PHASE 8 route must remain under manager-only /dev security.');
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-stress-screens.md');
foreach ([
    'Executive / Company Home',
    'Dense Operational DataGrid',
    'Entity Workspace',
    'COS Control / AI / Operations',
    'Критерії завершення',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 8 documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 8 COS canonical stress screens passed.\n";
