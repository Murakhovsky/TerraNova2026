<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$controller = file_get_contents($root . '/app/Interfaces/Web/Controller/CabinetController.php');
$indexView = file_get_contents($root . '/app/Interfaces/Web/View/cabinet/index.phtml');
$submissionView = file_get_contents($root . '/app/Interfaces/Web/View/cabinet/submission.phtml');
$header = file_get_contents($root . '/app/Interfaces/Web/View/shared/manager_header.phtml');
$navigation = file_get_contents($root . '/app/Interfaces/Web/Navigation/PropertyNavigationContributor.php');
$entrypoint = file_get_contents($root . '/frontend/entrypoints/portal-cabinet.js');
$browserModule = file_get_contents($root . '/frontend/features/portal/cabinet.js');
$styles = file_get_contents($root . '/frontend/features/portal/cabinet.css');
$vite = file_get_contents($root . '/vite.config.js');
$frontendAssets = file_get_contents($root . '/tests/architecture/frontend_assets.php');

$requireContains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException($message);
    }
};

$requireNotContains = static function (string $content, string $needle, string $message): void {
    if (str_contains($content, $needle)) {
        throw new RuntimeException($message);
    }
};

$requireContains($controller, "prepareCabinetSurface(\$user, 'Кабінет')", 'Cabinet overview must prepare the shared cabinet surface.');
$requireContains($controller, "prepareCabinetSurface(\$user, 'Редагування поданого об’єкта')", 'Cabinet submission editor must prepare the shared cabinet surface.');
$requireContains($controller, "\$this->view->metaRobots = 'noindex,nofollow';", 'Portal cabinet must remain private for search engines.');
$requireContains($controller, "\$this->view->pageAssetEntries = ['portal-cabinet'];", 'Cabinet pages must load the dedicated portal bundle.');
$requireContains($controller, "\$this->view->interfaceSurface = \$isManager ? 'workspace' : 'portal';", 'Cabinet surface must distinguish team workspace from portal users.');
$requireNotContains($controller, "workspaceSection = 'portal'", 'Portal must not masquerade as a Company Workspace section.');
$requireContains($indexView, "partial('shared/manager_header', ['active' => 'cabinet'])", 'Cabinet overview must keep the role-aware shared header.');
$requireContains($submissionView, "partial('shared/manager_header', ['active' => 'cabinet'])", 'Cabinet submission editor must keep the role-aware shared header.');
$requireContains($header, 'data-interface-surface="portal"', 'Shared header must expose the Portal interface surface.');
$requireContains($navigation, "['key' => 'catalog'", 'Property module must contribute catalog navigation to the portal.');
$requireContains($navigation, "['key' => 'favour'", 'Property module must contribute favourites navigation to the portal.');
$requireContains($navigation, 'LISTING_ROLES', 'Portal listing access must remain role-gated.');
$requireContains($navigation, 'SUBMIT_ROLES', 'Portal submission access must remain role-gated.');
$requireContains($entrypoint, "../features/portal/cabinet.css", 'Portal entrypoint must import feature CSS.');
$requireContains($entrypoint, "../features/portal/cabinet.js", 'Portal entrypoint must import feature JS.');
$requireContains($browserModule, '[data-interface-surface="portal"]', 'Portal browser module must activate only on the Portal surface.');
$requireContains($browserModule, 'dataset.portalCabinet', 'Portal browser module must expose its active state.');
$requireContains($browserModule, 'aria-busy', 'Portal forms must expose progressive submit state.');
$requireContains($styles, '.tn-portal-cabinet', 'Portal styles must be surface-scoped.');
$requireContains($styles, '@media (max-width: 650px)', 'Portal styles must cover the mobile baseline.');
$requireContains($vite, "'portal-cabinet'", 'Vite must expose the portal cabinet entrypoint.');
$requireContains($frontendAssets, "'portal-cabinet'", 'Frontend asset validation must include the portal cabinet entrypoint.');

if (is_dir($root . '/app/Domains/Portal')) {
    throw new RuntimeException('WEB V0.9 must not invent a Portal DDD domain.');
}

echo "WEB V0.9 portal refinement architecture passed.\n";
