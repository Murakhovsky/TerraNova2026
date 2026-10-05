<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class InstrumentIdentifier extends ValueObject
{
    public function __construct(
        public InstrumentIdentifierType $type,
        public string $value,
        public ?string $source = null,
    ) {
        if ($this->value === '' || trim($this->value) !== $this->value || mb_strlen($this->value) > 190) {
            throw new InvalidArgumentException('Instrument identifier value must be a trimmed non-empty value up to 190 characters.');
        }
        if ($this->source !== null && ($this->source === '' || trim($this->source) !== $this->source || mb_strlen($this->source) > 120)) {
            throw new InvalidArgumentException('Instrument identifier source must be null or a trimmed value up to 120 characters.');
        }
    }

    public function uniquenessKey(): string
    {
        return $this->type->value.'|'.($this->source ?? '').'|'.$this->value;
    }
}
