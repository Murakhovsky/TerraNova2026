<?php
declare(strict_types=1);

use Domains\Diagnostic\Application\Contract\MethodologyWorkbenchRepositoryInterface;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Application\Service\MethodologyWorkbenchService;
use Tests\Support\InMemoryMethodologyStudioRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/InMemoryMethodologyStudioRepository.php';

function v055(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$studioRepository = new InMemoryMethodologyStudioRepository();
$studio = new MethodologyStudioService($studioRepository);

$workbenchRepository = new class implements MethodologyWorkbenchRepositoryInterface {
    public array $updates = [];

    public function scenarios(string $organizationId, string $packId, string $version): array
    {
        return [];
    }

    public function diagnosticRun(string $organizationId, string $sessionId): ?array
    {
        return ['session' => ['session_id' => $sessionId]];
    }

    public function permissionMatrix(string $organizationId): array
    {
        return ['users' => [], 'audit' => []];
    }

    public function setPermissionOverride(
        string $organizationId,
        int $actorUserId,
        int $targetUserId,
        string $permission,
        string $mode,
    ): void {
        $this->updates[] = compact('organizationId', 'actorUserId', 'targetUserId', 'permission', 'mode');
    }
};

$workbench = new MethodologyWorkbenchService($studio, $studioRepository, $workbenchRepository);
$studio->create('org-universal', [
    'slug' => 'finance-health',
    'name' => 'Finance Health',
    'domain' => 'Finance',
    'methodology_version' => '0.1.0',
], 'methodologist');
$studio->saveScenario('org-universal', 'finance-health', '0.1.0', [
    'id' => 'baseline',
    'name' => 'Baseline',
    'input' => ['facts' => [], 'metrics' => []],
    'expected' => ['findings' => []],
], 'methodologist');

$copy = $workbench->cloneScenario(
    'org-universal',
    'finance-health',
    '0.1.0',
    'baseline',
    'baseline-copy',
    'Baseline copy',
    'methodologist',
);
v055($copy['id'] === 'baseline-copy', 'Scenario Manager cannot clone a non-Sales methodology scenario.');
v055(count($studioRepository->scenarios('org-universal', 'finance-health', '0.1.0')) === 2, 'Scenario clone was not persisted.');
$workbench->deleteScenario('org-universal', 'finance-health', '0.1.0', 'baseline-copy', 'methodologist');
v055(count($studioRepository->scenarios('org-universal', 'finance-health', '0.1.0')) === 1, 'Scenario delete did not persist.');

$workbench->setPermission('org-universal', 7, [
    'target_user_id' => 8,
    'permission' => 'diagnostic.methodology.edit',
    'mode' => 'deny',
]);
v055(($workbenchRepository->updates[0]['mode'] ?? '') === 'deny', 'Capability override was not delegated to the workbench repository.');

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach ([
    '/workbench/scenarios',
    '/permissions/matrix',
    '/permissions/override',
    '/runs/{session:',
] as $needle) {
    v055(str_contains($routes, $needle), 'V0.5.5 route is missing: ' . $needle);
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/DiagnosticMethodologyWorkbenchController.php');
foreach ([
    'deleteScenarioAction',
    'cloneScenarioAction',
    'runScenarioAction',
    'permissionMatrixAction',
    'permissionOverrideAction',
    'runAction',
] as $method) {
    v055(str_contains($controller, 'function ' . $method), 'Workbench controller is missing ' . $method . '.');
}
v055(
    str_contains($controller, "], 'admin');") && str_contains($controller, 'diagnosticRun($org, $sessionId)'),
    'Detailed Diagnostic Run access is not protected by edit capability.',
);

$repositorySource = (string) file_get_contents($root . '/app/Domains/Diagnostic/Infrastructure/Persistence/MySql/MysqlMethodologyWorkbenchRepository.php');
foreach ([
    'diagnostic_assessment_results',
    'diagnostic_interview_turns',
    'diagnostic_contradictions',
    'diagnostic_permission_audit',
    'diagnostic_ai_audit',
] as $table) {
    v055(str_contains($repositorySource, $table), 'Workbench repository is missing structured source: ' . $table);
}
v055(str_contains($repositorySource, 'You cannot remove your own methodology publish access'), 'Access Manager lacks self-lock protection.');

$frontend = (string) file_get_contents($root . '/frontend/features/diagnostics/methodology-studio-v055.js');
foreach ([
    'data-v055-scenario-action="edit"',
    'data-v055-scenario-action="clone"',
    'data-v055-scenario-action="run"',
    'data-v055-scenario-action="delete"',
    'Dependency map',
    'WHY trace',
    'diagnostic.methodology.publish',
] as $needle) {
    v055(str_contains($frontend, $needle), 'Methodologist workbench frontend is missing: ' . $needle);
}

$scenarios = json_decode(
    (string) file_get_contents($root . '/resources/diagnostic/sales/0.2.0/regression-scenarios.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
v055(count($scenarios['scenarios'] ?? []) >= 12, 'Sales regression pack needs at least twelve negative evidence scenarios.');

$pack = json_decode(
    (string) file_get_contents($root . '/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$ruleIds = array_fill_keys(array_column($pack['rules'] ?? [], 'id'), true);
$recommendationIds = array_fill_keys(array_column($pack['recommendations'] ?? [], 'id'), true);
foreach ($scenarios['scenarios'] ?? [] as $scenario) {
    $hasPerturbation = ($scenario['metric_overrides'] ?? []) !== []
        || ($scenario['fact_overrides'] ?? []) !== []
        || ($scenario['unset_facts'] ?? []) !== [];
    v055($hasPerturbation, 'Regression scenario has no actual perturbation: ' . ($scenario['id'] ?? 'unknown'));
    foreach ($scenario['expected']['findings'] ?? [] as $id) {
        v055(isset($ruleIds[$id]), 'Regression scenario references an unknown rule: ' . $id);
    }
    foreach ($scenario['expected']['recommendations'] ?? [] as $id) {
        v055(isset($recommendationIds[$id]), 'Regression scenario references an unknown recommendation: ' . $id);
    }
}

$importer = (string) file_get_contents($root . '/bin/import-sales-methodology-v02.php');
v055(
    str_contains($importer, 'regression-scenarios.json')
    && str_contains($importer, 'unset($facts[(string) $factId])'),
    'Sales v0.2 importer does not install negative evidence scenarios.',
);
v055(str_contains($importer, "'-missing'"), 'Sales v0.2 recommendations are not linked to missing-evidence findings.');

$composition = (string) file_get_contents($root . '/app/Bootstrap/DiagnosticServices.php');
v055(str_contains($composition, 'diagnosticMethodologyWorkbench'), 'Methodology Workbench is not wired through the Diagnostic composition root.');

$entrypoint = (string) file_get_contents($root . '/frontend/entrypoints/diagnostics-methodology-studio.js');
v055(str_contains($entrypoint, 'methodology-studio-v055.js'), 'V0.5.5 frontend is not wired into the Methodology Studio entrypoint.');

$ci = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');
v055(str_contains($ci, 'diagnostic_v055.php'), 'V0.5.5 smoke contract is not part of CI.');
v055(str_contains($ci, 'sales_v071_configuration.php'), 'Diagnostic CI patch accidentally removed the Sales V0.7.2 architecture gate.');

echo "Diagnostic V0.5.5 methodologist workbench contract passed.\n";
