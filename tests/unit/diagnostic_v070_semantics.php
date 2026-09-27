<?php
declare(strict_types=1);

use Domains\Diagnostic\Model\Assessment;
use Domains\Diagnostic\Model\AssessmentStatus;
use Domains\Diagnostic\Model\Fact;
use Domains\Diagnostic\Model\FactStatus;
use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Model\HypothesisStatus;
use Domains\Diagnostic\Model\Severity;
use Domains\Diagnostic\Model\TruthLevel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$now = new DateTimeImmutable('2026-09-28T00:00:00+00:00');
$fact = new Fact('fact-1','session-1','sales.pipeline.coverage',null,'number',FactStatus::Unknown,0.0,'bootstrap',[],$now,$now);
$fact = $fact->revise(40, FactStatus::Known, .8, 'CRM observation', 'CRM', ['ev-1'], $now->modify('+1 minute'), TruthLevel::Observed);
$fact = $fact->revise(45, FactStatus::Known, .9, 'Corrected CRM observation', 'CRM', ['ev-2'], $now->modify('+2 minutes'), TruthLevel::Observed);
$assert(count($fact->revisions) === 2, 'Fact revisions must be append-only.');
$assert($fact->revisions[1]->supersedesRevision === 1, 'Fact revision must point to the superseded revision.');
$assert($fact->revisions[1]->truthLevel === TruthLevel::Observed, 'Fact truth level was lost.');

$assessment = new Assessment('pipeline_health', AssessmentStatus::Warning, 62.5, .83, ['ev-2'], 'deterministic rule', Severity::High, .92);
$assert($assessment->coverage === .92 && $assessment->severity === Severity::High, 'Assessment dimensions are not independent.');

try {
    new Assessment('pipeline_health', AssessmentStatus::InsufficientData, 50, .4, ['ev-2'], 'insufficient evidence', Severity::None, .3);
    throw new RuntimeException('Insufficient-data assessment accepted a score.');
} catch (InvalidArgumentException) {
}

$hypothesis = new Hypothesis('hyp-1','Routing ownership causes response delay',HypothesisStatus::Unverified,.6,['ev-1','ev-2']);
$hypothesis = $hypothesis->transition(HypothesisStatus::Supported,.8);
$hypothesis = $hypothesis->transition(HypothesisStatus::StronglySupported,.9);
$hypothesis = $hypothesis->transition(HypothesisStatus::ConfirmedRootCause,.94);
$assert($hypothesis->isConfirmedRootCause(), 'Normative root-cause lifecycle failed.');

echo "Diagnostic V0.7 semantic model contract passed.\n";
