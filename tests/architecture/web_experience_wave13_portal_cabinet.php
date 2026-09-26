<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Portal/CabinetController.php',
    'symfony/src/Web/Portal/CabinetPresenter.php',
    'symfony/src/Web/Portal/ViewModel/CabinetViewModel.php',
    'symfony/templates/experience/portal_shell.html.twig',
    'symfony/templates/experience/portal/cabinet.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-025 artifact missing: ' . $relative);
    }
}
if (is_file($root . '/app/Interfaces/Web/View/cabinet/canonical.phtml')) {
    throw new RuntimeException('VR-025 legacy Cabinet home PHTML restored.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /cabinet', 'App\\Web\\Portal\\CabinetController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-025 canonical route incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Portal/CabinetController.php');
foreach ([
    'TenantContextProviderInterface',
    'PageArchetype::Portal',
    'PagePresentationFactory',
    'CabinetPresenter',
    "new RedirectResponse('/auth/login')",
    "new RedirectResponse('/sales')",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-025 controller contract incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'ViteAssetManifest', 'NavigationBuilder'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-025 controller leaked legacy presentation dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/portal/cabinet.html.twig');
foreach ([
    "extends 'experience/portal_shell.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosCard',
    'data-cos-portal="cabinet"',
    'data-cos-archetype',
    '/auth/logout',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-025 Portal composition incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-025 restored legacy/local Portal presentation: ' . $forbidden);
    }
}

echo "Wave 13 VR-025 Cabinet Portal passed.\n";
