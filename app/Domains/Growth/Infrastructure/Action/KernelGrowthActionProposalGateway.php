<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Action;

use Domains\Growth\Application\Contract\GrowthActionProposalGatewayInterface;
use Domains\Growth\Application\DTO\GrowthExecutionAction;
use Kernel\Action\Action;
use Kernel\Action\ActionProposal;
use Kernel\Action\Service\ActionService;
use Kernel\Policy\Service\ActionPolicyService;

final readonly class KernelGrowthActionProposalGateway implements GrowthActionProposalGatewayInterface
{
    public function __construct(
        private ActionPolicyService $policies,
        private ActionService $actions,
    ) {}

    public function proposeSalesMessage(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $dealId,string $channel,string $body,?float $confidence,string $kernelIdempotencyKey
    ):GrowthExecutionAction {
        return $this->submit(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,
            'sales.send_message','deal',$dealId,strtolower($channel),$body,$confidence,$kernelIdempotencyKey,'post_handoff',
        );
    }

    public function proposeGrowthMessage(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $contactId,string $channel,string $body,?float $confidence,string $kernelIdempotencyKey
    ):GrowthExecutionAction {
        return $this->submit(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,
            'growth.send_message','growth_contact',$contactId,strtolower($channel),$body,$confidence,$kernelIdempotencyKey,'pre_handoff',
        );
    }

    public function proposeGrowthLinkedIn(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $contactId,string $body,?float $confidence,string $kernelIdempotencyKey
    ):GrowthExecutionAction {
        return $this->submit(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,
            'growth.send_linkedin','growth_contact',$contactId,'linkedin',$body,$confidence,$kernelIdempotencyKey,'pre_handoff',
        );
    }

    public function proposeGrowthCall(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $contactId,string $callBrief,?float $confidence,string $kernelIdempotencyKey
    ):GrowthExecutionAction {
        return $this->submit(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,
            'growth.place_call','growth_contact',$contactId,'phone',$callBrief,$confidence,$kernelIdempotencyKey,'pre_handoff',
        );
    }

    public function find(string $organizationId,string $actionId):?GrowthExecutionAction
    {
        $action=$this->actions->find($organizationId,$actionId);
        return $action===null?null:$this->map($action);
    }

    private function submit(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $actionType,string $targetType,string $targetId,string $channel,string $body,?float $confidence,
        string $kernelIdempotencyKey,string $stage
    ):GrowthExecutionAction {
        $proposal=new ActionProposal(
            type:$actionType,
            targetType:$targetType,
            targetId:$targetId,
            parameters:[
                'channel'=>$actionType==='sales.send_message'?strtoupper($channel):$channel,
                'body'=>$body,
                'growth_candidate_id'=>$candidateId,
                'growth_recommendation_id'=>$recommendationId,
            ],
            sourceType:'GROWTH',
            sourceId:$recommendationId,
            executionMode:'APPROVAL_REQUIRED',
            riskLevel:'MEDIUM',
            idempotencyKey:$kernelIdempotencyKey,
            policyContext:[
                'actor'=>['role'=>'USER','user_id'=>(string)$actorId],
                'growth'=>[
                    'candidate_id'=>$candidateId,
                    'recommendation_id'=>$recommendationId,
                    'contact_id'=>$targetType==='growth_contact'?$targetId:null,
                    'channel'=>$channel,
                    'confidence'=>$confidence,
                    'stage'=>$stage,
                ],
            ],
        );
        return $this->map($this->policies->submit($organizationId,$proposal,$correlationId));
    }

    private function map(Action $action):GrowthExecutionAction
    {
        return new GrowthExecutionAction(
            $action->id,$action->type,$action->targetType,$action->targetId,$action->sourceType,$action->sourceId,
            $action->executionMode,$action->riskLevel,$action->status->value,$action->createdAt->format(DATE_ATOM),
            $action->executedAt?->format(DATE_ATOM),
        );
    }
}
