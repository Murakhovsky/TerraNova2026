<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Routing;

use Domains\Growth\Application\Contract\GrowthConversationRoutingTargetInterface;
use Domains\Growth\Application\DTO\GrowthConversationRouteRequest;
use Domains\Growth\Application\DTO\GrowthConversationRoutingTargetResult;
use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Module\ActiveModuleResolver;
use RuntimeException;

final readonly class ServiceGrowthConversationRoutingTarget implements GrowthConversationRoutingTargetInterface
{
    public function __construct(
        private ServiceApplicationBoundary $service,
        private ActiveModuleResolver $modules,
    ) {}

    public function route():string{return 'service';}

    public function accept(
        GrowthConversationRouteRequest $request,int $actorId,string $correlationId,string $idempotencyKey
    ):GrowthConversationRoutingTargetResult {
        if(!$this->modules->isEnabled($request->organizationId,'service')){
            return GrowthConversationRoutingTargetResult::rejected('Service module is disabled for this organization.');
        }

        $summary=$request->summary;
        if(trim($request->requestedAction)!=='')$summary.="\n\nRequested action: ".$request->requestedAction;
        $summary=$this->limit($summary,500);

        $result=$this->service->createRequest(
            $request->organizationId,$actorId,$correlationId,$idempotencyKey,[
                'subject'=>$this->limit('Inbound Growth response — '.str_replace('_',' ',$request->intent),220),
                'summary'=>$summary,
                'requester_ref'=>$this->limit(
                    $request->contactId===null?'growth:candidate:'.$request->candidateId:'growth:contact:'.$request->contactId,
                    191,
                ),
            ],
        );
        $requestId=$result['request_id']??null;
        if(!is_string($requestId)||trim($requestId)===''){
            throw new RuntimeException('Service accepted Growth conversation route but did not return request_id.');
        }

        return GrowthConversationRoutingTargetResult::accepted(
            'service_request',trim($requestId),'Service accepted the inbound Growth conversation as a Service Request.',
        );
    }

    private function limit(string $value,int $limit):string
    {
        $value=trim($value);return mb_strlen($value)<=$limit?$value:rtrim(mb_substr($value,0,$limit-1)).'…';
    }
}
