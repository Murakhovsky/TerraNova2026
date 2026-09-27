<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CollectedSignal
{
    /** @param array<string,scalar|null> $facts */
    public function __construct(
        public string $externalKey,
        public string $subjectType,
        public string $subjectId,
        public string $signalType,
        public array $facts,
        public string $sourceReference,
        public float $confidence,
        public DateTimeImmutable $occurredAt,
    ) {
        foreach([
            'externalKey'=>$externalKey,'subjectType'=>$subjectType,'subjectId'=>$subjectId,
            'signalType'=>$signalType,'sourceReference'=>$sourceReference,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Collected Growth signal '.$field.' is required.');
        }
        if($facts===[]||array_is_list($facts))throw new InvalidArgumentException('Collected Growth signal facts must be a non-empty object.');
        foreach($facts as $key=>$value){
            if(!is_string($key)||$key===''||(!is_scalar($value)&&$value!==null)){
                throw new InvalidArgumentException('Collected Growth signal facts must contain scalar values.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Collected Growth signal confidence must be between 0 and 1.');
    }

    /** @return array<string,mixed> */
    public function fingerprintPayload(): array
    {
        return [
            'external_key'=>$this->externalKey,
            'subject_type'=>$this->subjectType,
            'subject_id'=>$this->subjectId,
            'signal_type'=>$this->signalType,
            'facts'=>$this->facts,
            'source_reference'=>$this->sourceReference,
            'confidence'=>$this->confidence,
            'occurred_at'=>$this->occurredAt->format(DATE_ATOM),
        ];
    }
}
