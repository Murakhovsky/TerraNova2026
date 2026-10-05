<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum InstrumentStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Delisted = 'DELISTED';
    case Unknown = 'UNKNOWN';

    public function canBeLiveExecuted(): bool { return $this === self::Active; }
}
