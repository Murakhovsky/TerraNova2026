<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/symfony/src/Persistence/Federation/FederationTrustedOutcomeEvidenceResolver.php';

use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use Domains\Growth\Application\Contract\GrowthInboundResponseOutcomeReadModelInterface;
use Domains\Growth\Application\Service\GrowthInboundResponseOutcomeEvidenceProvider;
use Domains\CapitalMarkets\Application\Contract\CapitalResearchValidatedOutcomeReadModelInterface;
use Domains\CapitalMarkets\Application\Service\CapitalResearchValidatedOutcomeEvidenceProvider;
use Platform\Documents\Contract\DocumentSignatureOutcomeReadModelInterface;
use Platform\Documents\Service\DocumentSignatureOutcomeEvidenceProvider;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;
use Platform\Orchestration\Goal\GoalSpecification;

$growth = new class implements GrowthInboundResponseOutcomeReadModelInterface {
    public string $organization = '';
    public int $count = 2;
    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int {
        $this->organization = $organizationId;
        return $this->count;
    }
};
$research = new class implements CapitalResearchValidatedOutcomeReadModelInterface {
    public string $organization = '';
    public int $count = 1;
    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int {
        $this->organization = $organizationId;
        return $this->count;
    }
};
$documents = new class implements DocumentSignatureOutcomeReadModelInterface {
    public string $organization = '';
    public int $count = 1;
    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int {
        $this->organization = $organizationId;
        return $this->count;
    }
};
$growthProvider = new GrowthInboundResponseOutcomeEvidenceProvider($growth);
$researchProvider = new CapitalResearchValidatedOutcomeEvidenceProvider($research);
$documentsProvider = new DocumentSignatureOutcomeEvidenceProvider($documents);
foreach ([
    [$growthProvider, 'growth.inbound_responses_recorded', 'growth'],
    [$researchProvider, 'capital_markets.research_results_validated', 'capital_markets'],
    [$documentsProvider, 'documents.signatures_recorded', 'platform.documents'],
] as [$provider, $criterion, $module]) {
    if (!$provider->supports($criterion) || $provider->supports('tasks_created') || $provider->domain() !== $module) {
        throw new RuntimeException('Wrong Domain owns cross-domain Goal outcome criterion.');
    }
}
$states = new class implements ModuleStateRepositoryInterface {
    private array $enabled = [];
    public function enabledOverride(string $organizationId, string $moduleId): ?bool {
        return $this->enabled[$organizationId . ':' . $moduleId] ?? null;
    }
    public function setEnabled(string $organizationId, string $moduleId, bool $value): void {
        $this->enabled[$organizationId . ':' . $moduleId] = $value;
    }
    public function enable(string $organizationId, string $moduleId, bool $value): void {
        $this->setEnabled($organizationId, $moduleId, $value);
    }
};
$states->enable('org-one', 'growth', true);
$states->enable('org-one', 'capital_markets', true);
$modules = new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth', 'Growth', '1.0.0', enabledByDefault: false),
    new ModuleManifest('capital_markets', 'Capital Markets', '1.0.0', enabledByDefault: false),
]), $states);
$resolver = new FederationTrustedOutcomeEvidenceResolver($modules, [
    $growthProvider, $researchProvider, $documentsProvider,
]);
$criteria = [
    ['id' => 'growth.inbound_responses_recorded', 'operator' => 'at_least', 'expected' => 2],
    ['id' => 'capital_markets.research_results_validated', 'operator' => 'at_least', 'expected' => 1],
    ['id' => 'documents.signatures_recorded', 'operator' => 'at_least', 'expected' => 1],
];
$from = new DateTimeImmutable('2026-10-01T09:00:00.250000+00:00');
$to = new DateTimeImmutable('2026-10-02T12:00:00.750000+00:00');
$spec = new GoalSpecification('goal-x', 'org-one', 'user-one', 'Verify three independent domain outcomes', $criteria, []);
$observations = $resolver->collect($spec, $from, $to);
$evaluation = (new GoalOutcomeEvaluator())->evaluate($spec, $observations);
if ($evaluation['result'] !== 'satisfied'
    || count($observations) !== 3
    || $growth->organization !== 'org-one'
    || $research->organization !== 'org-one'
    || $documents->organization !== 'org-one') {
    throw new RuntimeException('Growth, Research and Documents did not jointly verify independent business outcomes.');
}
foreach ($evaluation['criteria'] as $criterion) {
    $value = $observations[$criterion['criterion_id']];
    if ($criterion['source'] !== $value['source']
        || $criterion['window_start'] !== $from->format('Y-m-d\TH:i:s.uP')
        || $criterion['window_end'] !== $to->format('Y-m-d\TH:i:s.uP')
        || count($criterion['evidence']) !== 1) {
        throw new RuntimeException('Cross-domain result lost Domain-owned source or UTC provenance.');
    }
}
$states->enable('org-one', 'growth', false);
$disabled = $resolver->collect($spec, $from, $to);
if (isset($disabled['growth.inbound_responses_recorded'])
    || !isset($disabled['capital_markets.research_results_validated'], $disabled['documents.signatures_recorded'])) {
    throw new RuntimeException('Disabled Growth Domain still contributed trusted observations.');
}
$foreignSpec = new GoalSpecification('goal-foreign', 'org-other', 'user-other',
    'Foreign tenant cannot share Growth or Research state', $criteria, []);
$foreign = $resolver->collect($foreignSpec, $from, $to);
if (count($foreign) !== 1 || !isset($foreign['documents.signatures_recorded'])
    || $documents->organization !== 'org-other') {
    throw new RuntimeException('Tenant module isolation or Platform Documents scope regressed.');
}
$documents->count = -1;
try {
    $resolver->collect($foreignSpec, $from, $to);
    throw new RuntimeException('Invalid Domain-owned Documents value was accepted.');
} catch (DomainException) {
}
try {
    $resolver->collect($spec, $to, $from);
    throw new RuntimeException('Reversed evidence window was accepted.');
} catch (DomainException) {
}
echo "Federation cross-domain Growth/Research/Documents outcomes: trusted scope and policies passed.\n";
