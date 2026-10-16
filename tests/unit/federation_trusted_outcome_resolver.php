<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/symfony/src/Persistence/Federation/FederationTrustedOutcomeEvidenceResolver.php';

use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;
use Platform\Orchestration\Goal\GoalSpecification;

$states = new class implements ModuleStateRepositoryInterface {
    private array $values = [];
    public function enabledOverride(string $organizationId, string $moduleId): ?bool {
        return $this->values[$organizationId . ':' . $moduleId] ?? null;
    }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void {
        $this->values[$organizationId . ':' . $moduleId] = $enabled;
    }
};
$modules = new ActiveModuleResolver(
    new ModuleCatalog([new ModuleManifest('sales', 'Sales', '1.0.0')]), $states,
);
$provider = new class implements GoalOutcomeEvidenceProviderInterface {
    public ?string $observedTenant = null;
    public bool $corrupt = false;
    public function domain(): string { return 'sales'; }
    public function supports(string $criterionId): bool { return $criterionId === 'sales.won_deals'; }
    public function observe(string $organizationId, string $criterionId, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
        $this->observedTenant = $organizationId;
        return [
            'value' => 3,
            'evidence' => ['sales:closed_outcomes:v1:' . hash('sha256', $organizationId)],
            'source' => 'sales.cos_events.closed_outcomes.v1',
            'window_start' => $this->corrupt ? 'forged' : $from->format('Y-m-d\TH:i:s.uP'),
            'window_end' => $to->format('Y-m-d\TH:i:s.uP'),
        ];
    }
};
$goal = new GoalSpecification('goal-test', 'tenant-a', 'user-a', 'Win 2 new deals', [
    ['id' => 'sales.won_deals', 'operator' => 'at_least', 'expected' => 2],
    ['id' => 'sales.tasks_created', 'operator' => 'at_least', 'expected' => 1],
], []);
$from = new \DateTimeImmutable('2026-10-01T00:00:00+00:00');
$to = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');
$resolver = new FederationTrustedOutcomeEvidenceResolver($modules, [$provider]);
$observations = $resolver->collect($goal, $from, $to);
if (count($observations) !== 1
    || $observations['sales.won_deals']['value'] !== 3
    || $provider->observedTenant !== 'tenant-a'
    || isset($observations['sales.tasks_created'])) {
    throw new RuntimeException('Federation Domain resolver accepted unsupported evidence or lost tenant scope.');
}
$states->setEnabled('tenant-a', 'sales', false);
if ($resolver->collect($goal, $from, $to) !== []) {
    throw new RuntimeException('Federation read disabled Domain as active.');
}
$states->setEnabled('tenant-a', 'sales', true);
try {
    (new FederationTrustedOutcomeEvidenceResolver($modules, [$provider, $provider]))
        ->collect($goal, $from, $to);
    throw new RuntimeException('Duplicate Goal metric ownership bypassed trust policy.');
} catch (\DomainException) {
}
$provider->corrupt = true;
try {
    $resolver->collect($goal, $from, $to);
    throw new RuntimeException('Goal Domain provider could spoof the approved time window.');
} catch (\DomainException) {
}
try {
    $resolver->collect($goal, $to, $from);
    throw new RuntimeException('Negative Goal observation period accepted.');
} catch (\DomainException) {
}
echo "Federation trusted outcome resolver passed: tenant, disabled domain, unsupported, ownership, period.\n";
