<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Routing;

use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthConversationRoutingTargetInterface;
use Domains\Growth\Application\DTO\GrowthConversationRouteRequest;
use Domains\Growth\Application\DTO\GrowthConversationRoutingTargetResult;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use RuntimeException;

final readonly class SalesGrowthConversationRoutingTarget implements GrowthConversationRoutingTargetInterface
{
    public function __construct(
        private GrowthBuyingCommitteeRepositoryInterface $contacts,
        private SalesWriteServiceFactoryInterface $sales,
        private ActiveModuleResolver $modules,
    ) {}

    public function route():string{return 'sales';}

    public function accept(
        GrowthConversationRouteRequest $request,int $actorId,string $correlationId,string $idempotencyKey
    ):GrowthConversationRoutingTargetResult {
        if(!$this->modules->isEnabled($request->organizationId,'sales')){
            return GrowthConversationRoutingTargetResult::rejected('Sales module is disabled for this organization.');
        }
        if($request->contactId===null){
            return GrowthConversationRoutingTargetResult::rejected('Sales conversation routing requires an authoritative Growth contact.');
        }
        $contact=$this->contacts->viewContact($request->organizationId,$request->contactId);
        if($contact===null)return GrowthConversationRoutingTargetResult::rejected('Growth contact no longer exists.');

        $identityType=strtolower(trim((string)($contact['identity_type']??'')));
        $identityValue=trim((string)($contact['identity_value']??''));
        $fullName=trim((string)($contact['full_name']??''));
        if($identityType!=='email'||filter_var($identityValue,FILTER_VALIDATE_EMAIL)===false||$fullName===''){
            return GrowthConversationRoutingTargetResult::rejected('Sales intake requires a Growth contact with full name and valid email identity.');
        }

        $message=$request->summary;
        if(trim($request->requestedAction)!=='')$message.="\n\nRequested action: ".$request->requestedAction;
        $managerNote=implode("\n",[
            'Growth conversation route: '.$request->routeId,
            'Growth candidate: '.$request->candidateId,
            'Response intent: '.$request->intent,
            'Urgency: '.$request->urgency,
            'Candidate target domain: '.($request->candidateTargetDomain??'unknown'),
        ]);

        $result=$this->sales->forOrganization($request->organizationId)->createLead([
            'full_name'=>$fullName,'email'=>$identityValue,'role'=>'other','deal_type'=>'consultation',
            'message'=>$message,'source'=>'growth-conversation','request_intent'=>'general_contact',
            'manager_note'=>$managerNote,
        ],$actorId,$correlationId,$idempotencyKey);

        if(!$result->ok){
            if($result->code==='idempotency_conflict')throw new RuntimeException('Sales conversation routing hit an idempotency conflict.');
            return GrowthConversationRoutingTargetResult::rejected('Sales intake rejected Growth conversation route: '.$result->code);
        }
        $leadId=$result->data['lead_id']??null;
        if(!is_int($leadId)&&!(is_string($leadId)&&ctype_digit($leadId))){
            throw new RuntimeException('Sales accepted Growth conversation route but did not return lead_id.');
        }
        return GrowthConversationRoutingTargetResult::accepted(
            'sales_lead',(string)(int)$leadId,'Sales accepted the inbound Growth conversation as a Lead.',
        );
    }
}
