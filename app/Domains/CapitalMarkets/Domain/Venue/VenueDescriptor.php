<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class VenueDescriptor extends ValueObject
{
    public function __construct(
        public VenueId $id,
        public VenueType $type,
        public string $name,
    ) {
        if ($this->name === '' || trim($this->name) !== $this->name || strlen($this->name) > 190) {
            throw new InvalidArgumentException('Venue name must be a trimmed non-empty value up to 190 characters.');
        }
    }
}
