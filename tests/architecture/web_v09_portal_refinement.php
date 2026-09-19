<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$controller = file_get_contents($root . '/app/Interfaces/Web/Controller/CabinetController.php');
$indexView = file_get_contents($root . '/app/Interfaces/Web/View/cabinet/index.phtml');
$submissionView = file_get_contents($root . '/app/Interfaces/Web/View/cabinet/submission.phtml');
$portalHeader = file_get_contents($root . '/app/Interfaces/Web/View/shared/portal_header.phtml');
$frontendNavigation = file_get_contents($root . '/app/Interfaces/Web/Navigation/FrontendNavigation.php');
$propertyNavigation = file_get_contents($root . '/app/Interfaces/Web/Navigation/PropertyNavigationContributor.php');
$entrypoint = file_get_contents($root . '/frontend/entrypoints/portal-cabinet.js');
$browserModule = file_get_contents($root . '/frontend/features/portal/cabinet.js');
$productionModule = file_get_contents($root . '/frontend/core/production.js');
$styles = file_get_contents($root . '/frontend/features/portal/cabinet.css');
$vite = file_get_contents($root . '/vite.config.js');
$frontendAssets = file_get_contents($root . '/tests/architecture/frontend_assets.php');

$requireContains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message);
};
$requireNotContains = static function (string $content, string $needle, string $message): void {
    if (str_contains($content, $needle)) throw new RuntimeException($message);
};

$requireContains($controller, "preparePortalSurface(\$user, 'Кабінет')", 'Cabinet overview must prepare the Portal surface.');
$requireContains($controller, "preparePortalSurface(\$user, 'Редагування поданого об’єкта')", 'Submission editor must prepare the Portal surface.');
$requireContains($controller, "\$this->view->interfaceSurface = 'portal';", 'Every cabinet route must render as Portal regardless of staff role.');
$requireContains($controller, "\$this->view->pageAssetEntries = ['portal-cabinet'];", 'Portal pages must load the dedicated bundle.');
$requireContains($controller, "\$this->view->metaRobots = 'noindex,nofollow';", 'Portal pages must remain private for search engines.');
$requireNotContains($controller, 'managerWorkspace', 'Portal controller must not assemble Company Workspace operational data.');
$requireNotContains($controller, "interfaceSurface = \$isManager", 'Portal surface ownership must not depend on manager role.');

$requireContains($indexView, "partial('shared/portal_header'", 'Cabinet overview must render the dedicated Portal shell.');
$requireContains($submissionView, "partial('shared/portal_header'", 'Submission editor must render the dedicated Portal shell.');
$requireNotContains($indexView, "partial('shared/manager_header'", 'Cabinet overview must never render the Workspace shell.');
$requireNotContains($submissionView, "partial('shared/manager_header'", 'Submission editor must never render the Workspace shell.');
$requireNotContains($indexView, "in_array(\$userRole", 'Role/capability rules must not be duplicated in Portal PHTML.');
$requireContains($indexView, 'id="properties"', 'Portal overview must expose the My Properties section.');
$requireContains($indexView, 'id="requests"', 'Portal overview must expose the Requests section.');
$requireContains($indexView, 'id="profile"', 'Portal overview must expose the supported read-only Profile section.');

$requireContains($portalHeader, 'data-interface-surface="portal"', 'Dedicated Portal shell must identify its surface.');
$requireContains($portalHeader, '$portalNavigation', 'Portal shell must consume centralized navigation through its view model.');
$requireNotContains($portalHeader, 'frontendNavigationService', 'Portal PHTML must not resolve navigation services directly.');
$requireNotContains($portalHeader, 'getDI()', 'Portal PHTML must remain container-free.');
$requireContains($portalHeader, 'data-portal-menu-button', 'Portal shell must expose a mobile navigation control.');

$requireContains($frontendNavigation, "'path' => 'cabinet#requests'", 'Core Portal navigation must link to requests supported by cabinet data.');
$requireContains($frontendNavigation, "'path' => 'cabinet#profile'", 'Core Portal navigation must link to the supported profile projection.');
$requireContains($propertyNavigation, "'path' => 'cabinet#properties'", 'Property Portal navigation must point My Properties to Portal data, not Workspace Listing.');
$requireNotContains($propertyNavigation, 'LISTING_ROLES', 'Portal My Properties must not be conflated with Workspace Listing access.');
$requireContains($propertyNavigation, 'SUBMIT_ROLES', 'Property submission capability must remain role-gated server-side.');

$requireContains($entrypoint, "../features/portal/cabinet.css", 'Portal entrypoint must import feature CSS.');
$requireContains($entrypoint, "../features/portal/cabinet.js", 'Portal entrypoint must import feature JS.');
$requireContains($entrypoint, "../core/production.js", 'Portal entrypoint must use the cross-surface production guard.');
$requireContains($browserModule, '[data-interface-surface="portal"][data-portal-header]', 'Portal browser module must activate only on the dedicated Portal shell.');
$requireContains($productionModule, 'aria-busy', 'Portal forms must inherit progressive submit state from the shared production guard.');
$requireContains($browserModule, 'data-portal-menu-button', 'Portal browser module must own mobile shell interaction only.');
$requireContains($styles, '.tn-portal-header__inner', 'Portal styles must include the dedicated shell.');
$requireContains($styles, '@media (max-width: 650px)', 'Portal styles must cover the mobile baseline.');
$requireContains($styles, '@media (prefers-reduced-motion: reduce)', 'Portal styles must respect reduced motion.');
$requireContains($vite, "'portal-cabinet'", 'Vite must expose the portal cabinet entrypoint.');
$requireContains($frontendAssets, "'portal-cabinet'", 'Frontend asset validation must include the portal cabinet entrypoint.');

if (is_dir($root . '/app/Domains/Portal')) throw new RuntimeException('WEB V0.9 must not invent a Portal DDD domain.');

echo "WEB V0.9 portal refinement architecture passed.\n";
