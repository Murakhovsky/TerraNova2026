<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$required = [
    'app/Interfaces/Web/Navigation/ModuleNavigationContributorInterface.php',
    'app/Interfaces/Web/Navigation/ModuleAwareNavigationService.php',
    'app/Interfaces/Web/Navigation/SalesNavigationContributor.php',
    'app/Interfaces/Web/Navigation/PropertyNavigationContributor.php',
    'app/Interfaces/Web/Navigation/DiagnosticNavigationContributor.php',
    'app/Infrastructure/Module/MysqlModuleStateRepository.php',
    'app/migrations/20260909_000028_cos_module_activation.sql',
    'docs/architecture/web-v0.5.md',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing WEB V0.5 module navigation artifact: ' . $path);
    }
}

$kernelContributions = (string) file_get_contents($root . '/app/Kernel/Module/ModuleContributions.php');
if (stripos($kernelContributions, 'navigation') !== false) {
    throw new RuntimeException('Kernel module contributions must remain UI-framework neutral.');
}

$frontendNavigation = (string) file_get_contents($root . '/app/Interfaces/Web/Navigation/FrontendNavigation.php');
foreach (['sales/dashboard', 'property/manage', 'admin/diagnostics/methodology-studio'] as $forbidden) {
    if (str_contains($frontendNavigation, $forbidden)) {
        throw new RuntimeException('Core FrontendNavigation still owns module-specific navigation: ' . $forbidden);
    }
}
foreach (['workspaceCore', 'portalCore'] as $needle) {
    if (!str_contains($frontendNavigation, $needle)) {
        throw new RuntimeException('Core navigation boundary missing: ' . $needle);
    }
}

$service = (string) file_get_contents($root . '/app/Interfaces/Web/Navigation/ModuleAwareNavigationService.php');
foreach (['OrganizationContextInterface', 'ActiveModuleResolver', 'modules->snapshot', 'moduleSnapshot->isEnabled', 'organization->id()', 'workspacePrimary', 'workspaceChildExtensions', 'portalPrimary'] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException('Module-aware navigation service is missing runtime behavior: ' . $needle);
    }
}
if (str_contains($service, 'modules->isEnabled')) {
    throw new RuntimeException('WEB V0.5 navigation must use one effective module snapshot per render instead of resolving module state repeatedly.');
}

// Module-aware navigation resolves tenant activation on every Portal/Workspace render.
// The runtime repository and its schema migration must therefore name the same table.
$moduleStateRepository = (string) file_get_contents($root . '/app/Infrastructure/Module/MysqlModuleStateRepository.php');
$moduleActivationMigration = (string) file_get_contents($root . '/app/migrations/20260909_000028_cos_module_activation.sql');
$moduleStateTable = 'cos_organization_modules';
if (!str_contains($moduleActivationMigration, 'CREATE TABLE IF NOT EXISTS ' . $moduleStateTable . ' (')) {
    throw new RuntimeException('Module activation migration does not create the canonical state table: ' . $moduleStateTable);
}
foreach (['FROM ' . $moduleStateTable, 'INSERT INTO ' . $moduleStateTable] as $needle) {
    if (!str_contains($moduleStateRepository, $needle)) {
        throw new RuntimeException('Module state repository is not aligned with the activation schema: ' . $needle);
    }
}
if (preg_match('/\bcos_organization_module\b/', $moduleStateRepository) === 1) {
    throw new RuntimeException('Module state repository still references the obsolete singular table name.');
}

$managerHeader = (string) file_get_contents($root . '/app/Interfaces/Web/View/shared/manager_header.phtml');
foreach (['$workspaceNavigation', '$portalNavigation', '$workspaceActiveSection'] as $needle) {
    if (!str_contains($managerHeader, $needle)) {
        throw new RuntimeException('Shared Web shell is missing navigation view-model input: ' . $needle);
    }
}
foreach (["getShared('frontendNavigationService')", 'navigationService->workspace', 'navigationService->portal', 'getDI()', 'FrontendNavigation::workspace(', 'FrontendNavigation::portal('] as $legacy) {
    if (str_contains($managerHeader, $legacy)) {
        throw new RuntimeException('Shared Web shell must remain container-free after Symfony SSR cutover: ' . $legacy);
    }
}

$symfonyNavigation = (string) file_get_contents($root . '/symfony/src/Web/Navigation/NavigationBuilder.php');
foreach (['ActiveModuleResolver', 'modules->snapshot', 'snapshot($organizationId)', "'key' => 'sales'", "'key' => 'properties'", "'key' => 'diagnostics'"] as $needle) {
    if (!str_contains($symfonyNavigation, $needle)) {
        throw new RuntimeException('Symfony navigation builder is missing module-aware behavior: ' . $needle);
    }
}

$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach ([
    "getShared('cosModuleWebNavigationContributors')",
    'new SalesNavigationContributor()',
    'new PropertyNavigationContributor()',
    'new DiagnosticNavigationContributor()',
    "setShared('frontendNavigationService'",
    'new ModuleAwareNavigationService(',
] as $needle) {
    if (!str_contains($webServices, $needle)) {
        throw new RuntimeException('Web composition is missing module-aware navigation wiring: ' . $needle);
    }
}
if (str_contains($webServices, "setShared('webModuleNavigationContributors'")) {
    throw new RuntimeException('WEB V0.5 must not restore the pre-V0.9 hardcoded navigation contributor aggregate.');
}

$moduleServices = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach (['ModuleExtensionPoint::WEB_NAVIGATION', "setShared('cosModuleWebNavigationContributors'", "'module_id' => \$extension->moduleId"] as $needle) {
    if (!str_contains($moduleServices, $needle)) {
        throw new RuntimeException('Generic module extension runtime is missing WEB navigation integration: ' . $needle);
    }
}

foreach ([
    'SalesNavigationContributor.php' => ["return 'sales'", 'sales/dashboard', 'client-case/inbox'],
    'PropertyNavigationContributor.php' => ["return 'property'", 'property/manage', 'property/catalog'],
    'DiagnosticNavigationContributor.php' => ["return 'diagnostic'", 'admin/diagnostics/methodology-studio'],
] as $file => $needles) {
    $source = (string) file_get_contents($root . '/app/Interfaces/Web/Navigation/' . $file);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            throw new RuntimeException(sprintf('%s is missing owned navigation: %s.', $file, $needle));
        }
    }
}

foreach ([
    'app/Domains/Sales/module.php' => 'salesNavigationContributor',
    'app/Domains/Property/module.php' => 'propertyNavigationContributor',
    'app/Domains/Diagnostic/module.php' => 'diagnosticNavigationContributor',
] as $manifestPath => $serviceId) {
    $manifest = (string) file_get_contents($root . '/' . $manifestPath);
    if (!str_contains($manifest, "'web.navigation'") || !str_contains($manifest, $serviceId)) {
        throw new RuntimeException(sprintf('%s must declare its Web navigation extension service.', $manifestPath));
    }
}

echo "WEB V0.5 module-aware Web navigation architecture passed.\n";
