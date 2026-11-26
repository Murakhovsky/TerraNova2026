<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;

use App\Persistence\Workflow\FailClosedWorkflowActionWorkerAdmission;
use DomainException;
use Domains\Sales\Application\Contract\SalesStageApprovalVerifier;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\Support\DealStateSnapshot;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesApprovalAuthority;
use Kernel\Action\ActionStatus;

final readonly class CanonicalSalesStageApprovalVerifier implements SalesStageApprovalVerifier
{
    public function __construct(private FailClosedWorkflowActionWorkerAdmission $admission,
        private MysqlSalesApprovalAuthority $approval,private SalesMutationAuthorityInterface $authority){}
    public function assertApproved(ChangeDealStageCommand $c,array $deal):void
    {
        $a=$c->approvedAction;
        $type=$c->expectedOutcome===null?'sales.governed.pipeline.stage.manage':'sales.governed.deal.outcome.record';
        if($a===null||$a->status!==ActionStatus::Running||$a->type!==$type||$a->organizationId!==$c->organizationId
            ||$a->sourceType!=='USER'||$c->actorType!=='USER'||$a->sourceId!==$c->actorId
            ||$a->correlationId!==$c->correlationId||$a->targetType!=='deal'||$a->targetId!==$c->dealId
            ||!ctype_digit($c->actorId)||(int)$c->actorId<1||$c->lostReasonNote!==null)
            throw new DomainException('Stage Action identity mismatch.');
        $state=DealStateSnapshot::fromNative($deal,$c->organizationId,(int)$c->dealId);
        $p=['deal_id'=>(int)$c->dealId,'expected_revision'=>$state['revision'],'target_stage_id'=>$c->targetStageId];
        if($c->expectedOutcome!==null)$p['outcome']=$c->expectedOutcome;
        if($c->lostReasonId!==null)$p['lost_reason_id']=$c->lostReasonId;
        $approved=$a->parameters;ksort($p);ksort($approved);
        if($approved!==$p||$c->expectedStageId!==$state['stage_id'])
            throw new DomainException('Stage approval no longer matches native state and pinned parameters.');
        $this->admission->assertAuthorized($a);
        $this->approval->assertApprovedBusinessExecution($a);
        $this->authority->actor($c->organizationId,(int)$c->actorId);
        $this->authority->entity($c->organizationId,(int)$c->actorId,'deal',(int)$c->dealId);
    }
}
