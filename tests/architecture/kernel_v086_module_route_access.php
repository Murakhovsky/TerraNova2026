<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (version_compare(KernelVersion::VERSION, '0.8.6', '<')) {
    throw new RuntimeException('COS Kernel per-request module route access requires Kernel 0.8.6+.');
}

$guard = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/ModuleRouteAccessGuard.php');
foreach (['OrganizationContextInterface', 'ActiveModuleResolver', 'organization->id()', 'modules->isEnabled'] as $needle) {
    if (!str_contains($guard, $needle)) {
        throw new RuntimeException('Module route guard is missing per-request tenant activation behavior: ' . $needle);
    }
}

$contributor = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesModuleRouteContributor.php');
foreach (['SalesRoutes::register', 'SalesTeamRoutes::register', 'SalesIntegrationRoutes::register', 'SalesAdministrationRoutes::register'] as $needle) {
    if (!str_contains($contributor, $needle)) {
        throw new RuntimeException('Sales route contribution lost route ownership: ' . $needle);
    }
}

$registrarPath = $root . '/app/Interfaces/Web/Routing/ModuleRouteRegistrar.php';
$registrar = is_file($registrarPath) ? (string) file_get_contents($registrarPath) : '';
$enforcement = $contributor . "\n" . $registrar;
foreach (['beforeMatch', 'spl_object_id'] as $needle) {
    if (!str_contains($enforcement, $needle)) {
        throw new RuntimeException('Module route access enforcement is missing: ' . $needle);
    }
}
if (!str_contains($enforcement, "allows('sales')") && !str_contains($enforcement, 'allows($moduleId)')) {
    throw new RuntimeException('Module route access enforcement is not backed by ModuleRouteAccessGuard.');
}

$services = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach (["setShared('moduleRouteAccessGuard'", "getShared('organizationContext')", "getShared('cosActiveModuleResolver')", "getShared('moduleRouteAccessGuard')"] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Web composition is missing route access wiring: ' . $needle);
    }
}

$webModule = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
foreach (['isEnabled(', 'activeModuleIds(', 'active('] as $forbidden) {
    if (str_contains($webModule, $forbidden)) {
        throw new RuntimeException('Web shell must not globally filter module routes at boot: ' . $forbidden);
    }
}
if (!str_contains($webModule, "getShared('cosModuleApiRouteContributors')")) {
    throw new RuntimeException('Web shell no longer consumes module route contributions.');
}

$context = (string) file_get_contents($root . '/app/Interfaces/Web/Tenant/SessionOrganizationContext.php');
if (!str_contains($context, 'currentOrganizationId()') || !str_contains($context, 'OrganizationContextInterface')) {
    throw new RuntimeException('Session organization context must resolve the active tenant for each request.');
}

echo "COS Kernel V0.8.6 per-request module route access architecture passed.\n";
