<?php
declare(strict_types=1);

use Domains\Growth\Application\UseCase\PrepareOpportunityHandoff;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use Domains\Growth\Domain\OpportunityRationale;
use Domains\Growth\Domain\OpportunityScore;
use Domains\Growth\Domain\OpportunityType;
use Domains\Growth\Domain\ScoreDimension;
use Domains\Growth\Domain\Signal;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$org = OrganizationId::fromString('org-growth-test');
$occurredAt = new DateTimeImmutable('2026-09-21T12:00:00+00:00');
$detectedAt = new DateTimeImmutable('2026-09-21T12:05:00+00:00');

$signal = new Signal(
    id: 'signal-1',
    organizationId: $org,
    subjectType: 'company',
    subjectId: 'acme',
    signalType: 'executive_change',
    facts: ['role' => 'COO', 'change' => 'appointed'],
    sourceReference: 'source:acme-news',
    confidence: 0.95,
    occurredAt: $occurredAt,
    detectedAt: $detectedAt,
);
$assert($signal->facts['role'] === 'COO', 'Growth Signal must preserve observed facts.');

$candidate = OpportunityCandidate::detect(
    id: 'growth-candidate-1',
    organizationId: $org,
    type: OpportunityType::CustomerAcquisition,
    mode: GrowthMode::Acquire,
    subjectType: 'company',
    subjectId: 'acme',
    targetDomain: 'sales',
    signalIds: [$signal->id],
);
$assert($candidate->status() === OpportunityCandidateStatus::Detected, 'Detected Growth candidate has wrong initial status.');

try {
    $dimension = new ScoreDimension(80, 'premature', ['signal-1'], 'growth-v1');
    $candidate->applyScore(new OpportunityScore($dimension, $dimension, $dimension, $dimension, $dimension, 0.8));
    throw new RuntimeException('Growth candidate accepted scoring before research.');
} catch (DomainException) {
}

$candidate->startEnrichment();
$candidate->markResearched(new OpportunityRationale(
    whyItMatters: 'Leadership transition can create a process redesign window.',
    problemHypothesis: 'The company may need to standardize scaling sales operations.',
    whyNow: 'A new COO joined while RevOps capacity is changing.',
    evidenceIds: ['signal-1'],
    counterEvidenceIds: [],
    assumptions: ['COO owns operational transformation'],
    unknowns: ['Current budget', 'Existing transformation program'],
    confidence: 0.78,
));
$assert($candidate->status() === OpportunityCandidateStatus::Researched, 'Growth research transition failed.');

$fit = new ScoreDimension(92, 'Company matches target profile.', ['signal-1'], 'growth-fit-v1');
$need = new ScoreDimension(81, 'Observed change suggests operational pressure.', ['signal-1'], 'growth-need-v1');
$timing = new ScoreDimension(94, 'Leadership transition is recent.', ['signal-1'], 'growth-timing-v1');
$access = new ScoreDimension(65, 'No warm path confirmed yet.', ['signal-1'], 'growth-access-v1');
$value = new ScoreDimension(84, 'Potential cross-functional implementation.', ['signal-1'], 'growth-value-v1');

$candidate->applyScore(new OpportunityScore($fit, $need, $timing, $access, $value, 0.82));
$assert($candidate->status() === OpportunityCandidateStatus::Scored, 'Growth scoring transition failed.');

$candidate->qualify('Evidence supports active research and a timely commercial conversation.');
$assert($candidate->status() === OpportunityCandidateStatus::Qualified, 'Growth qualification transition failed.');

$handoff = (new PrepareOpportunityHandoff())->execute(
    $candidate,
    expectedValue: 'Potential multi-domain COS implementation',
    recommendedPlay: 'sales_diagnostic',
    recommendedAction: 'research_buying_committee',
);
$assert($candidate->status() === OpportunityCandidateStatus::ReadyForHandoff, 'Growth handoff preparation transition failed.');
$assert($handoff->targetDomain === 'sales', 'Growth handoff lost target Domain.');
$assert($handoff->whyNow !== '', 'Growth handoff must preserve WHY NOW.');
$assert(($handoff->scores['timing']['score'] ?? null) === 94, 'Growth handoff lost explainable score dimensions.');

$candidate->startHandoffDispatch();
$assert($candidate->status() === OpportunityCandidateStatus::HandoffPending, 'Growth candidate did not enter handoff-pending state.');
$candidate->markHandedOff();
$assert($candidate->status() === OpportunityCandidateStatus::HandedOff, 'Growth candidate did not enter handed-off state.');

try {
    $candidate->disqualify('Growth should no longer own this decision.');
    throw new RuntimeException('Growth disqualified a candidate after handoff.');
} catch (DomainException) {
}

$manifest = ModuleDefinition::fromArray(require dirname(__DIR__, 2) . '/app/Domains/Growth/module.php');
$assert($manifest->manifest->id === 'growth', 'Growth module id is invalid.');
$assert($manifest->manifest->enabledByDefault === false, 'Growth V0.1 must remain disabled by default.');
$assert($manifest->contributions->runtimeModuleService === 'growthDomainModule', 'Growth runtime module contribution is missing.');
foreach ([
    'growth.signal.detect',
    'growth.candidate.research',
    'growth.candidate.score',
    'growth.candidate.qualify',
    'growth.handoff.prepare',
    'growth.candidate.monitor',
] as $capability) {
    $assert(in_array($capability, $manifest->contributions->capabilities, true), 'Growth capability missing: ' . $capability);
}

echo "Growth V0.1 domain foundation passed.\n";
