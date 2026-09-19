<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Diagnostic\Bootstrap\DiagnosticDomainModule;

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(($manifest['version'] ?? null) === '0.6.1', 'Diagnostic module version must be 0.6.1.');
$assert(($manifest['schema_version'] ?? null) === '0.6.0', 'Diagnostic schema version must remain 0.6.0.');
$assert(($manifest['contributions']['runtime_module_service'] ?? null) === 'diagnosticDomainModule', 'Diagnostic runtime module is not declared.');
$assert(($manifest['contributions']['api_route_contributor_services'] ?? null) === [], 'Retired Phalcon Diagnostic route contribution was restored.');
$assert(in_array('diagnosticActionOutcomeHandler', $manifest['contributions']['extension_services']['event.consumers'] ?? [], true), 'Diagnostic action outcome consumer is not declared.');
$assert(in_array('app/migrations/20260914_000049_diagnostic_runtime_v060.sql', $manifest['contributions']['migration_files'] ?? [], true), 'Diagnostic V0.6.0 runtime migration is not declared.');

$module = new DiagnosticDomainModule([]);
$assert($module->name() === 'diagnostic', 'Diagnostic runtime module id mismatch.');
$assert($module->actionTypes() === ['IMPLEMENT_DIAGNOSTIC_RECOMMENDATION'], 'Diagnostic action ownership mismatch.');

foreach ([
    'app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    'app/Interfaces/Web/Controller/DiagnosticReportController.php',
    'app/Interfaces/Web/Controller/MethodologyStudioController.php',
    'app/Interfaces/Api/Controller/DiagnosticRuntimeController.php',
] as $retired) {
    $assert(!file_exists($root . '/' . $retired), 'Retired Diagnostic transport restored: ' . $retired);
}

$symfonyRoutes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    '/api/v1/diagnostics',
    '/api/v1/diagnostics/{id}/interview/next',
    '/api/v1/diagnostics/{id}/interview/answers',
    '/api/v1/diagnostics/{id}/evidence',
    '/api/v1/diagnostics/{id}/complete',
    '/api/v1/diagnostics/{id}/report',
    '/api/v1/diagnostics/{id}/assessment',
    '/api/v1/diagnostics/{id}/findings',
    '/api/v1/diagnostics/{id}/recommendations',
    'cos_web_diagnostic_methodology_studio:',
    'path: /admin/diagnostics/methodology-studio',
    'cos_web_diagnostic_report:',
    'path: /diagnostics/{session}/report',
] as $marker) {
    $assert(str_contains($symfonyRoutes, $marker), 'Canonical Symfony Diagnostic route missing: ' . $marker);
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Domains\\Diagnostic\\Application\\Service\\DiagnosticRuntimeService:',
    'Domains\\Diagnostic\\Application\\Service\\DiagnosticMethodologyAccess:',
    'App\\Web\\Diagnostic\\DiagnosticPageController:',
] as $marker) {
    $assert(str_contains($services, $marker), 'Canonical Symfony Diagnostic composition marker missing: ' . $marker);
}

$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
$assert(!str_contains($webServices, 'DiagnosticModuleRouteContributor'), 'Phalcon Web composition still references the retired Diagnostic route contributor.');
$assert(!str_contains($webServices, "setShared('diagnosticRouteContributor'"), 'Retired Diagnostic route contributor service was restored.');

$reportController = (string) file_get_contents($root . '/symfony/src/Web/Diagnostic/DiagnosticPageController.php');
$reportView = (string) file_get_contents($root . '/app/Interfaces/Web/View/diagnostic_report/show.phtml');
$assert(str_contains($reportController, '$this->runtime->report('), 'HTML report is not backed by canonical Diagnostic runtime persistence.');
$assert(str_contains($reportController, 'DiagnosticMethodologyAccess::VIEW'), 'Methodology Studio lost fine-grained Diagnostic access control.');
$assert(str_contains($reportView, 'overallHealth') && str_contains($reportView, 'recommendations'), 'HTML report misses core diagnostic sections.');
$assert(str_contains($reportView, 'htmlspecialchars'), 'HTML report does not escape output.');

$outcome = (string) file_get_contents($root . '/app/Domains/Diagnostic/Automation/Handler/DiagnosticActionOutcomeHandler.php');
$assert(str_contains($outcome, "diagnostic.action-outcome.v1"), 'Diagnostic durable consumer name changed unexpectedly.');
$assert(str_contains($outcome, 'appendMeasurement'), 'Diagnostic action outcome is not feeding the measurement ledger.');

$workflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');
$assert(str_contains($workflow, 'branches: [main]'), 'Diagnostic CI must run on canonical main.');
$assert(!str_contains($workflow, 'branches: [main, COS]'), 'Diagnostic CI must not depend on the historical COS branch.');
$assert(str_contains($workflow, "github.ref == 'refs/heads/main'"), 'Diagnostic deployment must originate from canonical main.');
$assert(!str_contains($workflow, "refs/heads/COS"), 'Diagnostic deployment still references the historical COS branch.');
$assert(str_contains($workflow, 'diagnostic_v061.php'), 'Diagnostic V0.6.1 smoke is absent from CI.');

echo "Diagnostic V0.6.1 Symfony delivery contract: OK\n";
