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
foreach (['OrganizationContextInterface', 'ActiveModuleResolver', 'modules->isEnabled', 'organization->id()', 'workspacePrimary', 'workspaceChildExtensions', 'portalPrimary'] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException('Module-aware navigation service is missing runtime behavior: ' . $needle);
    }
}

$managerHeader = (string) file_get_contents($root . '/app/Interfaces/Web/View/shared/manager_header.phtml');
foreach (["getShared('frontendNavigationService')", 'navigationService->workspace', 'navigationService->portal'] as $needle) {
    if (!str_contains($managerHeader, $needle)) {
        throw new RuntimeException('Shared Web shell does not consume module-aware navigation: ' . $needle);
    }
}
if (str_contains($managerHeader, 'FrontendNavigation::workspace(') || str_contains($managerHeader, 'FrontendNavigation::portal(')) {
    throw new RuntimeException('Shared Web shell still bypasses module-aware navigation composition.');
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

echo "WEB V0.5 module-aware Web navigation architecture passed.\n";
