<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Query;

final readonly class FindInstrumentByIdentifier
{
    public function __construct(
        public string $organizationId,
        public string $type,
        public string $value,
        public ?string $source=null,
    ){}
}
