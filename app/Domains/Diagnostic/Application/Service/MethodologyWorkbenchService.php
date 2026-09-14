<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Service;

use DomainException;
use Domains\Diagnostic\Application\Contract\MethodologyStudioRepositoryInterface;
use Domains\Diagnostic\Application\Contract\MethodologyWorkbenchRepositoryInterface;

final readonly class MethodologyWorkbenchService
{
    private const PERMISSIONS = [
        DiagnosticMethodologyAccess::VIEW,
        DiagnosticMethodologyAccess::EDIT,
        DiagnosticMethodologyAccess::PUBLISH,
    ];

    public function __construct(
        private MethodologyStudioService $studio,
        private MethodologyStudioRepositoryInterface $studioRepository,
        private MethodologyWorkbenchRepositoryInterface $workbenchRepository,
    ) {}

    /** @return list<array<string,mixed>> */
    public function scenarios(string $organizationId, string $packId, string $version): array
    {
        $this->requireVersion($organizationId, $packId, $version);
        return $this->workbenchRepository->scenarios($organizationId, $packId, $version);
    }

    public function deleteScenario(
        string $organizationId,
        string $packId,
        string $version,
        string $scenarioId,
        string $userId,
    ): void {
        $this->requireEditableVersion($organizationId, $packId, $version);
        $scenario = $this->scenario($organizationId, $packId, $version, $scenarioId);
        $this->studioRepository->deleteScenario($organizationId, $packId, $version, $scenarioId);
        $this->studioRepository->audit($organizationId, [
            'id' => bin2hex(random_bytes(16)),
            'pack_id' => $packId,
            'version' => $version,
            'user_id' => $userId,
            'entity_type' => 'SCENARIO',
            'entity_id' => $scenarioId,
            'old' => [
                'id' => $scenario['scenario_id'],
                'name' => $scenario['name'],
                'input' => $scenario['input'],
                'expected' => $scenario['expected'],
            ],
            'new' => null,
        ]);
    }

    /** @return array<string,mixed> */
    public function cloneScenario(
        string $organizationId,
        string $packId,
        string $version,
        string $scenarioId,
        string $newScenarioId,
        string $newName,
        string $userId,
    ): array {
        $this->requireEditableVersion($organizationId, $packId, $version);
        $source = $this->scenario($organizationId, $packId, $version, $scenarioId);
        $newScenarioId = strtolower(trim($newScenarioId));
        $newName = trim($newName);
        if (!preg_match('/^[a-z][a-z0-9_.-]+$/', $newScenarioId)) {
            throw new DomainException('Scenario id must be a stable lowercase identifier.');
        }
        if ($newName === '') {
            throw new DomainException('Scenario name is required.');
        }
        foreach ($this->studioRepository->scenarios($organizationId, $packId, $version) as $scenario) {
            if ($scenario['scenario_id'] === $newScenarioId) {
                throw new DomainException('Scenario id already exists.');
            }
        }

        $copy = [
            'id' => $newScenarioId,
            'name' => $newName,
            'input' => $source['input'],
            'expected' => $source['expected'],
        ];
        $this->studio->saveScenario($organizationId, $packId, $version, $copy, $userId);
        return $copy;
    }

    /** @return array<string,mixed> */
    public function runScenario(
        string $organizationId,
        string $packId,
        string $version,
        string $scenarioId,
    ): array {
        $scenario = $this->scenario($organizationId, $packId, $version, $scenarioId);
        $actual = $this->studio->simulate($organizationId, $packId, $version, $scenario['input']);
        $comparison = $this->compare($actual, $scenario['expected']);
        $this->studioRepository->saveTestResult($organizationId, [
            'run_id' => bin2hex(random_bytes(16)),
            'pack_id' => $packId,
            'version' => $version,
            'scenario_id' => $scenarioId,
            'status' => $comparison['status'],
            'result' => $comparison['details'],
        ]);
        return $comparison + ['actual' => $actual];
    }

    /** @return array<string,mixed> */
    public function diagnosticRun(string $organizationId, string $sessionId): array
    {
        return $this->workbenchRepository->diagnosticRun($organizationId, $sessionId)
            ?? throw new DomainException('Diagnostic run was not found.');
    }

    /** @return array{users:list<array<string,mixed>>,audit:list<array<string,mixed>>} */
    public function permissionMatrix(string $organizationId): array
    {
        return $this->workbenchRepository->permissionMatrix($organizationId);
    }

    /** @param array<string,mixed> $input
     *  @return array{users:list<array<string,mixed>>,audit:list<array<string,mixed>>}
     */
    public function setPermission(string $organizationId, int $actorUserId, array $input): array
    {
        $targetUserId = (int) ($input['target_user_id'] ?? 0);
        $permission = trim((string) ($input['permission'] ?? ''));
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        if ($targetUserId <= 0) {
            throw new DomainException('Target user is required.');
        }
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new DomainException('Unsupported methodology permission.');
        }
        if (!in_array($mode, ['inherit', 'allow', 'deny'], true)) {
            throw new DomainException('Permission mode must be inherit, allow or deny.');
        }
        $this->workbenchRepository->setPermissionOverride(
            $organizationId,
            $actorUserId,
            $targetUserId,
            $permission,
            $mode,
        );
        return $this->permissionMatrix($organizationId);
    }

    /** @return array<string,mixed> */
    private function scenario(string $organizationId, string $packId, string $version, string $scenarioId): array
    {
        foreach ($this->studioRepository->scenarios($organizationId, $packId, $version) as $scenario) {
            if ($scenario['scenario_id'] === $scenarioId) {
                return $scenario;
            }
        }
        throw new DomainException('Scenario not found.');
    }

    /** @return array<string,mixed> */
    private function requireVersion(string $organizationId, string $packId, string $version): array
    {
        return $this->studioRepository->version($organizationId, $packId, $version)
            ?? throw new DomainException('Pack version not found.');
    }

    private function requireEditableVersion(string $organizationId, string $packId, string $version): void
    {
        $row = $this->requireVersion($organizationId, $packId, $version);
        if (!in_array($row['status'], ['DRAFT', 'VALIDATING', 'READY'], true)) {
            throw new DomainException('Published or archived versions are immutable. Clone the version before editing scenarios.');
        }
    }

    /** @param array<string,mixed> $actual
     *  @param array<string,mixed> $expected
     *  @return array{status:string,details:array<string,mixed>}
     */
    private function compare(array $actual, array $expected): array
    {
        $actualFindings = array_values(array_unique(array_map(
            static fn ($finding): string => (string) $finding->ruleId,
            $actual['findings'] ?? [],
        )));
        $expectedFindings = array_values(array_unique(array_map('strval', $expected['findings'] ?? [])));
        $actualRecommendations = array_values(array_unique(array_map('strval', $actual['recommendations'] ?? [])));
        $expectedRecommendations = array_values(array_unique(array_map('strval', $expected['recommendations'] ?? [])));
        $missingFindings = array_values(array_diff($expectedFindings, $actualFindings));
        $unexpectedFindings = array_values(array_diff($actualFindings, $expectedFindings));
        $missingRecommendations = array_values(array_diff($expectedRecommendations, $actualRecommendations));
        $score = isset($actual['score']) ? (float) $actual['score'] : null;
        $scoreOk = !isset($expected['score_min'])
            || ($score !== null && $score >= (float) $expected['score_min']);

        $status = ($missingFindings !== [] || $missingRecommendations !== [] || !$scoreOk)
            ? 'FAILED'
            : ($unexpectedFindings !== [] ? 'CHANGED' : 'PASSED');

        return [
            'status' => $status,
            'details' => [
                'missing_findings' => $missingFindings,
                'unexpected_findings' => $unexpectedFindings,
                'missing_recommendations' => $missingRecommendations,
                'score' => $score,
                'score_ok' => $scoreOk,
            ],
        ];
    }
}
