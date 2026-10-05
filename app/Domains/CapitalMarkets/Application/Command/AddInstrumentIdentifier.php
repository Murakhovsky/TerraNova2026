<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Command;

final readonly class AddInstrumentIdentifier
{
    /** @param array<string,mixed> $identifier */
    public function __construct(
        public string $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $instrumentId,
        public array $identifier,
    ){}
}
