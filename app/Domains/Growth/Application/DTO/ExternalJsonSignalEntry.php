<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExternalJsonSignalEntry
{
    /** @param array<string,scalar|null> $facts */
    public function __construct(
        public string $externalId,
        public string $sourceReference,
        public array $facts,
        public DateTimeImmutable $occurredAt,
    ) {
        if(trim($externalId)===''||trim($sourceReference)===''){
            throw new InvalidArgumentException('Growth ExternalJsonSignalEntry requires id and source reference.');
        }
        if(mb_strlen($externalId)>1000||mb_strlen($sourceReference)>2000){
            throw new InvalidArgumentException('Growth ExternalJsonSignalEntry identity exceeds its limit.');
        }
        if($facts===[]||array_is_list($facts)||count($facts)>50){
            throw new InvalidArgumentException('Growth ExternalJsonSignalEntry facts must be a non-empty object with at most 50 fields.');
        }
        foreach($facts as $key=>$value){
            if(!is_string($key)||!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,79}$/',$key)||(!is_scalar($value)&&$value!==null)){
                throw new InvalidArgumentException('Growth ExternalJsonSignalEntry facts contain invalid data.');
            }
            if(is_string($value)&&mb_strlen($value)>4000){
                throw new InvalidArgumentException('Growth ExternalJsonSignalEntry fact value exceeds its limit.');
            }
        }
    }
}
