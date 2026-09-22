<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('WEB V0.9 artifact is missing: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};

$controller = $read('symfony/src/Controller/CabinetPageController.php');
foreach ([
    'public function index(Request $request): Response',
    'public function retiredSubmission(Request $request, string $id): Response',
    '$this->tenants->current()',
    "return new RedirectResponse('/auth/login')",
    "return new RedirectResponse('/sales')",
    "'cabinet/canonical'",
    "'cabinet/retired-submission'",
    "'interfaceSurface' => 'portal'",
    "'pageAssetEntries' => ['portal-cabinet']",
    'Response::HTTP_GONE',
] as $needle) {
    $contains($controller, $needle, 'Native Cabinet controller contract is incomplete');
}

$canonical = $read('app/Interfaces/Web/View/cabinet/canonical.phtml');
foreach ([
    "partial('components/ui/page_header'",
    'tn-ui-panel',
    'tn-portal-profile',
    '$currentUser',
    '$organizationId',
    '$portalRole',
    '/auth/logout',
] as $needle) {
    $contains($canonical, $needle, 'Native Cabinet canonical view is incomplete');
}
foreach (['tn-portal-hero', 'tn-kicker', 'tn-actions'] as $needle) {
    $notContains($canonical, $needle, 'Native Cabinet must not restore historical portal shell');
}

$retired = $read('app/Interfaces/Web/View/cabinet/retired-submission.phtml');
foreach ([
    "partial('components/ui/state'",
    'Старий редактор заявки закрито',
    '/cabinet',
] as $needle) {
    $contains($retired, $needle, 'Retired submission boundary is incomplete');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /cabinet',
    'CabinetPageController::index',
    'path: /cabinet/submission/{id}',
    'CabinetPageController::retiredSubmission',
] as $needle) {
    $contains($routes, $needle, 'Native Cabinet route contract is incomplete');
}

foreach ([
    'app/Interfaces/Web/View/cabinet/index.phtml',
    'app/Interfaces/Web/View/cabinet/submission.phtml',
] as $historical) {
    if (is_file($root . '/' . $historical)) {
        throw new RuntimeException('Historical Cabinet renderer restored: ' . $historical);
    }
}

$entrypoint = $read('frontend/entrypoints/portal-cabinet.js');
$contains($entrypoint, '../features/portal/cabinet.css', 'Portal entrypoint must retain cabinet CSS');
$contains($entrypoint, '../features/portal/cabinet.js', 'Portal entrypoint must retain cabinet JS');
$contains($entrypoint, '../core/production.js', 'Portal entrypoint must retain production guard');

$production = $read('frontend/core/production.js');
$contains($production, 'aria-busy', 'Portal forms must inherit progressive submit state');

$vite = $read('vite.config.js');
$contains($vite, "'portal-cabinet'", 'Vite must expose portal-cabinet');

$frontendAssets = $read('tests/architecture/frontend_assets.php');
$contains($frontendAssets, "'portal-cabinet'", 'Frontend asset validation must include portal-cabinet');

if (is_dir($root . '/app/Domains/Portal')) {
    throw new RuntimeException('WEB V0.9 must not invent a Portal DDD domain.');
}

echo "WEB V0.9 native Cabinet architecture passed.\n";
