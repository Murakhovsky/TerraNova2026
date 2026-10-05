<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class EconomicRelationship extends ValueObject
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public RelationshipId $id,
        public InstrumentId $sourceInstrument,
        public InstrumentId $targetInstrument,
        public EconomicRelationshipType $type,
        public EconomicRelationshipStrength $strength,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveTo,
        public EconomicRelationshipStatus $status,
        public array $metadata = [],
    ) {
        if ($this->sourceInstrument->equals($this->targetInstrument)) {
            throw new InvalidArgumentException('Economic relationship must connect two different instruments.');
        }
        if ($this->effectiveTo!==null && $this->effectiveTo <= $this->effectiveFrom) {
            throw new InvalidArgumentException('Economic relationship effective_to must be later than effective_from.');
        }
        InstrumentDescriptor::assertMetadata($this->metadata);
    }

    public function key(): string
    {
        return $this->sourceInstrument->value().'|'.$this->type->value.'|'.$this->targetInstrument->value();
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        return $this->status===EconomicRelationshipStatus::Active
            && $at >= $this->effectiveFrom
            && ($this->effectiveTo===null || $at < $this->effectiveTo);
    }
}
