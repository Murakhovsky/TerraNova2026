<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\DomainEvent;

abstract readonly class AbstractCapitalMarketsEvent implements DomainEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        private string $id,
        private DateTimeImmutable $occurredAt,
        public string $organizationId='system',
        public string $aggregateId='unknown',
        public array $payload=[],
        public int $schemaVersion=1,
    ){
        foreach(['event id'=>$this->id,'organization id'=>$this->organizationId,'aggregate id'=>$this->aggregateId] as $label=>$value){
            if($value===''||trim($value)!==$value)throw new InvalidArgumentException('Capital Markets '.$label.' must be non-empty.');
        }
        if($this->schemaVersion<1)throw new InvalidArgumentException('Capital Markets event schema version must be >= 1.');
    }

    final public function eventId():string{return $this->id;}
    final public function occurredAt():DateTimeImmutable{return $this->occurredAt;}

    /** @return array<string,mixed> */
    final public function envelope():array
    {
        return [
            'event_id'=>$this->eventId(),
            'event_type'=>$this->eventName(),
            'aggregate_id'=>$this->aggregateId,
            'occurred_at'=>$this->occurredAt()->format(DATE_ATOM),
            'tenant'=>$this->organizationId,
            'payload'=>$this->payload,
            'schema_version'=>$this->schemaVersion,
        ];
    }
}
