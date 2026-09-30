<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('WEB V0.9 artifact is missing: ' . $path);
    return (string) file_get_contents($full);
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};

$controller = $read('symfony/src/Web/Portal/CabinetController.php');
foreach ([
    'public function index(): Response',
    'public function retiredSubmission(string $id): Response',
    '$this->tenants->current()',
    "new RedirectResponse('/auth/login')",
    "new RedirectResponse('/sales')",
    'PageArchetype::Portal',
    'Response::HTTP_GONE',
] as $needle) {
    $contains($controller, $needle, 'Wave 13 Cabinet controller contract is incomplete');
}
foreach (['PhtmlRenderer', 'ViteAssetManifest', 'portal-cabinet'] as $legacy) {
    $notContains($controller, $legacy, 'Wave 13 Cabinet controller restored legacy presentation dependency');
}

$cabinet = $read('symfony/templates/experience/portal/cabinet.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosCard', 'data-cos-portal="cabinet"', '/auth/logout'] as $needle) {
    $contains($cabinet, $needle, 'Wave 13 Cabinet Portal composition is incomplete');
}
$retired = $read('symfony/templates/experience/portal/submission_retired.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosAlert', 'data-cos-portal="retired-submission"', '/cabinet'] as $needle) {
    $contains($retired, $needle, 'Wave 13 retired submission composition is incomplete');
}
foreach ([$cabinet, $retired] as $surface) {
    foreach (['tn-', 'style=', '<script'] as $legacy) {
        $notContains($surface, $legacy, 'Portal Twig restored legacy/local presentation');
    }
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /cabinet',
    'App\\Web\\Portal\\CabinetController::index',
    'path: /cabinet/submission/{id}',
    'App\\Web\\Portal\\CabinetController::retiredSubmission',
] as $needle) {
    $contains($routes, $needle, 'Wave 13 Cabinet route contract is incomplete');
}

foreach ([
    'symfony/src/Controller/CabinetPageController.php',
    'app/Interfaces/Web/View/cabinet/canonical.phtml',
    'app/Interfaces/Web/View/cabinet/retired-submission.phtml',
    'frontend/entrypoints/portal-cabinet.js',
    'frontend/styles/layouts/portal.css',
    'frontend/features/portal/cabinet.css',
    'frontend/features/portal/cabinet.js',
] as $retiredArtifact) {
    if (file_exists($root . '/' . $retiredArtifact)) {
        throw new RuntimeException('Retired Portal artifact restored: ' . $retiredArtifact);
    }
}

$vite = $read('vite.config.js');
$notContains($vite, "'portal-cabinet'", 'Portal is now owned by Symfony AssetMapper rather than Vite');
$assetGate = $read('tests/architecture/frontend_assets.php');
$notContains($assetGate, "'portal-cabinet'", 'Frontend Vite asset gate must not require retired Portal entrypoint');

if (is_dir($root . '/app/Domains/Portal')) {
    throw new RuntimeException('Portal presentation must not become a DDD domain.');
}

echo "WEB V0.9 Cabinet contract passed on Wave 13 Portal runtime.\n";
