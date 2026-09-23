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
        $proposal=new ActionProposal(
            type:'sales.send_message',
            targetType:'deal',
            targetId:$dealId,
            parameters:[
                'channel'=>strtoupper($channel),'body'=>$body,
                'growth_candidate_id'=>$candidateId,'growth_recommendation_id'=>$recommendationId,
            ],
            sourceType:'GROWTH',
            sourceId:$recommendationId,
            executionMode:'APPROVAL_REQUIRED',
            riskLevel:'MEDIUM',
            idempotencyKey:$kernelIdempotencyKey,
            policyContext:[
                'actor'=>['role'=>'USER','user_id'=>(string)$actorId],
                'growth'=>[
                    'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,
                    'channel'=>$channel,'confidence'=>$confidence,
                ],
            ],
        );
        return $this->map($this->policies->submit($organizationId,$proposal,$correlationId));
    }

    public function find(string $organizationId,string $actionId):?GrowthExecutionAction
    {
        $action=$this->actions->find($organizationId,$actionId);
        return $action===null?null:$this->map($action);
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
