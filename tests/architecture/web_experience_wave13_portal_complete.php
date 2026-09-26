<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Portal/CabinetController.php',
    'symfony/src/Web/Portal/CabinetPresenter.php',
    'symfony/src/Web/Portal/ViewModel/CabinetViewModel.php',
    'symfony/src/Web/Portal/ViewModel/CabinetSubmissionStatusViewModel.php',
    'symfony/templates/experience/portal_shell.html.twig',
    'symfony/templates/experience/portal/cabinet.html.twig',
    'symfony/templates/experience/portal/submission_retired.html.twig',
] as $canonical) {
    if (!is_file($root . '/' . $canonical)) {
        throw new RuntimeException('Phase 7 canonical Portal artifact missing: ' . $canonical);
    }
}

foreach ([
    'symfony/src/Controller/CabinetPageController.php',
    'app/Interfaces/Web/View/cabinet/canonical.phtml',
    'app/Interfaces/Web/View/cabinet/retired-submission.phtml',
    'frontend/entrypoints/portal-cabinet.js',
    'frontend/styles/layouts/portal.css',
    'frontend/features/portal/cabinet.css',
] as $legacy) {
    if (file_exists($root . '/' . $legacy)) {
        throw new RuntimeException('Phase 7 legacy Portal artifact restored: ' . $legacy);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Portal/CabinetController.php');
foreach ([
    'PageArchetype::Portal',
    'Response::HTTP_GONE',
    "new RedirectResponse('/auth/login')",
    "new RedirectResponse('/sales')",
    'retiredSubmission',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Phase 7 controller contract incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'ViteAssetManifest', 'portal-cabinet'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Phase 7 controller restored legacy dependency: ' . $forbidden);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'App\\Web\\Portal\\CabinetController::index',
    'App\\Web\\Portal\\CabinetController::retiredSubmission',
] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Phase 7 route owner missing: ' . $marker);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, 'portal-cabinet')) {
    throw new RuntimeException('Phase 7 must retire the dedicated Portal Vite entrypoint.');
}

$tracker = (string) file_get_contents($root . '/docs/03-architecture/wave13-migration-tracker.md');
foreach (['VR-025', 'VR-026'] as $id) {
    if (preg_match('/\| ' . $id . ' \|[^\n]*\| DONE \|/', $tracker) !== 1) {
        throw new RuntimeException('Phase 7 tracker unit is not DONE: ' . $id);
    }
}

echo "Wave 13 Phase 7 Portal complete: VR-025/026 DONE, PHTML/Vite Portal ownership retired.\n";
