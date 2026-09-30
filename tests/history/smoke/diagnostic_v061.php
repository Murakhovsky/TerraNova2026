<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Diagnostic\Bootstrap\DiagnosticDomainModule;

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(version_compare((string)($manifest['version'] ?? '0.0.0'), '0.6.1', '>='), 'Diagnostic module version must be V0.6.1+.');
$assert(version_compare((string)($manifest['schema_version'] ?? '0.0.0'), '0.6.0', '>='), 'Diagnostic schema version must be V0.6.0+.');
$assert(($manifest['contributions']['runtime_module_service'] ?? null) === 'diagnosticDomainModule', 'Diagnostic runtime module is not declared.');
$assert(($manifest['contributions']['api_route_contributor_services'] ?? []) === [], 'Diagnostic module must not restore Phalcon Web route contributions.');
$assert(in_array('diagnosticActionOutcomeHandler', $manifest['contributions']['extension_services']['event.consumers'] ?? [], true), 'Diagnostic action outcome consumer is not declared.');
$assert(in_array('app/migrations/20260914_000049_diagnostic_runtime_v060.sql', $manifest['contributions']['migration_files'] ?? [], true), 'Diagnostic V0.6.0 runtime migration is not declared.');

$module = new DiagnosticDomainModule([]);
$assert($module->name() === 'diagnostic', 'Diagnostic runtime module id mismatch.');
$assert($module->actionTypes() === ['IMPLEMENT_DIAGNOSTIC_RECOMMENDATION'], 'Diagnostic action ownership mismatch.');

foreach ([
    '/app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    '/app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    '/app/Interfaces/Web/Controller/DiagnosticReportController.php',
    '/app/Interfaces/Web/Controller/MethodologyStudioController.php',
] as $retired) {
    $assert(!is_file($root . $retired), 'Retired Phalcon Diagnostic Web artifact restored: ' . $retired);
}
$assert(!is_file($root . '/app/Interfaces/Api/Controller/DiagnosticRuntimeController.php'), 'Retired DiagnosticRuntimeController was restored.');

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
    'cos_web_diagnostic_methodology:',
    'path: /admin/diagnostics/methodology-studio',
    'cos_web_diagnostic_report:',
    'path: /diagnostics/{session}/report',
] as $marker) {
    $assert(str_contains($symfonyRoutes, $marker), 'Canonical Symfony Diagnostic route missing: ' . $marker);
}

$services = (string) file_get_contents($root . '/app/Bootstrap/DiagnosticServices.php');
foreach ([
    "setShared('diagnosticRuntimeRepository'",
    "setShared('diagnosticAiGateway'",
    "setShared('diagnosticFactExtractor'",
    "setShared('diagnosticHypothesisGenerator'",
    "setShared('diagnosticAcceptRecommendation'",
    "setShared('diagnosticRuntimeService'",
    "setShared('diagnosticRecommendationActionHandler'",
    "setShared('diagnosticActionOutcomeHandler'",
    "setShared('diagnosticDomainModule'",
    "getShared('cosLlmClient')",
    "getShared('cosActionService')",
] as $marker) {
    $assert(str_contains($services, $marker), 'Diagnostic composition marker missing: ' . $marker);
}

$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
$assert(!str_contains($webServices, 'DiagnosticModuleRouteContributor'), 'Retired Diagnostic route contributor remains in Phalcon Web composition.');
$assert(!str_contains($webServices, "setShared('diagnosticRouteContributor'"), 'Retired Diagnostic route contributor service remains registered.');

$reportController = (string) file_get_contents($root . '/symfony/src/Web/Diagnostic/DiagnosticPageController.php');
$reportQuery = (string) file_get_contents($root . '/symfony/src/Application/Diagnostic/Report/GetDiagnosticReportQueryHandler.php');
$reportView = (string) file_get_contents($root . '/symfony/templates/experience/diagnostic/report.html.twig');
$assert(str_contains($reportController, 'GetDiagnosticReportQuery'), 'HTML report does not use the Application Query boundary.');
$assert(str_contains($reportQuery, 'DiagnosticRuntimeService'), 'Diagnostic report query is not backed by runtime report persistence.');
$assert(str_contains($reportQuery, 'DiagnosticMethodologyAccess::VIEW'), 'Diagnostic report query does not preserve explicit view authorization.');
$assert(str_contains($reportView, 'report.health') && str_contains($reportView, 'report.sections'), 'Twig report misses core diagnostic presentation.');
$assert(!str_contains($reportView, '|raw'), 'Diagnostic report must remain escaped by Twig.');

$outcome = (string) file_get_contents($root . '/app/Domains/Diagnostic/Automation/Handler/DiagnosticActionOutcomeHandler.php');
$assert(str_contains($outcome, "diagnostic.action-outcome.v1"), 'Diagnostic durable consumer name changed unexpectedly.');
$assert(str_contains($outcome, 'appendMeasurement'), 'Diagnostic action outcome is not feeding the measurement ledger.');

$workflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');
$assert(str_contains($workflow, 'branches: [main]'), 'Diagnostic CI must run on canonical main.');
$assert(!str_contains($workflow, 'branches: [main, COS]'), 'Diagnostic CI must not depend on the historical COS branch.');
$assert(str_contains($workflow, "github.ref == 'refs/heads/main'"), 'Diagnostic deployment must originate from canonical main.');
$assert(!str_contains($workflow, "refs/heads/COS"), 'Diagnostic deployment still references the historical COS branch.');
$assert(str_contains($workflow, 'diagnostic_v061.php'), 'Diagnostic V0.6.1 smoke is absent from CI.');

echo "Diagnostic V0.6.1 delivery contract: OK\n";
