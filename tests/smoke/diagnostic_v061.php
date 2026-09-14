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
$assert(in_array('diagnosticRouteContributor', $manifest['contributions']['api_route_contributor_services'] ?? [], true), 'Diagnostic routes are not module-owned.');
$assert(in_array('diagnosticActionOutcomeHandler', $manifest['contributions']['extension_services']['event.consumers'] ?? [], true), 'Diagnostic action outcome consumer is not declared.');
$assert(in_array('app/migrations/20260914_000049_diagnostic_runtime_v060.sql', $manifest['contributions']['migration_files'] ?? [], true), 'Diagnostic V0.6.0 runtime migration is not declared.');

$module = new DiagnosticDomainModule([]);
$assert($module->name() === 'diagnostic', 'Diagnostic runtime module id mismatch.');
$assert($module->actionTypes() === ['IMPLEMENT_DIAGNOSTIC_RECOMMENDATION'], 'Diagnostic action ownership mismatch.');

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/DiagnosticRoutes.php');
foreach ([
    '/api/diagnostics',
    '/answers',
    '/complete',
    '/report',
    '/recommendations/',
    '/accept',
    '/re-diagnostic',
    '/compare/',
    '/diagnostics/{session:',
] as $marker) {
    $assert(str_contains($routes, $marker), 'Diagnostic runtime route missing: ' . $marker);
}

$api = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/DiagnosticRuntimeController.php');
foreach (['startAction', 'resumeAction', 'nextAction', 'answerAction', 'completeAction', 'reportAction', 'acceptAction', 'reDiagnosticAction', 'compareAction'] as $action) {
    $assert(str_contains($api, 'function ' . $action), 'Diagnostic runtime API action missing: ' . $action);
}
$assert(str_contains($api, "getShared('diagnosticRuntimeService')"), 'Diagnostic API is not wired to the runtime service.');
$assert(str_contains($api, 'validMutation()'), 'Diagnostic mutation endpoints are missing CSRF protection.');

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
$assert(str_contains($webServices, 'DiagnosticModuleRouteContributor'), 'Diagnostic route contributor class is not registered in Web composition.');
$assert(str_contains($webServices, "setShared('diagnosticRouteContributor'"), 'Diagnostic route contributor service is missing.');

$reportController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/DiagnosticReportController.php');
$reportView = (string) file_get_contents($root . '/app/Interfaces/Web/View/diagnostic_report/show.phtml');
$assert(str_contains($reportController, "getShared('diagnosticRuntimeService')"), 'HTML report is not backed by runtime report persistence.');
$assert(str_contains($reportView, 'overallHealth') && str_contains($reportView, 'recommendations'), 'HTML report misses core diagnostic sections.');
$assert(str_contains($reportView, 'htmlspecialchars'), 'HTML report does not escape output.');

$outcome = (string) file_get_contents($root . '/app/Domains/Diagnostic/Automation/Handler/DiagnosticActionOutcomeHandler.php');
$assert(str_contains($outcome, "diagnostic.action-outcome.v1"), 'Diagnostic durable consumer name changed unexpectedly.');
$assert(str_contains($outcome, 'appendMeasurement'), 'Diagnostic action outcome is not feeding the measurement ledger.');

$workflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');
$assert(str_contains($workflow, 'branches: [main, COS]'), 'Diagnostic CI does not run on main.');
$assert(str_contains($workflow, 'diagnostic_v061.php'), 'Diagnostic V0.6.1 smoke is absent from CI.');

echo "Diagnostic V0.6.1 delivery contract: OK\n";
