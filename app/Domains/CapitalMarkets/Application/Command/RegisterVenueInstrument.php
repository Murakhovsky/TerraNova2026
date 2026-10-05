<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Command;

final readonly class RegisterVenueInstrument
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public string $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $venueId,
        public array $input,
    ){}
}
