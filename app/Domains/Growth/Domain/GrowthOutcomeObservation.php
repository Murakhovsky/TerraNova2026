<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GrowthOutcomeObservation
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $candidateId,
        public string $sourceDomain,
        public string $sourceEventId,
        public string $referenceType,
        public string $referenceId,
        public GrowthOutcomeType $outcomeType,
        public ?string $reasonCode,
        public ?string $reasonText,
        public ?float $economicValue,
        public ?string $currency,
        public DateTimeImmutable $observedAt,
    ) {
        foreach([
            'id'=>$id,'candidateId'=>$candidateId,'sourceDomain'=>$sourceDomain,'sourceEventId'=>$sourceEventId,
            'referenceType'=>$referenceType,'referenceId'=>$referenceId,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth outcome '.$field.' is required.');
        }
        if($economicValue!==null&&$economicValue<0)throw new InvalidArgumentException('Growth outcome economicValue cannot be negative.');
        if($currency!==null&&!preg_match('/^[A-Z]{3,8}$/',$currency))throw new InvalidArgumentException('Growth outcome currency is invalid.');
        if($economicValue!==null&&$currency===null)throw new InvalidArgumentException('Growth outcome value requires currency.');
        if($reasonCode!==null&&trim($reasonCode)==='')throw new InvalidArgumentException('Growth outcome reasonCode is invalid.');
        if($reasonText!==null&&trim($reasonText)==='')throw new InvalidArgumentException('Growth outcome reasonText is invalid.');
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'outcome_id'=>$this->id,'candidate_id'=>$this->candidateId,'source_domain'=>$this->sourceDomain,
            'source_event_id'=>$this->sourceEventId,'reference_type'=>$this->referenceType,'reference_id'=>$this->referenceId,
            'outcome_type'=>$this->outcomeType->value,'reason_code'=>$this->reasonCode,'reason_text'=>$this->reasonText,
            'economic_value'=>$this->economicValue,'currency'=>$this->currency,'observed_at'=>$this->observedAt->format(DATE_ATOM),
        ];
    }
}
