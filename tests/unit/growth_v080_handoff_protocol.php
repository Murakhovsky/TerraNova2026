<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DomainException;
use Domains\Growth\Application\Contract\GrowthHandoffTargetInterface;
use Domains\Growth\Application\DTO\HandoffTargetResult;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Growth\Application\Service\GrowthHandoffTargetRegistry;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use Domains\Growth\Domain\OpportunityRationale;
use Domains\Growth\Domain\OpportunityScore;
use Domains\Growth\Domain\OpportunityType;
use Domains\Growth\Domain\ScoreDimension;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV080(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$dimension=new ScoreDimension(80,'Evidence',['signal-1'],'score-v1');
$score=new OpportunityScore($dimension,$dimension,$dimension,$dimension,$dimension,0.9);
$rationale=new OpportunityRationale(
    'Material change','Operational gap','Current leadership change',['signal-1'],[],[],[],0.85,
);
$candidate=OpportunityCandidate::restore(
    'candidate-1',OrganizationId::fromString('org-1'),OpportunityType::CustomerAcquisition,GrowthMode::Acquire,
    'account','account-1','sales',['signal-1'],OpportunityCandidateStatus::Qualified,$rationale,$score,'qualified',
);
$candidate->prepareHandoff('Implementation value','diagnostic','schedule diagnostic');
$handoff=OpportunityHandoff::fromCandidate($candidate);
$roundTrip=OpportunityHandoff::fromArray($handoff->toArray());
expectGrowthV080($roundTrip->candidateId==='candidate-1'&&$roundTrip->targetDomain==='sales','Growth handoff package round-trip failed.');

$candidate->startHandoffDispatch();
expectGrowthV080($candidate->status()===OpportunityCandidateStatus::HandoffPending,'Growth candidate must enter handoff_pending during dispatch.');
try{
    $candidate->disqualify('late manual decision');
    throw new RuntimeException('Growth candidate must not be disqualified while handoff is pending.');
}catch(DomainException){}
$candidate->markHandoffDispatchFailed();
expectGrowthV080($candidate->status()===OpportunityCandidateStatus::ReadyForHandoff,'Technical handoff failure must restore ready_for_handoff.');
$candidate->startHandoffDispatch();
$candidate->markHandedOff();
expectGrowthV080($candidate->status()===OpportunityCandidateStatus::HandedOff,'Accepted target handoff must mark candidate handed_off.');

$rejectedCandidate=OpportunityCandidate::restore(
    'candidate-2',OrganizationId::fromString('org-1'),OpportunityType::CustomerAcquisition,GrowthMode::Acquire,
    'account','account-2','sales',['signal-1'],OpportunityCandidateStatus::Qualified,$rationale,$score,'qualified',
);
$rejectedCandidate->prepareHandoff('Implementation value','diagnostic','schedule diagnostic');
$rejectedCandidate->startHandoffDispatch();
$rejectedCandidate->markRejectedByTargetDomain('Target policy declined.');
expectGrowthV080(
    $rejectedCandidate->status()===OpportunityCandidateStatus::RejectedByTargetDomain,
    'Rejected target handoff must mark candidate rejected_by_target_domain.'
);

$target=new class implements GrowthHandoffTargetInterface {
    public function domain(): string { return 'sales'; }
    public function accept(OpportunityHandoff $handoff,int $actorId,string $correlationId,string $idempotencyKey): HandoffTargetResult
    {
        return HandoffTargetResult::accepted('sales_opportunity','42');
    }
};
$registry=new GrowthHandoffTargetRegistry([$target]);
expectGrowthV080($registry->domains()===['sales'],'Growth handoff target registry failed.');
expectGrowthV080($registry->get('sales')===$target,'Growth handoff target lookup failed.');

$accepted=HandoffTargetResult::accepted('sales_opportunity','42');
$rejected=HandoffTargetResult::rejected('Target policy declined the opportunity.');
expectGrowthV080($accepted->accepted&&$accepted->referenceId==='42','Growth accepted target result is invalid.');
expectGrowthV080(!$rejected->accepted&&$rejected->referenceId===null,'Growth rejected target result is invalid.');

echo "Growth V0.8 Handoff Protocol contracts passed.\n";
