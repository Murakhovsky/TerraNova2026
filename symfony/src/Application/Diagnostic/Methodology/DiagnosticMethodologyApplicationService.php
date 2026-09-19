<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Methodology;

use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Application\Service\MethodologyWorkbenchService;

final readonly class DiagnosticMethodologyApplicationService
{
    public function __construct(
        private MethodologyStudioService $studio,
        private MethodologyWorkbenchService $workbench,
        private DiagnosticMethodologyAccess $access,
    ) {}

    public function packs(string $organizationId, int $actorId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['packs' => $this->studio->packs($organizationId)];
    }

    public function createPack(string $organizationId, int $actorId, array $input): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return ['created' => $this->studio->create($organizationId, $input, (string) $actorId)];
    }

    public function pack(string $organizationId, int $actorId, string $packId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['pack' => $this->studio->pack($organizationId, $packId)];
    }

    public function versions(string $organizationId, int $actorId, string $packId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['versions' => $this->studio->versions($organizationId, $packId)];
    }

    public function entities(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['entities' => $this->studio->entities($organizationId, $packId, $version)];
    }

    public function saveEntity(string $organizationId, int $actorId, string $packId, string $version, string $type, array $input): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        $this->studio->saveEntity($organizationId, $packId, $version, $type, $input, (string) $actorId);
        return ['saved' => true];
    }

    public function deleteEntity(string $organizationId, int $actorId, string $packId, string $version, string $type, string $entityId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        $this->studio->deleteEntity($organizationId, $packId, $version, $type, $entityId, (string) $actorId);
        return ['deleted' => true];
    }

    public function scenarios(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['scenarios' => $this->studio->scenarios($organizationId, $packId, $version)];
    }

    public function saveScenario(string $organizationId, int $actorId, string $packId, string $version, array $input): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        $this->studio->saveScenario($organizationId, $packId, $version, $input, (string) $actorId);
        return ['saved' => true];
    }

    public function validate(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return $this->studio->validate($organizationId, $packId, $version);
    }

    public function simulate(string $organizationId, int $actorId, string $packId, string $version, array $input): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return $this->studio->simulate($organizationId, $packId, $version, $input);
    }

    public function regression(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return $this->studio->runRegression($organizationId, $packId, $version);
    }

    public function clonePack(string $organizationId, int $actorId, string $packId, string $version, string $targetVersion): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        $this->studio->clone($organizationId, $packId, $version, $targetVersion, (string) $actorId);
        return ['cloned' => true];
    }

    public function publish(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::PUBLISH);
        $this->studio->publish($organizationId, $packId, $version, (string) $actorId);
        return ['published' => true];
    }

    public function archive(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::PUBLISH);
        $this->studio->archive($organizationId, $packId, $version, (string) $actorId);
        return ['archived' => true];
    }

    public function activate(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::PUBLISH);
        $this->studio->activate($organizationId, $packId, $version, (string) $actorId);
        return ['activated' => true];
    }

    public function history(string $organizationId, int $actorId, string $packId = '', string $version = ''): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['history' => $this->studio->history($organizationId, $packId, $version)];
    }

    public function runs(string $organizationId, int $actorId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['runs' => $this->studio->runs($organizationId)];
    }

    public function permissions(string $organizationId, int $actorId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return [
            'view' => true,
            'edit' => $this->access->allows($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT),
            'publish' => $this->access->allows($organizationId, $actorId, DiagnosticMethodologyAccess::PUBLISH),
        ];
    }

    public function workbenchScenarios(string $organizationId, int $actorId, string $packId, string $version): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::VIEW);
        return ['scenarios' => $this->workbench->scenarios($organizationId, $packId, $version)];
    }

    public function deleteScenario(string $organizationId, int $actorId, string $packId, string $version, string $scenarioId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        $this->workbench->deleteScenario($organizationId, $packId, $version, $scenarioId, (string) $actorId);
        return ['deleted' => true];
    }

    public function cloneScenario(
        string $organizationId,
        int $actorId,
        string $packId,
        string $version,
        string $scenarioId,
        array $input,
    ): array {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return [
            'scenario' => $this->workbench->cloneScenario(
                $organizationId,
                $packId,
                $version,
                $scenarioId,
                (string) ($input['id'] ?? ''),
                (string) ($input['name'] ?? ''),
                (string) $actorId,
            ),
        ];
    }

    public function runScenario(string $organizationId, int $actorId, string $packId, string $version, string $scenarioId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return ['result' => $this->workbench->runScenario($organizationId, $packId, $version, $scenarioId)];
    }

    public function diagnosticRun(string $organizationId, int $actorId, string $sessionId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::EDIT);
        return ['run' => $this->workbench->diagnosticRun($organizationId, $sessionId)];
    }

    public function permissionMatrix(string $organizationId, int $actorId): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::PUBLISH);
        return ['matrix' => $this->workbench->permissionMatrix($organizationId)];
    }

    public function permissionOverride(string $organizationId, int $actorId, array $input): array
    {
        $this->assertAllowed($organizationId, $actorId, DiagnosticMethodologyAccess::PUBLISH);
        return ['matrix' => $this->workbench->setPermission($organizationId, $actorId, $input)];
    }

    private function assertAllowed(string $organizationId, int $actorId, string $permission): void
    {
        if (!$this->access->allows($organizationId, $actorId, $permission)) {
            throw new DiagnosticMethodologyPermissionDenied('Missing permission: ' . $permission);
        }
    }
}
