<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Handoff;

use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthHandoffTargetInterface;
use Domains\Growth\Application\DTO\HandoffTargetResult;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use InvalidArgumentException;
use RuntimeException;

final readonly class SalesGrowthHandoffTarget implements GrowthHandoffTargetInterface
{
    public function __construct(
        private GrowthBuyingCommitteeRepositoryInterface $contacts,
        private SalesWriteServiceFactoryInterface $sales,
    ) {}

    public function domain(): string
    {
        return 'sales';
    }

    public function accept(
        OpportunityHandoff $handoff,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
    ): HandoffTargetResult {
        if($handoff->targetDomain!==$this->domain()){
            throw new InvalidArgumentException('Sales Growth handoff target received a package for another Domain.');
        }

        $contact=$this->resolveContact($handoff);
        if($contact instanceof HandoffTargetResult)return $contact;

        $identityType=strtolower(trim((string)($contact['identity_type']??'')));
        $identityValue=trim((string)($contact['identity_value']??''));
        if($identityType!=='email'){
            return HandoffTargetResult::rejected('Sales handoff requires an email identity for the selected Growth contact.');
        }
        if(filter_var($identityValue,FILTER_VALIDATE_EMAIL)===false){
            return HandoffTargetResult::rejected('Selected Growth contact email identity is invalid for Sales intake.');
        }
        $fullName=trim((string)($contact['full_name']??''));
        if($fullName===''){
            return HandoffTargetResult::rejected('Selected Growth contact has no usable full name for Sales intake.');
        }

        $message=implode("\n\n",[
            $handoff->whyItMatters,
            'Problem hypothesis: '.$handoff->problemHypothesis,
            'Why now: '.$handoff->whyNow,
        ]);
        $managerNote=implode("\n",[
            'Growth candidate: '.$handoff->candidateId,
            'Growth mode: '.$handoff->growthMode,
            'Opportunity type: '.$handoff->opportunityType,
            'Expected value: '.$handoff->expectedValue,
            'Recommended play: '.$handoff->recommendedPlay,
            'Recommended action: '.$handoff->recommendedAction,
            'Score snapshot: '.json_encode($handoff->scores,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
        ]);

        $result=$this->sales->forOrganization($handoff->organizationId)->createLead([
            'full_name'=>$fullName,
            'email'=>$identityValue,
            'role'=>'other',
            'deal_type'=>'consultation',
            'message'=>$message,
            'source'=>'growth-handoff',
            'request_intent'=>'general_contact',
            'manager_note'=>$managerNote,
        ],$actorId,$correlationId,$idempotencyKey);

        if(!$result->ok){
            if($result->code==='idempotency_conflict'){
                throw new RuntimeException('Sales Growth handoff hit an idempotency conflict.');
            }
            return HandoffTargetResult::rejected('Sales intake rejected Growth handoff: '.$result->code);
        }

        $leadId=$result->data['lead_id']??null;
        if(!is_int($leadId)&&!(is_string($leadId)&&ctype_digit($leadId))){
            throw new RuntimeException('Sales accepted Growth handoff but did not return lead_id.');
        }
        if((int)$leadId<1)throw new RuntimeException('Sales returned an invalid lead_id for Growth handoff.');

        return HandoffTargetResult::accepted(
            'sales_lead',(string)(int)$leadId,'Sales accepted Growth opportunity as an inbound Lead.',
        );
    }

    /** @return array<string,mixed>|HandoffTargetResult */
    private function resolveContact(OpportunityHandoff $handoff): array|HandoffTargetResult
    {
        if($handoff->subjectType==='contact'){
            return $this->contacts->viewContact($handoff->organizationId,$handoff->subjectId)
                ?? HandoffTargetResult::rejected('Growth contact referenced by the handoff no longer exists.');
        }

        if($handoff->subjectType!=='account'){
            return HandoffTargetResult::rejected(
                'Sales handoff supports Growth subject_type account or contact; received '.$handoff->subjectType.'.'
            );
        }

        $assessment=$this->contacts->latestAssessment($handoff->organizationId,$handoff->subjectId);
        if($assessment===null){
            return HandoffTargetResult::rejected('Sales account handoff requires a Buying Committee assessment.');
        }

        $champions=$assessment['champion_contact_ids']??[];
        if(!is_array($champions)||!array_is_list($champions)){
            throw new RuntimeException('Stored Growth Buying Committee champion list is invalid.');
        }
        $champions=array_values(array_unique(array_filter(array_map(
            static fn(mixed $value):string=>is_string($value)?trim($value):'',
            $champions,
        ),static fn(string $value):bool=>$value!=='')));

        if(count($champions)!==1){
            return HandoffTargetResult::rejected(
                'Sales account handoff requires exactly one explicit Growth champion; found '.count($champions).'.'
            );
        }

        return $this->contacts->viewContact($handoff->organizationId,$champions[0])
            ?? HandoffTargetResult::rejected('Growth champion referenced by the Buying Committee no longer exists.');
    }
}
