<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Application\DTO\OperationResult;

/** Legacy operations delegate to the same native implementation under current transactional authority. */
final readonly class GuardedSalesWriteService implements SalesWriteServiceInterface
{
    public function __construct(private string $org,private SalesWriteServiceInterface $native,
        private SalesMutationGuard $guard,private SalesMutationAuthorityInterface $authority) {}

    public function receivePublicLead(array $input,string $sourcePage,?int $actorId=null,?string $correlationId=null):ClientCaseCommandResult
    {
        // Public intake has a separate native security contract, not a human manager mutation.
        return $this->native->receivePublicLead($input,$sourcePage,$actorId,$correlationId);
    }

    public function createOpportunity(array $input,int $actorId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            function()use($input,$actorId):ClientCaseCommandResult{
                $this->contact($input,$actorId);
                return $this->native->createOpportunity([...$input,'assigned_user_id'=>$this->owner($input,$actorId)],$actorId);
            },targetOwner:$this->owner($input,$actorId)));
    }

    public function updateOpportunity(int $opportunityId,array $input,int $actorId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            function()use($opportunityId,$input,$actorId):ClientCaseCommandResult{
                $this->authority->casePerson($this->org,$actorId,$opportunityId);
                $this->contact($input,$actorId);
                return $this->native->updateOpportunity($opportunityId,$input,$actorId);
            },'deal',$opportunityId,$this->owner($input)));
    }

    public function attachInboundRequest(int $opportunityId,int $leadId,int $actorId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,fn()=>
            $this->guard->run($this->org,$actorId,fn()=>$this->native->attachInboundRequest($opportunityId,$leadId,$actorId),
                'deal',$opportunityId),'lead',$leadId));
    }

    public function addOpportunityPropertyMatch(int $caseId,int $propertyId,array $input,int $actorId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            fn()=>$this->native->addOpportunityPropertyMatch($caseId,$propertyId,$input,$actorId),'deal',$caseId));
    }

    public function updateOpportunityPropertyMatch(int $matchId,array $input,int $actorId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,fn()=>
            $this->guard->run($this->org,$actorId,fn()=>$this->native->updateOpportunityPropertyMatch($matchId,$input,$actorId),
                'deal',$this->authority->propertyMatchDeal($this->org,$matchId))));
    }

    public function createLead(array $input,int $actorId,string $correlationId,string $idempotencyKey):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,function()use($input,$actorId,$correlationId,$idempotencyKey):ClientCaseCommandResult{
            $result=$this->native->createLead($input,$actorId,$correlationId,$idempotencyKey);
            if ($result->ok) $this->guard->run($this->org,$actorId,static fn()=>null,'lead',(int)($result->data['lead_id']??0));
            return $result;
        },targetOwner:$this->owner($input,$actorId)));
    }

    public function updateLead(int $leadId,array $input,int $actorId,string $correlationId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            fn()=>$this->native->updateLead($leadId,$input,$actorId,$correlationId),'lead',$leadId,$this->owner($input)));
    }

    public function convertLeadToOpportunity(int $leadId,array $input,int $actorId,string $correlationId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            function()use($leadId,$input,$actorId,$correlationId):ClientCaseCommandResult{
                $this->authority->leadContact($this->org,$actorId,$leadId);
                return $this->native->convertLeadToOpportunity($leadId,[...$input,'assigned_user_id'=>$this->owner($input,$actorId)],$actorId,$correlationId);
            },'lead',$leadId,$this->owner($input,$actorId)));
    }

    public function addOpportunityActivity(int $opportunityId,array $input,int $actorId,string $correlationId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            fn()=>$this->native->addOpportunityActivity($opportunityId,$input,$actorId,$correlationId),'deal',$opportunityId));
    }

    public function quickUpdateOpportunity(int $opportunityId,array $input,int $actorId,string $correlationId):ClientCaseCommandResult
    {
        return $this->result(fn()=>$this->guard->run($this->org,$actorId,
            fn()=>$this->native->quickUpdateOpportunity($opportunityId,$input,$actorId,$correlationId),'deal',$opportunityId,$this->owner($input)));
    }

    public function changeOpportunityStage(int $opportunityId,string $targetStageId,int $actorId,string $correlationId,
        ?string $lostReasonId=null,?string $lostReasonNote=null):ChangeDealStageResult
    {
        try {return $this->guard->run($this->org,$actorId,
            fn()=>$this->native->changeOpportunityStage($opportunityId,$targetStageId,$actorId,$correlationId,$lostReasonId,$lostReasonNote),
            'deal',$opportunityId);}
        catch (SalesMutationDenied) {return ChangeDealStageResult::failure('sales_access_denied');}
    }

    public function scheduleNextAction(int $opportunityId,string $title,?string $body,DateTimeImmutable $dueAt,int $actorId,
        string $correlationId,string $idempotencyKey):OperationResult
    {
        try {return $this->guard->run($this->org,$actorId,
            fn()=>$this->native->scheduleNextAction($opportunityId,$title,$body,$dueAt,$actorId,$correlationId,$idempotencyKey),
            'deal',$opportunityId);}
        catch (SalesMutationDenied) {return OperationResult::failure('sales_access_denied');}
    }

    private function result(callable $operation):ClientCaseCommandResult
    {
        try {return $operation();}
        catch (SalesMutationDenied) {return ClientCaseCommandResult::failure('sales_access_denied');}
    }

    private function contact(array $input,int $actor):void
    {
        $this->authority->contact($this->org,$actor,
            \Domains\Sales\Application\Support\ClientCaseInput::email((string)($input['email']??'')),
            \Domains\Sales\Application\Support\ClientCaseInput::nullable((string)($input['phone']??''),50));
    }

    private function owner(array $input,?int $default=null):?int
    {
        $owner=null;
        foreach (['owner_id','assigned_user_id'] as $key) {
            if (!array_key_exists($key,$input)) continue;
            $value=$input[$key];
            if ((!is_int($value)&&!is_string($value))||!ctype_digit((string)$value)||(int)$value<1||
                (string)(int)$value!==(string)$value||($owner!==null&&$owner!==(int)$value))
                throw new SalesMutationDenied('Sales mutation denied.');
            $owner=(int)$value;
        }
        return $owner??$default;
    }
}
