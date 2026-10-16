<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/symfony/src/Persistence/Federation/FederationTrustedOutcomeEvidenceResolver.php';

use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;

$actor = static fn(string $tenant): TenantContext => new TenantContext(
    UserId::fromString('test-manager'), OrganizationId::fromString($tenant),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)],
);
$state = new class implements ModuleStateRepositoryInterface {
    private array $enabled = [];
    public function enabledOverride(string $org, string $moduleId): ?bool {
        return $this->enabled[$org . ':' . $moduleId] ?? null;
    }
    public function setEnabled(string $org, string $moduleId, bool $enabled): void {
        $this->enabled[$org . ':' . $moduleId] = $enabled;
    }
};
$state->setEnabled('tenant-one', 'growth', true);
$modules = new ActiveModuleResolver(
    new ModuleCatalog([new ModuleManifest('growth', 'Growth', '1.0.0', enabledByDefault: false)]),
    $state,
);
$provider = new class implements RunScopedGoalOutcomeEvidenceProviderInterface {
    public bool $forgeRun = false;
    public bool $observeCalled = false;
    public string $lastOrg = '';
    public function domain(): string { return 'growth'; }
    public function supports(string $criterionId): bool {
        return $criterionId === 'growth.run_linked_inbound_responses';
    }
    public function observe(
        string $organizationId, string $criterionId, DateTimeImmutable $from, DateTimeImmutable $to,
    ): array {
        $this->observeCalled = true;
        throw new DomainException('A Run is required.');
    }
    public function observeRun(
        TenantContext $actor, string $runId, string $criterionId,
        DateTimeImmutable $from, DateTimeImmutable $to,
    ): array {
        $this->lastOrg = $actor->organizationId()->value();
        return [
            'value' => 2,
            'evidence' => ['growth:run_linked_response:v1:' . hash('sha256', $runId)],
            'source' => 'growth.inbound_responses.attested_action.v1',
            'window_start' => $from->format('Y-m-d\TH:i:s.uP'),
            'window_end' => $to->format('Y-m-d\TH:i:s.uP'),
            'attribution' => 'run_linked_action',
            'run_id' => $this->forgeRun ? 'run-foreign' : $runId,
            'verified_actions' => 1,
        ];
    }
};
$resolver = new FederationTrustedOutcomeEvidenceResolver($modules, [$provider]);
$spec = new GoalSpecification('goal-run', 'tenant-one', 'test-manager', 'Earn two linked replies',
    [['id' => 'growth.run_linked_inbound_responses', 'operator' => 'at_least', 'expected' => 2]], []);
$from = new DateTimeImmutable('2026-10-08T08:00:00.400000+00:00');
$to = new DateTimeImmutable('2026-10-08T10:00:00.900000+00:00');
if ($resolver->collect($spec, $from, $to) !== [] || $provider->observeCalled) {
    throw new RuntimeException('Run-linked Goal incorrectly fell back to unscoped provider.');
}
$obs = $resolver->collect($spec, $from, $to, $actor('tenant-one'), 'run-one');
$evaluation = (new GoalOutcomeEvaluator())->evaluate($spec, $obs);
if ($provider->lastOrg !== 'tenant-one'
    || $evaluation['result'] !== 'satisfied'
    || $evaluation['criteria'][0]['attribution'] !== 'run_linked_action'
    || $evaluation['criteria'][0]['verified_actions'] !== 1) {
    throw new RuntimeException('Attested Run source or provenance missing from evaluation.');
}
try {
    $resolver->collect($spec, $from, $to, $actor('tenant-foreign'), 'run-one');
    throw new RuntimeException('Cross-tenant Run actor was accepted.');
} catch (DomainException) {}
$provider->forgeRun = true;
try {
    $resolver->collect($spec, $from, $to, $actor('tenant-one'), 'run-one');
    throw new RuntimeException('Forged Domain claim for another Run was accepted.');
} catch (DomainException) {}
$state->setEnabled('tenant-one', 'growth', false);
if ($resolver->collect($spec, $from, $to, $actor('tenant-one'), 'run-one') !== []) {
    throw new RuntimeException('Disabled Growth module contributed Run-linked evidence.');
}
echo "Federation Run-scoped evidence: tenant, signature, module and provenance boundaries passed.\n";
