<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class EconomicRelationship extends ValueObject
{
    public function __construct(
        public InstrumentId $from,
        public InstrumentId $to,
        public EconomicRelationshipType $type,
        public ?string $evidenceReference = null,
    ) {
        if ($this->from->equals($this->to)) {
            throw new InvalidArgumentException('Economic relationship must connect two different instruments.');
        }
        if (
            $this->evidenceReference !== null
            && ($this->evidenceReference === '' || trim($this->evidenceReference) !== $this->evidenceReference)
        ) {
            throw new InvalidArgumentException('Economic relationship evidence reference must be null or a trimmed non-empty value.');
        }
    }

    public function key(): string
    {
        return $this->from->value() . '|' . $this->type->value . '|' . $this->to->value();
    }
}
